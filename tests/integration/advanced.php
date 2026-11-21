<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$gid = 'lf_synth_finance_group';
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$tag = bin2hex(random_bytes(8));
$space = $t->check('create collective space', $a, 'POST', '/spaces',
    ['name' => 'Synthetic group finance ' . $tag, 'kind' => 'collective', 'currency' => 'BRL'],
    201, static fn(array $v): bool => is_int($v['id'] ?? null));
$id = (int)$space['id'];
$path = '/spaces/' . $id;
$t->check('B denied unshared collective', $b, 'GET', $path . '/accounts', null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$t->check('A grants viewer to Nextcloud group', $a, 'POST', $path . '/groups',
    ['gid' => $gid, 'role' => 'viewer'], 200);
$t->check('member of group B can read accounts', $b, 'GET', $path . '/accounts', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 4);
$t->check('member of group B denied writing as viewer', $b, 'POST', $path . '/journals',
    ['debitId' => 1, 'creditId' => 3, 'amountMinor' => 500, 'date' => $today,
     'memo' => 'Unauthorized synthetic entry', 'externalKey' => 'php:' . $tag . ':group-denied'],
    403, static fn(array $v): bool => ($v['error'] ?? null) === 'permission_denied');
$t->check('A revokes group', $a, 'DELETE', $path . '/groups/' . $gid, null, 200);
$t->check('B denied after group revocation', $b, 'GET', $path . '/accounts', null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$accounts = $t->check('owner accounts before concurrency', $a, 'GET', $path . '/accounts', null, 200);
$accountByCode = [];
foreach ($accounts['accounts'] as $account) {
    $accountByCode[$account['code']] = (int)$account['id'];
}
$payload = [
    'debitId' => $accountByCode['1000'], 'creditId' => $accountByCode['4000'],
    'amountMinor' => 33333, 'date' => $today, 'memo' => 'Parallel synthetic entry ' . $tag,
    'externalKey' => 'php:' . $tag . ':parallel',
];
$req = ['user' => $a, 'method' => 'POST', 'path' => $path . '/journals', 'data' => $payload];
[$one, $two] = $t->parallel($req, $req);
$statuses = [$one['code'], $two['code']];
sort($statuses);
$replayed = [$one['body']['replayed'] ?? null, $two['body']['replayed'] ?? null];
$t->record('two identical concurrent requests post only once',
    $statuses === [201, 201]
        && in_array(true, $replayed, true) && in_array(false, $replayed, true)
        && ($one['body']['id'] ?? null) === ($two['body']['id'] ?? null),
    'codes ' . implode(',', $statuses), ['one' => $one, 'two' => $two]);
$t->check('one posting balance after concurrent requests', $a, 'GET', $path . '/accounts',
    null, 200, static function (array $v): bool {
        $balances = [];
        foreach ($v['accounts'] ?? [] as $account) {
            $balances[$account['code']] = (int)$account['balance_minor'];
        }
        return ($balances['1000'] ?? null) === 33333 && ($balances['4000'] ?? null) === -33333;
    });
$t->check('single journal from race', $a, 'GET', $path . '/journals', null, 200,
    static fn(array $v): bool => count($v['journals'] ?? []) === 1
        && count($v['journals'][0]['postings'] ?? []) === 2);
$key = 'php:' . $tag . ':conflict';
$req1 = ['user' => $a, 'method' => 'POST', 'path' => $path . '/journals',
    'data' => array_replace($payload, ['externalKey' => $key, 'amountMinor' => 111])];
$req2 = ['user' => $a, 'method' => 'POST', 'path' => $path . '/journals',
    'data' => array_replace($payload, ['externalKey' => $key, 'amountMinor' => 222])];
[$one, $two] = $t->parallel($req1, $req2);
$statuses = [$one['code'], $two['code']];
sort($statuses);
$t->record('concurrent conflicting keys return 201 and 409', $statuses === [201, 409],
    'codes ' . implode(',', $statuses), ['one' => $one, 'two' => $two]);
$t->check('conflicting race produces exactly two journals', $a, 'GET',
    $path . '/journals', null, 200, static fn(array $v): bool => count($v['journals'] ?? []) === 2);
for ($iteration = 1; $iteration <= 5; ++$iteration) {
    // Stress the race where two different requests claim the same unique key.
    $raceKey = 'php:' . $tag . ':stress-' . $iteration;
    $raceA = ['user' => $a, 'method' => 'POST', 'path' => $path . '/journals',
        'data' => array_replace($payload, ['externalKey' => $raceKey, 'amountMinor' => 100 + $iteration])];
    $raceB = ['user' => $a, 'method' => 'POST', 'path' => $path . '/journals',
        'data' => array_replace($payload, ['externalKey' => $raceKey, 'amountMinor' => 200 + $iteration])];
    [$responseA, $responseB] = $t->parallel($raceA, $raceB);
    $codes = [$responseA['code'], $responseB['code']];
    sort($codes);
    $t->record('different payload race #' . $iteration . ' returns 201+409',
        $codes === [201, 409], 'codes ' . implode(',', $codes),
        ['one' => $responseA, 'two' => $responseB]);
}
$other = $t->check('B creates independent own space', $b, 'POST', '/spaces',
    ['name' => 'Synthetic independent ' . $tag, 'kind' => 'personal', 'currency' => 'BRL'], 201);
$t->check('A denied B space', $a, 'GET', '/spaces/' . $other['id'] . '/accounts', null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$t->finish();
