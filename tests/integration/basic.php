<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
// Bootstrap the installed server before assertion output for the reversal fixture.
require_once dirname(__DIR__, 4) . '/lib/base.php';
\OC_App::loadApp('librefinance');

$a = 'lfmaintestA';
$b = 'lfmaintestB';
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$none = static fn(array $v): bool => is_array($v['spaces'] ?? null);
$t->check('baseline A spaces', $a, 'GET', '/spaces', null, 200, $none);
$t->check('baseline B spaces', $b, 'GET', '/spaces', null, 200, $none);
$space = $t->check('create space A', $a, 'POST', '/spaces',
    ['name' => 'Synthetic financial business A', 'kind' => 'organization', 'currency' => 'BRL'],
    201, static fn(array $v): bool => is_int($v['id'] ?? null) && ($v['role'] ?? null) === 'owner');
$id = (int)$space['id'];
$base = '/spaces/' . $id;

$t->check('B cannot enumerate A space', $b, 'GET', '/spaces', null, 200,
    static fn(array $v): bool => is_array($v['spaces'] ?? null)
        && count(array_filter($v['spaces'], static fn(array $row): bool => (int)$row['id'] === $id)) === 0);
foreach (['accounts', 'journals', 'members', 'groups'] as $endpoint) {
    $t->check('B denied A ' . $endpoint, $b, 'GET', $base . '/' . $endpoint, null, 404,
        static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
}
$accountSet = $t->check('A receives four default accounts', $a, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 4
        && count(array_filter($v['accounts'], static fn(array $row): bool => $row['balance_minor'] !== 0)) === 0);
$accounts = [];
foreach ($accountSet['accounts'] as $account) {
    $accounts[$account['code']] = (int)$account['id'];
}
$t->check('create bank account', $a, 'POST', $base . '/accounts',
    ['code' => '1010', 'name' => 'Synthetic bank', 'class' => 'asset', 'isBank' => true],
    201, static fn(array $v): bool => ($v['code'] ?? '') === '1010');
$t->check('B denied bank creation', $b, 'POST', $base . '/accounts',
    ['code' => '1011', 'name' => 'Forbidden', 'class' => 'asset'], 404);

$expense = [
    'debitId' => $accounts['5000'], 'creditId' => $accounts['1000'],
    'amountMinor' => 2599, 'date' => $today, 'memo' => 'Synthetic office expense',
    'externalKey' => 'php:synthetic:expense-20261120',
];
$entry = $t->check('post expense with double entry', $a, 'POST', $base . '/journals', $expense, 201,
    static fn(array $v): bool => ($v['replayed'] ?? null) === false);
$t->check('idempotent replay', $a, 'POST', $base . '/journals', $expense, 201,
    static fn(array $v): bool => ($v['replayed'] ?? null) === true && ($v['id'] ?? null) === $entry['id']);
$t->check('idempotency collision returns conflict', $a, 'POST', $base . '/journals',
    array_replace($expense, ['amountMinor' => 2600]), 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'idempotency_conflict');
$t->check('negative amount rejected', $a, 'POST', $base . '/journals',
    array_replace($expense, ['amountMinor' => -2, 'externalKey' => 'php:synthetic:invalid-amount']),
    422, static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_amount');
$t->check('unknown account rejected', $a, 'POST', $base . '/journals',
    array_replace($expense, ['debitId' => 999999, 'externalKey' => 'php:synthetic:bad-account']),
    404, static fn(array $v): bool => ($v['error'] ?? null) === 'account_not_found');
$t->check('B cannot post to A ledger', $b, 'POST', $base . '/journals',
    array_replace($expense, ['externalKey' => 'php:synthetic:other-user']), 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$balanceOf = static function (array $v, string $code): int {
    foreach ($v['accounts'] ?? [] as $account) {
        if (($account['code'] ?? null) === $code) {
            return (int)$account['balance_minor'];
        }
    }
    throw new RuntimeException('Missing account ' . $code);
};
$t->check('expense affects exact balances', $a, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => $balanceOf($v, '1000') === -2599 && $balanceOf($v, '5000') === 2599);
$t->check('expense created one journal', $a, 'GET', $base . '/journals', null, 200,
    static fn(array $v): bool => count($v['journals'] ?? []) === 1
        && count($v['journals'][0]['postings'] ?? []) === 2);

$t->check('grant B viewer permission', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200, static fn(array $v): bool => ($v['ok'] ?? null) === true);
$t->check('B can enumerate shared space', $b, 'GET', '/spaces', null, 200,
    static fn(array $v): bool => count(array_filter($v['spaces'] ?? [],
        static fn(array $row): bool => (int)$row['id'] === $id && $row['role'] === 'viewer')) === 1);
$t->check('viewer B can inspect accounts', $b, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 5);
$t->check('viewer B cannot post', $b, 'POST', $base . '/journals',
    array_replace($expense, ['externalKey' => 'php:synthetic:viewer-denied']), 403,
    static fn(array $v): bool => ($v['error'] ?? null) === 'permission_denied');
$t->check('viewer cannot grant role', $b, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'manager'], 403);
$t->check('promote B to operator', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'operator'], 200);
$t->check('operator cannot create accounts', $b, 'POST', $base . '/accounts',
    ['code' => '1012', 'name' => 'Denied', 'class' => 'asset'], 403);
$income = array_replace($expense, [
    'debitId' => $accounts['1000'], 'creditId' => $accounts['4000'],
    'amountMinor' => 10000, 'memo' => 'Synthetic service revenue',
    'externalKey' => 'php:synthetic:income-20261120',
]);
$t->check('operator can post income', $b, 'POST', $base . '/journals', $income, 201,
    static fn(array $v): bool => ($v['replayed'] ?? null) === false);
$t->check('income and expense balance', $a, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => $balanceOf($v, '1000') === 7401 && $balanceOf($v, '4000') === -10000);
$reversal = $t->check('manager reverses expense', $a, 'POST', $base . '/journals/' . $entry['id'] . '/reverse',
    null, 200, static fn(array $v): bool => ($v['replayed'] ?? null) === false);
$t->check('reversal is idempotent', $a, 'POST', $base . '/journals/' . $entry['id'] . '/reverse',
    null, 200, static fn(array $v): bool => ($v['replayed'] ?? null) === true && ($v['id'] ?? null) === $reversal['id']);
$t->check('reversal restores balances', $a, 'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => $balanceOf($v, '1000') === 10000 && $balanceOf($v, '5000') === 0);
$t->check('revoke B', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('B denied after revocation', $b, 'GET', $base . '/accounts', null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$t->check('B cannot post after revocation', $b, 'POST', $base . '/journals',
    array_replace($income, ['externalKey' => 'php:synthetic:revoked-denied']), 404);

(static function (TestHarness $t): void {
    require __DIR__ . '/journal-reversal.php';
})($t);
$t->finish();
