<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only cash activity projection. The ledger, obligation and bank statement
 * tables remain authoritative. Evidence is never a cash posting. All sources
 * are filtered in SQL before bounded, globally ordered keyset pagination.
 */
final class ActivityFeedService {
    private const PAGE_SIZE = 50;
    private const JOURNAL = 3;
    private const OBLIGATION = 2;
    private const EVIDENCE = 1;

    public function __construct(
        private AccessService $access,
        private ModuleService $modules,
        private IDBConnection $db,
    ) {}

    /** @return array{items:list<array<string,mixed>>,nextCursor:?string} */
    public function list(
        int $spaceId,
        string $cursor = '',
        string $direction = 'all',
        string $state = 'all',
        int $accountId = 0,
        string $search = '',
    ): array {
        $this->access->requireFinancialCapability($spaceId, 'workspace.read');
        if (!in_array($direction, ['all', 'in', 'out'], true)
            || !in_array($state, ['all', 'planned', 'posted', 'pending'], true)
            || $accountId < 0 || mb_strlen($search) > 120) {
            throw new DomainException('invalid_activity_filter');
        }
        $marker = $this->cursor($cursor);
        $term = trim($search);
        $enabled = [];
        foreach ($this->modules->listForSpace($spaceId)['modules'] as $module) {
            $enabled[(string)$module['id']] = (bool)$module['enabled'];
        }

        $items = [];
        if ($state === 'all' || $state === 'posted') {
            $items = $this->journals($spaceId, $marker, $direction, $accountId, $term);
        }
        if ($accountId === 0 && ($state === 'all' || $state === 'planned')
            && ($enabled['librefinance_obligations'] ?? false)) {
            $items = array_merge($items,
                $this->obligations($spaceId, $marker, $direction, $term));
        }
        if (($state === 'all' || $state === 'pending')
            && ($enabled['librefinance_banking'] ?? false)) {
            $items = array_merge($items,
                $this->evidence($spaceId, $marker, $direction, $accountId, $term));
        }
        usort($items, static function (array $a, array $b): int {
            return strcmp($b['date'], $a['date'])
                ?: ($b['sourceRank'] <=> $a['sourceRank'])
                ?: ($b['sourceId'] <=> $a['sourceId']);
        });
        $more = count($items) > self::PAGE_SIZE;
        $items = array_slice($items, 0, self::PAGE_SIZE);
        $last = $items === [] ? null : end($items);
        foreach ($items as &$item) {
            unset($item['sourceRank']);
        }
        unset($item);
        return [
            'items' => $items,
            'nextCursor' => $more && $last !== null
                ? $last['date'] . ':' . $last['sourceRank'] . ':' . $last['sourceId']
                : null,
        ];
    }

