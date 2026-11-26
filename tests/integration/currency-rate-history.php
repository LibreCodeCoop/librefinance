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
$preview = $path . '/' . $id . '/preview';
$otherPreview = $otherPath . '/' . $id . '/preview';
$t->check('read-only conversion uses exact selected historical rate', $a, 'GET',
    $preview . '?sourceMinor=1000', null, 200,
    static fn(array $r): bool => $r['sourceMinor'] === 1000 && $r['targetMinor'] === 2000
        && $r['rate']['id'] === $id && $r['rate']['sourcePrecision'] === 0
        && $r['rate']['targetPrecision'] === 3 && $r['postingCreated'] === false
        && $r['rounding'] === 'half_away_from_zero');
$t->check('negative exact preview preserves sign', $a, 'GET',
    $preview . '?sourceMinor=-1000', null, 200,
    static fn(array $r): bool => $r['targetMinor'] === -2000);
$t->check('zero exact preview is valid', $a, 'GET',
    $preview . '?sourceMinor=0', null, 200,
    static fn(array $r): bool => $r['targetMinor'] === 0);
$t->check('other actor cannot preview foreign rate', $b, 'GET',
    $preview . '?sourceMinor=1000', null, 404);
$t->check('authorized sibling cannot resolve a foreign snapshot ID', $b, 'GET',
    $otherPreview . '?sourceMinor=1000', null, 404,
    static fn(array $r): bool => $r === ['error' => 'exchange_rate_not_found']);
foreach (['-0', '01', '1e3', '1.1', '9000000000001', '9000000000000', ''] as $invalid) {
    $t->check('reject invalid or overflowing minor amount ' . $invalid, $a, 'GET',
        $preview . '?sourceMinor=' . rawurlencode($invalid), null, 422);
}
$t->check('reject missing source amount', $a, 'GET', $preview, null, 422);
$t->check('equivalent canonical quote replays same row', $a, 'POST', $path,
    array_replace($payload, ['rate' => '0.002']), 200,
    static fn(array $r): bool => $r['replayed'] === true && $r['rate']['id'] === $id);
$t->check('correction is a new immutable rate rather than a rewrite', $a, 'POST', $path,
    array_replace($payload, ['rate' => '0.003']), 200,
    static fn(array $r): bool => $r['replayed'] === false && $r['rate']['id'] !== $id);
$t->check('correction never replaces earlier preview snapshot', $a, 'GET',
    $preview . '?sourceMinor=1000', null, 200,
    static fn(array $r): bool => $r['targetMinor'] === 2000 && $r['rate']['id'] === $id);
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
$t->check('viewer can preview only scoped history', $b, 'GET', $preview . '?sourceMinor=10', null, 200,
    static fn(array $r): bool => $r['targetMinor'] === 20 && $r['postingCreated'] === false);
$t->check('viewer cannot change rate history', $b, 'POST', $path, $payload, 403);
$t->check('grant manager', $a, 'POST', $root . '/members', ['uid' => $b, 'role' => 'manager'], 200);
$t->check('manager records scoped rate evidence', $b, 'POST', $path,
    array_replace($payload, ['sourceRef' => 'manager-' . $tag]), 200,
    static fn(array $r): bool => $r['replayed'] === false);
$t->check('revoke role', $a, 'DELETE', $root . '/members/' . $b, null, 200);
$t->check('revocation denies next rate read', $b, 'GET', $path, null, 404);
$t->check('revocation denies next historical preview', $b, 'GET', $preview . '?sourceMinor=1', null, 404);
$t->check('invalid cursor rejected', $a, 'GET', $path . '?afterId=-1', null, 422);
$t->check('cursor reads only later rows in same book', $a, 'GET', $path . '?afterId=' . $id, null, 200,
    static fn(array $r): bool => count($r['rates']) === 3 && $r['rates'][0]['id'] > $id);
// Verify the explicitly dated lookup against the same installed HTTP API.
$beforeSelection = $t->check('snapshot accounting journals before rate selection', $a,
    'GET', $root . '/journals', null, 200);
$allExisting = $t->check('retain previously recorded immutable candidates', $a,
    'GET', $path, null, 200);
$expected = array_column($allExisting['rates'], 'id');
$selection = ['sourceCurrency' => 'JPY', 'targetCurrency' => 'KWD',
    'asOf' => '2026-11-24', 'sourceType' => 'manual'];
$query = http_build_query($selection);
$matches = $t->check('same pair and date exposes all ambiguous candidates', $a, 'GET',
    $path . '?' . $query, null, 200,
    static fn(array $r): bool => $r['selection'] === $selection
        && array_column($r['rates'], 'id') === $expected
        && $r['nextAfterId'] === null);
$t->check('normalized pair resolves the same candidates', $a, 'GET',
    $path . '?' . http_build_query(array_replace($selection,
        ['sourceCurrency' => ' jpy ', 'targetCurrency' => 'kwd'])), null, 200,
    static fn(array $r): bool => $r === $matches);
$t->check('dated preview uses explicitly selected immutable rate', $a, 'GET',
    $preview . '?sourceMinor=1000&' . $query, null, 200,
    static fn(array $r): bool => $r['selection'] === $selection
        && $r['rate']['id'] === $id && $r['targetMinor'] === 2000
        && $r['postingCreated'] === false);
$t->check('authorized empty selection is not a permission error', $a, 'GET',
    $path . '?' . http_build_query(array_replace($selection, ['asOf' => '2026-11-23'])), null, 200,
    static fn(array $r): bool => $r['rates'] === [] && $r['nextAfterId'] === null);
