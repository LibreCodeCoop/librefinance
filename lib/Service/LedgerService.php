<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class LedgerService {
    private const MAX_MINOR = 9000000000000;

    public function __construct(private IDBConnection $db,
        private WriteService $writer, private AccessService $access,
        private JournalClaimService $claims, private CategoryService $categories,
        private CustomFieldService $customFields,
        private PaymentMethodService $paymentMethods,
        private AccountReferenceGuard $accountGuard) {}

    /**
     * Bank identifiers are structured for future connectors, not login secrets.
     * All details are optional because cashboxes and international banking
     * formats do not share one mandatory identifier.
     * @return array<string,string|null>
     */
    private static function normalizeBankDetails(array $values, bool $isBank): array {
        if (!$isBank && $values !== []) {
            throw new DomainException('bank_details_not_applicable');
        }
        $names = [
            'institutionName' => ['institution_name', 150],
            'institutionCode' => ['institution_code', 40],
            'branchNumber' => ['branch_number', 40],
            'accountNumber' => ['account_number', 70],
            'accountType' => ['account_type', 32],
            'iban' => ['iban', 34],
            'swiftBic' => ['swift_bic', 11],
        ];
        foreach ($values as $key => $value) {
            if (!isset($names[$key]) || !is_string($value)) {
                throw new DomainException('invalid_bank_details');
            }
        }
        $result = [];
        foreach ($names as $key => [$column, $limit]) {
            if (!array_key_exists($key, $values)) continue;
            $value = trim($values[$key]);
            if (mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new DomainException('invalid_bank_details');
            }
            if ($key === 'iban' && $value !== '' && !preg_match('/^[A-Z0-9]{5,34}$/i', $value)) {
                throw new DomainException('invalid_bank_details');
            }
            if ($key === 'swiftBic' && $value !== '' && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/i', $value)) {
                throw new DomainException('invalid_bank_details');
            }
            $result[$column] = $value === '' ? null : $value;
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function accounts(int $spaceId): array {
        $this->access->requireFinancialCapability($spaceId, 'accounts.read');
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'code', 'name', 'class', 'currency', 'is_bank', 'active', 'institution_name', 'institution_code',
            'branch_number', 'account_number', 'account_type', 'iban', 'swift_bic', 'logo_version')
            ->from('lf_accounts')
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('code', 'ASC');
        $accounts = $qb->executeQuery()->fetchAllAssociative();

        $q = $this->db->getQueryBuilder();
        $q->select('account_id')->selectAlias($q->createFunction('SUM(amount_minor)'), 'balance_minor')
            ->from('lf_postings')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->groupBy('account_id');
        $balances = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $balances[(int)$row['account_id']] = (int)$row['balance_minor'];
        }
        foreach ($accounts as &$account) {
            $account['id'] = (int)$account['id'];
            $account['is_bank'] = (bool)$account['is_bank'];
            $account['active'] = (bool)$account['active'];
            $account['balance_minor'] = $balances[$account['id']] ?? 0;
        }
        unset($account);
        return $accounts;
    }

    /** @return array<string, mixed> */
    public function createAccount(int $spaceId, string $code, string $name, string $class, bool $isBank, bool $active = true, array $bankDetails = []): array {
        $this->access->requireFinancialCapability($spaceId, 'accounts.manage');
        $code = strtoupper(trim($code));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $code) || $name === '' || mb_strlen($name) > 150) {
            throw new DomainException('invalid_account');
        }
        if (!in_array($class, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
            throw new DomainException('invalid_account_class');
        }
        if ($isBank && $class !== 'asset') {
            throw new DomainException('invalid_bank_class');
        }
        $details = self::normalizeBankDetails($bankDetails, $isBank);
        $currency = $this->spaceCurrency($spaceId);
        $this->db->beginTransaction();
        try {
            $this->writer->insert('lf_accounts', [
                'space_id' => $spaceId, 'code' => $code, 'name' => $name, 'class' => $class,
                'currency' => $currency, 'is_bank' => $isBank ? 1 : 0, 'active' => $active ? 1 : 0,
                ...$details,
            ]);
            $id = $this->db->lastInsertId('*PREFIX*lf_accounts');
            $this->audit($spaceId, 'account.created', 'account', $id);
            $this->db->commit();
            return ['id' => $id, 'code' => $code, 'name' => $name, 'class' => $class,
                'currency' => $currency, 'is_bank' => $isBank, 'active' => $active, 'balance_minor' => 0, ...$details];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Edit descriptive metadata without rewriting history, currency, class,
     * posting IDs or bank-account semantics. Inactive accounts cannot be posted.
     * The existing balance must be zero before deactivation.
     */
    public function updateAccount(int $spaceId, int $accountId, string $name, bool $active, array $bankDetails = []): array {
        $this->access->requireFinancialCapability($spaceId, 'accounts.manage');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainException('invalid_account');
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id', 'code', 'name', 'class', 'currency', 'is_bank', 'active', 'institution_name', 'institution_code',
            'branch_number', 'account_number', 'account_type', 'iban', 'swift_bic', 'logo_version')
                ->from('lf_accounts')
                ->where($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
            $existing = $q->executeQuery()->fetchAssociative();
            if ($existing === false) { throw new DomainException('account_not_found'); }
            $details = self::normalizeBankDetails($bankDetails, (bool)$existing['is_bank']);
            if (!$active && (bool)$existing['active']) {
                $this->accountGuard->reserve($spaceId, $accountId);
                $balance = $this->db->getQueryBuilder();
                $balance->selectAlias($balance->createFunction('SUM(amount_minor)'), 'amount')
                    ->from('lf_postings')
                    ->where($balance->expr()->eq('space_id', $balance->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($balance->expr()->eq('account_id', $balance->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
                if ((int)($balance->executeQuery()->fetchOne() ?: 0) !== 0) {
                    throw new DomainException('account_has_balance');
                }
            }
            $this->writer->update('lf_accounts', ['name' => $name, 'active' => $active ? 1 : 0, ...$details],
                ['id' => $accountId, 'space_id' => $spaceId]);
            $this->audit($spaceId, 'account.updated', 'account', $accountId);
            $this->db->commit();
            return ['id' => $accountId, 'code' => (string)$existing['code'], 'name' => $name,
                'class' => (string)$existing['class'], 'currency' => (string)$existing['currency'],
                'is_bank' => (bool)$existing['is_bank'], 'active' => $active, ...$details];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * A deletion preview is not an authorization to erase an account.
     * The DELETE endpoint recomputes references inside its own transaction.
     *
     * @return array{accountId:int,canDelete:bool,reasons:list<string>}
     */
    public function accountDeletionPreview(int $spaceId, int $accountId): array {
        $this->access->requireFinancialCapability($spaceId, 'accounts.manage');
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('lf_accounts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        if ($q->executeQuery()->fetchOne() === false) {
            throw new DomainException('account_not_found');
        }
        $reasons = $this->accountDeletionBlockers($spaceId, $accountId);
        return ['accountId' => $accountId, 'canDelete' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Physically delete only an entirely unused account. Do not erase journals
     * with a net-zero balance, imported source identity or banking matches.
     * The account is first made inactive in this transaction, then rechecked.
     * Modules must also reject writes to inactive accounts.
     */
    public function deleteAccount(int $spaceId, int $accountId): array {
        $this->access->requireFinancialCapability($spaceId, 'accounts.manage');
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->update('lf_accounts')
                ->set('active', $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
            if ($q->executeStatement() !== 1) {
                throw new DomainException('account_not_found');
            }
            $reasons = $this->accountDeletionBlockers($spaceId, $accountId);
            if ($reasons !== []) {
                throw new DomainException('account_in_use');
            }
            $q = $this->db->getQueryBuilder();
            $q->delete('lf_accounts')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
            if ($q->executeStatement() !== 1) {
                throw new DomainException('account_not_found');
            }
            $this->audit($spaceId, 'account.deleted', 'account', $accountId);
            $this->db->commit();
            return ['id' => $accountId, 'deleted' => true];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** A fixed whitelist of native account references; missing optional tables are ignored. */
    private function accountDeletionBlockers(int $spaceId, int $accountId): array {
        $logo = $this->db->getQueryBuilder();
        $logo->select('logo_version')->from('lf_accounts')
            ->where($logo->expr()->eq('space_id',
                $logo->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($logo->expr()->eq('id',
                $logo->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        $hasLogo = $logo->executeQuery()->fetchOne();
        $references = [
            'lf_postings' => ['account_id' => 'journal_entries'],
            'lf_categories' => ['account_id' => 'category_links'],
            'lfb_transactions' => ['bank_account_id' => 'bank_transactions'],
            'lfb_transfers' => ['from_account_id' => 'bank_transfers',
                                'to_account_id' => 'bank_transfers'],
            'lfi_allocations' => ['bank_account_id' => 'invoice_allocations'],
            'lfm_liquid_origins' => ['target_account_id' => 'import_snapshots'],
            'lfm_records' => ['target_id' => 'import_mappings'],
            'lf_payment_methods' => ['bank_account_id' => 'payment_methods'],
        ];
        $blocked = $hasLogo !== false && $hasLogo !== null
            ? ['bank_logo' => true] : [];
        foreach ($references as $table => $columns) {
            if (!$this->db->tableExists($table)) {
                continue;
            }
            foreach ($columns as $column => $reason) {
                if (isset($blocked[$reason])) continue;
                $q = $this->db->getQueryBuilder();
                $q->select('id')->from($table)
                    ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq($column, $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
                    ->setMaxResults(1);
                if ($table === 'lfm_records') {
                    $q->andWhere($q->expr()->eq('record_kind', $q->createNamedParameter('account')));
                }
                if ($q->executeQuery()->fetchOne() !== false) {
                    $blocked[$reason] = true;
                }
            }
        }
        return array_keys($blocked);
    }

    /** @return array<string,mixed> */
    public function createJournal(
        int $spaceId,
        int $debitId,
        int $creditId,
        int $amountMinor,
        string $date,
        string $memo,
        string $externalKey,
        ?int $categoryId = null,
        array $customFields = [],
        ?string $notesMarkdown = null,
        ?int $paymentMethodId = null,
    ): array {
        $this->access->requireFinancialCapability($spaceId, 'ledger.post');
        if ($debitId <= 0 || $creditId <= 0 || $debitId === $creditId) {
            throw new DomainException('invalid_postings');
        }
        if ($amountMinor <= 0 || $amountMinor > self::MAX_MINOR) {
            throw new DomainException('invalid_amount');
        }
        $dateValue = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dateValue === false || $dateValue->format('Y-m-d') !== $date) {
            throw new DomainException('invalid_date');
        }
        $memo = trim($memo);
        if ($memo === '' || mb_strlen($memo) > 255) {
            throw new DomainException('invalid_memo');
        }
        $notesMarkdown = trim($notesMarkdown ?? '');
        if (mb_strlen($notesMarkdown) > 5000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $notesMarkdown)) {
            throw new DomainException('invalid_notes_markdown');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{12,64}$/', $externalKey)) {
            throw new DomainException('invalid_idempotency_key');
        }
        $currency = $this->spaceCurrency($spaceId);
        if ($categoryId !== null) {
            if ($categoryId < 1) {
                throw new DomainException('category_not_found');
            }
            $resolvedAccountId = $this->categories->resolveForPosting($spaceId, $categoryId);
            if ($resolvedAccountId !== $debitId && $resolvedAccountId !== $creditId) {
                throw new DomainException('category_account_mismatch');
            }
        }
        $hashData = [$debitId, $creditId, $amountMinor, $date, $memo, $currency, $categoryId];
        if ($customFields !== [] || $notesMarkdown !== '') {
            ksort($customFields, SORT_NATURAL);
            $hashData[] = $customFields;
            $hashData[] = $notesMarkdown;
        }
        if ($paymentMethodId !== null) $hashData[] = $paymentMethodId;
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        // Backward-compatible replay of uncategorized journals posted by v0.5.
        $legacyHash = ($categoryId === null && $customFields === [] && $notesMarkdown === '' && $paymentMethodId === null)
            ? hash('sha256', json_encode(
            [$debitId, $creditId, $amountMinor, $date, $memo, $currency],
            JSON_THROW_ON_ERROR,
        )) : null;
        $this->db->beginTransaction();
        try {
            $existing = $this->findByKey($spaceId, $externalKey);
            if ($existing !== null) {
                if ($existing['request_hash'] !== $hash
                    && ($legacyHash === null || $existing['request_hash'] !== $legacyHash)) {
                    throw new DomainException('idempotency_conflict');
                }
                $this->db->commit();
                return ['id' => (int)$existing['id'], 'replayed' => true];
            }

            // Acquire account locks in a globally stable order before posting.
            $ids = [$debitId, $creditId];
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                $this->accountGuard->reserve($spaceId, $id);
            }
            $custom = $this->customFields->validateValues($spaceId, $customFields, true, 0, 'journal');
            if ($paymentMethodId !== null) {
                $this->paymentMethods->validateForJournal($spaceId,$paymentMethodId,$debitId,$creditId);
            }
            $debit = $this->findAccount($spaceId, $debitId);
            $credit = $this->findAccount($spaceId, $creditId);
            if (!$debit || !$credit || !$debit['active'] || !$credit['active'] ||
                $debit['currency'] !== $currency || $credit['currency'] !== $currency) {
                throw new DomainException('account_not_found');
            }

            $this->writer->insert('lf_journals', [
                'space_id' => $spaceId, 'effective_on' => $date, 'memo' => $memo,
                'notes_md' => $notesMarkdown !== '' ? $notesMarkdown : null,
                'payment_method_id' => $paymentMethodId,
                'category_id' => $categoryId,
                'external_key' => $externalKey, 'request_hash' => $hash, 'status' => 'posted',
                'created_by' => $this->access->uid(), 'created_at' => time(),
            ]);
            $id = $this->db->lastInsertId('*PREFIX*lf_journals');
            $this->post($spaceId, $id, $debitId, $amountMinor, $currency);
            $this->post($spaceId, $id, $creditId, -$amountMinor, $currency);
            $this->customFields->saveValues($spaceId, (int)$id, $custom, 'journal');
            $this->audit($spaceId, 'journal.posted', 'journal', $id);
            $this->db->commit();
            return ['id' => $id, 'replayed' => false];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception) {
                // When the same key is submitted concurrently on SQLite,
                // one writer may observe the other as BUSY before the winner
                // commits. Re-read after rollback using a new snapshot.
                // Do not turn unrelated failures into a successful response.
                for ($attempt = 0; $attempt < 20; ++$attempt) {
                    try {
                        $existing = $this->findByKey($spaceId, $externalKey);
                    } catch (\OCP\DB\Exception) {
                        $existing = null;
                    }
                    if ($existing !== null) {
                        if ($existing['request_hash'] !== $hash
                            && ($legacyHash === null || $existing['request_hash'] !== $legacyHash)) {
                            throw new DomainException('idempotency_conflict');
                        }
                        return ['id' => (int)$existing['id'], 'replayed' => true];
                    }
                    if ($attempt < 19) {
                        usleep(30000);
                    }
                }
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function reverse(int $spaceId, int $journalId): array {
        $this->access->requireFinancialCapability($spaceId, 'ledger.reverse');
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('*')->from('lf_journals')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
            $original = $q->executeQuery()->fetchAssociative();
            if (!$original || $original['reversed_journal_id'] !== null) {
                throw new DomainException('journal_not_found');
            }
            // Preserve cross-module claims even when the claiming app is disabled.
            $this->claims->assertReversible($spaceId, $journalId);
            $key = 'reverse:' . $journalId;
            $existing = $this->findByKey($spaceId, $key);
            if ($existing !== null) {
                $this->db->commit();
                return ['id' => (int)$existing['id'], 'replayed' => true];
            }
            $q = $this->db->getQueryBuilder();
            $q->select('account_id', 'amount_minor', 'currency')->from('lf_postings')
                ->where($q->expr()->eq('journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
            $posts = $q->executeQuery()->fetchAllAssociative();
            if (count($posts) < 2) {
                throw new DomainException('invalid_original_postings');
            }
            $this->writer->insert('lf_journals', [
                'space_id' => $spaceId, 'effective_on' => date('Y-m-d'),
                'memo' => 'Estorno: ' . mb_substr((string)$original['memo'], 0, 245),
                'external_key' => $key, 'request_hash' => hash('sha256', $key),
                'category_id' => $original['category_id'],
                'status' => 'posted', 'reversed_journal_id' => $journalId,
                'created_by' => $this->access->uid(), 'created_at' => time(),
            ]);
            $id = $this->db->lastInsertId('*PREFIX*lf_journals');
            foreach ($posts as $post) {
                $this->post($spaceId, $id, (int)$post['account_id'],
                    -(int)$post['amount_minor'], (string)$post['currency']);
            }
            $this->audit($spaceId, 'journal.reversed', 'journal', $journalId);
            $this->db->commit();
            return ['id' => $id, 'replayed' => false];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function journals(int $spaceId, int $beforeId = 0): array {
        $this->access->requireFinancialCapability($spaceId, 'ledger.read');
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'effective_on', 'memo', 'notes_md', 'payment_method_id', 'created_by', 'created_at', 'reversed_journal_id', 'category_id')
            ->from('lf_journals')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')->setMaxResults(51);
        if ($beforeId > 0) {
            $q->andWhere($q->expr()->lt('id', $q->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        if ($rows === []) {
            return ['journals' => [], 'nextBeforeId' => null];
        }
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $q = $this->db->getQueryBuilder();
        $q->select('journal_id', 'account_id', 'amount_minor')->from('lf_postings')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('journal_id', $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $postsById = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $post) {
            $postsById[(int)$post['journal_id']][] = [
                'accountId' => (int)$post['account_id'],
                'amountMinor' => (int)$post['amount_minor'],
            ];
        }
        $customById = $this->customFields->valuesForEntities($spaceId, 'journal', $ids);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['custom_fields'] = $customById[$row['id']] ?? [];
            $row['payment_method_id'] = $row['payment_method_id'] === null ? null : (int)$row['payment_method_id'];
            $row['category_id'] = $row['category_id'] === null ? null : (int)$row['category_id'];
            $row['postings'] = $postsById[$row['id']] ?? [];
        }
        unset($row);
        return ['journals' => $rows, 'nextBeforeId' => $hasMore ? (int)end($rows)['id'] : null];
    }

    private function spaceCurrency(int $spaceId): string {
        $this->access->requireFinancialCapability($spaceId, 'workspace.read');
        $q = $this->db->getQueryBuilder();
        $q->select('currency')->from('lf_spaces')
            ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $result = $q->executeQuery()->fetchOne();
        if ($result === false) {
            throw new DomainException('space_not_found');
        }
        return (string)$result;
    }

    /** @return array<string,mixed>|null */
    private function findAccount(int $spaceId, int $id): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'active', 'currency')->from('lf_accounts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $result = $q->executeQuery()->fetchAssociative();
        return $result ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findByKey(int $spaceId, string $key): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'request_hash')->from('lf_journals')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('external_key', $q->createNamedParameter($key, IQueryBuilder::PARAM_STR)));
        return $q->executeQuery()->fetchAssociative() ?: null;
    }

    private function post(int $spaceId, int $journalId, int $accountId, int $amount, string $currency): void {
        $this->writer->insert('lf_postings', [
            'space_id' => $spaceId, 'journal_id' => $journalId,
            'account_id' => $accountId, 'amount_minor' => $amount, 'currency' => $currency,
        ]);
    }

    private function audit(int $spaceId, string $action, string $entity, int $entityId): void {
        $this->writer->insert('lf_audit', [
            'space_id' => $spaceId, 'actor_uid' => $this->access->uid(), 'action' => $action,
            'entity' => $entity, 'entity_id' => $entityId, 'occurred_at' => time(),
        ]);
    }
}
