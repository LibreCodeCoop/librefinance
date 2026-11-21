<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA'; $b = 'lfmaintestB';
$tag = bin2hex(random_bytes(5));
$space = $t->check('create synthetic field studio space', $a, 'POST', '/spaces',
    ['name' => 'Custom entity QA ' . $tag, 'kind' => 'organization', 'currency' => 'BRL'], 201);
$id = (int)$space['id'];
$base = '/spaces/' . $id;
$defs = $base . '/company-fields';
$t->check('empty field definitions', $a, 'GET', $defs, null, 200,
    static fn(array $v): bool => ($v['fields'] ?? null) === []);
$t->check('unrelated user denied definitions', $b, 'GET', $defs, null, 404);
$t->check('invalid field type refused', $a, 'POST', $defs,
    ['key' => 'bad_sql', 'label' => 'Unsafe', 'type' => 'sql'], 422);
$t->check('invalid option values refused', $a, 'POST', $defs,
    ['key' => 'dup_opts', 'label' => 'Invalid', 'type' => 'select', 'options' => ['A','A']], 422);
$name = $t->check('create required entity field', $a, 'POST', $defs,
    ['key' => 'registry', 'label' => 'Registration reference', 'type' => 'text', 'required' => true], 201);
$fieldId = (int)$name['id'];
$t->check('duplicate field key refused', $a, 'POST', $defs,
    ['key' => 'registry', 'label' => 'Duplicate', 'type' => 'text'], 409);
$rate = $t->check('create decimal field', $a, 'POST', $defs,
    ['key' => 'rate', 'label' => 'Tax rate', 'type' => 'number'], 201);
$rateId = (int)$rate['id'];
$choice = $t->check('create select field', $a, 'POST', $defs,
    ['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'options' => ['A','B']], 201);
$choiceId = (int)$choice['id'];
$boolean = $t->check('create boolean field', $a, 'POST', $defs,
    ['key' => 'approved', 'label' => 'Approved', 'type' => 'boolean'], 201);
$boolId = (int)$boolean['id'];
$date = $t->check('create date field', $a, 'POST', $defs,
    ['key' => 'registered_on', 'label' => 'Registered on', 'type' => 'date'], 201);
$dateId = (int)$date['id'];
$company = $base . '/companies';
$minimal = ['legalName' => 'International Synthetic Cooperative', 'country' => 'PT'];
$t->check('required extra field blocks empty company', $a, 'POST', $company, $minimal, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'required_custom_field');
$t->check('select rejects unlisted option before company creation', $a, 'POST', $company,
    [...$minimal, 'customFields' => [(string)$fieldId => 'PT-ABC', (string)$choiceId => 'C']], 422);
$t->check('number rejects precision loss', $a, 'POST', $company,
    [...$minimal, 'customFields' => [(string)$fieldId => 'PT-ABC', (string)$rateId => '1.23456']], 422);
$t->check('invalid ISO country code denied', $a, 'POST', $company,
    [...$minimal, 'country' => 'XX', 'customFields' => [(string)$fieldId => 'PT-ABC']], 422);
$created = $t->check('create PT entity without a mandatory national tax ID', $a, 'POST', $company,
    [...$minimal, 'customFields' => [
        (string)$fieldId => 'PT-ABC', (string)$rateId => '12.2500',
        (string)$choiceId => 'A', (string)$boolId => false,
        (string)$dateId => '2026-11-20',
    ]], 201, static fn(array $v): bool => ($v['country'] ?? null) === 'PT'
        && array_key_exists('tax_id', $v) && $v['tax_id'] === null
        && ($v['custom_fields'][(string)$fieldId] ?? '') === 'PT-ABC');
$companyId = (int)$created['id'];
$t->check('second PT entity without tax ID not treated as duplicate', $a, 'POST', $company,
    ['legalName' => 'Another PT Entity', 'country' => 'PT',
        'customFields' => [(string)$fieldId => 'PT-XYZ']], 201);
$t->check('company list persists typed values and keeps isolation', $a, 'GET', $company, null, 200,
    static fn(array $v): bool => count($v['companies'] ?? []) === 2
        && ($v['companies'][0]['custom_fields'][(string)$rateId] ?? null) === '12.2500'
        && ($v['companies'][0]['custom_fields'][(string)$boolId] ?? null) === false);
$t->check('outside user cannot read custom company data', $b, 'GET', $company, null, 404);
$t->check('outside user cannot alter field definition', $b, 'PUT', $defs . '/' . $fieldId,
    ['label' => 'Stolen'], 404);
$t->check('required field cannot be cleared', $a, 'PUT', $company . '/' . $companyId,
    [...$minimal, 'customFields' => [(string)$fieldId => null]], 422);
$t->check('company update preserves unmentioned custom values', $a, 'PUT',
    $company . '/' . $companyId, [...$minimal, 'legalName' => 'Renamed Entity'], 200,
    static fn(array $v): bool => ($v['custom_fields'][(string)$fieldId] ?? null) === 'PT-ABC');
$t->check('rename field without altering type or data', $a, 'PUT', $defs . '/' . $fieldId,
    ['label' => 'Official registry reference', 'required' => true, 'active' => true], 200);
$t->check('deactivate field without deletion', $a, 'PUT', $defs . '/' . $choiceId,
    ['label' => 'Tier', 'required' => false, 'active' => false], 200);
$t->check('inactive field refuses new value', $a, 'PUT', $company . '/' . $companyId,
    [...$minimal, 'customFields' => [(string)$choiceId => 'B']], 404);
$t->check('inactive field retains historical values on read', $a, 'GET', $company, null, 200,
    static fn(array $v): bool => ($v['companies'][0]['custom_fields'][(string)$choiceId] ?? null) === 'A');
$t->check('grant readonly access', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('viewer can inspect field metadata', $b, 'GET', $defs, null, 200);
$t->check('viewer cannot register a custom field', $b, 'POST', $defs,
    ['key' => 'unauthorized', 'label' => 'No', 'type' => 'text'], 403);
$t->check('viewer cannot overwrite typed company values', $b, 'PUT', $company . '/' . $companyId,
    [...$minimal, 'customFields' => [(string)$fieldId => 'stolen']], 403);
$t->check('viewer cannot rename custom field', $b, 'PUT', $defs . '/' . $fieldId,
    ['label' => 'No'], 403);
$t->check('revoke readonly access', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('revoked viewer blocked immediately', $b, 'GET', $defs, null, 404);
$t->finish();
