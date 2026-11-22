<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;
$t = new TestHarness();
$a = 'lfmaintestA'; $b = 'lfmaintestB';
$tag = bin2hex(random_bytes(5));
$space = $t->check('create report catalog space', $a, 'POST', '/spaces',
    ['name' => 'Saved report QA ' . $tag, 'kind' => 'collective', 'currency' => 'BRL'], 201);
$spaceId = (int)$space['id'];
$root = '/spaces/' . $spaceId;
$path = $root . '/saved-reports';
$app = 'librefinance_reports';
$t->checkModule('initial report catalog empty', $a, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => ($v['reports'] ?? null) === []
        && count($v['supportedKinds'] ?? []) === 3);
$t->checkModule('other user cannot list report catalog', $b, $app, 'GET', $path, null, 404);
$t->checkModule('unknown report engine denied', $a, $app, 'POST', $path,
    ['name' => 'Unsafe query', 'kind' => 'sql', 'from' => '2026-01-01', 'through' => '2026-11-30'], 422);
$t->checkModule('invalid report date denied', $a, $app, 'POST', $path,
    ['name' => 'Bad period', 'kind' => 'profit_loss', 'from' => '2026-02-31', 'through' => '2026-11-30'], 422);
$created = $t->checkModule('save a named profit loss report', $a, $app, 'POST', $path,
    ['name' => 'Annual operating result', 'kind' => 'profit_loss',
        'from' => '2026-01-01', 'through' => '2026-11-30'], 201,
    static fn(array $v): bool => ($v['kind'] ?? null) === 'profit_loss');
$reportId = (int)$created['id'];
$t->checkModule('save configurable balance report with selected columns and class', $a, $app, 'POST', $path,
    ['name' => 'Balance snapshot', 'kind' => 'trial_balance',
        'from' => null, 'through' => '2026-11-30', 'columns' => ['name', 'balanceMinor'],
        'accountClass' => 'asset'], 201,
    static fn(array $v): bool => ($v['columns'] ?? null) === ['name', 'balanceMinor']
        && ($v['accountClass'] ?? null) === 'asset');
$t->checkModule('invalid custom column does not become SQL', $a, $app, 'POST', $path,
    ['name' => 'Bad columns', 'kind' => 'trial_balance', 'through' => '2026-11-30',
        'columns' => ['name', 'DROP TABLE oc_lf_accounts']], 422);
$t->checkModule('invalid column object rejected rather than treated as SQL', $a, $app, 'POST', $path,
    ['name' => 'Bad shape', 'kind' => 'trial_balance', 'through' => '2026-11-30',
        'columns' => [['malicious' => 'field']]], 422);
$t->checkModule('invalid account class filter rejected', $a, $app, 'POST', $path,
    ['name' => 'Bad account class', 'kind' => 'trial_balance',
        'through' => '2026-11-30', 'accountClass' => 'unknown'], 422);
$t->checkModule('balance filter is applied before pagination', $a, $app, 'GET',
    $root . '/trial-balance?through=2026-11-30&accountClass=asset', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) >= 1
        && count(array_filter($v['accounts'], static fn(array $a): bool => $a['class'] !== 'asset')) === 0);
$t->checkModule('filter cannot access data in another space', $b, $app, 'GET',
    $root . '/trial-balance?through=2026-11-30&accountClass=asset', null, 404);

$t->checkModule('saved report catalog is persistent', $a, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => count($v['reports'] ?? []) === 2
        && ($v['reports'][0]['name'] ?? null) === 'Annual operating result');
$t->checkModule('other user cannot update report', $b, $app, 'PUT', $path . '/' . $reportId,
    ['name' => 'Stolen', 'kind' => 'cash_flow', 'from' => '2026-01-01', 'through' => '2026-11-30'], 404);
$t->checkModule('owner updates saved report configuration', $a, $app, 'PUT', $path . '/' . $reportId,
    ['name' => 'Rolling cash flow', 'kind' => 'cash_flow',
        'from' => '2026-11-01', 'through' => '2026-11-30'], 200,
    static fn(array $v): bool => ($v['kind'] ?? null) === 'cash_flow');
$t->check('grant report viewer', $a, 'POST', $root . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('viewer reads saved reports', $b, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => count($v['reports'] ?? []) === 2);
$t->checkModule('viewer cannot create report', $b, $app, 'POST', $path,
    ['name' => 'Unauthorized', 'kind' => 'profit_loss',
        'from' => '2026-01-01', 'through' => '2026-11-30'], 403);
$t->checkModule('viewer cannot archive report', $b, $app, 'DELETE', $path . '/' . $reportId, null, 403);
$t->checkModule('owner archives saved report without deleting accounting', $a, $app, 'DELETE',
    $path . '/' . $reportId, null, 200,
    static fn(array $v): bool => ($v['archived'] ?? false) === true);
$t->checkModule('archived report no longer in catalog', $a, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => count($v['reports'] ?? []) === 1);
$t->checkModule('archived report cannot be overwritten', $a, $app, 'PUT', $path . '/' . $reportId,
    ['name' => 'Again', 'kind' => 'profit_loss', 'from' => '2026-01-01', 'through' => '2026-11-30'], 404);
$t->checkModule('viewer cannot permanently delete archived model', $b, $app, 'DELETE',
    $path . '/' . $reportId . '/permanent', null, 403);
$t->checkModule('manager permanently deletes only archived report configuration', $a,
    $app, 'DELETE', $path . '/' . $reportId . '/permanent', null, 200,
    static fn(array $v): bool => ($v['deleted'] ?? false) === true);
$t->checkModule('repeat permanent delete returns not found', $a, $app, 'DELETE',
    $path . '/' . $reportId . '/permanent', null, 404);
$temporary = $t->checkModule('create disposable report configuration', $a, $app, 'POST',
    $path, ['name' => 'Temporary template', 'kind' => 'trial_balance',
        'through' => '2026-11-30'], 201);
$t->checkModule('manager deletes unused active model permanently', $a, $app, 'DELETE',
    $path . '/' . $temporary['id'] . '/permanent', null, 200,
    static fn(array $v): bool => ($v['deleted'] ?? false) === true);
$t->checkModule('only intended report remains', $a, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => count($v['reports'] ?? []) === 1);
$t->check('disable report module for this space', $a, 'PUT', $root . '/modules/' . $app,
    ['enabled' => false], 200);
$t->checkModule('disabled module denies saved catalog even owner', $a, $app, 'GET', $path, null, 403);
$t->check('reenable report module', $a, 'PUT', $root . '/modules/' . $app, ['enabled' => true], 200);
$t->check('revoke report viewer', $a, 'DELETE', $root . '/members/' . $b, null, 200);
$t->checkModule('revoked user cannot discover saved reports', $b, $app, 'GET', $path, null, 404);
$t->finish();
