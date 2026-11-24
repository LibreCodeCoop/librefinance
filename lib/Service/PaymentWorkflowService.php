<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCA\LibreFinance\Domain\Invoicing\InvoiceService;
use OCA\LibreFinance\Domain\Obligations\ObligationService;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * One user payment action, one financial journal and one linked settlement.
 *
 * A double-entry cash journal and its linked invoice allocation/obligation
 * settlement are committed together, or neither exists. No external bank
 * transfer or fiscal issuance happens here.
 */
final class PaymentWorkflowService {
    private const MAX_MINOR = 9000000000000;

    public function __construct(
        private FinanceApi $finance,
        private LedgerService $ledger,
        private InvoiceService $invoices,
        private ObligationService $obligations,
        private IDBConnection $db,
    ) {}

    /** @return array<string,int|string|bool> */
    public function forInvoice(int $spaceId, int $invoiceId,
        int $bankAccountId, int $counterAccountId, int $amountMinor,
        string $paidOn, string $operationKey): array {
        return $this->pay('invoice', $spaceId, $invoiceId, $bankAccountId,
            $counterAccountId, $amountMinor, $paidOn, $operationKey);
    }

    /** @return array<string,int|string|bool> */
    public function forObligation(int $spaceId, int $obligationId,
        int $bankAccountId, int $counterAccountId, int $amountMinor,
        string $paidOn, string $operationKey): array {
        return $this->pay('obligation', $spaceId, $obligationId, $bankAccountId,
            $counterAccountId, $amountMinor, $paidOn, $operationKey);
    }

