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
$space = $t->check('create organization space for company registry', $a, 'POST',
    '/spaces', ['name' => 'Synthetic companies', 'kind' => 'organization', 'currency' => 'BRL'], 201);
$id = (int)$space['id'];
$base = '/spaces/' . $id . '/companies';
$t->check('initial company registry empty', $a, 'GET', $base, null, 200,
    static fn(array $v): bool => $v['companies'] === []);
$t->check('other user cannot enumerate companies', $b, 'GET', $base, null, 404);
$payload = ['legalName' => 'Synthetic Cooperative Alpha',
    'country' => 'BR', 'taxId' => '12345678000190',
    'tradeName' => 'Alpha', 'municipalRegistration' => 'TEST-123'];
$first = $t->check('register legal entity in permitted finance space', $a, 'POST',
    $base, $payload, 201,
    static fn(array $v): bool => ($v['legal_name'] ?? null) === 'Synthetic Cooperative Alpha'
        && ($v['tax_id'] ?? null) === '12345678000190');
$companyId = (int)$first['id'];
$t->check('duplicate entity in same space denied', $a, 'POST',
    $base, $payload, 409, static fn(array $v): bool => ($v['error'] ?? null) === 'company_tax_id_conflict');
$t->check('company list returns scoped record', $a, 'GET', $base, null, 200,
    static fn(array $v): bool => count($v['companies'] ?? []) === 1
        && $v['companies'][0]['trade_name'] === 'Alpha');
$t->check('invalid legal country rejected', $a, 'POST', $base,
    [...$payload, 'country' => 'XX'], 422);
$t->check('invalid tax identifier rejected', $a, 'POST', $base,
    [...$payload, 'taxId' => 'WRONG'], 422);
$t->check('other user cannot update company', $b, 'PUT',
    $base . '/' . $companyId, $payload, 404);
$t->check('missing company cannot be updated', $a, 'PUT',
    $base . '/99999999', $payload, 404);
$t->check('manager edits company attributes without replacing identity', $a, 'PUT',
    $base . '/' . $companyId,
    [...$payload, 'legalName' => 'Synthetic Cooperative Alpha Edited',
        'stateRegistration' => 'EXEMPT', 'active' => false], 200,
    static fn(array $v): bool => ($v['legal_name'] ?? null) === 'Synthetic Cooperative Alpha Edited'
        && ($v['state_registration'] ?? null) === 'EXEMPT'
        && $v['active'] === false);
$t->check('inactive record remains auditable', $a, 'GET', $base, null, 200,
    static fn(array $v): bool => ($v['companies'][0]['active'] ?? null) === false);
$t->check('viewer group can inspect the permitted company', $a, 'POST',
    '/spaces/' . $id . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('viewer can list scoped company', $b, 'GET', $base, null, 200);
$t->check('viewer cannot alter company registry', $b, 'PUT',
    $base . '/' . $companyId, $payload, 403);
$t->check('viewer cannot create a company', $b, 'POST', $base, $payload, 403);
$t->check('revoking viewer cuts off company data', $a, 'DELETE',
    '/spaces/' . $id . '/members/' . $b, null, 200);
$t->check('revoked user cannot list companies', $b, 'GET', $base, null, 404);
$t->finish();
