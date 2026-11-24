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
$tag = bin2hex(random_bytes(4));
$space = $t->check('create isolated FX evidence ledger', $a, 'POST', '/spaces',
    ['name' => 'Synthetic FX ' . $tag, 'kind' => 'organization', 'currency' => 'BRL'], 201);
$other = $t->check('create separate ledger', $b, 'POST', '/spaces',
    ['name' => 'Synthetic FX sibling ' . $tag, 'kind' => 'organization', 'currency' => 'BRL'], 201);
$root = '/spaces/' . (int)$space['id'];
$path = $root . '/exchange-rates';
$otherPath = '/spaces/' . (int)$other['id'] . '/exchange-rates';
$chart = $t->check('read initial accounting balances', $a, 'GET', $root . '/accounts', null, 200);
$payload = ['sourceCurrency' => 'JPY', 'targetCurrency' => 'KWD', 'rate' => '0.002000',
    'effectiveOn' => '2026-11-24', 'sourceType' => 'manual', 'sourceRef' => 'synthetic-' . $tag];
$t->check('unknown actor cannot read FX rates', $b, 'GET', $path, null, 404);
$t->check('unknown actor cannot record FX rates', $b, 'POST', $path, $payload, 404);
$t->check('new ledger has no quotes', $a, 'GET', $path, null, 200,
    static fn(array $r): bool => $r['rates'] === [] && $r['supportedCurrencies']['JPY'] === 0
        && $r['supportedCurrencies']['KWD'] === 3);
$first = $t->check('record canonical immutable FX quote', $a, 'POST', $path, $payload, 200,
    static fn(array $r): bool => $r['replayed'] === false && $r['rate']['rate'] === '0.002'
        && $r['rate']['sourcePrecision'] === 0 && $r['rate']['targetPrecision'] === 3);
$id = (int)$first['rate']['id'];
$t->check('equivalent canonical quote replays same row', $a, 'POST', $path,
    array_replace($payload, ['rate' => '0.002']), 200,
    static fn(array $r): bool => $r['replayed'] === true && $r['rate']['id'] === $id);
$t->check('correction is a new immutable rate rather than a rewrite', $a, 'POST', $path,
    array_replace($payload, ['rate' => '0.003']), 200,
    static fn(array $r): bool => $r['replayed'] === false && $r['rate']['id'] !== $id);
$t->check('invalid currency is rejected', $a, 'POST', $path,
    array_replace($payload, ['sourceCurrency' => 'ZZZ']), 422);
$t->check('invalid date is rejected', $a, 'POST', $path,
    array_replace($payload, ['effectiveOn' => '2026-02-30']), 422);
$t->check('floating exponent rate is rejected', $a, 'POST', $path,
    array_replace($payload, ['rate' => '1e-3']), 422);
$t->check('JSON numeric rate is rejected', $a, 'POST', $path,
    array_replace($payload, ['rate' => 0.002]), 422);
$t->check('provenance URL is rejected', $a, 'POST', $path,
    array_replace($payload, ['sourceRef' => 'https://example.invalid']), 422);
$t->check('other ledger does not inherit rates', $b, 'GET', $otherPath, null, 200,
    static fn(array $r): bool => $r['rates'] === []);
$race = array_replace($payload, ['sourceRef' => 'race-' . $tag]);
$request = ['user' => $a, 'method' => 'POST', 'path' => $path, 'data' => $race];
[$one, $two] = $t->parallel($request, $request);
$t->record('parallel equivalent quote creates exactly one row',
    $one['code'] === 200 && $two['code'] === 200
    && $one['body']['rate']['id'] === $two['body']['rate']['id']
    && $one['body']['replayed'] !== $two['body']['replayed'], 'two HTTP requests');
$t->check('original immutable quote and correction both retained', $a, 'GET', $path, null, 200,
    static fn(array $r): bool => count($r['rates']) === 3 && $r['rates'][0]['rate'] === '0.002'
        && $r['rates'][1]['rate'] === '0.003');
$t->check('recording rate leaves accounting balances unchanged', $a, 'GET', $root . '/accounts', null, 200,
    static fn(array $r): bool => $r === $chart);
$t->check('grant viewer', $a, 'POST', $root . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('viewer can read scoped rate evidence', $b, 'GET', $path, null, 200,
    static fn(array $r): bool => count($r['rates']) === 3);
$t->check('viewer cannot change rate history', $b, 'POST', $path, $payload, 403);
$t->check('grant manager', $a, 'POST', $root . '/members', ['uid' => $b, 'role' => 'manager'], 200);
$t->check('manager records scoped rate evidence', $b, 'POST', $path,
    array_replace($payload, ['sourceRef' => 'manager-' . $tag]), 200,
    static fn(array $r): bool => $r['replayed'] === false);
$t->check('revoke role', $a, 'DELETE', $root . '/members/' . $b, null, 200);
$t->check('revocation denies next rate read', $b, 'GET', $path, null, 404);
$t->check('invalid cursor rejected', $a, 'GET', $path . '?afterId=-1', null, 422);
$t->check('cursor reads only later rows in same book', $a, 'GET', $path . '?afterId=' . $id, null, 200,
    static fn(array $r): bool => count($r['rates']) === 3 && $r['rates'][0]['id'] > $id);
$t->finish();
