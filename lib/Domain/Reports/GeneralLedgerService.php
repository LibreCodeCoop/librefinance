<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Reports;

use DomainException;
use OCA\LibreFinance\Domain\Currency\CurrencyMetadata;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** The existing posted, debit-positive ledger; no conversion or forecast. */
final class GeneralLedgerService {
    private const MODULE = 'librefinance_reports';
    private const PAGE_SIZE = 50;

    public function __construct(private FinanceApi $finance, private IDBConnection $db) {}

    /** @return array<string,mixed> */
    public function page(int $spaceId, int $accountId, string $from, string $through,
        string $snapshot = '', string $afterDate = '', int $afterId = 0, bool $export = false): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE,
            $export ? 'reports.export' : 'reports.read');
        self::date($from);
        self::date($through);
        if ($through < $from) {
            throw new DomainException('invalid_period');
        }
        if ($afterId < 0 || ($afterId === 0) !== ($afterDate === '')
            || ($afterId > 0 && ($snapshot === '' || $afterDate < $from || $afterDate > $through))
            || ($snapshot !== '' && !preg_match('/^[0-9a-f]{64}$/D', $snapshot))) {
            throw new DomainException('invalid_cursor');
        }
        if ($afterDate !== '') {
            self::date($afterDate);
        }
        $account = $this->account($spaceId, $accountId);
        $summary = $this->summary($spaceId, $accountId, $from, $through, $account['currency']);
        $fingerprint = hash('sha256', json_encode([
            'version' => 1, 'spaceId' => $spaceId, 'account' => $account,
            'from' => $from, 'through' => $through, 'summary' => $summary,
        ], JSON_THROW_ON_ERROR));
        if ($snapshot !== '' && !hash_equals($fingerprint, $snapshot)) {
            throw new DomainException('ledger_report_changed');
        }

        // Start a later page at the exact prefix balance, not at the opening
        // balance and not at an untrusted running balance supplied by a client.
        $running = $summary['opening'];
        if ($afterId > 0) {
            $cursor = $this->postings($spaceId, $accountId, $through);
            $cursor->select('p.id')
                ->andWhere($cursor->expr()->eq('p.id', $cursor->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
                ->andWhere($cursor->expr()->eq('j.effective_on', $cursor->createNamedParameter($afterDate)));
            if ($cursor->executeQuery()->fetchOne() === false) {
                throw new DomainException('invalid_cursor');
            }
            $prefix = $this->postings($spaceId, $accountId, $through);
            $prefix->selectAlias($prefix->createFunction('SUM(p.amount_minor)'), 'balance')
                ->andWhere($prefix->expr()->orX(
                    $prefix->expr()->lt('j.effective_on', $prefix->createNamedParameter($afterDate)),
                    $prefix->expr()->andX(
                        $prefix->expr()->eq('j.effective_on', $prefix->createNamedParameter($afterDate)),
                        $prefix->expr()->lte('p.id', $prefix->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)),
                    ),
                ));
            $running = self::integer($this->aggregate($prefix)['balance'] ?? 0);
        }
        $pageOpening = $running;
        $q = $this->postings($spaceId, $accountId, $through);
        $q->select('p.id', 'p.journal_id', 'p.amount_minor', 'p.currency',
            'j.effective_on', 'j.memo', 'j.reversed_journal_id')
            ->andWhere($q->expr()->gte('j.effective_on', $q->createNamedParameter($from)))
            ->orderBy('j.effective_on', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setMaxResults(self::PAGE_SIZE + 1);
        if ($afterId > 0) {
            $q->andWhere($q->expr()->orX(
                $q->expr()->gt('j.effective_on', $q->createNamedParameter($afterDate)),
                $q->expr()->andX(
                    $q->expr()->eq('j.effective_on', $q->createNamedParameter($afterDate)),
                    $q->expr()->gt('p.id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)),
                ),
            ));
        }
        $raw = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($raw) > self::PAGE_SIZE;
        $rows = [];
        foreach (array_slice($raw, 0, self::PAGE_SIZE) as $row) {
            if ($row['currency'] !== $account['currency']) {
                throw new DomainException('ledger_currency_mismatch');
            }
            $amount = self::integer($row['amount_minor']);
            if ($amount === PHP_INT_MIN) {
                throw new DomainException('money_overflow');
            }
            $running = self::add($running, $amount);
            $rows[] = [
                'postingId' => (int)$row['id'],
                'journalId' => (int)$row['journal_id'],
                'date' => (string)$row['effective_on'],
                'memo' => (string)$row['memo'],
                'reversedJournalId' => $row['reversed_journal_id'] === null ? null : (int)$row['reversed_journal_id'],
                'currency' => (string)$row['currency'],
                'amountMinor' => (string)$amount,
                'debitMinor' => (string)max(0, $amount),
                'creditMinor' => (string)max(0, -$amount),
                'runningMinor' => (string)$running,
            ];
        }
        // Posted facts are immutable. Detect newly visible postings (including
        // backdated postings or late commits with older IDs) across the reads.
        // A report must be applied again rather than mixing ledger versions.
        if ($summary !== $this->summary($spaceId, $accountId, $from, $through, $account['currency'])
            || $account !== $this->account($spaceId, $accountId)
            || (!$hasMore && $running !== $summary['closing'])) {
            throw new DomainException('ledger_report_changed');
        }
        $this->finance->requireModuleCapability($spaceId, self::MODULE,
            $export ? 'reports.export' : 'reports.read');
        $last = $hasMore ? $rows[count($rows) - 1] : null;
        return [
            'basis' => 'posted_debit_positive',
            'account' => $account,
            'currency' => $account['currency'],
            'currencyPrecision' => CurrencyMetadata::precision($account['currency']),
            'from' => $from, 'through' => $through,
            'snapshot' => $fingerprint,
            'openingMinor' => (string)$summary['opening'],
            'closingMinor' => (string)$summary['closing'],
            'pageOpeningMinor' => (string)$pageOpening,
            'rowCount' => $summary['periodCount'],
            'rows' => $rows,
            'nextCursor' => $last === null ? null : ['afterDate' => $last['date'], 'afterId' => $last['postingId']],
        ];
    }

    /** The actual journal, including both posted sides, independently authorized. */
    public function journal(int $spaceId, int $journalId): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'reports.read');
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'ledger.read');
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'effective_on', 'memo', 'reversed_journal_id')
            ->from('lf_journals')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('status', $q->createNamedParameter('posted')));
        $journal = $q->executeQuery()->fetchAssociative();
        if ($journal === false) {
            throw new DomainException('journal_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('p.account_id', 'p.amount_minor', 'p.currency', 'a.code', 'a.name')
            ->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_accounts', 'a', 'a.id = p.account_id AND a.space_id = p.space_id')
            ->where($q->expr()->eq('p.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->orderBy('p.id', 'ASC');
        $currency = $this->finance->currency($spaceId, self::MODULE);
        $postings = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            if ($currency !== 'BRL' || $row['currency'] !== $currency) {
                throw new DomainException('ledger_currency_mismatch');
            }
            $postings[] = [
                'accountId' => (int)$row['account_id'],
                'code' => (string)$row['code'], 'name' => (string)$row['name'],
                'amountMinor' => (string)self::integer($row['amount_minor']),
                'currency' => (string)$row['currency'],
            ];
        }
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'reports.read');
        return [
            'id' => (int)$journal['id'], 'date' => (string)$journal['effective_on'],
            'memo' => (string)$journal['memo'],
            'reversedJournalId' => $journal['reversed_journal_id'] === null ? null : (int)$journal['reversed_journal_id'],
            'postings' => $postings,
        ];
    }

    /** Include inactive accounts: historical ledger entries remain readable. */
    private function account(int $spaceId, int $accountId): array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'code', 'name', 'class', 'currency')
            ->from('lf_accounts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        $account = $q->executeQuery()->fetchAssociative();
        if ($account === false) {
            throw new DomainException('account_not_found');
        }
        $account['id'] = (int)$account['id'];
        // This report does not invent exchange rates, dimensions or a
        // multi-currency accounting basis before those contracts exist.
        if ($account['currency'] !== 'BRL'
            || $this->finance->currency($spaceId, self::MODULE) !== $account['currency']) {
            throw new DomainException('ledger_currency_mismatch');
        }
        return $account;
    }

    /** Restrict the join and period before aggregation or pagination. */
    private function postings(int $spaceId, int $accountId, string $through): IQueryBuilder {
        $q = $this->db->getQueryBuilder();
        return $q->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id AND j.space_id = p.space_id')
            ->where($q->expr()->eq('p.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.account_id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('j.status', $q->createNamedParameter('posted')))
            ->andWhere($q->expr()->lte('j.effective_on', $q->createNamedParameter($through)));
    }

    /** @return array{opening:int,closing:int,periodCount:int,count:int,maxId:int} */
    private function summary(int $spaceId, int $accountId, string $from, string $through, string $currency): array {
        $q = $this->postings($spaceId, $accountId, $through);
        $openingDate = $q->createNamedParameter($from);
        $periodDate = $q->createNamedParameter($from);
        $q->selectAlias($q->createFunction('COUNT(p.id)'), 'row_count')
            ->selectAlias($q->createFunction('MAX(p.id)'), 'max_id')
            ->selectAlias($q->createFunction('MIN(p.currency)'), 'min_currency')
            ->selectAlias($q->createFunction('MAX(p.currency)'), 'max_currency')
            ->selectAlias($q->createFunction('SUM(p.amount_minor)'), 'closing')
            ->selectAlias($q->createFunction('SUM(CASE WHEN j.effective_on < ' . $openingDate . ' THEN p.amount_minor ELSE 0 END)'), 'opening')
            ->selectAlias($q->createFunction('SUM(CASE WHEN j.effective_on >= ' . $periodDate . ' THEN 1 ELSE 0 END)'), 'period_count');
        $summary = $this->aggregate($q);
        if ($summary['min_currency'] !== null && ($summary['min_currency'] !== $currency
            || $summary['max_currency'] !== $currency)) {
            throw new DomainException('ledger_currency_mismatch');
        }
        return [
            'opening' => self::integer($summary['opening'] ?? 0),
            'closing' => self::integer($summary['closing'] ?? 0),
            'periodCount' => self::integer($summary['period_count'] ?? 0),
            'count' => self::integer($summary['row_count']),
            'maxId' => self::integer($summary['max_id'] ?? 0),
        ];
    }

    private function aggregate(IQueryBuilder $q): array {
        try {
            $result = $q->executeQuery()->fetchAssociative();
        } catch (\OCP\DB\Exception $e) {
            // SQLite SUM uses a signed integer accumulator. Never fall back
            // to floating-point TOTAL or clamp a financial result.
            if (str_contains(strtolower($e->getMessage()), 'integer overflow')) {
                throw new DomainException('money_overflow', previous: $e);
            }
            throw $e;
        }
        if ($result === false) {
            throw new \LogicException('missing_ledger_aggregate');
        }
        return $result;
    }

    private static function date(string $value): void {
        if (!preg_match('/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) {
            throw new DomainException('invalid_date');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('invalid_date');
        }
    }

    private static function integer(mixed $value): int {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('money_overflow');
        }
        return (int)$value;
    }

    private static function add(int $left, int $right): int {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new DomainException('money_overflow');
        }
        return $left + $right;
    }
}
