<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$owner = 'lfmaintestA';
$outsider = 'lfmaintestB';
$tag = bin2hex(random_bytes(7));
$space = $t->check('create space for realized cash-flow report', $owner, 'POST', '/spaces',
    ['name' => 'Synthetic cash movement ' . $tag,
        'kind' => 'organization', 'currency' => 'BRL'], 201);
$spaceId = (int)$space['id'];
$base = '/spaces/' . $spaceId;
$reportApp = 'librefinance_reports';

$accountRows = $t->check('cash-flow opening chart contains base accounts',
    $owner, 'GET', $base . '/accounts', null, 200);
$accounts = [];
foreach ($accountRows['accounts'] as $account) {
    $accounts[(string)$account['code']] = (int)$account['id'];
}
$t->checkModule('empty cash-flow has zero opening, movement and closing',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-12-31', null, 200,
    static fn(array $v): bool =>
        ($v['openingMinor'] ?? null) === 0
        && ($v['netMinor'] ?? null) === 0
        && ($v['closingMinor'] ?? null) === 0
        && ($v['months'] ?? null) === []
        && ($v['isForecast'] ?? true) === false);

$secondBank = $t->check('add second cash account for transfer neutrality',
    $owner, 'POST', $base . '/accounts',
    ['code' => '1100', 'name' => 'Synthetic second bank',
        'class' => 'asset', 'isBank' => true], 201);
$accounts['1100'] = (int)$secondBank['id'];
$make = static function(string $suffix, string $date, int $debit, int $credit,
    int $minor) use ($t, $owner, $base, $tag): array {
    return $t->check('post cash-flow journal ' . $suffix, $owner, 'POST',
        $base . '/journals',
        ['debitId' => $debit, 'creditId' => $credit,
            'amountMinor' => $minor, 'date' => $date,
            'memo' => 'Synthetic cash-flow ' . $suffix,
            'externalKey' => 'cf:' . $tag . ':' . $suffix], 201);
};
$make('opening-receipt', '2026-10-31',
    $accounts['1000'], $accounts['4000'], 10000);
$make('expense', '2026-11-02',
    $accounts['5000'], $accounts['1000'], 2300);
$make('internal-transfer', '2026-11-03',
    $accounts['1100'], $accounts['1000'], 3300);
$make('revenue-november', '2026-11-04',
    $accounts['1000'], $accounts['4000'], 6000);
$make('revenue-december', '2026-12-05',
    $accounts['1100'], $accounts['4000'], 2000);

$report = $t->checkModule(
    'cash-flow aggregates opening and monthly bank movements without transfer inflation',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-12-31', null, 200,
    static fn(array $v): bool =>
        ($v['openingMinor'] ?? null) === 10000
        && ($v['netMinor'] ?? null) === 5700
        && ($v['closingMinor'] ?? null) === 15700
        && ($v['currency'] ?? null) === 'BRL'
        && ($v['basis'] ?? null) === 'realized_bank_postings'
        && ($v['isForecast'] ?? true) === false
        && ($v['months'] ?? null) === [
            ['month' => '2026-11', 'netMinor' => 3700],
            ['month' => '2026-12', 'netMinor' => 2000],
        ]);
$t->checkModule('cash-flow period excludes movements after its end',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-11-30', null, 200,
    static fn(array $v): bool =>
        ($v['openingMinor'] ?? null) === 10000
        && ($v['netMinor'] ?? null) === 3700
        && ($v['closingMinor'] ?? null) === 13700
        && ($v['months'] ?? null) === [
            ['month' => '2026-11', 'netMinor' => 3700],
        ]);
$t->checkModule('cash-flow longer period recovers the entire cash balance',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-01-01&through=2026-12-31', null, 200,
    static fn(array $v): bool =>
        ($v['openingMinor'] ?? null) === 0
        && ($v['netMinor'] ?? null) === 15700
        && ($v['closingMinor'] ?? null) === 15700
        && count($v['months'] ?? []) === 3);
$t->check('individual bank accounts sum to total closing cash',
    $owner, 'GET', $base . '/accounts', null, 200,
    static function(array $v): bool {
        $bank = 0;
        foreach ($v['accounts'] as $account) {
            if ($account['is_bank']) {
                $bank += $account['balance_minor'];
            }
        }
        return $bank === 15700;
    });

$t->checkModule('cash-flow denies unrelated company users',
    $outsider, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-12-31', null, 404);
$t->checkModule('invalid cash-flow date is rejected',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-02-30&through=2026-12-31', null, 422);
$t->checkModule('reversed cash-flow period is rejected',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-12-31&through=2026-01-01', null, 422);
$t->check('grant viewer access to accounting reports',
    $owner, 'POST', $base . '/members',
    ['uid' => $outsider, 'role' => 'viewer'], 200);
$t->checkModule('finance viewer reads realized cash-flow but cannot modify it',
    $outsider, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-12-31', null, 200,
    static fn(array $v): bool => ($v['closingMinor'] ?? null) === 15700);
$t->check('disable reports in this space',
    $owner, 'PUT', $base . '/modules/' . $reportApp,
    ['enabled' => false], 200);
$t->checkModule('disabled report refuses cash-flow even to an owner',
    $owner, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-12-31', null, 403);
$t->check('reactivate report extension',
    $owner, 'PUT', $base . '/modules/' . $reportApp,
    ['enabled' => true], 200);
$t->check('revoke report viewer',
    $owner, 'DELETE', $base . '/members/' . $outsider, null, 200);
$t->checkModule('revoked viewer cannot read cash-flow',
    $outsider, $reportApp, 'GET',
    $base . '/cash-flow?from=2026-11-01&through=2026-12-31', null, 404);
$t->finish();
