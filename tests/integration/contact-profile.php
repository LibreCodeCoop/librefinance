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
$tag = bin2hex(random_bytes(6));
$space = $t->check('create isolated contact profile workspace', $a, 'POST', '/spaces',
    ['name' => 'Synthetic contact profile ' . $tag, 'kind' => 'organization', 'currency' => 'BRL'], 201);
$base = '/spaces/' . $space['id'];
$name = 'Synthetic buyer ' . $tag;
$payload = ['name' => $name, 'kind' => 'both'];
$profile = [
    'contactPerson' => 'Synthetic person', 'phone' => '+55 21 0000-0000',
    'website' => 'https://example.invalid/',
    'billingAddress' => ['line1' => 'Rua Exemplo, 10', 'line2' => '  Sala  3',
        'city' => 'São José', 'region' => 'RJ', 'postalCode' => '00123-000', 'country' => 'BR'],
    'shippingAddress' => ['line1' => 'Synthetic street', 'line2' => null,
        'city' => 'Lisboa', 'region' => null, 'postalCode' => '0000-000', 'country' => 'PT'],
];
$contact = $t->check('create separate billing and shipping profile', $a, 'POST', $base . '/contacts',
    [...$payload, 'profile' => $profile], 201, static fn(array $v): bool => $v['profile'] === $profile);
$id = (int)$contact['id'];
$path = $base . '/contacts/' . $id;
$legacy = $t->check('similar name with distinct stable identity and missing profile', $a, 'POST', $base . '/contacts',
    $payload, 201, static fn(array $v): bool => $v['id'] !== $id && $v['profile']['phone'] === null
        && $v['profile']['billingAddress']['country'] === null && $v['profile']['shippingAddress']['line1'] === null);
$read = static function() use ($t, $a, $base, $id): array {
    $result = $t->request($a, 'GET', $base . '/contacts');
    return array_values(array_filter($result['body']['contacts'], static fn(array $v): bool => $v['id'] === $id))[0];
};
$t->record('authorized read preserves original profile', $read()['profile'] === $profile, 'same nested data');
$app = 'librefinance_invoicing';
$inv = $t->checkModule('create invoice using stable party ID', $a, $app, 'POST', $base . '/invoices',
    ['number' => 'PROFILE-' . strtoupper($tag), 'kind' => 'invoice', 'contactId' => $id,
        'issueOn' => '2026-11-20', 'dueOn' => '2026-12-20', 'country' => 'BR',
        'lines' => [['description' => 'Synthetic service', 'quantity' => 1, 'unitPriceMinor' => 10000]]], 201);
$invoicePath = $base . '/invoices/' . $inv['id'];
$t->checkModule('issue before modifying the party profile', $a, $app, 'POST', $invoicePath . '/issue', null, 200);
$issued = $t->requestModule($a, $app, 'GET', $invoicePath);
$t->check('legacy update omits profile and retains existing fields', $a, 'PUT', $path, $payload, 200,
    static fn(array $v): bool => $v['profile'] === $profile);
$before = $read();
foreach ([
    ['phone' => 4], ['website' => 'javascript:alert(1)'], ['billingAddress' => ['country' => 'ZZ']],
    ['shippingAddress' => ['line1' => str_repeat('á', 256)]], ['unexpected' => 'x'],
] as $i => $invalid) {
    $t->check('invalid profile ' . $i . ' rejected', $a, 'PUT', $path,
        [...$payload, 'profile' => $invalid], 422,
        static fn(array $v): bool => $v['error'] === 'invalid_contact_profile');
}
$t->record('rejected edits preserve contact data', $before === $read(), 'unchanged record');
$t->check('cross-workspace read denied', $b, 'GET', $base . '/contacts', null, 404);
$t->check('cross-workspace write denied', $b, 'PUT', $path, [...$payload, 'profile' => ['phone' => '123']], 404);
$other = $t->check('create different workspace', $b, 'POST', '/spaces',
    ['name' => 'Synthetic unrelated profile', 'kind' => 'organization', 'currency' => 'BRL'], 201);
$t->check('foreign contact cannot be updated from own workspace', $b, 'PUT',
    '/spaces/' . $other['id'] . '/contacts/' . $id, [...$payload, 'profile' => ['phone' => '123']], 404);
$changed = $profile;
$changed['billingAddress']['city'] = 'Rio de Janeiro';
$changed['phone'] = null;
$t->check('partial nested edit does not overwrite other fields', $a, 'PUT', $path,
    [...$payload, 'profile' => ['phone' => null, 'billingAddress' => ['city' => 'Rio de Janeiro']]], 200,
    static fn(array $v): bool => $v['profile'] === $changed);
$t->check('archive with unchanged historical party reference', $a, 'PUT', $path, [...$payload, 'active' => false], 200,
    static fn(array $v): bool => $v['active'] === false && $v['profile'] === $changed);
$t->record('issued evidence and financial facts unchanged', $issued === $t->requestModule($a, $app, 'GET', $invoicePath),
    'exact issued invoice response');
$t->check('viewer access', $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('viewer reads archived profile', $b, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => $v['contacts'][0]['id'] === $id && !$v['contacts'][0]['active']
        && $v['contacts'][0]['profile'] === $changed);
$t->check('viewer cannot modify profile', $b, 'PUT', $path, [...$payload, 'profile' => ['phone' => '123']], 403);
$t->check('restore and clear shipping explicitly', $a, 'PUT', $path,
    [...$payload, 'active' => true, 'profile' => ['shippingAddress' => null]], 200,
    static fn(array $v): bool => $v['active'] && $v['profile']['billingAddress'] === $changed['billingAddress']
        && count(array_filter($v['profile']['shippingAddress'], static fn($v) => $v !== null)) === 0);
$t->check('revoke viewer', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('revoked viewer cannot read profiles', $b, 'GET', $base . '/contacts', null, 404);
$t->finish();
