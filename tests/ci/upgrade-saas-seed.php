<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Mounted only in a disposable Nextcloud upgrade test.
require_once '/var/www/html/custom_apps/librefinance/tests/integration/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

if (getenv('LF_SYNTHETIC_SAAS_UPGRADE') !== 'yes') {
    throw new RuntimeException('Synthetic upgrade opt-in required');
}
$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$action = getenv('LF_UPGRADE_ACTION');
if ($action === 'seed') {
    $space = $t->check('create original legacy mixed book', $a, 'POST', '/spaces',
        ['name' => 'Synthetic historical mixed book', 'kind' => 'organization', 'currency' => 'BRL'], 201);
    $id = (int)$space['id'];
    $base = '/spaces/' . $id;
    $first = ['legalName'=>'Synthetic Company One','country'=>'PT','taxId'=>'SYNTHETIC1'];
    $second = ['legalName'=>'Synthetic Company Two','country'=>'PT','taxId'=>'SYNTHETIC2'];
    $t->check('legacy first legal identity', $a, 'POST', $base . '/companies', $first, 201);
    $inactive = $t->check('legacy second legal identity', $a, 'POST', $base . '/companies', $second, 201);
    $t->check('historical company deactivation without deletion', $a, 'PUT',
        $base . '/companies/' . $inactive['id'], [...$second,'active'=>false], 200);
    $accountSet = $t->check('historical account chart', $a, 'GET', $base . '/accounts', null, 200);
    $ids = array_column($accountSet['accounts'],'id','code');
    $t->check('historic receipt', $a, 'POST', $base . '/journals',
        ['debitId'=>$ids['1000'],'creditId'=>$ids['4000'],'amountMinor'=>12345,
            'date'=>'2026-11-20','memo'=>'Synthetic receipt','externalKey'=>'upgrade-saas-receipt'], 201);
    $expense = $t->check('historic expense', $a, 'POST', $base . '/journals',
        ['debitId'=>$ids['5000'],'creditId'=>$ids['1000'],'amountMinor'=>345,
            'date'=>'2026-11-20','memo'=>'Synthetic expense','externalKey'=>'upgrade-saas-expense'], 201);
    $t->check('historic reversal', $a, 'POST', $base . '/journals/' . $expense['id'] . '/reverse', null, 200);
    $t->check('historical explicit viewer membership', $a, 'POST', $base . '/members',
        ['uid'=>$b,'role'=>'viewer'], 200);
    $t->check('historic group viewer grant', $a, 'POST', $base . '/groups',
        ['gid'=>'lf_synth_legacy_upgrade','role'=>'viewer'], 200);
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
$t->check('legacy receipt and reversal preserve exact balance', $a, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => $cash($v) === 12345);
$t->check('legacy original and reversal journal identities remain', $a, 'GET', $base . '/journals', null, 200,
    static fn(array $v): bool => count($v['journals'] ?? []) === 3);
$t->check('legacy viewer still has only authorized access', $b, 'GET', $base . '/journals', null, 200);
$t->check('legacy viewer cannot post after upgrade', $b, 'POST', $base . '/journals',
    ['debitId'=>1,'creditId'=>2,'amountMinor'=>1,'date'=>'2026-11-20','memo'=>'Denied','externalKey'=>'denied-upgrade'],403);
$t->check('personal ledger exact balance remains separate', $b, 'GET', $controlBase . '/accounts', null, 200,
    static fn(array $v): bool => $cash($v) === 6789);
$t->check('foreign personal book remains denied', $a, 'GET', $controlBase . '/journals', null, 404);
$t->check('two historic identities preserved including inactive one', $a, 'GET', $base . '/companies', null, 200,
    static fn(array $v): bool => count($v['companies'] ?? []) === 2
        && count(array_filter($v['companies'], static fn(array $c): bool => !$c['active'])) === 1);
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
