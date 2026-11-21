<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA'; $b = 'lfmaintestB';
$id = (int)$t->check('create isolated UX regression space', $a, 'POST', '/spaces',
    ['name' => 'UX regression ' . bin2hex(random_bytes(5)), 'kind' => 'organization', 'currency' => 'BRL'], 201)['id'];
$base = '/spaces/' . $id;
$accounts = $t->check('load original chart', $a, 'GET', $base . '/accounts', null, 200);
$byCode = array_column($accounts['accounts'], 'id', 'code');
$account = $t->check('create editable bank account', $a, 'POST', $base . '/accounts',
    ['code' => 'BANK_UX', 'name' => 'Initial bank', 'class' => 'asset', 'isBank' => true], 201);
$acc = (int)$account['id'];
$t->check('outside user cannot edit account', $b, 'PUT', $base . '/accounts/' . $acc,
    ['name' => 'Stolen bank', 'active' => true], 404);
$t->check('owner renames account without changing identity', $a, 'PUT', $base . '/accounts/' . $acc,
    ['name' => 'Approved bank', 'active' => true], 200,
    static fn(array $v): bool => $v['code'] === 'BANK_UX' && $v['name'] === 'Approved bank' && $v['class'] === 'asset');
$t->check('zero-balance account may be disabled', $a, 'PUT', $base . '/accounts/' . $acc,
    ['name' => 'Approved bank', 'active' => false], 200,
    static fn(array $v): bool => $v['active'] === false);
$t->check('inactive account cannot be used for posting', $a, 'POST', $base . '/journals',
    ['debitId' => $byCode['5000'], 'creditId' => $acc, 'amountMinor' => 1000,
        'date' => '2026-11-21', 'memo' => 'Inactive account posting', 'externalKey' => 'ux:disabled:account:1234'], 404);
$t->check('owner reactivates account', $a, 'PUT', $base . '/accounts/' . $acc,
    ['name' => 'Approved bank', 'active' => true], 200);

$category = $t->check('create colored category', $a, 'POST', $base . '/categories',
    ['code' => 'UXEXPENSE', 'name' => 'Operations', 'kind' => 'expense',
        'accountId' => $byCode['5000'], 'color' => '#2563EB'], 201,
    static fn(array $v): bool => ($v['color'] ?? null) === '#2563EB');
$cat = (int)$category['id'];
$t->check('invalid category color denied', $a, 'PUT', $base . '/categories/' . $cat,
    ['name' => 'Bad', 'color' => 'javascript:invalid'], 422);
$t->check('rename and recolor category', $a, 'PUT', $base . '/categories/' . $cat,
    ['name' => 'Operating costs', 'color' => '#059669', 'active' => true], 200,
    static fn(array $v): bool => ($v['color'] ?? null) === '#059669');
$t->check('category colors persist in listings', $a, 'GET', $base . '/categories', null, 200,
    static fn(array $v): bool => count(array_filter($v['categories'] ?? [],
        static fn(array $cat): bool => $cat['color'] === '#059669')) === 1);

$layout = $t->check('initial dashboard layout has bounded widgets', $a, 'GET',
    $base . '/dashboard-layout', null, 200,
    static fn(array $v): bool => count($v['widgets'] ?? []) === 5);
$widgets = array_reverse($layout['widgets']);
$widgets[0]['width'] = 2;
$widgets[1]['visible'] = false;
$t->check('unknown widget cannot inject code', $a, 'PUT', $base . '/dashboard-layout',
    ['widgets' => [['id' => 'https://external.invalid', 'width' => 1, 'visible' => true]]], 422);
$t->check('persist reordered, hidden and expanded widgets', $a, 'PUT',
    $base . '/dashboard-layout', ['widgets' => $widgets], 200,
    static fn(array $v): bool => ($v['widgets'][0]['id'] ?? null) === 'quickActions');
$t->check('dashboard layout survives subsequent API requests', $a, 'GET',
    $base . '/dashboard-layout', null, 200,
    static fn(array $v): bool => ($v['widgets'][0]['id'] ?? null) === 'quickActions'
        && $v['widgets'][1]['visible'] === false);
$t->check('outside user cannot read dashboard settings', $b, 'GET',
    $base . '/dashboard-layout', null, 404);

$defs = $base . '/custom-fields';
$t->check('nonexistent field entity rejected', $a, 'POST', $defs . '/not_a_table',
    ['key' => 'unsafe', 'label' => 'Unsafe', 'type' => 'text'], 422);
$t->check('hidden mandatory field rejected', $a, 'POST', $defs . '/contact',
    ['key' => 'bad_hidden', 'label' => 'Invalid hidden required', 'type' => 'text',
        'required' => true, 'visible' => false], 422);
$hidden = $t->check('create manager-only hidden contact field', $a, 'POST', $defs . '/contact',
    ['key' => 'private_ref', 'label' => 'Private reference', 'type' => 'text',
        'visible' => false, 'required' => false], 201);