    /** @return ?array{date:string,rank:int,id:int} */
    private function cursor(string $value): ?array {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}):([1-3]):([1-9]\d{0,17})$/D',
            $value, $matches)
            || !$this->dateValid($matches[1])
            || (float)$matches[3] > PHP_INT_MAX) {
            throw new DomainException('invalid_activity_cursor');
        }
        return ['date' => $matches[1], 'rank' => (int)$matches[2],
            'id' => (int)$matches[3]];
    }

    private function dateValid(string $value): bool {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param ?array{date:string,rank:int,id:int} $cursor */
    private function after(
        IQueryBuilder $q,
        string $dateField,
        string $idField,
        int $rank,
        ?array $cursor,
    ): void {
        if ($cursor === null) {
            return;
        }
        $date = $q->createNamedParameter($cursor['date']);
        if ($rank !== $cursor['rank']) {
            $q->andWhere($rank < $cursor['rank']
                ? $q->expr()->lte($dateField, $date)
                : $q->expr()->lt($dateField, $date));
            return;
        }
        $q->andWhere($q->expr()->orX(
            $q->expr()->lt($dateField, $date),
            $q->expr()->andX(
                $q->expr()->eq($dateField, $q->createNamedParameter($cursor['date'])),
                $q->expr()->lt($idField,
                    $q->createNamedParameter($cursor['id'], IQueryBuilder::PARAM_INT)),
            ),
        ));
    }

    /** @param ?array{date:string,rank:int,id:int} $cursor
     * @return list<array<string,mixed>> */
    private function journals(int $spaceId, ?array $cursor,
        string $direction, int $accountId, string $term): array {
        $q = $this->db->getQueryBuilder();
        $sum = $q->createFunction('SUM(p.amount_minor)');
        $q->select('j.id', 'j.effective_on', 'j.memo', 'j.reversed_journal_id')
            ->selectAlias($sum, 'cash_minor')
            ->selectAlias($q->createFunction('MIN(p.account_id)'), 'bank_id')
            ->selectAlias($q->createFunction('COUNT(p.id)'), 'legs')
            ->selectAlias($q->createFunction('MAX(ABS(p.amount_minor))'), 'transfer_minor')
            ->from('lf_journals', 'j')
            ->innerJoin('j', 'lf_postings', 'p',
                'p.journal_id = j.id AND p.space_id = j.space_id')
            ->innerJoin('p', 'lf_accounts', 'a',
                'a.id = p.account_id AND a.space_id = p.space_id')
            ->where($q->expr()->eq('j.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('a.is_bank',
                $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->groupBy('j.id')->addGroupBy('j.effective_on')
            ->addGroupBy('j.memo')->addGroupBy('j.reversed_journal_id')
            ->orderBy('j.effective_on', 'DESC')->addOrderBy('j.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);
        if ($accountId > 0) {
            $q->andWhere($q->expr()->eq('p.account_id',
                $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }
        if ($term !== '') {
            $q->andWhere($q->expr()->iLike('j.memo',
                $q->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')));
        }
        if ($direction !== 'all') {
            $q->having($direction === 'in'
                ? $q->expr()->gt($sum, $q->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                : $q->expr()->lt($sum, $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }
        $this->after($q, 'j.effective_on', 'j.id', self::JOURNAL, $cursor);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $items = [];
        foreach ($rows as $row) {
            $minor = (int)$row['cash_minor'];
            // One journal between two own accounts nets to zero, but showing
            // R$ 0,00 would hide the actual transfer amount from the user.
            // Display the moved magnitude while keeping its net cash impact zero.
            $isTransfer = $minor === 0 && (int)$row['legs'] === 2;
            $items[] = [
                'source' => 'journal', 'sourceRank' => self::JOURNAL,
                'sourceId' => (int)$row['id'], 'date' => (string)$row['effective_on'],
                'description' => (string)$row['memo'],
                'amountMinor' => $isTransfer ? (int)$row['transfer_minor'] : $minor,
                'kind' => $isTransfer ? 'transfer' : ($minor > 0 ? 'in' : 'out'),
                'state' => 'posted', 'countsAsCash' => !$isTransfer,
                'accountId' => $accountId > 0 ? $accountId
                    : ((int)$row['legs'] === 1 ? (int)$row['bank_id'] : null),
                'obligationId' => null, 'invoiceId' => null,
                'reversal' => $row['reversed_journal_id'] !== null,
            ];
        }
        return $items;
    }

    /** @param ?array{date:string,rank:int,id:int} $cursor
     * @return list<array<string,mixed>> */
    private function obligations(int $spaceId, ?array $cursor,
        string $direction, string $term): array {
        $q = $this->db->getQueryBuilder();
        $q->select('o.id', 'o.kind', 'o.description', 'o.reference',
                'o.due_on', 'o.amount_minor', 'o.paid_minor', 'o.status')
            ->addSelect('l.invoice_id')
            ->from('lfob_obligations', 'o')
            ->leftJoin('o', 'lf_invoice_obligation_links', 'l',
                'l.obligation_id = o.id AND l.space_id = o.space_id')
            ->where($q->expr()->eq('o.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('o.status',
                $q->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('o.due_on', 'DESC')->addOrderBy('o.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);
        if ($direction !== 'all') {
            $q->andWhere($q->expr()->eq('o.kind',
                $q->createNamedParameter($direction === 'in' ? 'receivable' : 'payable')));
        }
        if ($term !== '') {
            $pattern = $q->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%');
            $q->andWhere($q->expr()->orX(
                $q->expr()->iLike('o.description', $pattern),
                $q->expr()->iLike('o.reference',
                    $q->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')),
            ));
        }
        $this->after($q, 'o.due_on', 'o.id', self::OBLIGATION, $cursor);
        $items = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $remaining = (int)$row['amount_minor'] - (int)$row['paid_minor'];
            if ($remaining <= 0) {
                continue;
            }
            $items[] = [
                'source' => 'obligation', 'sourceRank' => self::OBLIGATION,
                'sourceId' => (int)$row['id'], 'date' => (string)$row['due_on'],
                'description' => (string)$row['description'],
                'amountMinor' => $row['kind'] === 'receivable' ? $remaining : -$remaining,
                'totalMinor' => (int)$row['amount_minor'],
                'paidMinor' => (int)$row['paid_minor'],
                'kind' => $row['kind'] === 'receivable' ? 'in' : 'out',
                'state' => 'planned', 'countsAsCash' => false,
                'accountId' => null, 'obligationId' => (int)$row['id'],
                'invoiceId' => $row['invoice_id'] === null ? null : (int)$row['invoice_id'],
                'reference' => (string)$row['reference'],
            ];
        }
        return $items;
    }

    /** @param ?array{date:string,rank:int,id:int} $cursor
     * @return list<array<string,mixed>> */
    private function evidence(int $spaceId, ?array $cursor,
        string $direction, int $accountId, string $term): array {
        $q = $this->db->getQueryBuilder();
        $q->select('b.id', 'b.booking_date', 'b.description',
                'b.amount_minor', 'b.bank_account_id')
            ->from('lfb_transactions', 'b')
            ->where($q->expr()->eq('b.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('b.status', $q->createNamedParameter('pending')))
            ->orderBy('b.booking_date', 'DESC')->addOrderBy('b.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);
        if ($accountId > 0) {
            $q->andWhere($q->expr()->eq('b.bank_account_id',
                $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }
        if ($direction !== 'all') {
            $q->andWhere($direction === 'in'
                ? $q->expr()->gt('b.amount_minor', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                : $q->expr()->lt('b.amount_minor', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }
        if ($term !== '') {
            $q->andWhere($q->expr()->iLike('b.description',
                $q->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')));
        }
        $this->after($q, 'b.booking_date', 'b.id', self::EVIDENCE, $cursor);
        $items = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $minor = (int)$row['amount_minor'];
            $items[] = [
                'source' => 'statement', 'sourceRank' => self::EVIDENCE,
                'sourceId' => (int)$row['id'], 'date' => (string)$row['booking_date'],
                'description' => (string)$row['description'], 'amountMinor' => $minor,
                'kind' => $minor >= 0 ? 'in' : 'out',
                'state' => 'pending', 'countsAsCash' => false,
                'accountId' => (int)$row['bank_account_id'],
                'obligationId' => null, 'invoiceId' => null,
            ];
        }
        return $items;
    }
}
