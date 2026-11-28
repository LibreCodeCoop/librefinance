<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Banking;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Read-only advisory search. BankingService::match remains authoritative. */
final class ReconciliationCandidateService {
    private const MODULE = 'librefinance_banking';
    private const PAGE_SIZE = 50;
    private const MAX_WINDOW_DAYS = 30;

    public function __construct(private FinanceApi $finance, private IDBConnection $db) {}

    /** @return array<string,mixed> */
    public function candidates(int $spaceId, int $transactionId, int $windowDays = 3, int $afterId = 0): array {
        $this->authorize($spaceId);
        if ($windowDays < 0 || $windowDays > self::MAX_WINDOW_DAYS) {
            throw new DomainException('invalid_reconciliation_window');
        }
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        $evidence = $this->pending($spaceId, $transactionId);
        $accountId = (int)$evidence['bank_account_id'];
        $amount = (int)$evidence['amount_minor'];
        $currency = $this->finance->requireBankAccount($spaceId, self::MODULE, $accountId);
        $booking = (string)$evidence['booking_date'];
        if (!preg_match('/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $booking)) {
            throw new DomainException('invalid_date');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $booking, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $booking) {
            throw new DomainException('invalid_date');
        }
        // Compare DateTime objects: a five-digit year would not sort lexically.
        $through = min(new \DateTimeImmutable('9999-12-31', new \DateTimeZone('UTC')),
            $date->modify('+' . $windowDays . ' days'))->format('Y-m-d');
        $from = max(new \DateTimeImmutable('0001-01-01', new \DateTimeZone('UTC')),
            $date->modify('-' . $windowDays . ' days'))->format('Y-m-d');

        // The classification is about the entire filtered result, even on a
        // final page containing only one row. A bounded two-row probe suffices.
        $probe = $this->eligible($spaceId, $accountId, $amount, $currency, $from, $through);
        $first = $probe->selectDistinct('j.id')->orderBy('j.id', 'ASC')
            ->setMaxResults(2)->executeQuery()->fetchAllAssociative();
        $classification = match (count($first)) {
            0 => 'no_candidate',
            1 => 'unique',
            default => 'ambiguous',
        };

        $q = $this->eligible($spaceId, $accountId, $amount, $currency, $from, $through);
        $raw = $q->selectDistinct('j.id')->addSelect('j.effective_on', 'j.memo')
            ->andWhere($q->expr()->gt('j.id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('j.id', 'ASC')->setMaxResults(self::PAGE_SIZE + 1)
            ->executeQuery()->fetchAllAssociative();
        $more = count($raw) > self::PAGE_SIZE;
        $rows = [];
        foreach (array_slice($raw, 0, self::PAGE_SIZE) as $row) {
            $rows[] = [
                'journalId' => (int)$row['id'],
                'effectiveOn' => (string)$row['effective_on'],
                'description' => (string)$row['memo'],
                'accountId' => $accountId,
                'amountMinor' => $amount,
                'currency' => $currency,
                'reasons' => [
                    'same_account', 'exact_signed_amount',
                    $row['effective_on'] === $booking ? 'same_booking_date' : 'within_date_window',
                ],
            ];
        }

        // Suggestions never reserve or claim anything. Recheck the selected
        // statement and actor after the read; match independently revalidates
        // the journal under its transactional claim and uniqueness rules.
        $this->authorize($spaceId);
        $current = $this->pending($spaceId, $transactionId);
        if ($current !== $evidence
            || $currency !== $this->finance->requireBankAccount($spaceId, self::MODULE, $accountId)) {
            throw new DomainException('reconciliation_evidence_changed');
        }
        // Candidate eligibility can change between the probe and page, e.g.
        // another actor claims a journal. Report an incomplete lookup rather
        // than asserting a false unique/no-candidate classification.
        $consistent = $afterId > 0 || (count($rows) === 0 && $classification === 'no_candidate')
            || (count($rows) === 1 && $classification === 'unique')
            || (count($rows) >= 2 && $classification === 'ambiguous');
        return [
            'contractVersion' => 1,
            'transactionId' => $transactionId,
            'evidence' => [
                'accountId' => $accountId,
                'amountMinor' => $amount,
                'currency' => $currency,
                'bookingDate' => $booking,
                'sourceReference' => (string)$evidence['external_id'],
                // Ledger external_key is an idempotency key, not a bank
                // source reference. Do not equate unrelated namespaces.
                'sourceReferenceComparison' => 'unavailable',
                'windowDays' => $windowDays,
                'from' => $from,
                'through' => $through,
            ],
            'status' => $more || !$consistent ? 'incomplete_lookup' : $classification,
            'candidateStatus' => $consistent ? $classification : 'unconfirmed',
            'candidates' => $rows,
            'nextAfterId' => $more ? $rows[count($rows) - 1]['journalId'] : null,
        ];
    }

    private function authorize(int $spaceId): void {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'bank.reconcile');
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'ledger.read');
        // A candidate must be visible to an actor allowed to claim it.
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'ledger.post');
    }

    private function pending(int $spaceId, int $transactionId): array {
        $q = $this->db->getQueryBuilder();
        $q->select('bank_account_id', 'external_id', 'booking_date', 'amount_minor', 'status')
            ->from('lfb_transactions')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));
        $row = $q->executeQuery()->fetchAssociative();
        if ($row === false) {
            throw new DomainException('bank_transaction_not_found');
        }
        if ($row['status'] !== 'pending') {
            throw new DomainException('bank_transaction_matched');
        }
        return $row;
    }

    /** Mirrors the scoped posting and journal-claim predicates without locks or mutations. */
    private function eligible(int $spaceId, int $accountId, int $amount, string $currency,
        string $from, string $through): IQueryBuilder {
        $q = $this->db->getQueryBuilder();
        $module = $q->createNamedParameter(self::MODULE);
        return $q->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id AND j.space_id = p.space_id')
            ->leftJoin('j', 'lf_journals', 'r', 'r.reversed_journal_id = j.id AND r.space_id = j.space_id')
            ->leftJoin('j', 'lf_journal_claims', 'c',
                'c.journal_id = j.id AND c.space_id = j.space_id AND c.module_id = ' . $module)
            ->leftJoin('p', 'lfb_transactions', 'm',
                'm.matched_journal_id = p.journal_id AND m.space_id = p.space_id AND m.bank_account_id = p.account_id')
            ->where($q->expr()->eq('p.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.account_id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.amount_minor', $q->createNamedParameter($amount, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.currency', $q->createNamedParameter($currency)))
            ->andWhere($q->expr()->eq('j.status', $q->createNamedParameter('posted')))
            ->andWhere($q->expr()->isNull('j.reversed_journal_id'))
            ->andWhere($q->expr()->isNull('r.id'))
            ->andWhere($q->expr()->isNull('c.id'))
            ->andWhere($q->expr()->isNull('m.id'))
            ->andWhere($q->expr()->gte('j.effective_on', $q->createNamedParameter($from)))
            ->andWhere($q->expr()->lte('j.effective_on', $q->createNamedParameter($through)));
    }
}
