<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$user = 'lfmaintestA';
$tag = bin2hex(random_bytes(5));
$space = $t->check('race: create isolated financial space', $user, 'POST', '/spaces',
    ['name' => 'Concurrent account references ' . $tag,
        'kind' => 'organization', 'currency' => 'BRL'], 201);
$base = '/spaces/' . (int)$space['id'];
$t->check('race: enable banking module', $user, 'PUT',
    $base . '/modules/librefinance_banking', ['enabled' => true], 200);

for ($i = 1; $i <= 6; ++$i) {
    $account = $t->check('race: create disposable bank account #' . $i,
        $user, 'POST', $base . '/accounts',
        ['code' => 'RACE_' . strtoupper($tag) . '_' . $i,
            'name' => 'Bank race ' . $i, 'class' => 'asset', 'isBank' => true], 201);
    $accountId = (int)$account['id'];
    $externalId = 'guard:' . $tag . ':' . $i;
    $csv = "id,date,amount,description\n"
        . $externalId . ",2026-11-21,12.34,Concurrent import\n";
    $import = ['user' => $user, 'method' => 'POST', 'module' => 'librefinance_banking',
        'path' => $base . '/imports',
        'data' => ['bankAccountId' => $accountId, 'csv' => $csv]];
    $delete = ['user' => $user, 'method' => 'DELETE',
        'path' => $base . '/accounts/' . $accountId, 'data' => null];

    [$importResult, $deleteResult] = $t->parallel($import, $delete);
    $posted = $importResult['code'] === 201 && $deleteResult['code'] === 409;
    $removed = in_array($importResult['code'], [404, 409], true)
        && $deleteResult['code'] === 200
        && ($importResult['code'] !== 409
            || ($importResult['body']['error'] ?? null) === 'account_reference_busy');
    $t->record('race: import and delete cannot both succeed #' . $i,
        $posted || $removed,
        'import=' . $importResult['code'] . ' delete=' . $deleteResult['code'],
        ['import' => $importResult, 'delete' => $deleteResult]);

    $accounts = $t->check('race: read actual account outcome #' . $i,
        $user, 'GET', $base . '/accounts', null, 200);
    $present = count(array_filter($accounts['accounts'],
        static fn(array $item): bool => (int)$item['id'] === $accountId)) === 1;
    $t->record('race: account survives only when new banking reference is recorded #' . $i,
        $present === $posted, $present ? 'retained' : 'removed');

    $movements = $t->checkModule('race: examine persisted bank references #' . $i,
        $user, 'librefinance_banking', 'GET',
        $base . '/transactions?bankAccountId=' . $accountId, null, 200);
    $count = count($movements['transactions'] ?? []);
    $t->record('race: no orphaned banking movement #' . $i,
        $count === ($posted ? 1 : 0),
        'bank rows=' . $count);
}

$t->finish();
