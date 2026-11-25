<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Mounted only in a disposable Nextcloud upgrade test.
$integration = '/var/www/html/custom_apps/librefinance/tests/integration';
require_once $integration . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

if (getenv('LF_SYNTHETIC_SAAS_UPGRADE') !== 'yes') {
    throw new RuntimeException('Synthetic upgrade opt-in required');
}
$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$date = '2026-11-20';
$bank = 'librefinance_banking';
$invoicing = 'librefinance_invoicing';
$obligations = 'librefinance_obligations';
$reports = 'librefinance_reports';
$report = ['name' => 'Synthetic asset balances', 'kind' => 'trial_balance',
    'through' => '2026-11-30', 'columns' => ['name', 'balanceMinor'], 'accountClass' => 'asset'];
// The historical app stores private logos in AppData. It has no document-attachment
// storage contract. Check the supported binary via HTTP since TestHarness expects JSON.
$logoSha256 = 'a39ef054ed053763632a8b53c28255db11c7d5fdc1b8615a791ec715e86bfca7';
$checkLogo = static function (string $path) use ($t, $b, $logoSha256): void {
    $url = rtrim((string)getenv('LF_BASE_URL'), '/'); // Validated by TestHarness.
    $curl = curl_init($url . $path);
    curl_setopt_array($curl, [
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $b . ':' . (string)getenv('LF_TEST_USER_PASS'),
        CURLOPT_HTTPHEADER => ['OCS-APIREQUEST: true', 'Accept: image/png'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $mime = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $t->record('legacy viewer reads exact private company logo bytes',
        $status === 200 && $mime === 'image/png' && is_string($raw) && hash('sha256', $raw) === $logoSha256,
        'HTTP ' . $status);
};

$action = getenv('LF_UPGRADE_ACTION');
if ($action === 'seed') {
    $logoFixture = file_get_contents($integration . '/fixtures/logo-valid.b64');
    if ($logoFixture === false) {
        throw new RuntimeException('Missing synthetic logo fixture');
    }
    $space = $t->check('create original legacy mixed book', $a, 'POST', '/spaces',
        ['name' => 'Synthetic historical mixed book', 'kind' => 'organization', 'currency' => 'BRL'], 201);
    $id = (int)$space['id'];
    $base = '/spaces/' . $id;
    $first = ['legalName'=>'Synthetic Company One','country'=>'PT','taxId'=>'SYNTHETIC1'];
    $second = ['legalName'=>'Synthetic Company Two','country'=>'PT','taxId'=>'SYNTHETIC2'];
    $active = $t->check('legacy first legal identity', $a, 'POST', $base . '/companies', $first, 201);
    $inactive = $t->check('legacy second legal identity', $a, 'POST', $base . '/companies', $second, 201);
    $t->check('historical company deactivation without deletion', $a, 'PUT',
        $base . '/companies/' . $inactive['id'], [...$second,'active'=>false], 200);
    $logoPath = $base . '/companies/' . $active['id'] . '/logo';
    $t->check('store synthetic private company logo', $a, 'POST', $logoPath,
        ['imageBase64' => trim($logoFixture)], 200,
        static fn(array $v): bool => ($v['companyId'] ?? null) === (int)$active['id']
            && ($v['logoVersion'] ?? 0) > 0);
    $accountSet = $t->check('historical account chart', $a, 'GET', $base . '/accounts', null, 200);
    $ids = array_column($accountSet['accounts'],'id','code');
    $bankId = (int)$ids['1000'];
    $receipt = $t->check('historic receipt', $a, 'POST', $base . '/journals',
        ['debitId'=>$ids['1000'],'creditId'=>$ids['4000'],'amountMinor'=>12345,
            'date'=>$date,'memo'=>'Synthetic receipt','externalKey'=>'upgrade-saas-receipt'], 201);
    $receiptId = (int)$receipt['id'];
    $expense = $t->check('historic expense', $a, 'POST', $base . '/journals',
        ['debitId'=>$ids['5000'],'creditId'=>$ids['1000'],'amountMinor'=>345,
            'date'=>'2026-11-20','memo'=>'Synthetic expense','externalKey'=>'upgrade-saas-expense'], 201);
    $t->check('historic reversal', $a, 'POST', $base . '/journals/' . $expense['id'] . '/reverse', null, 200);

    // The match and allocation both reference the original receipt without posting again.
    $t->checkModule('import historic matched and pending bank evidence', $a, $bank, 'POST',
        $base . '/imports', ['bankAccountId' => $bankId, 'csv' => "id,date,amount,description\n"
            . "upgrade-receipt,$date,123.45,Synthetic receipt\n"
            . "upgrade-pending,$date,-7.89,Synthetic pending debit\n"], 201,
        static fn(array $v): bool => ($v['imported'] ?? null) === 2 && ($v['skipped'] ?? null) === 0);
    $statement = $t->checkModule('read imported bank rows', $a, $bank, 'GET', $base . '/transactions',
        null, 200, static fn(array $v): bool => count($v['transactions'] ?? []) === 2);
    $bankRows = array_column($statement['transactions'], 'id', 'external_id');
    $t->checkModule('match original receipt to its statement row', $a, $bank, 'POST',
        $base . '/transactions/' . $bankRows['upgrade-receipt'] . '/match',
        ['journalId' => $receiptId], 200,
        static fn(array $v): bool => ($v['status'] ?? null) === 'matched'
            && ($v['journalId'] ?? null) === $receiptId);

    $contact = $t->check('create historical invoice customer', $a, 'POST', $base . '/contacts',
        ['name' => 'Synthetic historical customer', 'kind' => 'both'], 201);
    $vat = $t->checkModule('create historical tax rule', $a, $invoicing, 'POST', $base . '/tax-rules',
        ['code' => 'UPGRADE_VAT', 'label' => 'Synthetic VAT', 'country' => 'GB', 'kind' => 'vat',
            'rateBps' => 2000, 'inclusive' => false, 'validFrom' => '2026-01-01',
            'validThrough' => '2026-12-31', 'sourceRef' => 'synthetic:upgrade-vat'], 201);
    $invoice = $t->checkModule('create exact historical invoice', $a, $invoicing, 'POST',
        $base . '/invoices', ['number' => 'UPGRADE-INV', 'kind' => 'invoice',
            'contactId' => (int)$contact['id'], 'issueOn' => $date, 'dueOn' => '2026-12-20', 'country' => 'GB',
            'lines' => [
                ['description' => 'Synthetic service', 'quantity' => 1,
                    'unitPriceMinor' => 10000, 'taxRuleId' => (int)$vat['id']],
                ['description' => 'Synthetic untaxed item', 'quantity' => 1, 'unitPriceMinor' => 345],
            ]], 201,
        static fn(array $v): bool => ($v['totalMinor'] ?? null) === 12345 && ($v['taxMinor'] ?? null) === 2000);
    $invoicePath = $base . '/invoices/' . $invoice['id'];
    $t->checkModule('issue invoice and create its receivable', $a, $invoicing, 'POST', $invoicePath . '/issue',
        null, 200, static fn(array $v): bool => ($v['status'] ?? null) === 'issued');
    $t->checkModule('allocate the original receipt and settle its linked receivable', $a, $invoicing, 'POST',
        $invoicePath . '/allocations', ['journalId' => $receiptId,
            'bankAccountId' => $bankId, 'amountMinor' => 12345], 201,
        static fn(array $v): bool => ($v['status'] ?? null) === 'paid' && ($v['paidMinor'] ?? null) === 12345);

    $t->checkModule('save historical report with ordered columns and class filter', $a, $reports, 'POST',
        $base . '/saved-reports', $report, 201);
    $archive = $t->checkModule('save historical report for archive', $a, $reports, 'POST',
        $base . '/saved-reports', ['name' => 'Synthetic archived cash flow', 'kind' => 'cash_flow',
            'from' => '2026-11-01', 'through' => '2026-11-30'], 201);
    $t->checkModule('archive historical report', $a, $reports, 'DELETE',
        $base . '/saved-reports/' . $archive['id'], null, 200,
        static fn(array $v): bool => ($v['archived'] ?? null) === true);

    $t->check('historical explicit viewer membership', $a, 'POST', $base . '/members',
        ['uid'=>$b,'role'=>'viewer'], 200);
    $t->check('historic group viewer grant', $a, 'POST', $base . '/groups',
        ['gid'=>'lf_synth_legacy_upgrade','role'=>'viewer'], 200);
    $checkLogo($logoPath);
    $control = $t->check('independent personal book', $b, 'POST', '/spaces',
        ['name'=>'Synthetic independent personal book','kind'=>'personal','currency'=>'BRL'],201);
    $controlBase = '/spaces/' . $control['id'];
    $controlChart = $t->check('personal chart', $b, 'GET', $controlBase . '/accounts', null, 200);
    $controlIds = array_column($controlChart['accounts'],'id','code');
    $t->check('unrelated personal receipt', $b, 'POST', $controlBase . '/journals',
        ['debitId'=>$controlIds['1000'],'creditId'=>$controlIds['4000'],'amountMinor'=>6789,
            'date'=>'2026-11-20','memo'=>'Synthetic personal receipt','externalKey'=>'upgrade-personal-receipt'],201);
    $t->check('other book remains private', $a, 'GET', $controlBase . '/journals', null, 404);
    echo 'SPACE_ID=', $id, PHP_EOL;
    echo 'CONTROL_SPACE_ID=', (int)$control['id'], PHP_EOL;
    $t->finish();
    exit;
}
if ($action !== 'verify') throw new RuntimeException('Unknown disposable upgrade operation');
$id = filter_var(getenv('LF_UPGRADE_SPACE_ID'), FILTER_VALIDATE_INT);
$control = filter_var(getenv('LF_UPGRADE_CONTROL_SPACE_ID'), FILTER_VALIDATE_INT);
if (!is_int($id) || !is_int($control) || $id < 1 || $control < 1 || $id === $control) {
    throw new RuntimeException('Invalid synthetic upgrade identifiers');
}
$base = '/spaces/' . $id;
$controlBase = '/spaces/' . $control;
$cash = static fn(array $v): int => (int)(array_column($v['accounts'] ?? [],'balance_minor','code')['1000'] ?? -1);
$accounts = $t->check('legacy receipt and reversal preserve exact balance', $a, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => $cash($v) === 12345);
$bankId = (int)array_column($accounts['accounts'], 'id', 'code')['1000'];
$journalList = $t->check('legacy original and reversal journal identities remain', $a, 'GET',
    $base . '/journals', null, 200,
    static fn(array $v): bool => count($v['journals'] ?? []) === 3
        && in_array('Synthetic receipt', array_column($v['journals'], 'memo'), true));
$receiptId = (int)array_column($journalList['journals'], 'id', 'memo')['Synthetic receipt'];
$t->check('legacy viewer still has only authorized access', $b, 'GET', $base . '/journals', null, 200);
$t->check('legacy viewer cannot post after upgrade', $b, 'POST', $base . '/journals',
    ['debitId'=>1,'creditId'=>2,'amountMinor'=>1,'date'=>'2026-11-20','memo'=>'Denied','externalKey'=>'denied-upgrade'],403);
$t->check('personal ledger exact balance remains separate', $b, 'GET', $controlBase . '/accounts', null, 200,
    static fn(array $v): bool => $cash($v) === 6789);
$t->check('foreign personal book remains denied', $a, 'GET', $controlBase . '/journals', null, 404);
$companyList = $t->check('two historic identities preserved including inactive one', $a, 'GET',
    $base . '/companies', null, 200,
    static fn(array $v): bool => count($v['companies'] ?? []) === 2
        && count(array_filter($v['companies'], static fn(array $c): bool => !$c['active'])) === 1);
$logoCompany = array_column($companyList['companies'], null, 'legal_name')['Synthetic Company One'] ?? [];
$t->record('original company retains its logo version', ($logoCompany['logo_version'] ?? 0) > 0,
    'company metadata');
$checkLogo($base . '/companies/' . $logoCompany['id'] . '/logo');

$t->checkModule('legacy viewer sees exact matched and pending bank evidence', $b, $bank, 'GET',
    $base . '/transactions', null, 200, static function (array $v) use ($bankId, $receiptId): bool {
        $rows = array_column($v['transactions'] ?? [], null, 'external_id');
        $matched = $rows['upgrade-receipt'] ?? [];
        $pending = $rows['upgrade-pending'] ?? [];
        return count($v['transactions'] ?? []) === 2
            && ($matched['bank_account_id'] ?? null) === $bankId
            && ($matched['amount_minor'] ?? null) === 12345
            && ($matched['status'] ?? null) === 'matched'
            && ($matched['matched_journal_id'] ?? null) === $receiptId
            && ($pending['bank_account_id'] ?? null) === $bankId
            && ($pending['amount_minor'] ?? null) === -789
            && ($pending['status'] ?? null) === 'pending'
            && array_key_exists('matched_journal_id', $pending) && $pending['matched_journal_id'] === null;
    });
$contacts = $t->check('historical customer remains visible', $b, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => count($v['contacts'] ?? []) === 1
        && ($v['contacts'][0]['name'] ?? null) === 'Synthetic historical customer');
$contactId = (int)$contacts['contacts'][0]['id'];
$invoiceList = $t->checkModule('original commercial invoice remains visible', $b, $invoicing, 'GET',
    $base . '/invoices', null, 200, static fn(array $v): bool => count($v['invoices'] ?? []) === 1
        && ($v['invoices'][0]['number'] ?? null) === 'UPGRADE-INV');
$invoiceId = (int)$invoiceList['invoices'][0]['id'];
$detail = $t->checkModule('paid invoice retains tax snapshot, receipt allocation and receivable link',
    $b, $invoicing, 'GET', $base . '/invoices/' . $invoiceId, null, 200,
    static fn(array $v): bool => ($v['invoice']['id'] ?? null) === $invoiceId
        && ($v['invoice']['contact_id'] ?? null) === $contactId
        && ($v['invoice']['status'] ?? null) === 'paid'
        && ($v['invoice']['total_minor'] ?? null) === 12345
        && ($v['invoice']['paid_minor'] ?? null) === 12345
        && ($v['invoice']['tax_minor'] ?? null) === 2000
        && count($v['lines'] ?? []) === 2
        && ($v['lines'][0]['tax_snapshot']['rate_bps'] ?? null) === 2000
        && ($v['lines'][0]['total_minor'] ?? null) === 12000
        && ($v['lines'][1]['total_minor'] ?? null) === 345
        && count($v['allocations'] ?? []) === 1
        && ($v['allocations'][0]['amount_minor'] ?? null) === 12345
        && ($v['allocations'][0]['bank_account_id'] ?? null) === $bankId
        && ($v['allocations'][0]['journal_id'] ?? null) === $receiptId
        && ($v['obligationId'] ?? 0) > 0);
$obligationId = (int)$detail['obligationId'];
$obligationPath = $base . '/obligations/' . $obligationId;
$t->checkModule('linked receivable remains settled by the original ledger receipt', $b, $obligations,
    'GET', $obligationPath, null, 200,
    static fn(array $v): bool => ($v['obligation']['id'] ?? null) === $obligationId
        && ($v['obligation']['linkedInvoiceId'] ?? null) === $invoiceId
        && ($v['obligation']['contact_id'] ?? null) === $contactId
        && ($v['obligation']['kind'] ?? null) === 'receivable'
        && ($v['obligation']['status'] ?? null) === 'settled'
        && ($v['obligation']['amount_minor'] ?? null) === 12345
        && ($v['obligation']['paid_minor'] ?? null) === 12345);
$t->checkModule('original settlement still points to the same journal', $b, $obligations,
    'GET', $obligationPath . '/settlements', null, 200,
    static fn(array $v): bool => count($v['settlements'] ?? []) === 1
        && ($v['settlements'][0]['journal_id'] ?? null) === $receiptId
        && ($v['settlements'][0]['amount_minor'] ?? null) === 12345
        && ($v['settlements'][0]['paid_on'] ?? null) === $date);
$t->checkModule('report catalog preserves configuration and excludes the archived report', $b, $reports,
    'GET', $base . '/saved-reports', null, 200,
    static fn(array $v): bool => count($v['reports'] ?? []) === 1
        && ($v['reports'][0]['name'] ?? null) === $report['name']
        && ($v['reports'][0]['kind'] ?? null) === $report['kind']
        && ($v['reports'][0]['through'] ?? null) === $report['through']
        && ($v['reports'][0]['columns'] ?? null) === $report['columns']
        && ($v['reports'][0]['accountClass'] ?? null) === $report['accountClass']);

$t->check('legacy owner has no inferred new environment', $a, 'GET', '/saas/v1/environments', null, 200,
    static fn(array $v): bool => ($v['environments'] ?? null) === []);
$t->check('ordinary user cannot inspect legacy review state', $b, 'GET', '/admin/contexts', null, 403);
$url = (string)getenv('LF_BASE_URL');
$parts = parse_url($url);
$password = (string)getenv('LF_TEST_ADMIN_PASSWORD');
if (!is_array($parts) || !in_array($parts['host'] ?? null,['127.0.0.1','localhost'],true)
    || !str_ends_with((string)($parts['path'] ?? ''),'/apps/librefinance/api') || $password === '') {
    throw new RuntimeException('Only synthetic loopback administrator allowed');
}
$curl = curl_init($url . '/admin/contexts');
curl_setopt_array($curl, [
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => 'synthetictestadmin:' . $password,
    CURLOPT_HTTPHEADER => ['OCS-APIREQUEST: true','Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($curl);
$status = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
$body = is_string($raw) ? json_decode($raw,true) : null;
$rows = array_column($body['contexts'] ?? [],null,'id');
if ($status !== 200 || ($rows[$id]['legacyReviewState'] ?? null) !== 'pending_review'
    || ($rows[$control]['legacyReviewState'] ?? null) !== 'not_required') {
    throw new RuntimeException('Legacy classification did not preserve ambiguous versus independent book');
}
echo 'PASS: administrator sees review requirement, not inferred SaaS ownership', PHP_EOL;
$t->finish();
