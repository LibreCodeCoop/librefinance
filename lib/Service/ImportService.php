<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use OCA\LibreFinance\Import\Source\SourceSystem;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Security\ICrypto;

/**
 * Bounded, local, normalized-export importer. No web scraping, credentials,
 * HTTP proxying, or external endpoints. Stored mapping contains hashes and
 * identifiers only; the import body is never retained by this extension.
 */
final class ImportService {
    private const MODULE = 'librefinance_import';
    private const FORMAT = 'librefinance.normalized.v1';
    private const MAX_BYTES = 131072;
    private const MAX_RECORDS = 100;

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
        private ICrypto $crypto,
    ) {}

    /**
     * Scoped ledger of source identities actually registered by this importer.
     * This is not a claim of complete source migration or fiscal parity.
     *
     * @return array<string,mixed>
     */
    public function coverage(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic'): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        if (!SourceSystem::valid($sourceSystem)
            || !self::identifier($sourceCompanyId, 64)) {
            throw new DomainException('invalid_import_source');
        }
        $totals = [];
        foreach (['company' => 'lf_companies', 'contact' => 'lf_contacts',
            'category' => 'lf_categories', 'account' => 'lf_accounts'] as $kind => $table) {
            $q = $this->db->getQueryBuilder();
            $q->selectAlias($q->createFunction('COUNT(m.id)'), 'mapped')
                ->selectAlias($q->createFunction('COUNT(t.id)'), 'present')
                ->from('lfm_records', 'm')
                ->leftJoin('m', $table, 't',
                    't.id = m.target_id AND t.space_id = m.space_id')
                ->where($q->expr()->eq('m.space_id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('m.source_system',
                    $q->createNamedParameter($sourceSystem)))
                ->andWhere($q->expr()->eq('m.source_company_id',
                    $q->createNamedParameter($sourceCompanyId)))
                ->andWhere($q->expr()->eq('m.record_kind', $q->createNamedParameter($kind)));
            $count = $q->executeQuery()->fetchAssociative();
            $mapped = (int)($count['mapped'] ?? 0);
            $present = (int)($count['present'] ?? 0);
            $totals[$kind] = [
                'mapped' => $mapped,
                'targetPresent' => $present,
                'missingTarget' => $mapped - $present,
            ];
            if ($kind === 'account') {
                $countDetails = $this->db->getQueryBuilder();
                $countDetails->selectAlias($countDetails->createFunction('COUNT(s.id)'), 'total')
                    ->from('lfm_records', 'm')
                    ->leftJoin('m', 'lfm_liquid_origins', 's',
                        's.target_account_id = m.target_id AND s.space_id = m.space_id')
                    ->where($countDetails->expr()->eq('m.space_id',
                        $countDetails->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($countDetails->expr()->eq('m.source_system',
                        $countDetails->createNamedParameter($sourceSystem)))
                    ->andWhere($countDetails->expr()->eq('m.source_company_id',
                        $countDetails->createNamedParameter($sourceCompanyId)))
                    ->andWhere($countDetails->expr()->eq('m.record_kind',
                        $countDetails->createNamedParameter('account')));
                $snapshots = (int)$countDetails->executeQuery()->fetchOne();
                $totals[$kind]['snapshotsPresent'] = $snapshots;
                $totals[$kind]['missingSnapshots'] = $mapped - $snapshots;
                $totals[$kind]['balanceReconciled'] = false;
            }
        }
        return [
            'sourceSystem' => $sourceSystem,
            'sourceCompanyId' => $sourceCompanyId,
            'kinds' => $totals,
            'sourceInventoryVerified' => false,
            'completeSourceMigration' => false,
            'note' => 'Mapping counts only; reconcile against a separately verified, complete source inventory.',
        ];
    }

    /**
     * Source balance snapshots, never posted or approved for cutover.
     * Keyset pagination and ONE aggregated posting query per page.
     * Bank account numbers remain encrypted and are not returned by API.
     *
     * @return array<string,mixed>
     */
    public function accountSnapshots(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic', int $afterId = 0): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        if (!SourceSystem::valid($sourceSystem)
            || !self::identifier($sourceCompanyId, 64)
            || $afterId < 0) {
            throw new DomainException('invalid_import_source');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('m.id', 'm.source_record_id', 'm.target_id',
                'a.code', 'a.name', 'a.active', 'a.currency',
                's.kind', 's.bank_name', 's.number_last4',
                's.opening_balance_minor', 's.current_balance_minor',
                's.balance_as_of')
            ->from('lfm_records', 'm')
            ->leftJoin('m', 'lf_accounts', 'a',
                'a.id = m.target_id AND a.space_id = m.space_id')
            ->leftJoin('m', 'lfm_liquid_origins', 's',
                's.target_account_id = m.target_id AND s.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($sourceSystem)))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($sourceCompanyId)))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('account')))
            ->andWhere($q->expr()->gt('m.id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.id', 'ASC')->setMaxResults(51);
        $found = $q->executeQuery()->fetchAllAssociative();
        $more = count($found) > 50;
        $found = array_slice($found, 0, 50);
        $ids = array_map(static fn(array $r): int => (int)$r['target_id'], $found);
        $ledger = [];
        if ($ids !== []) {
            $p = $this->db->getQueryBuilder();
            $p->select('account_id')
                ->selectAlias($p->createFunction('SUM(amount_minor)'), 'balance_minor')
                ->from('lf_postings')
                ->where($p->expr()->eq('space_id',
                    $p->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($p->expr()->in('account_id',
                    $p->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('account_id');
            foreach ($p->executeQuery()->fetchAllAssociative() as $posting) {
                $ledger[(int)$posting['account_id']] = (int)$posting['balance_minor'];
            }
        }
        $result = [];
        foreach ($found as $row) {
            $id = (int)$row['target_id'];
            $intact = $row['code'] !== null && $row['balance_as_of'] !== null;
            $current = $intact ? (int)$row['current_balance_minor'] : null;
            $balance = $intact ? ($ledger[$id] ?? 0) : null;
            $result[] = [
                'sourceId' => $row['source_record_id'],
                'targetAccountId' => $id,
                'code' => $row['code'],
                'name' => $row['name'],
                'currency' => $row['currency'],
                'active' => $row['active'] === null ? null : (bool)$row['active'],
                'kind' => $row['kind'],
                'bankName' => $row['bank_name'],
                'numberMasked' => $row['number_last4'] === null ? null
                    : '****' . $row['number_last4'],
                'openingBalanceMinor' => $intact ? (int)$row['opening_balance_minor'] : null,
                'sourceCurrentBalanceMinor' => $current,
                'balanceAsOf' => $row['balance_as_of'],
                'ledgerBalanceMinor' => $balance,
                'unreconciledDifferenceMinor' => $intact ? $current - $balance : null,
                'integrity' => $intact ? 'present' : 'missing_target_or_snapshot',
                'balanceReconciled' => false,
            ];
        }
        return [
            'sourceSystem' => $sourceSystem, 'sourceCompanyId' => $sourceCompanyId,
            'accounts' => $result,
            'nextAfterId' => $more ? (int)end($found)['id'] : null,
            'sourceInventoryVerified' => false,
            'balanceReconciled' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function preview(int $spaceId, string $exportJson): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        $batch = $this->parse($exportJson);
        $existing = $this->existing($spaceId, $batch);
        return $this->inspect($spaceId, $batch, $existing);
    }

    /** @return array<string,mixed> */
    public function apply(int $spaceId, string $exportJson, string $previewHash, bool $confirmed): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        if (!$confirmed) {
            throw new DomainException('import_confirmation_required');
        }
        $batch = $this->parse($exportJson);
        if (!preg_match('/^[0-9a-f]{64}$/', $previewHash)
            || !hash_equals($batch['digest'], $previewHash)) {
            throw new DomainException('import_preview_changed');
        }

        $this->db->beginTransaction();
        try {
            $existing = $this->existing($spaceId, $batch);
            $inspection = $this->inspect($spaceId, $batch, $existing);
            if ($inspection['issues'] !== []) {
                throw new DomainException('import_conflict');
            }

            $created = 0;
            $skipped = 0;
            if (($batch['company'] ?? null) !== null) {
                $row = $batch['company'];
                if (isset($existing['company'][$row['sourceId']])) {
                    ++$skipped;
                } else {
                    $target = $this->finance->importCompany($spaceId, self::MODULE,
                        $row['legalName'], $row['country'], $row['taxId'],
                        $row['tradeName'], $row['municipalRegistration'],
                        $row['stateRegistration']);
                    $this->record($spaceId, $batch, 'company', $row, (int)$target['id']);
                    ++$created;
                }
            }
            // A source bank balance is retained as a snapshot only. It is NOT
            // a posting and cannot become a second opening balance when the
            // transaction history is imported in a later migration stage.
            foreach (($batch['accounts'] ?? []) as $row) {
                $prior = $existing['account'][$row['sourceId']] ?? null;
                if ($prior !== null) {
                    ++$skipped;
                    continue;
                }
                $target = $this->finance->importLiquidAccount($spaceId, self::MODULE,
                    $row['code'], $row['name'], $row['currency'], $row['enabled']);
                $id = (int)$target['id'];
                $digits = $row['number'] === null ? ''
                    : (string)preg_replace('/[^0-9]/', '', $row['number']);
                $this->insert('lfm_liquid_origins', [
                    'space_id' => $spaceId,
                    'target_account_id' => $id,
                    'kind' => $row['kind'],
                    'bank_name' => $row['bankName'],
                    'account_number_cipher' => $row['number'] === null
                        ? null : $this->crypto->encrypt($row['number']),
                    'number_last4' => $digits === '' ? null : substr($digits, -4),
                    'currency' => $row['currency'],
                    'opening_balance_minor' => $row['openingBalanceMinor'],
                    'current_balance_minor' => $row['currentBalanceMinor'],
                    'balance_as_of' => $row['balanceAsOf'],
                    'created_at' => time(),
                ]);
                $this->record($spaceId, $batch, 'account', $row, $id);
                ++$created;
            }
            $mapCategories = [];
            foreach ($existing['category'] as $sourceId => $prior) {
                $mapCategories[$sourceId] = (int)$prior['target_id'];
            }

            // Import contacts first, each target and mapping within ONE
            // database transaction. Nested Nextcloud transactions are safe
            // with the supported DBAL backends, tested by rollback cases.
            foreach ($batch['contacts'] as $row) {
                $prior = $existing['contact'][$row['sourceId']] ?? null;
                if ($prior !== null) {
                    ++$skipped;
                    continue;
                }
                $target = $this->finance->importContact($spaceId, self::MODULE,
                    $row['name'], $row['kind'], $row['email'], $row['taxId']);
                $this->record($spaceId, $batch, 'contact', $row, (int)$target['id']);
                ++$created;
            }

            // Parents may precede children, follow them, or belong to an
            // earlier batch of the SAME source company and financial space.
            $pending = [];
            foreach ($batch['categories'] as $row) {
                if (isset($existing['category'][$row['sourceId']])) {
                    ++$skipped;
                } else {
                    $pending[$row['sourceId']] = $row;
                }
            }
            $accounts = $inspection['_account_map'];
            while ($pending !== []) {
                $advanced = false;
                foreach ($pending as $sourceId => $row) {
                    $parentSourceId = $row['parentSourceId'];
                    if ($parentSourceId !== null && !isset($mapCategories[$parentSourceId])) {
                        continue;
                    }
                    $parentId = $parentSourceId !== null ? $mapCategories[$parentSourceId] : null;
                    $accountId = $row['accountCode'] !== null
                        ? $accounts[$row['accountCode']]['id'] : null;
                    $target = $this->finance->importCategory($spaceId, self::MODULE,
                        $row['code'], $row['name'], $row['kind'], $parentId, $accountId);
                    $mapCategories[$sourceId] = (int)$target['id'];
                    $this->record($spaceId, $batch, 'category', $row, (int)$target['id']);
                    unset($pending[$sourceId]);
                    ++$created;
                    $advanced = true;
                }
                if (!$advanced) {
                    throw new DomainException('import_unresolved_parent');
                }
            }

            $this->insert('lfm_audit', [
                'space_id' => $spaceId,
                'source_system' => $batch['source']['system'],
                'source_company_id' => $batch['source']['companyId'],
                'batch_hash' => $batch['digest'],
                'created_count' => $created,
                'skipped_count' => $skipped,
                'actor_uid' => $this->actor(),
                'occurred_at' => time(),
            ]);
            $this->db->commit();
            return [
                'created' => $created, 'skipped' => $skipped,
                'total' => count($batch['contacts']) + count($batch['categories'])
                    + count($batch['accounts'] ?? [])
                    + (($batch['company'] ?? null) === null ? 0 : 1),
                'sourceSystem' => $batch['source']['system'],
                'previewHash' => $batch['digest'],
                'complete' => true,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception) {
                $reason = $e->getReason();
                if (in_array($reason, [
                    \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
                    \OCP\DB\Exception::REASON_DEADLOCK,
                    \OCP\DB\Exception::REASON_LOCK_WAIT_TIMEOUT,
                ], true)) {
                    throw new DomainException('import_conflict', previous: $e);
                }
                if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE
                    && $reason === \OCP\DB\Exception::REASON_DRIVER
                    && (str_contains(strtolower($e->getMessage()), 'database is locked')
                        || str_contains(strtolower($e->getMessage()), 'database is busy'))) {
                    throw new DomainException('import_concurrent_change', previous: $e);
                }
            }
            throw $e;
        }
    }

    /**
     * Strict canonical representation. The source's system/company ID MUST
     * be independently selected and verified by the importing administrator.
     * @return array<string,mixed>
     */
    private function parse(string $json): array {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new DomainException('invalid_import_size');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('invalid_import_json');
        }
        if (!is_array($data) || array_is_list($data)
            || ($data['format'] ?? null) !== self::FORMAT
            || !self::onlyKeys($data, ['format', 'source', 'company', 'contacts', 'categories', 'accounts'])
            || !is_array($data['source'] ?? null)
            || !self::onlyKeys($data['source'], ['system', 'companyId'])) {
            throw new DomainException('invalid_import_format');
        }
        $source = $data['source'];
        if (!SourceSystem::valid($source['system'] ?? null)
            || !self::identifier($source['companyId'] ?? null, 64)) {
            throw new DomainException('invalid_import_source');
        }
        $company = null;
        if (array_key_exists('company', $data)) {
            $row = $data['company'];
            if (!is_array($row) || array_is_list($row)
                || !self::onlyKeys($row, ['sourceId', 'legalName', 'country', 'taxId',
                    'tradeName', 'municipalRegistration', 'stateRegistration'])
                || !self::identifier($row['sourceId'] ?? null, 64)
                || $row['sourceId'] !== $source['companyId']
                || !is_string($row['legalName'] ?? null)
                || trim($row['legalName']) === '' || mb_strlen(trim($row['legalName'])) > 180
                || !is_string($row['country'] ?? null)
                || !CompanyService::validCountry(strtoupper(trim($row['country'])))
                || (isset($row['taxId']) && !is_string($row['taxId']))) {
                throw new DomainException('invalid_import_company');
            }
            $country = strtoupper(trim($row['country']));
            $taxId = trim($row['taxId'] ?? '');
            if ($country === 'BR') {
                $taxId = strtoupper(preg_replace('/[^0-9a-zA-Z]/', '', $taxId));
                if ($taxId !== '' && !preg_match('/^[0-9A-Z]{14}$/D', $taxId)) {
                    throw new DomainException('invalid_import_company');
                }
            } elseif ($taxId !== '' && (mb_strlen($taxId) > 40
                || preg_match('/^[\p{L}\p{N} .\/-]+$/uD', $taxId) !== 1)) {
                throw new DomainException('invalid_import_company');
            }
            $company = ['sourceId' => $row['sourceId'],
                'legalName' => trim($row['legalName']), 'country' => $country,
                'taxId' => $taxId === '' ? null : $taxId];
            foreach (['tradeName' => 180, 'municipalRegistration' => 40,
                'stateRegistration' => 40] as $field => $limit) {
                if (isset($row[$field]) && (!is_string($row[$field])
                    || mb_strlen(trim($row[$field])) > $limit)) {
                    throw new DomainException('invalid_import_company');
                }
                $value = trim($row[$field] ?? '');
                $company[$field] = $value === '' ? null : $value;
            }
        }
        $contacts = $data['contacts'] ?? [];
        $categories = $data['categories'] ?? [];
        $liquidAccounts = $data['accounts'] ?? [];
        if (!is_array($contacts) || !array_is_list($contacts)
            || !is_array($categories) || !array_is_list($categories)
            || !is_array($liquidAccounts) || !array_is_list($liquidAccounts)
            || count($contacts) + count($categories) + count($liquidAccounts)
                + ($company === null ? 0 : 1) < 1
            || count($contacts) + count($categories) + count($liquidAccounts)
                + ($company === null ? 0 : 1) > self::MAX_RECORDS) {
            throw new DomainException('invalid_import_batch');
        }

        $normalizedContacts = [];
        $normalizedCategories = [];
        $normalizedAccounts = [];
        $seen = [];
        $bankCodes = [];
        foreach ($liquidAccounts as $row) {
            if (!is_array($row) || array_is_list($row)
                || !self::onlyKeys($row, ['sourceId', 'code', 'name', 'kind',
                    'currency', 'openingBalanceMinor', 'currentBalanceMinor',
                    'balanceAsOf', 'bankName', 'number', 'enabled'])
                || !self::identifier($row['sourceId'] ?? null, 80)
                || !is_string($row['code'] ?? null)
                || !is_string($row['name'] ?? null)
                || !in_array($row['kind'] ?? null, ['bank', 'cash'], true)
                || !is_string($row['currency'] ?? null)
                || !is_bool($row['enabled'] ?? null)
                || !is_int($row['openingBalanceMinor'] ?? null)
                || !is_int($row['currentBalanceMinor'] ?? null)
                || !is_string($row['balanceAsOf'] ?? null)) {
                throw new DomainException('invalid_import_account');
            }
            $code = strtoupper(trim($row['code']));
            $name = trim($row['name']);
            $currency = strtoupper(trim($row['currency']));
            $asOf = $row['balanceAsOf'];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
            if (!preg_match('/^[A-Z0-9_-]{1,32}$/D', $code)
                || $name === '' || mb_strlen($name) > 150
                || !preg_match('/^[A-Z]{3}$/D', $currency)
                || abs($row['openingBalanceMinor']) > 9000000000000
                || abs($row['currentBalanceMinor']) > 9000000000000
                || $date === false || $date->format('Y-m-d') !== $asOf) {
                throw new DomainException('invalid_import_account');
            }
            $bankName = self::nullableText($row['bankName'] ?? null, 150);
            $number = self::nullableText($row['number'] ?? null, 80);
            if ($number !== null && !preg_match('/^[A-Za-z0-9 ._\/-]{1,80}$/D', $number)) {
                throw new DomainException('invalid_import_account');
            }
            if (isset($seen['account'][$row['sourceId']]) || isset($bankCodes[$code])) {
                throw new DomainException('duplicate_import_account');
            }
            $seen['account'][$row['sourceId']] = true;
            $bankCodes[$code] = true;
            $normalizedAccounts[] = [
                'sourceId' => $row['sourceId'], 'code' => $code,
                'name' => $name, 'kind' => $row['kind'], 'currency' => $currency,
                'openingBalanceMinor' => $row['openingBalanceMinor'],
                'currentBalanceMinor' => $row['currentBalanceMinor'],
                'balanceAsOf' => $asOf, 'bankName' => $bankName,
                'number' => $number, 'enabled' => $row['enabled'],
            ];
        }
        $codes = [];
        foreach ($contacts as $row) {
            if (!is_array($row) || array_is_list($row)
                || !self::onlyKeys($row, ['sourceId', 'name', 'kind', 'email', 'taxId'])
                || !self::identifier($row['sourceId'] ?? null, 80)
                || !is_string($row['name'] ?? null)
                || trim($row['name']) === '' || mb_strlen(trim($row['name'])) > 150
                || !in_array($row['kind'] ?? null, ['customer', 'vendor', 'both'], true)) {
                throw new DomainException('invalid_import_contact');
            }
            $id = $row['sourceId'];
            if (isset($seen['contact'][$id])) {
                throw new DomainException('duplicate_import_source_id');
            }
            $seen['contact'][$id] = true;
            $email = self::nullableText($row['email'] ?? null, 190);
            $taxId = self::nullableText($row['taxId'] ?? null, 40);
            if (($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false)
                || ($taxId !== null && !preg_match('~^[A-Za-z0-9./-]+$~', $taxId))) {
                throw new DomainException('invalid_import_contact');
            }
            $normalizedContacts[] = [
                'sourceId' => $id, 'name' => trim($row['name']),
                'kind' => $row['kind'], 'email' => $email, 'taxId' => $taxId,
            ];
        }
        foreach ($categories as $row) {
            if (!is_array($row) || array_is_list($row)
                || !self::onlyKeys($row, ['sourceId', 'code', 'name', 'kind',
                    'parentSourceId', 'accountCode'])
                || !self::identifier($row['sourceId'] ?? null, 80)
                || !is_string($row['code'] ?? null)
                || !is_string($row['name'] ?? null)
                || !in_array($row['kind'] ?? null, ['income', 'expense'], true)) {
                throw new DomainException('invalid_import_category');
            }
            $sourceId = $row['sourceId'];
            $code = strtoupper(trim($row['code']));
            $name = trim($row['name']);
            if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $code)
                || $name === '' || mb_strlen($name) > 150) {
                throw new DomainException('invalid_import_category');
            }
            $parent = self::nullableIdentifier($row['parentSourceId'] ?? null, 80);
            $accountCode = $row['accountCode'] ?? null;
            if ($accountCode !== null) {
                if (!is_string($accountCode)) {
                    throw new DomainException('invalid_import_category');
                }
                $accountCode = strtoupper(trim($accountCode));
                if (!preg_match('/^[A-Z0-9._-]{1,32}$/', $accountCode)) {
                    throw new DomainException('invalid_import_category');
                }
            }
            if ($parent === $sourceId || isset($seen['category'][$sourceId])
                || isset($codes[$code])) {
                throw new DomainException('duplicate_import_category');
            }
            $seen['category'][$sourceId] = true;
            $codes[$code] = $sourceId;
            $normalizedCategories[] = [
                'sourceId' => $sourceId, 'code' => $code, 'name' => $name,
                'kind' => $row['kind'], 'parentSourceId' => $parent,
                'accountCode' => $accountCode,
            ];
        }
        $batch = [
            'source' => ['system' => $source['system'], 'companyId' => $source['companyId']],
            'contacts' => $normalizedContacts, 'categories' => $normalizedCategories,
        ];
        // Keep existing 0.1.0 batch digests unchanged for legacy payloads.
        if ($company !== null) {
            $batch['company'] = $company;
        }
        if (array_key_exists('accounts', $data)) {
            $batch['accounts'] = $normalizedAccounts;
        }
        $batch['digest'] = hash('sha256', json_encode($batch,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $batch;
    }

    /** @return array<string,array<string,array<string,mixed>>> */
    private function existing(int $spaceId, array $batch): array {
        $result = ['contact' => [], 'category' => [], 'company' => [], 'account' => []];
        if (($batch['company'] ?? null) !== null) {
            $q = $this->db->getQueryBuilder();
            $q->select('source_record_id', 'target_id', 'payload_hash')->from('lfm_records')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('source_system', $q->createNamedParameter($batch['source']['system'])))
                ->andWhere($q->expr()->eq('source_company_id', $q->createNamedParameter($batch['source']['companyId'])))
                ->andWhere($q->expr()->eq('record_kind', $q->createNamedParameter('company')))
                ->andWhere($q->expr()->eq('source_record_id', $q->createNamedParameter($batch['company']['sourceId'])));
            foreach ($q->executeQuery()->fetchAllAssociative() as $record) {
                $result['company'][$record['source_record_id']] = $record;
            }
        }
        foreach (['contact' => 'contacts', 'category' => 'categories',
            'account' => 'accounts'] as $kind => $field) {
            $ids = array_column($batch[$field] ?? [], 'sourceId');
            if ($kind === 'category') {
                $ids = array_merge($ids, array_filter(array_column(
                    $batch['categories'], 'parentSourceId'), static fn($v) => $v !== null));
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                continue;
            }
            $q = $this->db->getQueryBuilder();
            $q->select('source_record_id', 'target_id', 'payload_hash')->from('lfm_records')
                ->where($q->expr()->eq('space_id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('source_system',
                    $q->createNamedParameter($batch['source']['system'])))
                ->andWhere($q->expr()->eq('source_company_id',
                    $q->createNamedParameter($batch['source']['companyId'])))
                ->andWhere($q->expr()->eq('record_kind', $q->createNamedParameter($kind)))
                ->andWhere($q->expr()->in('source_record_id',
                    $q->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
            foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
                $result[$kind][$row['source_record_id']] = $row;
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function inspect(int $spaceId, array $batch, array $existing): array {
        $issues = [];
        $records = [];
        $counts = ['new' => 0, 'unchanged' => 0, 'conflict' => 0];
        if (($batch['company'] ?? null) !== null) {
            $row = $batch['company'];
            $prior = $existing['company'][$row['sourceId']] ?? null;
            $status = $prior === null ? 'new'
                : ($prior['payload_hash'] === self::rowHash($row) ? 'unchanged' : 'conflict');
            if ($status === 'new' && $row['taxId'] !== null
                && $this->finance->importCompanyTaxIdInUse(
                    $spaceId, self::MODULE, $row['country'], $row['taxId'])) {
                $status = 'conflict';
                $issues[] = ['kind' => 'company', 'sourceId' => $row['sourceId'],
                    'reason' => 'company_tax_id_in_use'];
            }
            if ($status === 'conflict' && $prior !== null) {
                $issues[] = ['kind' => 'company', 'sourceId' => $row['sourceId'],
                    'reason' => 'source_record_changed'];
            }
            ++$counts[$status];
            $records[] = ['kind' => 'company', 'sourceId' => $row['sourceId'], 'status' => $status];
        }
        $incomingBankAccounts = $batch['accounts'] ?? [];
        $existingBankCodes = $this->finance->importLiquidAccountCodes($spaceId,
            self::MODULE, array_column($incomingBankAccounts, 'code'));
        $spaceCurrency = $incomingBankAccounts === [] ? null
            : $this->finance->currency($spaceId, self::MODULE);
        $mappedAccountIds = array_values(array_map(static fn(array $v): int =>
            (int)$v['target_id'], $existing['account']));
        $snapshots = [];
        if ($mappedAccountIds !== []) {
            $snapshotQ = $this->db->getQueryBuilder();
            $snapshotQ->select('target_account_id')->from('lfm_liquid_origins')
                ->where($snapshotQ->expr()->eq('space_id',
                    $snapshotQ->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($snapshotQ->expr()->in('target_account_id',
                    $snapshotQ->createNamedParameter($mappedAccountIds,
                        IQueryBuilder::PARAM_INT_ARRAY)));
            foreach ($snapshotQ->executeQuery()->fetchAllAssociative() as $s) {
                $snapshots[(int)$s['target_account_id']] = true;
            }
        }
        foreach ($incomingBankAccounts as $row) {
            $prior = $existing['account'][$row['sourceId']] ?? null;
            $status = $prior === null ? 'new'
                : ($prior['payload_hash'] === self::rowHash($row)
                    ? 'unchanged' : 'conflict');
            if ($status === 'new' && isset($existingBankCodes[$row['code']])) {
                $status = 'conflict';
                $issues[] = ['kind' => 'account', 'sourceId' => $row['sourceId'],
                    'reason' => 'account_code_in_use'];
            }
            if ($row['currency'] !== $spaceCurrency) {
                $status = 'conflict';
                $issues[] = ['kind' => 'account', 'sourceId' => $row['sourceId'],
                    'reason' => 'currency_mismatch'];
            }
            if ($prior !== null && $prior['payload_hash'] !== self::rowHash($row)) {
                $issues[] = ['kind' => 'account', 'sourceId' => $row['sourceId'],
                    'reason' => 'source_record_changed'];
            }
            if ($prior !== null && (
                ($existingBankCodes[$row['code']] ?? null) !== (int)$prior['target_id']
                || !isset($snapshots[(int)$prior['target_id']])
            )) {
                $status = 'conflict';
                $issues[] = ['kind' => 'account', 'sourceId' => $row['sourceId'],
                    'reason' => 'source_target_mapping_damaged'];
            }
            ++$counts[$status];
            $records[] = ['kind' => 'account', 'sourceId' => $row['sourceId'],
                'status' => $status];
        }

        $codeMap = [];
        foreach ($batch['categories'] as $category) {
            $codeMap[$category['sourceId']] = $category;
        }

        $allAccountCodes = array_values(array_unique(array_filter(array_column(
            $batch['categories'], 'accountCode'), static fn($v) => $v !== null)));
        $accounts = $this->finance->importAccountCodes($spaceId, self::MODULE, $allAccountCodes);
        $targetCategoryCodes = $this->finance->importCategoryCodes($spaceId, self::MODULE,
            array_column($batch['categories'], 'code'));
        foreach (['contact' => 'contacts', 'category' => 'categories'] as $kind => $field) {
            foreach ($batch[$field] as $row) {
                $sourceId = $row['sourceId'];
                $hash = self::rowHash($row);
                $prior = $existing[$kind][$sourceId] ?? null;
                $status = $prior === null ? 'new'
                    : ($prior['payload_hash'] === $hash ? 'unchanged' : 'conflict');
                if ($status === 'conflict') {
                    $issues[] = ['kind' => $kind, 'sourceId' => $sourceId,
                        'reason' => 'source_record_changed'];
                }
                if ($kind === 'category') {
                    if ($status === 'new' && isset($targetCategoryCodes[$row['code']])) {
                        $status = 'conflict';
                        $issues[] = ['kind' => $kind, 'sourceId' => $sourceId,
                            'reason' => 'category_code_in_use'];
                    }
                    $parent = $row['parentSourceId'];
                    if ($parent !== null && !isset($codeMap[$parent])
                        && !isset($existing['category'][$parent])) {
                        $status = 'conflict';
                        $issues[] = ['kind' => $kind, 'sourceId' => $sourceId,
                            'reason' => 'parent_not_imported'];
                    }
                    if ($parent !== null && isset($codeMap[$parent])
                        && $codeMap[$parent]['kind'] !== $row['kind']) {
                        $status = 'conflict';
                        $issues[] = ['kind' => $kind, 'sourceId' => $sourceId,
                            'reason' => 'incompatible_parent_kind'];
                    }
                    $accountCode = $row['accountCode'];
                    if ($accountCode !== null) {
                        $account = $accounts[$accountCode] ?? null;
                        if ($account === null || $account['class'] !== (
                            $row['kind'] === 'income' ? 'revenue' : 'expense')) {
                            $status = 'conflict';
                            $issues[] = ['kind' => $kind, 'sourceId' => $sourceId,
                                'reason' => 'account_not_found_or_incompatible'];
                        }
                    }
                }
                ++$counts[$status];
                $records[] = ['kind' => $kind, 'sourceId' => $sourceId, 'status' => $status];
            }
        }

        // Full parent cycle detection, regardless of original category order.
        foreach ($codeMap as $category) {
            $walked = [];
            $cursor = $category['sourceId'];
            for ($step = 0; $cursor !== null && $step <= self::MAX_RECORDS; ++$step) {
                if (isset($walked[$cursor])) {
                    $issues[] = ['kind' => 'category', 'sourceId' => $category['sourceId'],
                        'reason' => 'parent_cycle'];
                    break;
                }
                $walked[$cursor] = true;
                $cursor = $codeMap[$cursor]['parentSourceId'] ?? null;
            }
        }
        return [
            'source' => $batch['source'],
            'previewHash' => $batch['digest'],
            'total' => count($records),
            'counts' => $counts, 'records' => $records, 'issues' => $issues,
            'ready' => $issues === [],
            // Internal-only field; removed by the controller before returning.
            '_account_map' => $accounts,
        ];
    }

    private function record(int $spaceId, array $batch, string $kind,
        array $row, int $targetId): void {
        $this->insert('lfm_records', [
            'space_id' => $spaceId,
            'source_system' => $batch['source']['system'],
            'source_company_id' => $batch['source']['companyId'],
            'record_kind' => $kind,
            'source_record_id' => $row['sourceId'],
            'target_id' => $targetId,
            'payload_hash' => self::rowHash($row),
            'created_by' => $this->actor(),
            'created_at' => time(),
        ]);
    }

    /** @param array<string,int|string|null> $values */
    private function insert(string $table, array $values): void {
        $q = $this->db->getQueryBuilder();
        $params = [];
        foreach ($values as $name => $value) {
            $params[$name] = $q->createNamedParameter($value,
                is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
        }
        $q->insert($table)->values($params)->executeStatement();
    }

    private function actor(): string {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new DomainException('authentication_required');
        }
        return $uid;
    }

    private static function rowHash(array $row): string {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function identifier(mixed $value, int $limit): bool {
        return is_string($value) && strlen($value) <= $limit
            && preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1;
    }

    private static function nullableIdentifier(mixed $value, int $limit): ?string {
        if ($value === null) {
            return null;
        }
        if (!self::identifier($value, $limit)) {
            throw new DomainException('invalid_import_category');
        }
        return $value;
    }

    private static function nullableText(mixed $value, int $limit): ?string {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new DomainException('invalid_import_contact');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            throw new DomainException('invalid_import_contact');
        }
        return $value;
    }

    private static function onlyKeys(array $object, array $allowed): bool {
        return array_diff(array_keys($object), $allowed) === [];
    }
}
