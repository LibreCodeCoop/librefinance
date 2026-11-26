<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Banking;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCA\LibreFinance\Service\SaasPolicyRuntimeGate;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

final class BankingService {
    private const MODULE = 'librefinance_banking';
    private const MAX_MINOR = 9000000000000;

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
        private SaasPolicyRuntimeGate $policies,
    ) {}

    /** @return array<string,mixed> */
    public function list(int $spaceId, int $bankAccountId = 0, string $status = 'all',
        int $afterId = 0, string $from = '', string $through = ''): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'bank.read');
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        if (!in_array($status, ['all', 'pending', 'matched'], true)) {
            throw new DomainException('invalid_status');
        }
        foreach ([$from, $through] as $date) {
            if ($date === '') {
                continue;
            }
            if (preg_match('/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1) {
                throw new DomainException('invalid_date');
            }
            self::date($date);
        }
        if ($from !== '' && $through !== '' && $from > $through) {
            throw new DomainException('invalid_period');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'bank_account_id', 'external_id', 'booking_date', 'amount_minor',
            'description', 'status', 'matched_journal_id')
            ->from('lfb_transactions')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        if ($bankAccountId > 0) {
            $q->andWhere($q->expr()->eq('bank_account_id', $q->createNamedParameter($bankAccountId, IQueryBuilder::PARAM_INT)));
        }
        if ($status !== 'all') {
            $q->andWhere($q->expr()->eq('status', $q->createNamedParameter($status)));
        }
        if ($from !== '') {
            $q->andWhere($q->expr()->gte('booking_date', $q->createNamedParameter($from)));
        }
        if ($through !== '') {
            $q->andWhere($q->expr()->lte('booking_date', $q->createNamedParameter($through)));
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['bank_account_id'] = (int)$row['bank_account_id'];
            $row['amount_minor'] = (int)$row['amount_minor'];
            $row['matched_journal_id'] = $row['matched_journal_id'] === null ? null : (int)$row['matched_journal_id'];
        }
        unset($row);
        return ['transactions' => $rows, 'nextAfterId' => $more ? (int)end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    public function import(int $spaceId, int $bankAccountId, string $csv,
        string $format = 'csv', string $ofx = ''): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'bank.import_statement');
        $this->policies->requireWorkspaceEnabled($spaceId, 'imports.enabled');
        // This check precedes all parsing, bank lookup and financial writes.
        // Unknown formats still use the existing explicit 422 contract.
        if (in_array($format, ['csv', 'ofx'], true)
            && !in_array($format,
                $this->policies->workspace($spaceId, 'banking.allowedImportFormats')['effectiveValue'],
                true)) {
            throw new DomainException('module_disabled');
        }
        $currency = $this->finance->requireBankAccount($spaceId, self::MODULE, $bankAccountId);
        if ($format === 'csv') {
            if (strlen($csv) < 1 || strlen($csv) > 262144 || !mb_check_encoding($csv, 'UTF-8')
                || $ofx !== '') {
                throw new DomainException('invalid_csv');
            }
            $rows = self::parse($csv);
        } elseif ($format === 'ofx') {
            if ($csv !== '') {
                throw new DomainException('invalid_ofx');
            }
            $statement = OfxStatementParser::parse($ofx);
            if ($statement['currency'] !== null && $statement['currency'] !== $currency) {
                throw new DomainException('bank_import_currency_mismatch');
            }
            $rows = $statement['rows'];
        } else {
            throw new DomainException('unsupported_bank_import_format');
        }
        if ($rows === []) {
            throw new DomainException('empty_import');
        }
        $created = 0;
        $skipped = 0;
        $this->db->beginTransaction();
        try {
            $this->finance->reserveBankAccountsForWrite($spaceId, self::MODULE, [$bankAccountId]);
            foreach ($rows as $row) {
                $existing = $this->findByExternalId($spaceId, $bankAccountId, $row['id']);
                if ($existing !== null) {
                    if ((int)$existing['amount_minor'] !== $row['amount_minor']
                        || $existing['booking_date'] !== $row['date']
                        || $existing['description'] !== $row['description']) {
                        throw new DomainException('bank_import_conflict');
                    }
                    ++$skipped;
                    continue;
                }
                $this->insert('lfb_transactions', [
                    'space_id' => $spaceId, 'bank_account_id' => $bankAccountId,
                    'external_id' => $row['id'], 'booking_date' => $row['date'],
                    'amount_minor' => $row['amount_minor'], 'description' => $row['description'],
                    'status' => 'pending', 'matched_journal_id' => null,
                    'created_by' => $this->uid(), 'created_at' => time(),
                ]);
                $id = (int)$this->db->lastInsertId('*PREFIX*lfb_transactions');
                $this->audit($spaceId, $id, 'bank_transaction.imported');
                ++$created;
            }
            $this->db->commit();
            return ['imported' => $created, 'skipped' => $skipped, 'currency' => $currency];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception
                && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('bank_import_conflict', previous: $e);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function match(int $spaceId, int $transactionId, int $journalId): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'bank.reconcile');
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id', 'bank_account_id', 'amount_minor', 'status')
                ->from('lfb_transactions')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));
            $transaction = $q->executeQuery()->fetchAssociative();
            if (!$transaction) {
                throw new DomainException('bank_transaction_not_found');
            }
            if ($transaction['status'] !== 'pending') {
                throw new DomainException('bank_transaction_matched');
            }
            if ($journalId < 1) {
                throw new DomainException('journal_posting_not_found');
            }
            $this->finance->journalContainsBankPosting(
                $spaceId, self::MODULE, (int)$transaction['bank_account_id'],
                $journalId, (int)$transaction['amount_minor'],
            );
            $this->finance->claimJournal($spaceId, self::MODULE, $journalId,
                'bank:' . $transactionId);
            $q = $this->db->getQueryBuilder();
            $count = $q->update('lfb_transactions')
                ->set('status', $q->createNamedParameter('matched'))
                ->set('matched_journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('status', $q->createNamedParameter('pending')))
                ->executeStatement();
            if ($count !== 1) {
                throw new DomainException('bank_transaction_matched');
            }
            $this->audit($spaceId, $transactionId, 'bank_transaction.matched');
            $this->db->commit();
            return ['id' => $transactionId, 'status' => 'matched', 'journalId' => $journalId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception
                && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('journal_already_matched', previous: $e);
            }
            throw $e;
        }
    }

    /** @return array<int,array{id:string,date:string,amount_minor:int,description:string}> */
    private static function parse(string $csv): array {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new DomainException('invalid_csv');
        }
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '\\');
            if (!is_array($header)) {
                throw new DomainException('invalid_csv_header');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
            if ($header !== ['id', 'date', 'amount', 'description']) {
                throw new DomainException('invalid_csv_header');
            }
            $rows = [];
            $ids = [];
            while (($record = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                if ($record === [null]) {
                    continue;
                }
                if (count($rows) >= 500 || count($record) !== 4) {
                    throw new DomainException('invalid_csv_row');
                }
                [$id, $date, $amount, $description] = $record;
                $id = trim($id);
                $description = trim($description);
                self::date($date);
                if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $id)
                    || $description === '' || mb_strlen($description) > 255
                    || isset($ids[$id])) {
                    throw new DomainException('invalid_csv_row');
                }
                $minor = self::minor($amount);
                $ids[$id] = true;
                $rows[] = ['id' => $id, 'date' => $date, 'amount_minor' => $minor, 'description' => $description];
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private static function minor(string $amount): int {
        if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/', trim($amount), $match)) {
            throw new DomainException('invalid_csv_amount');
        }
        $minor = (int)$match[2] * 100 + (int)str_pad($match[3] ?? '', 2, '0');
        if ($match[1] === '-') {
            $minor = -$minor;
        }
        if ($minor === 0 || abs($minor) > self::MAX_MINOR) {
            throw new DomainException('invalid_csv_amount');
        }
        return $minor;
    }

    private static function date(string $value): void {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new DomainException('invalid_date');
        }
    }

    /** @return array<string,mixed>|null */
    private function findByExternalId(int $spaceId, int $bankAccountId, string $externalId): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'amount_minor', 'booking_date', 'description')
            ->from('lfb_transactions')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('bank_account_id', $q->createNamedParameter($bankAccountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('external_id', $q->createNamedParameter($externalId)));
        return $q->executeQuery()->fetchAssociative() ?: null;
    }

    /** @param array<string,string|int|null> $values */
    private function insert(string $table, array $values): void {
        $q = $this->db->getQueryBuilder();
        $params = [];
        foreach ($values as $name => $value) {
            $params[$name] = $q->createNamedParameter($value,
                is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
        }
        $q->insert($table)->values($params)->executeStatement();
    }

    private function audit(int $spaceId, int $transactionId, string $action): void {
        $this->insert('lfb_audit', [
            'space_id' => $spaceId, 'transaction_id' => $transactionId,
            'actor_uid' => $this->uid(), 'action' => $action, 'occurred_at' => time(),
        ]);
    }

    private function uid(): string {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new DomainException('authentication_required');
        }
        return $uid;
    }
}
