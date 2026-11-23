<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

// This file is exclusively executed by tests/ci/smoke.sh against a disposable
// freshly installed Nextcloud. Do not use with production administrator login.
if (getenv('LF_SYNTHETIC_SAAS_LIFECYCLE') !== 'yes') {
    throw new RuntimeException('Synthetic SaaS lifecycle test opt-in required');
}
$url = (string)getenv('LF_BASE_URL');
$parts = parse_url($url);
if (!is_array($parts) || !in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true)
    || !str_ends_with((string)($parts['path'] ?? ''), '/apps/librefinance/api')) {
    throw new RuntimeException('SaaS lifecycle requires loopback app API');
}
$password = (string)getenv('LF_TEST_ADMIN_PASSWORD');
if ($password === '') {
    throw new RuntimeException('No synthetic CI admin password');
}
$t = new TestHarness();
$checks = 0;
/** @return array{status:int,data:array<string,mixed>} */
function adminCall(string $method, string $route, ?array $body = null): array {
    global $url, $password;
    if (!str_starts_with($route, '/') || str_contains($route, '..')) {
        throw new InvalidArgumentException('Invalid synthetic route');
    }
    $curl = curl_init($url . $route);
    if (!$curl instanceof CurlHandle) {
        throw new RuntimeException('Cannot initialize synthetic admin client');
    }
    $headers = ['OCS-APIREQUEST: true', 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => 'synthetictestadmin:' . $password,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($curl);
    if (!is_string($raw)) {
        throw new RuntimeException('Synthetic admin request failed: ' . curl_error($curl));
    }
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = json_decode($raw, true);
    return ['status' => $status, 'data' => is_array($decoded) ? $decoded : ['invalid_response' => true]];
}
function adminCheck(string $name, string $method, string $route, ?array $body,
    int $expected, ?callable $test = null): array {
    global $checks;
    $response = adminCall($method, $route, $body);
    $good = $response['status'] === $expected
        && ($test === null || $test($response['data']));
    echo $good ? 'PASS: ' : 'FAIL: ', $name, ' (HTTP ', $response['status'], ')', PHP_EOL;
    if (!$good) {
        throw new RuntimeException('Failed synthetic SaaS test ' . $name
            . ': ' . json_encode($response['data']));
    }
    ++$checks;
    return $response['data'];
}
function syntheticCheck(string $name, bool $ok): void {
    global $checks;
    echo $ok ? 'PASS: ' : 'FAIL: ', $name, PHP_EOL;
    if (!$ok) {
        throw new RuntimeException('Failed synthetic SaaS test ' . $name);
    }
    ++$checks;
}
$gid = 'lf_synth_finance_group';
$a = 'lfmaintestA';
$b = 'lfmaintestB';
adminCheck('admin can enable managed governance only in disposable lab',
    'PUT', '/admin/governance', ['managed' => true], 200);
try {
    // The instance parent policy is an actual runtime gate for tenant
    // activation, with no implicit break-glass override by a group admin.
    $parent = adminCheck('read current environment enablement policy', 'GET',
        '/saas/v1/admin/policies/environments.enabled', null, 200);
    $revision = (int)$parent['revision'];
    adminCheck('instance policy forbids new environment enablement', 'PUT',
        '/saas/v1/admin/policies/environments.enabled',
        ['valueJson' => 'false', 'expectedRevision' => $revision], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false);
    adminCheck('new environment cannot bypass instance policy', 'PUT',
        '/saas/v1/admin/groups/' . $gid . '/enablement',
        ['enabled' => true], 403,
        static fn(array $r): bool => ($r['error'] ?? '') === 'module_disabled');
    adminCheck('clear instance parent deny', 'DELETE',
        '/saas/v1/admin/policies/environments.enabled?expectedRevision=' . ($revision + 1),
        null, 200, static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === true);
    $first = adminCheck('admin enables one group without ledger creation',
        'PUT', '/saas/v1/admin/groups/' . $gid . '/enablement', ['enabled' => true], 200,
        static fn(array $r): bool =>
            ($r['environment']['state'] ?? null) === 'enabled_pending_admin'
            && ($r['environment']['canManage'] ?? null) === false);
    $t->check('unknown active-governance environment has 404 without enumeration', $b,
        'POST', '/saas/v1/environments/123e4567-e89b-42d3-a456-426614174000/provision',
        null, 404);
    $id = (string)$first['environment']['id'];
    syntheticCheck('tenant has an opaque UUID', preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1);
    adminCheck('enabling same group returns same durable ID',
        'PUT', '/saas/v1/admin/groups/' . $gid . '/enablement', ['enabled' => true], 200,
        static fn(array $r): bool => ($r['environment']['id'] ?? null) === $id);

    $t->check('ordinary member discovers the pending environment', $b, 'GET',
        '/saas/v1/environments', null, 200,
        static fn(array $r): bool => count(array_filter($r['environments'] ?? [],
            static fn(array $e): bool => ($e['id'] ?? '') === $id
                && ($e['canManage'] ?? null) === false)) === 1);
    $t->check('ordinary group member cannot activate financial environment', $b,
        'POST', '/saas/v1/environments/' . $id . '/provision', null, 404);

    adminCheck('instance admin explicitly delegates only group administration',
        'POST', '/admin/groups/' . $gid . '/delegates', ['uid' => $b], 201);
    $parallel = $t->parallel(
        ['user' => $b, 'method' => 'POST', 'path' => '/saas/v1/environments/' . $id . '/provision', 'data' => []],
        ['user' => $b, 'method' => 'POST', 'path' => '/saas/v1/environments/' . $id . '/provision', 'data' => []],
    );
    syntheticCheck('two parallel activation requests return identical tenant',
        count($parallel) === 2
        && $parallel[0]['code'] === 200 && $parallel[1]['code'] === 200
        && ($parallel[0]['body']['environment']['id'] ?? null) === $id
        && ($parallel[1]['body']['environment']['id'] ?? null) === $id);

    $t->check('delegated administrator can discover own active environment', $b,
        'GET', '/saas/v1/environments', null, 200,
        static fn(array $r): bool => count(array_filter($r['environments'] ?? [],
            static fn(array $e): bool => ($e['id'] ?? '') === $id
                && ($e['state'] ?? '') === 'active'
                && ($e['canManage'] ?? null) === true)) === 1);
    // Only qualified admins may change typed child policies. A parent
    // prohibition cannot be reopened at the environment level.
    $policyPrefix = '/saas/v1/environments/' . $id . '/policies';
    $t->check('ordinary group user cannot read environment policies', $a,
        'GET', $policyPrefix . '/effective', null, 404);
    $t->check('delegated admin reads inherited environment policy', $b,
        'GET', $policyPrefix . '/imports.enabled', null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === true
            && ($r['inherited'] ?? null) === true
            && ($r['editableByCurrentActor'] ?? null) === true);
    $t->check('child cannot override instance-only scope', $b, 'PUT',
        $policyPrefix . '/environments.enabled',
        ['valueJson' => 'true', 'expectedRevision' => 0], 422);
    $t->check('parent denial is not bypassed by child', $b, 'PUT',
        $policyPrefix . '/externalSharing.enabled',
        ['valueJson' => 'true', 'expectedRevision' => 0], 403);
    $t->check('delegated admin restricts environment imports', $b, 'PUT',
        $policyPrefix . '/imports.enabled',
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'environment'
            && ($r['revision'] ?? null) === 1);
    $t->check('stale environment policy update rejected', $b, 'PUT',
        $policyPrefix . '/imports.enabled',
        ['valueJson' => 'true', 'expectedRevision' => 0], 409);
    $t->check('clearing child policy restores instance inheritance', $b,
        'DELETE', $policyPrefix . '/imports.enabled?expectedRevision=1',
        null, 200, static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === true
            && ($r['inherited'] ?? null) === true);
    adminCheck('instance admin suspends enabled group without deleting data',
        'PUT', '/saas/v1/admin/groups/' . $gid . '/enablement', ['enabled' => false], 200,
        static fn(array $r): bool => ($r['environment']['state'] ?? null) === 'suspended');
    $t->check('suspended tenant cannot be activated', $b,
        'POST', '/saas/v1/environments/' . $id . '/provision', null, 423);
    adminCheck('instance admin can revoke the SaaS delegation',
        'DELETE', '/admin/groups/' . $gid . '/delegates/' . $b, null, 200);
    $t->check('revoked delegate cannot activate suspended environment', $b,
        'POST', '/saas/v1/environments/' . $id . '/provision', null, 404);
} finally {
    adminCheck('synthetic governance mode restored', 'PUT', '/admin/governance',
        ['managed' => false], 200);
}
echo 'PASS: synthetic admin lifecycle verified ', $checks, " privileged/consistency assertions\n";
echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
