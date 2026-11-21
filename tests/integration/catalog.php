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
$token = bin2hex(random_bytes(8));
$space = $t->check('create catalog test space', $a, 'POST', '/spaces',
    ['name' => 'Catalog synthetic ' . $token, 'kind' => 'organization', 'currency' => 'BRL'], 201);
$id = (int)$space['id'];
$base = '/spaces/' . $id;

$t->check('catalog empty contacts', $a, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => ($v['contacts'] ?? null) === []);
$t->check('catalog empty items', $a, 'GET', $base . '/items', null, 200,
    static fn(array $v): bool => ($v['items'] ?? null) === []);
foreach (['contacts', 'items'] as $resource) {
    $t->check('B denied catalog ' . $resource, $b, 'GET', $base . '/' . $resource, null, 404,
        static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
}
$contact = $t->check('create customer', $a, 'POST', $base . '/contacts',
    ['name' => 'Synthetic client', 'kind' => 'customer',
     'email' => 'customer@example.invalid', 'taxId' => '00.000.000/0000-00'],
    201, static fn(array $v): bool => is_int($v['id'] ?? null) && ($v['kind'] ?? null) === 'customer');
$cid = (int)$contact['id'];
$t->check('customer visible in scoped list', $a, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => count($v['contacts'] ?? []) === 1
        && ($v['contacts'][0]['email'] ?? null) === 'customer@example.invalid');
$t->check('invalid email rejected', $a, 'POST', $base . '/contacts',
    ['name' => 'Bad contact', 'kind' => 'vendor', 'email' => 'not-an-email'], 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_email');
$t->check('update customer without deleting data', $a, 'PUT', $base . '/contacts/' . $cid,
    ['name' => 'Synthetic client updated', 'kind' => 'both',
     'email' => 'client@example.invalid', 'taxId' => '00.000.000/0000-00', 'active' => true], 200,
    static fn(array $v): bool => ($v['name'] ?? null) === 'Synthetic client updated'
        && ($v['tax_id'] ?? null) === '00.000.000/0000-00');
$t->check('reject cross-company contact update', $b, 'PUT', $base . '/contacts/' . $cid,
    ['name' => 'Abuse', 'kind' => 'vendor'], 404);
$t->check('reject nonexistent contact', $a, 'PUT', $base . '/contacts/99999999',
    ['name' => 'Abuse', 'kind' => 'vendor'], 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'contact_not_found');

$item = $t->check('create product', $a, 'POST', $base . '/items',
    ['sku' => 'ITEM-' . $token, 'name' => 'Synthetic product',
     'kind' => 'product', 'unitPriceMinor' => 1299, 'description' => 'Test only'], 201,
    static fn(array $v): bool => is_int($v['id'] ?? null) && ($v['unit_price_minor'] ?? null) === 1299);
$itemId = (int)$item['id'];
$t->check('duplicate SKU denied with conflict', $a, 'POST', $base . '/items',
    ['sku' => 'ITEM-' . $token, 'name' => 'Duplicate SKU',
     'kind' => 'service', 'unitPriceMinor' => 42], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'sku_conflict');
$t->check('price stays integer in list', $a, 'GET', $base . '/items', null, 200,
    static fn(array $v): bool => count($v['items'] ?? []) === 1
        && $v['items'][0]['unit_price_minor'] === 1299);
$t->check('reject negative catalog price', $a, 'POST', $base . '/items',
    ['sku' => 'NEG' . $token, 'name' => 'Negative price',
     'kind' => 'service', 'unitPriceMinor' => -1], 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_unit_price');
$t->check('update service price', $a, 'PUT', $base . '/items/' . $itemId,
    ['sku' => 'ITEM-' . $token, 'name' => 'Updated item',
     'kind' => 'service', 'unitPriceMinor' => 2500, 'description' => 'Changed', 'active' => true],
    200, static fn(array $v): bool => ($v['unit_price_minor'] ?? null) === 2500);
$t->check('reject cross-company item update', $b, 'PUT', $base . '/items/' . $itemId,
    ['sku' => 'ABUSE', 'name' => 'Abuse', 'kind' => 'service', 'unitPriceMinor' => 1], 404);
$t->check('reject nonexistent item', $a, 'PUT', $base . '/items/99999999',
    ['sku' => 'UNKNOWN', 'name' => 'Missing', 'kind' => 'service', 'unitPriceMinor' => 1],
    404, static fn(array $v): bool => ($v['error'] ?? null) === 'item_not_found');
$t->check('viewer can inspect catalog', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('viewer B gets products', $b, 'GET', $base . '/items', null, 200,
    static fn(array $v): bool => count($v['items'] ?? []) === 1);
$t->check('viewer B denied creating customers', $b, 'POST', $base . '/contacts',
    ['name' => 'Forbidden', 'kind' => 'customer'], 403);
$t->check('viewer B denied editing product', $b, 'PUT', $base . '/items/' . $itemId,
    ['sku' => 'NEW', 'name' => 'Forbidden', 'kind' => 'service', 'unitPriceMinor' => 1], 403);
$t->check('revoke catalog viewer', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('viewer B loses catalog access', $b, 'GET', $base . '/items', null, 404);
$t->finish();
