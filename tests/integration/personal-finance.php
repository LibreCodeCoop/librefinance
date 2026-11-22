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
$token = bin2hex(random_bytes(6));
$space = $t->check('create a household workspace without a company', $a, 'POST',
    '/spaces', ['name' => 'Household '.$token, 'kind' => 'personal', 'currency' => 'BRL'],
    201, static fn(array $v): bool => ($v['kind'] ?? '') === 'personal'
        && ($v['role'] ?? '') === 'owner');
$id = (int)$space['id'];
$base = '/spaces/'.$id;
$t->check('household starts without corporate identities', $a, 'GET',
    $base.'/companies', null, 200, static fn(array $v): bool => ($v['companies'] ?? null) === []);
$accounts = $t->check('household gets default chart and cash account', $a,
    'GET', $base.'/accounts', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 4);
$codes = array_column($accounts['accounts'], 'id', 'code');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$t->check('record a household expense without a registered company', $a,
    'POST', $base.'/journals', [
        'debitId' => (int)$codes['5000'],
        'creditId' => (int)$codes['1000'],
        'amountMinor' => 4250, 'date' => $today,
        'memo' => 'Household groceries',
        'externalKey' => 'household:groceries:'.$token,
    ], 201);
$t->check('household expense is a balanced posted journal', $a, 'GET',
    $base.'/journals', null, 200,
    static fn(array $v): bool => count($v['journals'] ?? []) === 1
        && ($v['journals'][0]['memo'] ?? '') === 'Household groceries'
        && count($v['journals'][0]['postings'] ?? []) === 2);
$t->check('personal space supports a two-way household contact', $a,
    'POST', $base.'/contacts',
    ['name' => 'Family contact '.$token, 'kind' => 'both'],
    201, static fn(array $v): bool => ($v['kind'] ?? '') === 'both');
$custom = $t->check('owner may customize household contact fields', $a,
    'POST', $base.'/custom-fields/contact',
    ['key' => 'household_group', 'label' => 'Household group', 'type' => 'text'],
    201);
$t->check('personal customization remains scoped to that space', $a,
    'GET', $base.'/custom-fields/contact', null, 200,
    static fn(array $v): bool => count($v['fields'] ?? []) === 1
        && (int)$v['fields'][0]['id'] === (int)$custom['id']);
$t->check('household still needs no company after financial activity', $a,
    'GET', $base.'/companies', null, 200,
    static fn(array $v): bool => ($v['companies'] ?? null) === []);
$t->check('other user cannot inspect personal household accounts', $b,
    'GET', $base.'/accounts', null, 404);
$t->check('other user cannot change personal field definitions', $b,
    'PUT', $base.'/custom-fields/contact/'.(int)$custom['id'],
    ['label' => 'Intrusive edit'], 404);
$t->finish();
