<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Reports;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/** Persist user-named reports bound to known, audited financial engines only. */
final class SavedReportService {
    private const MODULE = 'librefinance_reports';
    private const KINDS = ['profit_loss', 'cash_flow', 'trial_balance'];
    private const BALANCE_COLUMNS = ['code', 'name', 'class', 'balanceMinor'];
    private const CLASSES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    public function __construct(private FinanceApi $finance, private IDBConnection $db,
        private IUserSession $session) {}

    /** @return array<string,mixed> */
    public function list(int $spaceId, int $afterId = 0): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'viewer');
        if ($afterId < 0) { throw new DomainException('invalid_cursor'); }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'name', 'kind', 'from_on', 'through_on', 'columns_json', 'class_filter', 'created_by', 'created_at', 'updated_at')
            ->from('lfr_saved_reports')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('active', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $rows = array_map([self::class, 'cast'], $rows);
        return ['reports' => $rows, 'nextAfterId' => $more ? (int)end($rows)['id'] : null,
            'supportedKinds' => self::KINDS];
    }

    /** @return array<string,mixed> */
    public function create(int $spaceId, string $name, string $kind, ?string $from, string $through,
        array $columns = [], ?string $accountClass = null): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        $fields = self::validate($name, $kind, $from, $through, $columns, $accountClass);
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('lfr_saved_reports')->values([
            'space_id' => $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT),
            'name' => $qb->createNamedParameter($fields['name']),
            'kind' => $qb->createNamedParameter($fields['kind']),
            'from_on' => $qb->createNamedParameter($fields['from']),
            'through_on' => $qb->createNamedParameter($fields['through']),
            'columns_json' => $qb->createNamedParameter(json_encode($fields['columns'], JSON_THROW_ON_ERROR)),
            'class_filter' => $qb->createNamedParameter($fields['accountClass']),
            'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'created_by' => $qb->createNamedParameter($this->uid()),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return ['id' => (int)$this->db->lastInsertId('*PREFIX*lfr_saved_reports'),
            ...$fields, 'created_at' => $now, 'updated_at' => $now];
    }

    /** @return array<string,mixed> */
    public function update(int $spaceId, int $reportId, string $name,
        string $kind, ?string $from, string $through, array $columns = [],
        ?string $accountClass = null): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        $this->requireExists($spaceId, $reportId);
        $fields = self::validate($name, $kind, $from, $through, $columns, $accountClass);
        $now = time();
        $q = $this->db->getQueryBuilder();
        $q->update('lfr_saved_reports')
            ->set('name', $q->createNamedParameter($fields['name']))
            ->set('kind', $q->createNamedParameter($fields['kind']))
            ->set('from_on', $q->createNamedParameter($fields['from']))
            ->set('through_on', $q->createNamedParameter($fields['through']))
            ->set('columns_json', $q->createNamedParameter(json_encode($fields['columns'], JSON_THROW_ON_ERROR)))
            ->set('class_filter', $q->createNamedParameter($fields['accountClass']))
            ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id', $q->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('active', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->executeStatement();
        return ['id' => $reportId, ...$fields, 'updated_at' => $now];
    }

    /** @return array<string,mixed> */
    public function archive(int $spaceId, int $reportId): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        $this->requireExists($spaceId, $reportId);
        $q = $this->db->getQueryBuilder();
        $q->update('lfr_saved_reports')
            ->set('active', $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->set('updated_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id', $q->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        return ['id' => $reportId, 'archived' => true];
    }

    /** Permanently remove only the saved report configuration, never journals. */
    public function deletePermanently(int $spaceId, int $reportId): array {
        $this->finance->requireModule($spaceId, self::MODULE, 'manager');
        if ($reportId < 1) { throw new DomainException('saved_report_not_found'); }
        $q = $this->db->getQueryBuilder();
        $q->delete('lfr_saved_reports')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)));
        if ($q->executeStatement() !== 1) { throw new DomainException('saved_report_not_found'); }
        return ['id' => $reportId, 'deleted' => true];
    }

    private function requireExists(int $spaceId, int $reportId): void {
        if ($reportId < 1) { throw new DomainException('saved_report_not_found'); }
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('lfr_saved_reports')
            ->where($q->expr()->eq('id', $q->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('active', $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        if ($q->executeQuery()->fetchOne() === false) {
            throw new DomainException('saved_report_not_found');
        }
    }

    /** @return array<string,mixed> */
    private static function validate(string $name, string $kind, ?string $from,
        string $through, array $columns, ?string $accountClass): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || !in_array($kind, self::KINDS, true)) {
            throw new DomainException('invalid_saved_report');
        }
        if (!self::validDate($through)) { throw new DomainException('invalid_report_period'); }
        if ($kind === 'trial_balance') {
            $from = null;
            $columns = $columns === [] ? self::BALANCE_COLUMNS : $columns;
            if (!array_is_list($columns) || count($columns) > 4) {
                throw new DomainException('invalid_report_columns');
            }
            $seen = [];
            foreach ($columns as $column) {
                if (!is_string($column) || !in_array($column, self::BALANCE_COLUMNS, true)
                    || isset($seen[$column])) {
                    throw new DomainException('invalid_report_columns');
                }
                $seen[$column] = true;
            }
            if ($accountClass === '') $accountClass = null;
            if ($accountClass !== null && !in_array($accountClass, self::CLASSES, true)) {
                throw new DomainException('invalid_account_class');
            }
        } else {
            if ($columns !== [] || $accountClass !== null) {
                throw new DomainException('invalid_report_columns');
            }
            if ($from === null || !self::validDate($from) || strcmp($from, $through) > 0) {
                throw new DomainException('invalid_report_period');
            }
        }
        return compact('name', 'kind', 'from', 'through', 'columns', 'accountClass');
    }

    private static function validDate(string $value): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) { return false; }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function cast(array $row): array {
        return ['id' => (int)$row['id'], 'name' => (string)$row['name'],
            'kind' => (string)$row['kind'], 'from' => $row['from_on'],
            'through' => (string)$row['through_on'],
            'columns' => $row['kind'] === 'trial_balance'
                ? (json_decode($row['columns_json'] ?? 'null', true) ?: self::BALANCE_COLUMNS) : [],
            'accountClass' => $row['class_filter'],
            'created_by' => (string)$row['created_by'],
            'created_at' => (int)$row['created_at'], 'updated_at' => (int)$row['updated_at']];
    }

    private function uid(): string {
        $uid = $this->session->getUser()?->getUID();
        if (!$uid) { throw new DomainException('authentication_required'); }
        return $uid;
    }
}