    /**
     * Link a prior posted cash journal to a standalone obligation without
     * generating a new journal. Invoice-born obligations must use their
     * originating commercial document instead.
     *
     * @return array<string,int|string|bool>
     */
    public function linkExistingObligation(int $spaceId, int $obligationId,
        int $bankAccountId, int $journalId, int $amountMinor,
        string $paidOn, string $operationKey): array {
        if ($obligationId < 1 || $bankAccountId < 1 || $journalId < 1
            || $amountMinor < 1 || $amountMinor > self::MAX_MINOR
            || !preg_match('/^[A-Za-z0-9_.:-]{12,55}$/D', $operationKey)) {
            throw new DomainException('invalid_payment');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if (!$date || $date->format('Y-m-d') !== $paidOn) {
            throw new DomainException('invalid_date');
        }
        $this->finance->requireModuleCapability($spaceId, 'librefinance_obligations', 'obligations.manage');
        $this->db->beginTransaction();
        try {
            $detail = $this->obligations->show($spaceId, $obligationId);
            if ($detail['obligation']['linkedInvoiceId'] !== null) {
                throw new DomainException('invoice_payment_via_allocation');
            }
            $this->finance->requireBankAccount($spaceId,
                'librefinance_obligations', $bankAccountId);
            $q = $this->db->getQueryBuilder();
            $q->select('effective_on')->from('lf_journals')
                ->where($q->expr()->eq('space_id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id',
                    $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
            $effectiveOn = $q->executeQuery()->fetchOne();
            if ($effectiveOn === false) {
                throw new DomainException('journal_not_found');
            }
            if ((string)$effectiveOn !== $paidOn) {
                throw new DomainException('invalid_payment_date');
            }
            $payment = $this->obligations->settleWithJournal($spaceId, $obligationId,
                $amountMinor, $paidOn, 'lfjournal:' . $operationKey,
                $journalId, $bankAccountId);
            $this->db->commit();
            return ['replayed' => (bool)($payment['replayed'] ?? false),
                'journalId' => $journalId, 'id' => (int)$payment['id'],
                'sourceId' => $obligationId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,int|string|bool> */
    private function pay(string $source, int $spaceId, int $sourceId,
        int $bankId, int $counterId, int $amount, string $paidOn, string $key): array {
        if ($sourceId < 1 || $bankId < 1 || $counterId < 1 || $bankId === $counterId
            || $amount < 1 || $amount > self::MAX_MINOR) {
            throw new DomainException('invalid_payment');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if (!$date || $date->format('Y-m-d') !== $paidOn) {
            throw new DomainException('invalid_date');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{12,55}$/D', $key)) {
            throw new DomainException('invalid_idempotency_key');
        }

        $module = $source === 'invoice' ? 'librefinance_invoicing' : 'librefinance_obligations';
        $this->finance->requireModuleCapability($spaceId, $module,
            $source === 'invoice' ? 'invoice.manage' : 'obligations.manage');
        $this->db->beginTransaction();
        try {
            $detail = $source === 'invoice'
                ? $this->invoices->show($spaceId, $sourceId)
                : $this->obligations->show($spaceId, $sourceId);
            if ($source === 'invoice') {
                $invoice = $detail['invoice'];
                if (!in_array($invoice['status'], ['issued', 'partially_paid', 'paid'], true)) {
                    throw new DomainException('invoice_not_issued');
                }
                if ($detail['obligationId'] === null) {
                    throw new DomainException('invoice_obligation_unlinked');
                }
                // The current two-entry cash journal cannot split VAT or tax
                // withholdings. Crediting the gross amount to revenue (or
                // debiting expense) would misstate tax liabilities. Require
                // separately reviewed accounting entries for taxed documents.
                if ((int)$invoice['tax_minor'] !== 0
                    || (int)$invoice['withheld_minor'] !== 0) {
                    throw new DomainException('invoice_tax_allocation_required');
                }
                $kind = $invoice['kind'] === 'invoice' ? 'receivable' : 'payable';
                $description = ($kind === 'receivable' ? 'Recebimento da venda ' : 'Pagamento da compra ')
                    . (string)$invoice['number'];
            } else {
                $obligation = $detail['obligation'];
                if ($obligation['linkedInvoiceId'] !== null) {
                    throw new DomainException('invoice_payment_via_allocation');
                }
                $kind = (string)$obligation['kind'];
                $description = ($kind === 'receivable' ? 'Recebimento do compromisso ' : 'Pagamento do compromisso ')
                    . (string)$obligation['reference'];
            }
            if (!in_array($kind, ['payable', 'receivable'], true)) {
                throw new DomainException('invalid_payment');
            }
            $bankCurrency = $this->finance->requireBankAccount($spaceId, $module, $bankId);
            $account = $this->counterAccount($spaceId, $counterId);
            if ($account === null || !$account['active'] || $account['is_bank']
                || $account['currency'] !== $bankCurrency) {
                throw new DomainException('payment_account_not_found');
            }
            // Issuing a commercial document is operational in LibreFinance:
            // it does NOT yet book an accrual journal. Automatic cash posting
            // uses revenue/expense once, rather than crediting an unrecognized
            // receivable/debiting an unrecognized payable balance. For existing
            // accrual postings, use the explicit link-existing-journal flow.
            if ($source === 'invoice'
                && $account['class'] !== ($kind === 'receivable' ? 'revenue' : 'expense')) {
                throw new DomainException('invalid_payment_counterpart');
            }
            $externalKey = 'lfpay:' . $key;
            $bankIn = $kind === 'receivable';
            $debit = $bankIn ? $bankId : $counterId;
            $credit = $bankIn ? $counterId : $bankId;
            $memo = mb_substr($description, 0, 255);
            $journal = $this->ledger->createJournal($spaceId, $debit, $credit,
                $amount, $paidOn, $memo, $externalKey);
            $journalId = (int)$journal['id'];

            if (($journal['replayed'] ?? false) === true) {
                $prior = $this->existingLinkedPayment($source, $spaceId, $sourceId,
                    $bankId, $journalId, $amount);
                if ($prior === null) {
                    throw new DomainException('idempotency_conflict');
                }
                $this->db->commit();
                return ['replayed' => true, 'journalId' => $journalId,
                    'id' => $prior, 'sourceId' => $sourceId];
            }

            if ($source === 'invoice') {
                // A paid document accepts only an exact retry of a recorded
                // payment; any new posting must roll back and cannot overpay.
                if ($invoice['status'] === 'paid') {
                    throw new DomainException('invoice_overpayment');
                }
                $payment = $this->invoices->allocate($spaceId, $sourceId,
                    $bankId, $journalId, $amount);
            } else {
                $payment = $this->obligations->settleWithJournal($spaceId, $sourceId,
                    $amount, $paidOn, 'lfpay:' . $key, $journalId, $bankId);
            }
            $this->db->commit();
            return ['replayed' => false, 'journalId' => $journalId,
                'id' => (int)$payment['id'], 'sourceId' => $sourceId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{class:string,currency:string,active:bool,is_bank:bool}|null */
    private function counterAccount(int $spaceId, int $id): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('class', 'currency', 'active', 'is_bank')->from('lf_accounts')
            ->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $row = $q->executeQuery()->fetchAssociative();
        if ($row === false) return null;
        return ['class' => (string)$row['class'], 'currency' => (string)$row['currency'],
            'active' => (bool)$row['active'], 'is_bank' => (bool)$row['is_bank']];
    }

    private function existingLinkedPayment(string $source, int $spaceId, int $sourceId,
        int $bankId, int $journalId, int $amount): ?int {
        $q = $this->db->getQueryBuilder();
        if ($source === 'invoice') {
            $q->select('id')->from('lfi_allocations')
                ->where($q->expr()->eq('invoice_id',
                    $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('bank_account_id',
                    $q->createNamedParameter($bankId, IQueryBuilder::PARAM_INT)));
        } else {
            $q->select('id')->from('lfob_settlements')
                ->where($q->expr()->eq('obligation_id',
                    $q->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));
        }
        $q->andWhere($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('journal_id',
                $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('amount_minor',
                $q->createNamedParameter($amount, IQueryBuilder::PARAM_INT)));
        $found = $q->executeQuery()->fetchOne();
        return $found === false ? null : (int)$found;
    }
}
