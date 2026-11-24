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
use OCA\LibreFinance\PublicAPI\BankImportApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Server;

/**
 * A normalized and explicitly approved movement import. Entries enter the
 * banking module PENDING reconciliation, not the authoritative ledger.
 * Transfers need a separate two-account contract and are intentionally denied.
 */
final class MovementImportService {
    private const MODULE = 'librefinance_import';
    private const BANKING = 'librefinance_banking';
    private const FORMAT = 'librefinance.transactions.v1';
    private const MAX_BYTES = 131072;
    private const MAX_RECORDS = 100;

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
    ) {}

    private function banking(): BankImportApi {
        // Never make the banking extension a mandatory Nextcloud dependency
        // for the existing company/contact/category migration features.
        if (!class_exists(BankImportApi::class)) {
            throw new DomainException('module_not_found');
        }
        return Server::get(BankImportApi::class);
    }

    /** @return array<string,mixed> */
    public function preview(int $spaceId, string $exportJson): array {
        $this->requireModules($spaceId);
        $batch = $this->parse($exportJson);
        $out = $this->inspect($spaceId, $batch);
        unset($out['_newEntries'], $out['_newRows']);
        return $out;
    }

    /** @return array<string,mixed> */
    public function apply(int $spaceId, string $exportJson,
        string $previewHash, bool $confirmed): array {
        $this->requireModules($spaceId);
        if (!$confirmed) {
            throw new DomainException('import_confirmation_required');
        }
        $batch = $this->parse($exportJson);
        if (!preg_match('/^[a-f0-9]{64}$/D', $previewHash)
            || !hash_equals($batch['digest'], $previewHash)) {
            throw new DomainException('import_preview_changed');
        }
        $this->db->beginTransaction();
        try {
            $result = $this->inspect($spaceId, $batch);
            if (!$result['ready']) {
                throw new DomainException('import_conflict');
            }
            $created = 0;
            $new = $result['_newEntries'];
            if ($new !== []) {
                $ids = $this->banking()->appendPending($spaceId, $new);
                foreach ($result['_newRows'] as $entry) {
                    $targetId = $ids[$entry['externalId']] ?? null;
                    if (!is_int($targetId) || $targetId < 1) {
                        throw new DomainException('import_bank_target_missing');
                    }
                    $this->insert('lfm_records', [
                        'space_id' => $spaceId,
                        'source_system' => $batch['source']['system'],
                        'source_company_id' => $batch['source']['companyId'],
                        'record_kind' => 'transaction',
                        'source_record_id' => $entry['sourceId'],
                        'target_id' => $targetId,
                        'payload_hash' => self::rowHash($entry['row']),
                        'created_by' => $this->uid(),
                        'created_at' => time(),
                    ]);
                    ++$created;
                }
            }
            $skipped = count($batch['transactions']) - $created;
            $this->insert('lfm_audit', [
                'space_id' => $spaceId,
                'source_system' => $batch['source']['system'],
                'source_company_id' => $batch['source']['companyId'],
                'batch_hash' => $batch['digest'],
                'created_count' => $created,
                'skipped_count' => $skipped,
                'actor_uid' => $this->uid(),
                'occurred_at' => time(),
            ]);
            $this->db->commit();
            return [
                'created' => $created, 'skipped' => $skipped,
                'total' => count($batch['transactions']),
                'source' => $batch['source'],
                'bankEntriesPending' => true,
                'ledgerEntriesPosted' => 0,
                'transfersImported' => 0,
                'sourceInventoryVerified' => false,
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

    /** @return array<string,mixed> */
    public function coverage(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic'): array {
        $this->requireModules($spaceId);
        self::requireSource($sourceSystem, $sourceCompanyId);
        $q = $this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(m.id)'), 'mapped')
            ->selectAlias($q->createFunction('COUNT(t.id)'), 'present')
            ->from('lfm_records', 'm')
            ->leftJoin('m', 'lfb_transactions', 't',
                't.id = m.target_id AND t.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($sourceSystem)))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($sourceCompanyId)))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('transaction')));
        $counts = $q->executeQuery()->fetchAssociative();
        $status = [];
        $q = $this->db->getQueryBuilder();
        $q->select('t.status')
            ->selectAlias($q->createFunction('COUNT(t.id)'), 'total')
            ->from('lfm_records', 'm')
            ->innerJoin('m', 'lfb_transactions', 't',
                't.id = m.target_id AND t.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($sourceSystem)))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($sourceCompanyId)))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('transaction')))
            ->groupBy('t.status');
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $status[(string)$row['status']] = (int)$row['total'];
        }
        $mapped = (int)($counts['mapped'] ?? 0);
        $present = (int)($counts['present'] ?? 0);
        return [
            'sourceSystem' => $sourceSystem, 'sourceCompanyId' => $sourceCompanyId,
            'mapped' => $mapped, 'targetPresent' => $present,
            'missingTarget' => $mapped - $present,
            'pending' => $status['pending'] ?? 0,
            'matched' => $status['matched'] ?? 0,
            'sourceInventoryVerified' => false,
            'balanceReconciled' => false,
            'completeSourceMigration' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function inspect(int $spaceId, array $batch): array {
        $source = $batch['source'];
        $accountSourceIds = array_values(array_unique(array_column(
            $batch['transactions'], 'accountSourceId')));
        $accountMappings = $this->lookup($spaceId, $source,
            'account', $accountSourceIds, true);
        $prior = $this->lookup($spaceId, $source, 'transaction',
            array_column($batch['transactions'], 'sourceId'));
        $issues = [];
        $records = [];
        $counts = ['new' => 0, 'unchanged' => 0, 'conflict' => 0];
        $entries = [];
        $entryRows = [];
        foreach ($batch['transactions'] as $row) {
            $status = 'new';
            $reason = null;
            $mapping = $accountMappings[$row['accountSourceId']] ?? null;
            $existing = $prior[$row['sourceId']] ?? null;
            if ($mapping === null || $mapping['snapshot_id'] === null
                || $mapping['code'] === null) {
                $reason = 'source_bank_account_not_imported';
            } elseif (!(bool)$mapping['is_bank'] || !(bool)$mapping['active']) {
                $reason = 'source_bank_account_inactive';
            } elseif ($mapping['currency'] !== $row['currency']) {
                $reason = 'currency_mismatch';
            } elseif ($existing !== null
                && !hash_equals((string)$existing['payload_hash'], self::rowHash($row))) {
                $reason = 'source_record_changed';
            }
            if ($reason !== null) {
                $status = 'conflict';
                $issues[] = ['sourceId' => $row['sourceId'], 'reason' => $reason];
            } else {
                $externalId = self::externalId($source, $row['sourceId']);
                $entry = [
                    'externalId' => $externalId,
                    'accountId' => (int)$mapping['target_id'],
                    'bookingDate' => $row['bookingDate'],
                    'amountMinor' => $row['type'] === 'expense'
                        ? -$row['amountMinor'] : $row['amountMinor'],
                    'description' => $row['description'],
                    'currency' => $row['currency'],
                ];
                $entries[] = $entry;
                $entryRows[$externalId] = ['externalId' => $externalId,
                    'sourceId' => $row['sourceId'], 'row' => $row,
                    'prior' => $existing];
            }
            $records[$row['sourceId']] = ['sourceId' => $row['sourceId'],
                'accountSourceId' => $row['accountSourceId'],
                'status' => $status];
        }
        $found = $entries === [] ? [] : $this->banking()->inspect($spaceId, $entries);
        $newEntries = [];
        $newRows = [];
        foreach ($entries as $entry) {
            $info = $entryRows[$entry['externalId']];
            $existing = $info['prior'];
            $target = $found[$entry['externalId']] ?? null;
            $reason = null;
            if ($existing === null && $target !== null) {
                $reason = 'bank_entry_origin_collision';
            } elseif ($existing !== null && ($target === null
                || (int)$existing['target_id'] !== $target['id'])) {
                $reason = 'mapped_bank_entry_missing';
            } elseif ($target !== null && (
                $target['accountId'] !== $entry['accountId']
                || $target['bookingDate'] !== $entry['bookingDate']
                || $target['amountMinor'] !== $entry['amountMinor']
                || $target['description'] !== $entry['description'])) {
                $reason = 'bank_entry_changed';
            }
            if ($reason !== null) {
                $issues[] = ['sourceId' => $info['sourceId'], 'reason' => $reason];
                $records[$info['sourceId']]['status'] = 'conflict';
            } elseif ($existing !== null) {
                $records[$info['sourceId']]['status'] = 'unchanged';
            } else {
                $newEntries[] = $entry;
                $newRows[] = $info;
            }
        }
        foreach ($records as $record) {
            ++$counts[$record['status']];
        }
        return [
            'source' => $source, 'previewHash' => $batch['digest'],
            'total' => count($batch['transactions']), 'counts' => $counts,
            'records' => array_values($records), 'issues' => $issues,
            'ready' => $issues === [],
            'ledgerEntriesPosted' => 0, 'transfersImported' => 0,
            '_newEntries' => $newEntries, '_newRows' => $newRows,
        ];
    }

    /** @return array<string,mixed> */
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
            || !self::onlyKeys($data, ['format', 'source', 'transactions'])
            || !is_array($data['source'] ?? null)
            || !self::onlyKeys($data['source'], ['system', 'companyId'])
            || !is_array($data['transactions'] ?? null)
            || !array_is_list($data['transactions'])
            || count($data['transactions']) < 1
            || count($data['transactions']) > self::MAX_RECORDS) {
            throw new DomainException('invalid_import_transactions');
        }
        $source = $data['source'];
        self::requireSource($source['system'] ?? null, $source['companyId'] ?? null);
        $ids = [];
        $transactions = [];
        foreach ($data['transactions'] as $r) {
            if (!is_array($r) || array_is_list($r)
                || !self::onlyKeys($r, ['sourceId', 'accountSourceId', 'type',
                    'bookingDate', 'amountMinor', 'currency', 'description'])
                || !self::identifier($r['sourceId'] ?? null, 80)
                || !self::identifier($r['accountSourceId'] ?? null, 80)
                || !in_array($r['type'] ?? null, ['income', 'expense'], true)
                || !is_string($r['bookingDate'] ?? null)
                || !is_int($r['amountMinor'] ?? null)
                || $r['amountMinor'] < 1 || $r['amountMinor'] > 9000000000000
                || !is_string($r['currency'] ?? null)
                || preg_match('/^[A-Z]{3}$/D', $r['currency']) !== 1
                || !is_string($r['description'] ?? null)
                || trim($r['description']) === ''
                || mb_strlen(trim($r['description'])) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $r['description']) === 1) {
                throw new DomainException('invalid_import_transaction');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $r['bookingDate']);
            if ($date === false || $date->format('Y-m-d') !== $r['bookingDate']
                || isset($ids[$r['sourceId']])) {
                throw new DomainException('invalid_import_transaction');
            }
            $ids[$r['sourceId']] = true;
            $transactions[] = [
                'sourceId' => $r['sourceId'],
                'accountSourceId' => $r['accountSourceId'],
                'type' => $r['type'],
                'bookingDate' => $r['bookingDate'],
                'amountMinor' => $r['amountMinor'],
                'currency' => $r['currency'],
                'description' => trim($r['description']),
            ];
        }
        $batch = ['source' => ['system' => $source['system'],
            'companyId' => $source['companyId']], 'transactions' => $transactions];
        $batch['digest'] = hash('sha256', json_encode($batch,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $batch;
    }

    /**
     * @param array{system:string,companyId:string} $source
     * @param list<string> $sourceIds
     * @return array<string,array<string,mixed>>
     */
    private function lookup(int $spaceId, array $source, string $kind,
        array $sourceIds, bool $account = false): array {
        if ($sourceIds === []) { return []; }
        $q = $this->db->getQueryBuilder();
        $q->select('m.source_record_id', 'm.target_id', 'm.payload_hash')
            ->from('lfm_records', 'm')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($source['system'])))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($source['companyId'])))
            ->andWhere($q->expr()->eq('m.record_kind', $q->createNamedParameter($kind)))
            ->andWhere($q->expr()->in('m.source_record_id',
                $q->createNamedParameter($sourceIds, IQueryBuilder::PARAM_STR_ARRAY)));
        if ($account) {
            $q->addSelect('a.code', 'a.currency', 'a.is_bank', 'a.active')
                ->addSelect('s.id AS snapshot_id')
                ->leftJoin('m', 'lf_accounts', 'a',
                    'a.id = m.target_id AND a.space_id = m.space_id')
                ->leftJoin('m', 'lfm_liquid_origins', 's',
                    's.target_account_id = m.target_id AND s.space_id = m.space_id');
        }
        $rows = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $rows[(string)$row['source_record_id']] = $row;
        }
        return $rows;
    }

    private function requireModules(int $spaceId): void {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'imports.apply');
        $this->finance->requireModuleCapability($spaceId, self::BANKING, 'bank.manage');
    }

    private static function identifier(mixed $value, int $max): bool {
        return is_string($value) && $value !== '' && strlen($value) <= $max
            && preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) === 1;
    }

    private static function requireSource(mixed $system, mixed $company): void {
        if (!SourceSystem::valid($system)
            || !self::identifier($company, 64)) {
            throw new DomainException('invalid_import_source');
        }
    }

    private static function onlyKeys(array $data, array $keys): bool {
        return array_diff(array_keys($data), $keys) === [];
    }

    private static function rowHash(array $data): string {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function externalId(array $source, string $sourceId): string {
        return 'lfm:' . hash('sha256', json_encode([$source['system'],
            $source['companyId'], $sourceId], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,int|string|null> $values */
    private function insert(string $table, array $values): void {
        $q = $this->db->getQueryBuilder();
        $params = [];
        foreach ($values as $key => $value) {
            $params[$key] = $q->createNamedParameter($value,
                is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
        }
        $q->insert($table)->values($params)->executeStatement();
    }

    private function uid(): string {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new DomainException('authentication_required');
        }
        return $uid;
    }
}
