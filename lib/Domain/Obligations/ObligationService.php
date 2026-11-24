<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Obligations;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

final class ObligationService {
    private const MODULE = 'librefinance_obligations';
    private const MAX_MINOR = 9000000000000;

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
        private IUserSession $session,
    ) {}

    /** @return array<string,mixed> */
    public function list(int $spaceId, int $afterId = 0, string $status = 'all',
        string $kind = 'all', string $dueRange = 'all', string $search = '',
        string $sortBy = 'id', string $sortDirection = 'asc',
        string $referenceDate = ''): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.read');
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        if (!in_array($status, ['all', 'open', 'partial', 'settled', 'cancelled'], true)) {
            throw new DomainException('invalid_status');
        }
        if (!in_array($kind, ['all', 'payable', 'receivable'], true)) {
            throw new DomainException('invalid_kind');
        }
        if (!in_array($dueRange, ['all', 'overdue', 'next7'], true)) {
            throw new DomainException('invalid_due_range');
        }
        // Use a whitelist of real columns, never user supplied SQL identifiers.
        $columns = [
            'id' => 'id', 'due' => 'due_on', 'description' => 'description',
            'amount' => 'amount_minor', 'reference' => 'reference',
        ];
        if (!isset($columns[$sortBy]) || !in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new DomainException('invalid_sort');
        }
        if (mb_strlen($search) > 120) {
            throw new DomainException('invalid_search');
        }
        $column = $columns[$sortBy];
        $sort = strtoupper($sortDirection);
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'kind', 'description', 'reference', 'contact_id',
            'amount_minor', 'paid_minor', 'currency', 'issued_on', 'due_on', 'status', 'version')
            ->from('lfob_obligations')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->orderBy($column, $sort)
            ->setMaxResults(51);
        if ($column !== 'id') {
            $q->addOrderBy('id', $sort);
        }
        // Continuation is resolved from the last row ID, not by shifting
        // offsets. This remains stable across several sorted pages with ties.
        if ($afterId > 0) {
            if ($column === 'id') {
                $comparison = $sort === 'ASC' ? 'gt' : 'lt';
                $q->andWhere($q->expr()->{$comparison}('id',
                    $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
            } else {
                $anchor = $this->db->getQueryBuilder();
                $anchor->select($column)->from('lfob_obligations')
                    ->where($anchor->expr()->eq('space_id',
                        $anchor->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($anchor->expr()->eq('id',
                        $anchor->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
                $marker = $anchor->executeQuery()->fetchOne();
                if ($marker === false) throw new DomainException('invalid_cursor');
                $markerType = $column === 'amount_minor'
                    ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
                $comparison = $sort === 'ASC' ? 'gt' : 'lt';
                $q->andWhere($q->expr()->orX(
                    $q->expr()->{$comparison}($column, $q->createNamedParameter($marker, $markerType)),
                    $q->expr()->andX(
                        $q->expr()->eq($column, $q->createNamedParameter($marker, $markerType)),
                        $q->expr()->{$comparison}('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)),
                    ),
                ));
            }
        }
        $needle = trim($search);
        if ($needle !== '') {
            $pattern = $q->createNamedParameter('%' . $this->db->escapeLikeParameter($needle) . '%');
            $q->andWhere($q->expr()->orX(
                $q->expr()->iLike('description', $pattern),
                $q->expr()->iLike('reference', $pattern),
            ));
        }
        if ($status !== 'all') {
            $q->andWhere($q->expr()->eq('status', $q->createNamedParameter($status)));
        }
        // Authorization and flow kind are resolved in SQL before keyset paging.
        if ($kind !== 'all') {
            $q->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)));
        }
        // Apply time filters before keyset pagination, never to the current page.
        if ($dueRange !== 'all') {
            $q->andWhere($q->expr()->in('status', $q->createNamedParameter(
                ['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)));
            // Browser-local calendar dates can differ by one day from UTC
            // around midnight. This bounded hint only controls read filters,
            // never amounts, due dates or audit/posting timestamps.
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
                ->format('Y-m-d');
            if ($referenceDate !== '') {
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $referenceDate, $parts)
                    || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                    throw new DomainException('invalid_reference_date');
                }
                $utcDay = new \DateTimeImmutable($today, new \DateTimeZone('UTC'));
                $userDay = new \DateTimeImmutable($referenceDate, new \DateTimeZone('UTC'));
                if ($utcDay->diff($userDay)->days > 1) {
                    throw new DomainException('invalid_reference_date');
                }
                $today = $referenceDate;
            }
            if ($dueRange === 'overdue') {
                $q->andWhere($q->expr()->lt('due_on', $q->createNamedParameter($today)));
            } else {
                $through = (new \DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
                $q->andWhere($q->expr()->gte('due_on', $q->createNamedParameter($today)))
                    ->andWhere($q->expr()->lte('due_on', $q->createNamedParameter($through)));
            }
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row = self::cast($row);
        }
        unset($row);
        // Fetch links in one bounded query, not one lookup per obligation.
        $links = [];
        if ($rows !== []) {
            $q = $this->db->getQueryBuilder();
            $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
            $q->select('obligation_id', 'invoice_id')->from('lf_invoice_obligation_links')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->in('obligation_id',
                    $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            foreach ($q->executeQuery()->fetchAllAssociative() as $link) {
                $links[(int)$link['obligation_id']] = (int)$link['invoice_id'];
            }
        }
        $ledgerPaid = $this->ledgerPaidByIds($spaceId, array_map(
            static fn(array $row): int => (int)$row['id'], $rows));
        foreach ($rows as &$row) {
            $row['linkedInvoiceId'] = $links[(int)$row['id']] ?? null;
            $row['ledgerPaidMinor'] = $ledgerPaid[(int)$row['id']] ?? 0;
            $row['administrativePaidMinor'] = (int)$row['paid_minor'] - $row['ledgerPaidMinor'];
        }
        unset($row);
        return ['obligations' => $rows, 'nextAfterId' => $hasMore ? (int)end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    public function show(int $spaceId, int $id): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.read');
        if ($id < 1) {
            throw new DomainException('obligation_not_found');
        }
        $row = $this->find($spaceId, $id);
        if ($row === null) {
            throw new DomainException('obligation_not_found');
        }
        $row = self::cast($row);
        $row['linkedInvoiceId'] = $this->invoiceIdForObligation($spaceId, $id);
        $ledgerPaid = $this->ledgerPaidByIds($spaceId, [$id]);
        $row['ledgerPaidMinor'] = $ledgerPaid[$id] ?? 0;
        $row['administrativePaidMinor'] = (int)$row['paid_minor'] - $row['ledgerPaidMinor'];
        return ['obligation' => $row];
    }

    /**
     * Sum only journal-backed installments. A paid title is NOT necessarily
     * an accounted cash receipt; administrative and ledger-backed payments
     * must be distinguishable in every financial view. Bounded by page IDs.
     *
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function ledgerPaidByIds(int $spaceId, array $ids): array {
        if ($ids === []) return [];
        $q = $this->db->getQueryBuilder();
        $q->select('obligation_id')
            ->selectAlias($q->createFunction('SUM(amount_minor)'), 'paid_minor')
            ->from('lfob_settlements')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('obligation_id',
                $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($q->expr()->isNotNull('journal_id'))
            ->groupBy('obligation_id');
        $amounts = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $amounts[(int)$row['obligation_id']] = (int)$row['paid_minor'];
        }
        return $amounts;
    }

    /** @return array<string,mixed> */
    public function create(int $spaceId, string $kind, string $description, string $reference,
        int $amountMinor, string $issuedOn, string $dueOn, ?int $contactId = null): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.manage');
        if (!in_array($kind, ['payable', 'receivable'], true)) {
            throw new DomainException('invalid_kind');
        }
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new DomainException('invalid_description');
        }
        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw new DomainException('invalid_amount');
        }
        self::date($issuedOn);
        self::date($dueOn);
        if ($dueOn < $issuedOn) {
            throw new DomainException('due_before_issue');
        }
        $reference = strtoupper(trim($reference));
        if ($reference === '') {
            $reference = 'LF-' . strtoupper(bin2hex(random_bytes(8)));
        }
        if (str_starts_with($reference, 'LF-INVOICE-')) {
            throw new DomainException('invoice_reference_reserved');
        }
        if (!preg_match('/^[A-Z0-9_.:\/-]{4,80}$/', $reference)) {
            throw new DomainException('invalid_reference');
        }
        if ($contactId !== null) {
            if ($contactId < 1) {
                throw new DomainException('contact_not_found');
            }
            $this->finance->requireActiveContact($spaceId, self::MODULE, $contactId);
        }
        $currency = $this->finance->currency($spaceId, self::MODULE);
        $values = [
            'space_id' => $spaceId, 'kind' => $kind, 'description' => $description,
            'reference' => $reference, 'contact_id' => $contactId,
            'amount_minor' => $amountMinor, 'paid_minor' => 0, 'currency' => $currency,
            'issued_on' => $issuedOn, 'due_on' => $dueOn, 'status' => 'open',
            'version' => 0, 'created_by' => $this->uid(), 'created_at' => time(),
        ];
        $this->db->beginTransaction();
        try {
            $this->insert('lfob_obligations', $values);
            $id = (int)$this->db->lastInsertId('*PREFIX*lfob_obligations');
            $this->audit($spaceId, $id, 'obligation.created');
            $this->db->commit();
            return ['id' => $id, 'reference' => $reference, 'status' => 'open',
                'kind' => $kind, 'description' => $description,
                'currency' => $currency, 'amount_minor' => $amountMinor,
                'paid_minor' => 0, 'issued_on' => $issuedOn, 'due_on' => $dueOn];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception
                && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('reference_conflict', previous: $e);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function settle(int $spaceId, int $id, int $amountMinor, string $paidOn,
        string $operationKey, ?string $note): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.manage');
        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw new DomainException('invalid_amount');
        }
        self::date($paidOn);
        $note = trim($note ?? '');
        if (mb_strlen($note) > 255) {
            throw new DomainException('invalid_note');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{12,64}$/', $operationKey)) {
            throw new DomainException('invalid_idempotency_key');
        }
        $hash = hash('sha256', json_encode([$id, $amountMinor, $paidOn, $note], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $prior = $this->findSettlementByKey($spaceId, $operationKey);
            if ($prior !== null) {
                if ($prior['request_hash'] !== $hash) {
                    throw new DomainException('idempotency_conflict');
                }
                $this->db->commit();
                return ['id' => (int)$prior['id'], 'replayed' => true];
            }
            $obligation = $this->find($spaceId, $id);
            if ($obligation === null) {
                throw new DomainException('obligation_not_found');
            }
            // Linked commercial documents must be settled only via their
            // posted journal allocation, never via a second cash counter.
            if ($this->invoiceIdForObligation($spaceId, $id) !== null) {
                throw new DomainException('invoice_payment_via_allocation');
            }
            if (!in_array($obligation['status'], ['open', 'partial'], true)) {
                throw new DomainException('obligation_closed');
            }
            $newPaid = (int)$obligation['paid_minor'] + $amountMinor;
            if ($newPaid > (int)$obligation['amount_minor']) {
                throw new DomainException('amount_exceeds_balance');
            }
            $status = $newPaid === (int)$obligation['amount_minor'] ? 'settled' : 'partial';
            $version = (int)$obligation['version'];
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lfob_obligations')
                ->set('paid_minor', $q->createNamedParameter($newPaid, IQueryBuilder::PARAM_INT))
                ->set('status', $q->createNamedParameter($status))
                ->set('version', $q->createNamedParameter($version + 1, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('version', $q->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $this->insert('lfob_settlements', [
                'space_id' => $spaceId, 'obligation_id' => $id,
                'operation_key' => $operationKey, 'request_hash' => $hash,
                'amount_minor' => $amountMinor, 'paid_on' => $paidOn,
                'note' => $note !== '' ? $note : null,
                'created_by' => $this->uid(), 'created_at' => time(),
            ]);
            $settlementId = (int)$this->db->lastInsertId('*PREFIX*lfob_settlements');
            $this->audit($spaceId, $id, 'obligation.settled');
            $this->db->commit();
            return ['id' => $settlementId, 'obligationId' => $id,
                'status' => $status, 'paidMinor' => $newPaid, 'replayed' => false];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            // Resolve duplicate retries only when the previously committed
            // operation has exactly the same content.
            if ($e instanceof \OCP\DB\Exception || ($e instanceof DomainException && $e->getMessage() === 'concurrent_change')) {
                $prior = $this->findSettlementByKey($spaceId, $operationKey);
                if ($prior !== null) {
                    if ($prior['request_hash'] !== $hash) {
                        throw new DomainException('idempotency_conflict');
                    }
                    return ['id' => (int)$prior['id'], 'replayed' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Settle a standalone obligation with a posted journal in the same SQL
     * transaction. The caller owns the outer payment+ledger transaction;
     * this method never creates a second bank or accounting movement.
     *
     * @return array<string,mixed>
     */
    public function settleWithJournal(int $spaceId, int $id, int $amountMinor,
        string $paidOn, string $operationKey, int $journalId, int $bankId): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.manage');
        if ($journalId < 1 || $bankId < 1) {
            throw new DomainException('invalid_payment');
        }
        $this->db->beginTransaction();
        try {
            $obligation = $this->find($spaceId, $id);
            if ($obligation === null) {
                throw new DomainException('obligation_not_found');
            }
            if ($this->invoiceIdForObligation($spaceId, $id) !== null) {
                throw new DomainException('invoice_payment_via_allocation');
            }
            $signed = $obligation['kind'] === 'receivable' ? $amountMinor : -$amountMinor;
            $this->finance->journalContainsBankPosting($spaceId, self::MODULE,
                $bankId, $journalId, $signed);
            $result = $this->settle($spaceId, $id, $amountMinor, $paidOn,
                $operationKey, 'Bank journal linked to standalone obligation');
            if (($result['replayed'] ?? false) === true) {
                $q = $this->db->getQueryBuilder();
                $q->select('journal_id')->from('lfob_settlements')
                    ->where($q->expr()->eq('space_id',
                        $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('id',
                        $q->createNamedParameter((int)$result['id'], IQueryBuilder::PARAM_INT)));
                if ((int)$q->executeQuery()->fetchOne() !== $journalId) {
                    throw new DomainException('idempotency_conflict');
                }
                $this->db->commit();
                return $result;
            }
            $this->finance->claimJournal($spaceId, self::MODULE, $journalId,
                'obligation:' . $id);
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lfob_settlements')
                ->set('journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('space_id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id',
                    $q->createNamedParameter((int)$result['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->isNull('journal_id'))->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $this->audit($spaceId, $id, 'obligation.journal_settlement');
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function cancel(int $spaceId, int $id): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.cancel');
        $this->db->beginTransaction();
        try {
            $obligation = $this->find($spaceId, $id);
            if ($obligation === null) {
                throw new DomainException('obligation_not_found');
            }
            if ($this->invoiceIdForObligation($spaceId, $id) !== null) {
                throw new DomainException('invoice_payment_via_allocation');
            }
            if ($obligation['status'] !== 'open' || (int)$obligation['paid_minor'] !== 0) {
                throw new DomainException('obligation_closed');
            }
            $version = (int)$obligation['version'];
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lfob_obligations')
                ->set('status', $q->createNamedParameter('cancelled'))
                ->set('version', $q->createNamedParameter($version + 1, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('version', $q->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $this->audit($spaceId, $id, 'obligation.cancelled');
            $this->db->commit();
            return ['id' => $id, 'status' => 'cancelled'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function settlements(int $spaceId, int $id, int $afterId = 0): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'obligations.read');
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        if ($this->find($spaceId, $id) === null) {
            throw new DomainException('obligation_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'amount_minor', 'paid_on', 'journal_id', 'note', 'created_by', 'created_at')
            ->from('lfob_settlements')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('obligation_id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['amount_minor'] = (int)$row['amount_minor'];
            $row['journal_id'] = $row['journal_id'] === null ? null : (int)$row['journal_id'];
        }
        unset($row);
        return ['settlements' => $rows, 'nextAfterId' => $more ? (int)end($rows)['id'] : null];
    }

    /**
     * Construct exactly one payable/receivable for a newly issued commercial
     * document, in the same SQL transaction as the invoice status change.
     * @param array<string,mixed> $invoice
     */
    public function linkIssuedInvoice(int $spaceId, int $invoiceId, array $invoice): int {
        $this->finance->requireModuleCapability($spaceId, 'librefinance_invoicing', 'invoice.issue');
        if ($invoiceId < 1 || (int)($invoice['paid_minor'] ?? -1) !== 0
            || (int)($invoice['total_minor'] ?? 0) <= 0
            || !in_array($invoice['kind'] ?? null, ['invoice', 'bill'], true)) {
            throw new DomainException('invoice_link_invalid');
        }
        if ($this->linkedObligationId($spaceId, $invoiceId) !== null) {
            throw new DomainException('invoice_already_linked');
        }
        $reference = 'LF-INVOICE-' . $invoiceId;
        $this->insert('lfob_obligations', [
            'space_id' => $spaceId,
            'kind' => $invoice['kind'] === 'invoice' ? 'receivable' : 'payable',
            'description' => ($invoice['kind'] === 'invoice' ? 'Invoice ' : 'Bill ')
                . (string)$invoice['number'],
            'reference' => $reference,
            'contact_id' => $invoice['contact_id'] === null ? null : (int)$invoice['contact_id'],
            'amount_minor' => (int)$invoice['total_minor'],
            'paid_minor' => 0,
            'currency' => (string)$invoice['currency'],
            'issued_on' => (string)$invoice['issue_on'],
            'due_on' => (string)$invoice['due_on'],
            'status' => 'open',
            'version' => 0,
            'created_by' => $this->uid(),
            'created_at' => time(),
        ]);
        $obligationId = (int)$this->db->lastInsertId('*PREFIX*lfob_obligations');
        $this->insert('lf_invoice_obligation_links', [
            'space_id' => $spaceId,
            'invoice_id' => $invoiceId,
            'obligation_id' => $obligationId,
            'created_at' => time(),
        ]);
        $this->audit($spaceId, $obligationId, 'obligation.from_invoice');
        return $obligationId;
    }

    /** Read-only one-to-one relationship for a visible commercial invoice. */
    public function linkedObligationId(int $spaceId, int $invoiceId): ?int {
        $this->finance->requireModuleCapability($spaceId, 'librefinance_invoicing', 'invoice.read');
        $q = $this->db->getQueryBuilder();
        $q->select('obligation_id')->from('lf_invoice_obligation_links')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('invoice_id',
                $q->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)));
        $found = $q->executeQuery()->fetchOne();
        return $found === false ? null : (int)$found;
    }

    /**
     * Apply a previously validated and claimed bank journal allocation to the
     * related receivable/payable. InvoiceService owns the transaction and
     * must commit both projections together or roll back both.
     */
    public function recordInvoiceAllocation(int $spaceId, int $invoiceId,
        int $journalId, int $amountMinor, int $previousPaidMinor, int $invoiceTotalMinor): void {
        $this->finance->requireModuleCapability($spaceId, 'librefinance_invoicing', 'invoice.manage');
        if ($amountMinor <= 0 || $journalId <= 0) {
            throw new DomainException('invalid_allocation');
        }
        $obligationId = $this->linkedObligationId($spaceId, $invoiceId);
        if ($obligationId === null) {
            // Do not fabricate past payments or link unrelated legacy
            // invoices by their human-readable number.
            throw new DomainException('invoice_obligation_unlinked');
        }
        $obligation = $this->find($spaceId, $obligationId);
        if ($obligation === null || (int)$obligation['paid_minor'] !== $previousPaidMinor
            || (int)$obligation['amount_minor'] !== $invoiceTotalMinor
            || !in_array($obligation['status'], ['open', 'partial'], true)) {
            throw new DomainException('invoice_obligation_mismatch');
        }
        $newPaid = $previousPaidMinor + $amountMinor;
        if ($newPaid > $invoiceTotalMinor) {
            throw new DomainException('amount_exceeds_balance');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('effective_on')->from('lf_journals')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id',
                $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $paidOn = $q->executeQuery()->fetchOne();
        if ($paidOn === false) {
            throw new DomainException('journal_posting_not_found');
        }
        $status = $newPaid === $invoiceTotalMinor ? 'settled' : 'partial';
        $q = $this->db->getQueryBuilder();
        $changed = $q->update('lfob_obligations')
            ->set('paid_minor', $q->createNamedParameter($newPaid, IQueryBuilder::PARAM_INT))
            ->set('status', $q->createNamedParameter($status))
            ->set('version', $q->createNamedParameter(
                (int)$obligation['version'] + 1, IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id',
                $q->createNamedParameter($obligationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('version',
                $q->createNamedParameter((int)$obligation['version'], IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('paid_minor',
                $q->createNamedParameter($previousPaidMinor, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        if ($changed !== 1) {
            throw new DomainException('invoice_obligation_mismatch');
        }
        $this->insert('lfob_settlements', [
            'space_id' => $spaceId,
            'obligation_id' => $obligationId,
            'operation_key' => 'invoice:' . $invoiceId . ':journal:' . $journalId,
            'request_hash' => hash('sha256', json_encode(
                [$obligationId, $amountMinor, (string)$paidOn, $journalId],
                JSON_THROW_ON_ERROR)),
            'amount_minor' => $amountMinor,
            'paid_on' => (string)$paidOn,
            'journal_id' => $journalId,
            'note' => 'Commercial invoice payment allocation',
            'created_by' => $this->uid(),
            'created_at' => time(),
        ]);
        $this->audit($spaceId, $obligationId, 'obligation.invoice_allocation');
    }

    private function invoiceIdForObligation(int $spaceId, int $obligationId): ?int {
        $q = $this->db->getQueryBuilder();
        $q->select('invoice_id')->from('lf_invoice_obligation_links')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('obligation_id',
                $q->createNamedParameter($obligationId, IQueryBuilder::PARAM_INT)));
        $id = $q->executeQuery()->fetchOne();
        return $id === false ? null : (int)$id;
    }

    /** @return array<string,mixed>|null */
    private function find(int $spaceId, int $id): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lfob_obligations')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $q->executeQuery()->fetchAssociative() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findSettlementByKey(int $spaceId, string $key): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'request_hash')->from('lfob_settlements')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('operation_key', $q->createNamedParameter($key)));
        return $q->executeQuery()->fetchAssociative() ?: null;
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

    private function audit(int $spaceId, int $id, string $action): void {
        $this->insert('lfob_audit', [
            'space_id' => $spaceId, 'obligation_id' => $id,
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

    private static function date(string $date): void {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('invalid_date');
        }
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array {
        foreach (['id', 'amount_minor', 'paid_minor', 'version'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        $row['contact_id'] = $row['contact_id'] === null ? null : (int)$row['contact_id'];
        return $row;
    }
}