foreach ([
    'partial pair' => 'sourceCurrency=JPY',
    'missing cutoff' => 'sourceCurrency=JPY&targetCurrency=KWD',
    'unsupported pair' => 'sourceCurrency=XYZ&targetCurrency=KWD&asOf=2026-11-24',
    'same pair' => 'sourceCurrency=JPY&targetCurrency=jpy&asOf=2026-11-24',
    'invalid leap day' => 'sourceCurrency=JPY&targetCurrency=KWD&asOf=2026-02-29',
    'noncanonical cutoff' => 'sourceCurrency=JPY&targetCurrency=KWD&asOf=2026-2-01',
    'invalid source' => 'sourceCurrency=JPY&targetCurrency=KWD&asOf=2026-11-24&sourceType=unknown',
] as $label => $invalidQuery) {
    $t->check('reject selection ' . $label, $a, 'GET', $path . '?' . $invalidQuery, null, 422);
    $t->check('reject preview selection ' . $label, $a, 'GET',
        $preview . '?sourceMinor=1000&' . $invalidQuery, null, 422);
}
$t->check('selection authorization precedes validation', $b, 'GET',
    $path . '?sourceCurrency=XYZ', null, 404);
$t->check('denied workspace cannot reveal eligible quotes', $b, 'GET',
    $path . '?' . $query, null, 404);
$t->check('denied workspace cannot preview selected quote', $b, 'GET',
    $preview . '?sourceMinor=1000&' . $query, null, 404);
$t->check('authorized sibling cannot preview selected foreign ID', $b, 'GET',
    $otherPreview . '?sourceMinor=1000&' . $query, null, 404,
    static fn(array $r): bool => $r === ['error' => 'exchange_rate_not_found']);

$future = $t->check('record future dated evidence independently of selection', $a, 'POST', $path,
    array_replace($payload, ['effectiveOn' => '2026-11-25', 'sourceRef' => 'future-' . $tag]), 200);
$wrongPair = $t->check('record different pair evidence', $a, 'POST', $path,
    array_replace($payload, ['targetCurrency' => 'USD', 'sourceRef' => 'other-pair-' . $tag]), 200);
$imported = $t->check('record older imported evidence', $a, 'POST', $path,
    array_replace($payload, ['effectiveOn' => '2026-11-23', 'sourceType' => 'imported',
        'sourceRef' => 'imported-' . $tag]), 200);
$t->check('record matching pair in a separate ledger', $b, 'POST', $otherPath,
    array_replace($payload, ['sourceRef' => 'other-book-' . $tag]), 200);
foreach (['future' => $future, 'wrong pair' => $wrongPair, 'wrong source' => $imported] as $label => $quote) {
    $t->check('ineligible selected snapshot denied: ' . $label, $a, 'GET',
        $path . '/' . $quote['rate']['id'] . '/preview?sourceMinor=1000&' . $query, null, 404,
        static fn(array $r): bool => $r === ['error' => 'exchange_rate_not_found']);
}
$t->check('historical preview remains compatible without dated selection', $a, 'GET',
    $path . '/' . $future['rate']['id'] . '/preview?sourceMinor=1000', null, 200,
    static fn(array $r): bool => !array_key_exists('selection', $r) && $r['targetMinor'] === 2000);
$t->check('older imported evidence can be selected explicitly', $a, 'GET',
    $path . '?' . http_build_query(array_replace($selection, ['sourceType' => 'imported'])), null, 200,
    static fn(array $r): bool => array_column($r['rates'], 'id') === [$imported['rate']['id']]
        && $r['rates'][0]['effectiveOn'] === '2026-11-23'
        && $r['rates'][0]['sourceType'] === 'imported'
        && $r['rates'][0]['sourceRef'] === 'imported-' . $tag);

// Interleaved ineligible snapshots must not consume candidate page slots.
for ($i = 1; $i <= 47; ++$i) {
    $created = $t->check('record synthetic candidate ' . $i, $a, 'POST', $path,
        array_replace($payload, ['sourceRef' => 'candidate-' . $tag . '-' . $i]), 200);
    $expected[] = $created['rate']['id'];
}
$t->record('fixture has exactly 51 matching immutable candidates', count($expected) === 51, 'count');
$page = $t->check('filter before first candidate page', $a, 'GET', $path . '?' . $query, null, 200,
    static fn(array $r): bool => array_column($r['rates'], 'id') === array_slice($expected, 0, 50)
        && $r['nextAfterId'] === $expected[49]);
$t->check('filtered continuation retains the exact selection', $a, 'GET',
    $path . '?' . $query . '&afterId=' . $page['nextAfterId'], null, 200,
    static fn(array $r): bool => $r['selection'] === $selection
        && array_column($r['rates'], 'id') === [$expected[50]] && $r['nextAfterId'] === null);
$allSources = $selection;
unset($allSources['sourceType']);
$t->check('omitting origin includes older imported evidence without preferring a source', $a, 'GET',
    $path . '?' . http_build_query($allSources), null, 200,
    static fn(array $r): bool => $r['selection']['sourceType'] === null
        && in_array($imported['rate']['id'], array_column($r['rates'], 'id'), true)
        && count($r['rates']) === 50 && $r['nextAfterId'] !== null);
$t->check('rate selection and previews never alter posted journals', $a, 'GET',
    $root . '/journals', null, 200, static fn(array $r): bool => $r === $beforeSelection);
$t->check('rate selection and previews never alter booked balances', $a, 'GET',
    $root . '/accounts', null, 200, static fn(array $r): bool => $r === $chart);
$t->finish();
