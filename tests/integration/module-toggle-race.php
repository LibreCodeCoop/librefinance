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
$report = 'librefinance_reports';
$create = static fn(string $name): array => $t->check('create isolated module configuration space', $a,
    'POST', '/spaces', ['name' => $name, 'kind' => 'collective', 'currency' => 'BRL'], 201);
$control = $create('Synthetic module isolation control');
$controlPath = '/spaces/' . (int)$control['id'];

$hasModule = static fn(bool $enabled): callable => static fn(array $r): bool =>
    count(array_filter($r['modules'] ?? [], static fn(array $m): bool =>
        $m['id'] === $report && $m['enabled'] === $enabled)) === 1;

for ($i = 1; $i <= 12; ++$i) {
    $space = $create('Synthetic module first-write race ' . $i);
    $path = '/spaces/' . (int)$space['id'];
    $t->check('grant independent manager', $a, 'POST', $path . '/members',
        ['uid' => $b, 'role' => 'manager'], 200);
    $t->check('module starts enabled without a configuration row', $a, 'GET',
        $path . '/modules', null, 200, $hasModule(true));
    $route = $path . '/modules/' . $report;
    $request = ['method' => 'PUT', 'path' => $route, 'data' => ['enabled' => false]];
    [$first, $second] = $t->parallel(['user' => $a, ...$request], ['user' => $b, ...$request]);
    $t->record('concurrent first disables both succeed #' . $i,
        $first['code'] === 200 && $second['code'] === 200
            && ($first['body']['enabled'] ?? null) === false
            && ($second['body']['enabled'] ?? null) === false,
        'HTTP ' . $first['code'] . '/' . $second['code'], [$first, $second]);
    $t->check('both authorized readers observe the disabled module', $b, 'GET',
        $path . '/modules', null, 200, $hasModule(false));
    $t->check('report access denied after concurrent disable', $a, 'GET',
        $path . '/trial-balance?through=2026-12-31', null, 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'module_disabled');
    $t->check('owner can re-enable the existing flag', $a, 'PUT', $route,
        ['enabled' => true], 200, static fn(array $r): bool => ($r['enabled'] ?? null) === true);
    $t->check('other workspace remains unaffected', $a, 'GET',
        $controlPath . '/modules', null, 200, $hasModule(true));
}
$t->finish();