$visible = $t->check('create visible required contact field', $a, 'POST', $defs . '/contact',
    ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => true,
        'visible' => true, 'columnSpan' => 6, 'help' => 'Customer region'], 201);
$hiddenId = (string)$hidden['id']; $visibleId = (string)$visible['id'];
$t->check('required contact field cannot be omitted', $a, 'POST', $base . '/contacts',
    ['name' => 'Customer without region', 'kind' => 'customer'], 422);
$t->check('valid contact retains hidden and visible typed values', $a, 'POST', $base . '/contacts',
    ['name' => 'Field customer', 'kind' => 'customer',
        'customFields' => [$visibleId => 'South', $hiddenId => 'manager-only']], 201,
    static fn(array $v): bool => count($v['custom_fields'] ?? []) === 2);
$item = $t->check('create custom item field definition', $a, 'POST', $defs . '/item',
    ['key' => 'serial', 'label' => 'Serial', 'type' => 'text'], 201);
$itemId = (string)$item['id'];
$t->check('create item with typed field', $a, 'POST', $base . '/items',
    ['sku' => 'UX_ITEM', 'name' => 'Synthetic item', 'kind' => 'product',
        'unitPriceMinor' => 2500, 'customFields' => [$itemId => 'UX-123']], 201,
    static fn(array $v): bool => count($v['custom_fields'] ?? []) === 1);
$journalField = $t->check('create journal field definition', $a, 'POST', $defs . '/journal',
    ['key' => 'cost_center', 'label' => 'Cost center', 'type' => 'select',
        'options' => ['Operational', 'Admin'], 'required' => true], 201);
$journalId = (string)$journalField['id'];
$journal = [
    'debitId' => (int)$byCode['5000'], 'creditId' => (int)$acc,
    'amountMinor' => 3300, 'date' => '2026-11-21',
    'memo' => 'Synthetic journal with fields', 'externalKey' => 'ux:fields:20261121:post',
    'customFields' => [$journalId => 'Operational'],
    'notesMarkdown' => "**Synthetic** notes\n- item A",
];
$t->check('new journal requires cost center', $a, 'POST', $base . '/journals',
    array_replace($journal, ['externalKey' => 'ux:missing:20261121:post', 'customFields' => []]), 422);
$post = $t->check('record posted journal with immutable typed metadata and Markdown',
    $a, 'POST', $base . '/journals', $journal, 201);
$t->check('journal replay with altered notes conflicts', $a, 'POST', $base . '/journals',
    array_replace($journal, ['notesMarkdown' => '**Changed**']), 409);
$t->check('journal list includes saved Markdown and field values', $a, 'GET',
    $base . '/journals', null, 200, static fn(array $v): bool =>
        ($v['journals'][0]['notes_md'] ?? null) === "**Synthetic** notes\n- item A"
        && ($v['journals'][0]['custom_fields'][(string)$journalId] ?? null) === 'Operational');
$t->check('account with nonzero balance cannot be deactivated', $a, 'PUT',
    $base . '/accounts/' . $acc, ['name' => 'Approved bank', 'active' => false], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'account_has_balance');

$t->check('grant read-only access to B', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('viewer only discovers visible fields', $b, 'GET', $defs . '/contact', null, 200,
    static fn(array $v): bool => count($v['fields'] ?? []) === 1
        && ($v['fields'][0]['key'] ?? null) === 'region');
$t->check('viewer cannot access hidden customer data', $b, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => count($v['contacts'] ?? []) === 1
        && count($v['contacts'][0]['custom_fields'] ?? []) === 1
        && !array_key_exists((string)$hiddenId, $v['contacts'][0]['custom_fields']));
$t->check('viewer layout is independent of owner layout', $b, 'GET', $base . '/dashboard-layout',
    null, 200, static fn(array $v): bool => ($v['widgets'][0]['id'] ?? null) === 'summary');
$t->check('viewer cannot rename ledger account', $b, 'PUT', $base . '/accounts/' . $acc,
    ['name' => 'No', 'active' => true], 403);
$t->check('viewer cannot recolor category', $b, 'PUT', $base . '/categories/' . $cat,
    ['name' => 'Stolen', 'color' => '#DC2626'], 403);
$t->check('viewer cannot alter field schema', $b, 'PUT',
    $defs . '/contact/' . $visibleId, ['label' => 'Unauthorized'], 403);
$t->check('owner can reset widgets without touching financial records', $a, 'DELETE',
    $base . '/dashboard-layout', null, 200,
    static fn(array $v): bool => ($v['widgets'][0]['id'] ?? null) === 'summary');
$t->check('revoke viewer', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('revoked viewer cannot list fields', $b, 'GET', $defs . '/contact', null, 404);
$t->finish();
