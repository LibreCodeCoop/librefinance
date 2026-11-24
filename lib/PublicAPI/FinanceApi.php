<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\PublicAPI;

use DomainException;
use OCA\LibreFinance\Service\ModuleService;
use OCA\LibreFinance\Service\JournalClaimService;
use OCA\LibreFinance\Service\CatalogService;
use OCA\LibreFinance\Service\CategoryService;
use OCA\LibreFinance\Service\CompanyService;
use OCA\LibreFinance\Service\LedgerService;
use OCA\LibreFinance\Service\AccountReferenceGuard;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Finance API v1 for Nextcloud extension apps.
 * Any request here is authorized against the actual space and installed module.
 */
final class FinanceApi {
    public function __construct(private ModuleService $modules, private IDBConnection $db,
        private JournalClaimService $claims, private CatalogService $catalog,
        private CategoryService $categories, private CompanyService $companies,
        private LedgerService $ledger, private AccountReferenceGuard $accountGuard) {}

    public function requireModule(int $spaceId, string $moduleId, string $role = 'viewer'): void {
        $this->modules->requireEnabled($spaceId, $moduleId, $role);
    }

    /** Check an explicit financial operation as well as module availability and policy. */
    public function requireModuleCapability(int $spaceId, string $moduleId, string $capability): void {
        $this->modules->requireEnabledForCapability($spaceId, $moduleId, $capability);
    }

    /** A durable core-owned reference to a posted journal, inside the caller's transaction. */
    public function claimJournal(int $spaceId, string $moduleId, int $journalId,
        string $reference): void {
        $this->requireModuleCapability($spaceId, $moduleId, 'ledger.post');
        $this->claims->claim($spaceId, $journalId, $moduleId, $reference);
    }

