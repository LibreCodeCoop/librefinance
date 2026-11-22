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
use OCA\LibreFinance\PublicAPI\BankTransferApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Server;

/**
 * Historical bank-to-bank transfer staging, without duplicate statement legs.
 *
 * A source transfer cannot be represented as revenue or expenditure. These
 * records remain pending until each source statement leg and corresponding
 * accounting journal has been independently matched and validated.
 */
final class TransferImportService {
    private const MODULE = 'librefinance_import';
    private const BANKING = 'librefinance_banking';
    private const FORMAT = 'librefinance.transfers.v1';
    private const MAX_BYTES = 131072;
    private const MAX_RECORDS = 50;

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
    ) {}

    private function banking(): BankTransferApi {
        if (!class_exists(BankTransferApi::class)) {
            throw new DomainException('module_not_found');
        }
        return Server::get(BankTransferApi::class);
    }

    /** @return array<string,mixed> */
    public function preview(int $spaceId, string $exportJson): array {
        $this->requireModules($spaceId);
        $batch = $this->parse($exportJson);
        $result = $this->inspect($spaceId, $batch);
        unset($result['_newRows'], $result['_newTransfers']);
        return $result;
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
            $review = $this->inspect($spaceId, $batch);
            if (!$review['ready']) {
                throw new DomainException('import_conflict');
            }
            $created = 0;
            if ($review['_newTransfers'] !== []) {
                $targets = $this->banking()->appendPending(
                    $spaceId, $review['_newTransfers']);
                foreach ($review['_newRows'] as $entry) {
                    $targetId = $targets[$entry['externalId']] ?? null;
                    if (!is_int($targetId) || $targetId <= 0) {
                        throw new DomainException('transfer_target_missing');
                    }
                    $this->insert('lfm_records', [
                        'space_id' => $spaceId,
                        'source_system' => $batch['source']['system'],
                        'source_company_id' => $batch['source']['companyId'],
                        'record_kind' => 'transfer',
                        'source_record_id' => $entry['sourceId'],
                        'target_id' => $targetId,
                        'payload_hash' => self::rowHash($entry['row']),
                        'created_by' => $this->actor(),
                        'created_at' => time(),
                    ]);
                    ++$created;
                }
            }
            $skipped = count($batch['transfers']) - $created;
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
                'total' => count($batch['transfers']),
                'source' => $batch['source'],
                'transfersPendingSourceMatch' => true,
                'statementLegsCreated' => 0,
                'ledgerEntriesPosted' => 0,
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
        self::source($sourceSystem, $sourceCompanyId);
        $q = $this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(m.id)'), 'mapped')
            ->selectAlias($q->createFunction('COUNT(t.id)'), 'present')
            ->from('lfm_records', 'm')
            ->leftJoin('m', 'lfb_transfers', 't',
                't.id = m.target_id AND t.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($sourceSystem)))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($sourceCompanyId)))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('transfer')));
        $counts = $q->executeQuery()->fetchAssociative();
        $q = $this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(t.id)'), 'pending')
            ->from('lfm_records', 'm')
            ->innerJoin('m', 'lfb_transfers', 't',
                't.id = m.target_id AND t.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($sourceSystem)))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($sourceCompanyId)))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('transfer')))
            ->andWhere($q->expr()->eq('t.status',
                $q->createNamedParameter('pending_source_match')));
        $pending = (int)$q->executeQuery()->fetchOne();
        $mapped = (int)($counts['mapped'] ?? 0);
        $present = (int)($counts['present'] ?? 0);
        return [
            'sourceSystem' => $sourceSystem, 'sourceCompanyId' => $sourceCompanyId,
            'mapped' => $mapped, 'targetPresent' => $present,
            'missingTarget' => $mapped - $present,
            'pendingSourceMatch' => $pending,
            'statementLegsCreated' => 0,
            'ledgerEntriesPosted' => 0,
            'sourceInventoryVerified' => false,
            'transferLegsReconciled' => false,
            'completeSourceMigration' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function inspect(int $spaceId, array $batch): array {
        $source = $batch['source'];
        $accountIds = [];
        foreach ($batch['transfers'] as $row) {
            $accountIds[$row['fromAccountSourceId']] = true;
            $accountIds[$row['toAccountSourceId']] = true;
        }
        $accounts = $this->sourceAccounts($spaceId, $source, array_keys($accountIds));
        $prior = $this->prior($spaceId, $source,
            array_column($batch['transfers'], 'sourceId'));
        $records = [];
        $issues = [];
        $proposed = [];
        $byExternal = [];
        foreach ($batch['transfers'] as $row) {
            $reason = null;
            $from = $accounts[$row['fromAccountSourceId']] ?? null;
            $to = $accounts[$row['toAccountSourceId']] ?? null;
            $existing = $prior[$row['sourceId']] ?? null;
            if ($from === null || $to === null
                || $from['source_snapshot_id'] === null
                || $to['source_snapshot_id'] === null
                || $from['code'] === null || $to['code'] === null) {
                $reason = 'source_transfer_account_not_imported';
            } elseif (!(bool)$from['active'] || !(bool)$to['active']
                || !(bool)$from['is_bank'] || !(bool)$to['is_bank']) {
                $reason = 'source_transfer_account_inactive';
            } elseif ($from['currency'] !== $row['currency']
                || $to['currency'] !== $row['currency']) {
                $reason = 'transfer_currency_mismatch';
            } elseif ((int)$from['target_id'] === (int)$to['target_id']) {
                $reason = 'source_transfer_same_target_account';
            } elseif ($existing !== null
                && !hash_equals((string)$existing['payload_hash'], self::rowHash($row))) {
                $reason = 'source_record_changed';
            }
            if ($reason !== null) {
                $issues[] = ['sourceId' => $row['sourceId'], 'reason' => $reason];
                $records[$row['sourceId']] = [
                    'sourceId' => $row['sourceId'], 'status' => 'conflict',
                ];
                continue;
            }
            $externalId = self::externalId($source, $row['sourceId']);
            $target = [
                'externalId' => $externalId,
                'fromAccountId' => (int)$from['target_id'],
                'toAccountId' => (int)$to['target_id'],
                'bookingDate' => $row['bookingDate'],
                'amountMinor' => $row['amountMinor'],
                'currency' => $row['currency'],
                'description' => $row['description'],
            ];
            $proposed[] = $target;
            $byExternal[$externalId] = ['sourceId' => $row['sourceId'],
                'row' => $row, 'prior' => $existing, 'target' => $target];
            $records[$row['sourceId']] = ['sourceId' => $row['sourceId'],
                'fromAccountSourceId' => $row['fromAccountSourceId'],
                'toAccountSourceId' => $row['toAccountSourceId'], 'status' => 'new'];
        }
        $found = $proposed === [] ? [] : $this->banking()->inspect($spaceId, $proposed);
        $newRows = [];
        $newTransfers = [];
        foreach ($proposed as $row) {
            $info = $byExternal[$row['externalId']];
            $existing = $info['prior'];
            $target = $found[$row['externalId']] ?? null;
            $reason = null;
            if ($existing === null && $target !== null) {
                $reason = 'bank_transfer_origin_collision';
            } elseif ($existing !== null && ($target === null
                || (int)$existing['target_id'] !== $target['id'])) {
                $reason = 'mapped_bank_transfer_missing';
            } elseif ($target !== null && (
                $target['fromAccountId'] !== $row['fromAccountId']
                || $target['toAccountId'] !== $row['toAccountId']
                || $target['bookingDate'] !== $row['bookingDate']
                || $target['amountMinor'] !== $row['amountMinor']
                || $target['currency'] !== $row['currency']
                || $target['description'] !== $row['description'])) {
                $reason = 'bank_transfer_changed';
            }
            if ($reason !== null) {
                $issues[] = ['sourceId' => $info['sourceId'], 'reason' => $reason];
                $records[$info['sourceId']]['status'] = 'conflict';
            } elseif ($existing !== null) {
                $records[$info['sourceId']]['status'] = 'unchanged';
            } else {
                $newTransfers[] = $row;
                $newRows[] = ['externalId' => $row['externalId'],
                    'sourceId' => $info['sourceId'], 'row' => $info['row']];
            }
        }
        $counts = ['new' => 0, 'unchanged' => 0, 'conflict' => 0];
        foreach ($records as $record) { ++$counts[$record['status']]; }
        return [
            'source' => $source,
            'previewHash' => $batch['digest'],
            'total' => count($batch['transfers']),
            'counts' => $counts,
            'records' => array_values($records),
            'issues' => $issues,
            'ready' => $issues === [],
            'statementLegsCreated' => 0,
            'ledgerEntriesPosted' => 0,
            '_newTransfers' => $newTransfers,
            '_newRows' => $newRows,
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
            || !self::onlyKeys($data, ['format', 'source', 'transfers'])
            || !is_array($data['source'] ?? null)
            || !self::onlyKeys($data['source'], ['system', 'companyId'])
            || !is_array($data['transfers'] ?? null)
            || !array_is_list($data['transfers'])
            || count($data['transfers']) < 1
            || count($data['transfers']) > self::MAX_RECORDS) {
            throw new DomainException('invalid_transfer_batch');
        }
        $source = $data['source'];
        self::source($source['system'] ?? null, $source['companyId'] ?? null);
        $seen = [];
        $rows = [];
        foreach ($data['transfers'] as $row) {
            if (!is_array($row) || array_is_list($row)
                || !self::onlyKeys($row, ['sourceId', 'fromAccountSourceId',
                    'toAccountSourceId', 'bookingDate', 'amountMinor',
                    'currency', 'description'])
                || !self::identifier($row['sourceId'] ?? null, 80)
                || !self::identifier($row['fromAccountSourceId'] ?? null, 80)
                || !self::identifier($row['toAccountSourceId'] ?? null, 80)
                || $row['fromAccountSourceId'] === $row['toAccountSourceId']
                || !is_string($row['bookingDate'] ?? null)
                || !is_int($row['amountMinor'] ?? null)
                || $row['amountMinor'] < 1 || $row['amountMinor'] > 9000000000000
                || !is_string($row['currency'] ?? null)
                || preg_match('/^[A-Z]{3}$/D', $row['currency']) !== 1
                || !is_string($row['description'] ?? null)
                || trim($row['description']) === ''
                || mb_strlen(trim($row['description'])) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $row['description']) === 1) {
                throw new DomainException('invalid_transfer');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['bookingDate']);
            if ($date === false || $date->format('Y-m-d') !== $row['bookingDate']
                || isset($seen[$row['sourceId']])) {
                throw new DomainException('invalid_transfer');
            }
            $seen[$row['sourceId']] = true;
            $rows[] = [
                'sourceId' => $row['sourceId'],
                'fromAccountSourceId' => $row['fromAccountSourceId'],
                'toAccountSourceId' => $row['toAccountSourceId'],
                'bookingDate' => $row['bookingDate'],
                'amountMinor' => $row['amountMinor'],
                'currency' => $row['currency'],
                'description' => trim($row['description']),
            ];
        }
        $batch = ['source' => ['system' => $source['system'],
            'companyId' => $source['companyId']], 'transfers' => $rows];
        $batch['digest'] = hash('sha256', json_encode($batch,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $batch;
    }

    /**
     * @param array{system:string,companyId:string} $source
     * @param list<string> $sourceIds
     * @return array<string,array<string,mixed>>
     */
    private function sourceAccounts(int $spaceId, array $source, array $sourceIds): array {
        if ($sourceIds === []) { return []; }
        $q = $this->db->getQueryBuilder();
        $q->select('m.source_record_id', 'm.target_id',
                'a.code', 'a.currency', 'a.is_bank', 'a.active')
            ->addSelect('s.id AS source_snapshot_id')
            ->from('lfm_records', 'm')
            ->leftJoin('m', 'lf_accounts', 'a',
                'a.id = m.target_id AND a.space_id = m.space_id')
            ->leftJoin('m', 'lfm_liquid_origins', 's',
                's.target_account_id = m.target_id AND s.space_id = m.space_id')
            ->where($q->expr()->eq('m.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.source_system',
                $q->createNamedParameter($source['system'])))
            ->andWhere($q->expr()->eq('m.source_company_id',
                $q->createNamedParameter($source['companyId'])))
            ->andWhere($q->expr()->eq('m.record_kind',
                $q->createNamedParameter('account')))
            ->andWhere($q->expr()->in('m.source_record_id',
                $q->createNamedParameter($sourceIds, IQueryBuilder::PARAM_STR_ARRAY)));
        $mapped = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $mapped[(string)$row['source_record_id']] = $row;
        }
        return $mapped;
    }

    /**
     * @param array{system:string,companyId:string} $source
     * @param list<string> $sourceIds
     * @return array<string,array<string,mixed>>
     */
    private function prior(int $spaceId, array $source, array $sourceIds): array {
        $q = $this->db->getQueryBuilder();
        $q->select('source_record_id', 'target_id', 'payload_hash')
            ->from('lfm_records')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('source_system',
                $q->createNamedParameter($source['system'])))
            ->andWhere($q->expr()->eq('source_company_id',
                $q->createNamedParameter($source['companyId'])))
            ->andWhere($q->expr()->eq('record_kind',
                $q->createNamedParameter('transfer')))
            ->andWhere($q->expr()->in('source_record_id',
                $q->createNamedParameter($sourceIds, IQueryBuilder::PARAM_STR_ARRAY)));
        $found = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $found[(string)$row['source_record_id']] = $row;
        }
        return $found;
    }

    private function requireModules(int $spaceId): void {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        $this->finance->requireModule($spaceId, self::BANKING, 'manager');
    }

    private static function source(mixed $system, mixed $companyId): void {
        if (!SourceSystem::valid($system)
            || !self::identifier($companyId, 64)) {
            throw new DomainException('invalid_import_source');
        }
    }

    private static function identifier(mixed $value, int $max): bool {
        return is_string($value) && $value !== '' && strlen($value) <= $max
            && preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) === 1;
    }

    private static function onlyKeys(array $row, array $fields): bool {
        return array_diff(array_keys($row), $fields) === [];
    }

    private static function externalId(array $source, string $sourceId): string {
        return 'lfmt:' . hash('sha256', json_encode([
            $source['system'], $source['companyId'], $sourceId,
        ], JSON_THROW_ON_ERROR));
    }

    private static function rowHash(array $row): string {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function actor(): string {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new DomainException('authentication_required');
        }
        return $uid;
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
}