    /** Authorized, Nextcloud-native import targets for migration adapters. */
    /** Check a legal-identity collision in ONE authorized finance space. */
    public function importCompanyTaxIdInUse(int $spaceId, string $moduleId,
        string $country, string $taxId): bool {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.read');
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('lf_companies')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('country',
                $q->createNamedParameter($country)))
            ->andWhere($q->expr()->eq('tax_id',
                $q->createNamedParameter($taxId)));
        return $q->executeQuery()->fetchOne() !== false;
    }

    /** Create a legal entity from an explicitly approved, normalized migration batch. */
    public function importCompany(int $spaceId, string $moduleId, string $legalName,
        string $country, ?string $taxId, ?string $tradeName,
        ?string $municipalRegistration, ?string $stateRegistration): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.apply');
        return $this->companies->create($spaceId, $legalName, $country,
            $taxId, $tradeName, $municipalRegistration, $stateRegistration);
    }

    /**
     * Import a cash/bank asset account into the authoritative ledger chart,
     * but NEVER book the source opening/current snapshot as a journal entry.
     * Historical postings and opening cutover must be reconciled separately.
     */
    public function importLiquidAccount(int $spaceId, string $moduleId,
        string $code, string $name, string $currency, bool $active): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.apply');
        if ($currency !== $this->currency($spaceId, $moduleId)) {
            throw new DomainException('import_currency_mismatch');
        }
        return $this->ledger->createAccount($spaceId, $code, $name, 'asset', true, $active);
    }

    /** Existing account codes include disabled accounts: never claim availability. */
    public function importLiquidAccountCodes(int $spaceId, string $moduleId,
        array $codes): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.read');
        $codes = array_values(array_unique($codes));
        if ($codes === []) { return []; }
        if (count($codes) > 100) { throw new DomainException('invalid_import_batch'); }
        $q = $this->db->getQueryBuilder();
        $q->select('code', 'id')->from('lf_accounts')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('code',
                $q->createNamedParameter($codes, IQueryBuilder::PARAM_STR_ARRAY)));
        $found = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $account) {
            $found[(string)$account['code']] = (int)$account['id'];
        }
        return $found;
    }

    public function importContact(int $spaceId, string $moduleId, string $name,
        string $kind, ?string $email, ?string $taxId): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.apply');
        return $this->catalog->createContact($spaceId, $name, $kind, $email, $taxId);
    }

    public function importCategory(int $spaceId, string $moduleId, string $code,
        string $name, string $kind, ?int $parentId, ?int $accountId): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.apply');
        return $this->categories->create($spaceId, $code, $name, $kind, $parentId, $accountId);
    }

    public function accountIdByCode(int $spaceId, string $moduleId, string $code): int {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.read');
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'active')->from('lf_accounts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('code', $q->createNamedParameter(strtoupper($code))));
        $account = $q->executeQuery()->fetchAssociative();
        if ($account === false || !(bool)$account['active']) {
            throw new DomainException('account_not_found');
        }
        return (int)$account['id'];
    }

    /**
     * Fetch verified account codes for an authorized import in one scoped read.
     * @param list<string> $codes
     * @return array<string,array{id:int,class:string}>
     */
    public function importAccountCodes(int $spaceId, string $moduleId, array $codes): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.read');
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }
        if (count($codes) > 100) {
            throw new DomainException('invalid_import_batch');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'code', 'class', 'active')->from('lf_accounts')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('code',
                $q->createNamedParameter($codes, IQueryBuilder::PARAM_STR_ARRAY)));
        $result = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $account) {
            if ((bool)$account['active']) {
                $result[$account['code']] = [
                    'id' => (int)$account['id'],
                    'class' => (string)$account['class'],
                ];
            }
        }
        return $result;
    }

    /**
     * Prevent preview from claiming a category code is available when already
     * owned by an unrelated imported/manual category in this finance space.
     * @param list<string> $codes
     * @return array<string,int>
     */
    public function importCategoryCodes(int $spaceId, string $moduleId, array $codes): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'imports.read');
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }
        if (count($codes) > 100) {
            throw new DomainException('invalid_import_batch');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('code', 'id')->from('lf_categories')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('code',
                $q->createNamedParameter($codes, IQueryBuilder::PARAM_STR_ARRAY)));
        $codesFound = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $category) {
            $codesFound[$category['code']] = (int)$category['id'];
        }
        return $codesFound;
    }

    public function currency(int $spaceId, string $moduleId): string {
        $this->requireModuleCapability($spaceId, $moduleId, 'workspace.read');
        $q = $this->db->getQueryBuilder();
        $q->select('currency')->from('lf_spaces')
            ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $value = $q->executeQuery()->fetchOne();
        if ($value === false) {
            throw new DomainException('space_not_found');
        }
        return (string)$value;
    }

    /**
     * Validate the issuer against an active legal company inside the authorized
     * space. A CNPJ typed in a client form is never sufficient authority.
     */
    public function requireCompanyCnpj(int $spaceId, string $moduleId,
        int $companyId, string $issuerCnpj): void {
        $this->requireModuleCapability($spaceId, $moduleId, 'companies.resolve');
        if ($companyId < 1 || !preg_match('/^[0-9]{14}$/D', $issuerCnpj)) {
            throw new DomainException('invalid_company_issuer');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('tax_id', 'active', 'country')->from('lf_companies')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
        $row = $q->executeQuery()->fetchAssociative();
        if (!$row || !(bool)$row['active']) {
            throw new DomainException('company_not_found');
        }
        if ($row['country'] !== 'BR' || $row['tax_id'] !== $issuerCnpj) {
            throw new DomainException('issuer_company_mismatch');
        }
    }

    public function requireActiveContact(int $spaceId, string $moduleId, int $contactId): void {
        $this->requireModuleCapability($spaceId, $moduleId, 'contacts.read');
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'active')->from('lf_contacts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)));
        $contact = $q->executeQuery()->fetchAssociative();
        if ($contact === false || !(bool)$contact['active']) {
            throw new DomainException('contact_not_found');
        }
    }

    /**
     * Resolve a bounded set of active bank-account IDs for cross-app imports
     * in ONE authorized, space-filtered query. Never returns account numbers.
     *
     * @param list<int> $ids
     * @return array<int,string> account ID => currency
     */
    public function requireBankImportAccounts(int $spaceId, string $moduleId, array $ids): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'bank.manage');
        $ids = array_values(array_unique($ids));
        if ($ids === [] || count($ids) > 100) {
            throw new DomainException('invalid_import_batch');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new DomainException('bank_account_not_found');
            }
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'currency', 'active', 'is_bank')->from('lf_accounts')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('id',
                $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $accounts = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            if (!(bool)$row['active'] || !(bool)$row['is_bank']) {
                throw new DomainException('bank_account_not_found');
            }
            $accounts[(int)$row['id']] = (string)$row['currency'];
        }
        if (count($accounts) !== count($ids)) {
            throw new DomainException('bank_account_not_found');
        }
        return $accounts;
    }

    public function requireBankAccount(int $spaceId, string $moduleId, int $accountId): string {
        $this->requireModuleCapability($spaceId, $moduleId, 'bank.use');
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'currency', 'active', 'is_bank')->from('lf_accounts')
            ->where($q->expr()->eq('id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $account = $q->executeQuery()->fetchAssociative();
        if (!$account || !(bool)$account['active'] || !(bool)$account['is_bank']) {
            throw new DomainException('bank_account_not_found');
        }
        return (string)$account['currency'];
    }

    /**
     * Reserve active bank accounts against concurrent deletion. Call only
     * inside the caller's open transaction, before creating ANY reference.
     * All IDs are ordered to prevent cross-module deadlocks.
     *
     * @param list<int> $accountIds
     * @return array<int,string> account ID => currency
     */
    public function reserveBankAccountsForWrite(int $spaceId, string $moduleId,
        array $accountIds): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'bank.use');
        if (!$this->db->inTransaction()) {
            throw new \LogicException('bank_reference_guard_requires_transaction');
        }
        if ($accountIds === [] || count($accountIds) > 100) {
            throw new DomainException('invalid_import_batch');
        }
        $ids = array_values(array_unique($accountIds));
        sort($ids, SORT_NUMERIC);
        $result = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new DomainException('bank_account_not_found');
            }
            $result[$id] = $this->accountGuard->reserve($spaceId, $id, true);
        }
        return $result;
    }

    public function journalContainsBankPosting(int $spaceId, string $moduleId,
        int $accountId, int $journalId, int $amountMinor): void {
        $this->requireBankAccount($spaceId, $moduleId, $accountId);
        $q = $this->db->getQueryBuilder();
        $q->select('p.id')->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id')
            ->where($q->expr()->eq('p.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('j.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.account_id', $q->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.journal_id', $q->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('p.amount_minor', $q->createNamedParameter($amountMinor, IQueryBuilder::PARAM_INT)));
        if ($q->executeQuery()->fetchOne() === false) {
            throw new DomainException('journal_posting_not_found');
        }
    }

    /** Returns net revenue and expenses for a period. Amounts use integer minor units. */
    public function profitLoss(int $spaceId, string $moduleId, string $from, string $through): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'reports.read');
        self::date($from);
        self::date($through);
        if ($through < $from) {
            throw new DomainException('invalid_period');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('a.class')
            ->selectAlias($q->createFunction('SUM(p.amount_minor)'), 'total_minor')
            ->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id')
            ->innerJoin('p', 'lf_accounts', 'a', 'a.id = p.account_id')
            ->where($q->expr()->eq('p.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('j.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('a.space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gte('j.effective_on', $q->createNamedParameter($from)))
            ->andWhere($q->expr()->lte('j.effective_on', $q->createNamedParameter($through)))
            ->andWhere($q->expr()->in('a.class', $q->createNamedParameter(['revenue', 'expense'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->groupBy('a.class');
        $totals = ['revenue' => 0, 'expense' => 0];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $totals[(string)$row['class']] = (int)$row['total_minor'];
        }
        return ['from' => $from, 'through' => $through,
            'revenueMinor' => -$totals['revenue'],
            'expenseMinor' => $totals['expense'],
            'resultMinor' => -$totals['revenue'] - $totals['expense'],
        ];
    }

    /**
     * Realized cash-flow by month, across all bank/cash accounts of ONE space.
     * Bank-to-bank transfers contribute net zero rather than inflating income
     * and expenses. This is not a forecast and not an IAS 7 cash-flow statement.
     * Both queries are bounded aggregate scans, never per-journal PHP queries.
     */
    public function cashFlow(int $spaceId, string $moduleId,
        string $from, string $through): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'reports.read');
        self::date($from);
        self::date($through);
        if ($through < $from) {
            throw new DomainException('invalid_period');
        }
        $q = $this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('SUM(p.amount_minor)'), 'opening_minor')
            ->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_accounts', 'a', 'a.id = p.account_id')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id')
            ->where($q->expr()->eq('p.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('a.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('j.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('a.is_bank',
                $q->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->lt('j.effective_on',
                $q->createNamedParameter($from)));
        $opening = (int)($q->executeQuery()->fetchOne() ?: 0);

        $q = $this->db->getQueryBuilder();
        $monthExpr = 'SUBSTR(j.effective_on, 1, 7)';
        $q->selectAlias($q->createFunction($monthExpr), 'month')
            ->selectAlias($q->createFunction('SUM(p.amount_minor)'), 'net_minor')
            ->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_accounts', 'a', 'a.id = p.account_id')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id')
            ->where($q->expr()->eq('p.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('a.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('j.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('a.is_bank',
                $q->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gte('j.effective_on', $q->createNamedParameter($from)))
            ->andWhere($q->expr()->lte('j.effective_on', $q->createNamedParameter($through)))
            ->groupBy('month')
            ->orderBy('month', 'ASC');
        $total = 0;
        $months = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $net = (int)$row['net_minor'];
            if (($net > 0 && $total > PHP_INT_MAX - $net)
                || ($net < 0 && $total < PHP_INT_MIN - $net)) {
                throw new DomainException('money_overflow');
            }
            $total += $net;
            $months[] = ['month' => (string)$row['month'], 'netMinor' => $net];
        }
        if (($total > 0 && $opening > PHP_INT_MAX - $total)
            || ($total < 0 && $opening < PHP_INT_MIN - $total)) {
            throw new DomainException('money_overflow');
        }
        return [
            'from' => $from, 'through' => $through,
            'currency' => $this->currency($spaceId, $moduleId),
            'openingMinor' => $opening,
            'months' => $months,
            'netMinor' => $total,
            'closingMinor' => $opening + $total,
            'basis' => 'realized_bank_postings',
            'isForecast' => false,
        ];
    }

    /** Paginated chart of account balances as of a date. Empty accounts are included. */
    public function trialBalance(int $spaceId, string $moduleId, string $through,
        int $afterId = 0, ?string $accountClass = null): array {
        $this->requireModuleCapability($spaceId, $moduleId, 'reports.read');
        self::date($through);
        if ($accountClass !== null && !in_array($accountClass,
            ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
            throw new DomainException('invalid_account_class');
        }
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'code', 'name', 'class', 'currency')
            ->from('lf_accounts')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(51);
        if ($accountClass !== null) {
            $q->andWhere($q->expr()->eq('class', $q->createNamedParameter($accountClass)));
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        if ($rows === []) {
            return ['through' => $through, 'accounts' => [], 'nextAfterId' => null];
        }
        $ids = array_map(static fn(array $v): int => (int)$v['id'], $rows);
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.account_id')
            ->selectAlias($qb->createFunction('SUM(p.amount_minor)'), 'balance_minor')
            ->from('lf_postings', 'p')
            ->innerJoin('p', 'lf_journals', 'j', 'j.id = p.journal_id')
            ->where($qb->expr()->eq('p.space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('j.space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('j.effective_on', $qb->createNamedParameter($through)))
            ->andWhere($qb->expr()->in('p.account_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('p.account_id');
        $balances = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $v) {
            $balances[(int)$v['account_id']] = (int)$v['balance_minor'];
        }
        foreach ($rows as &$account) {
            $account['id'] = (int)$account['id'];
            $account['balanceMinor'] = $balances[$account['id']] ?? 0;
        }
        unset($account);
        return ['through' => $through, 'accounts' => $rows,
            'nextAfterId' => $more ? (int)end($rows)['id'] : null];
    }

    private static function date(string $value): void {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('invalid_date');
        }
    }
}
