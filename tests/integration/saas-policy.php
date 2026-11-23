<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

if (getenv('LF_SYNTHETIC_SAAS_POLICY') !== 'yes') {
    throw new RuntimeException('Synthetic policy test opt-in required');
}
$base = rtrim((string)getenv('LF_BASE_URL'), '/');
$parts = parse_url($base);
if (!is_array($parts) || !in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true)
    || !str_ends_with((string)($parts['path'] ?? ''), '/apps/librefinance/api')) {
    throw new RuntimeException('Only the loopback Nextcloud lab API is accepted');
}
$password = (string)getenv('LF_TEST_ADMIN_PASSWORD');
if ($password === '') {
    throw new RuntimeException('Missing synthetic admin credentials');
}
$checks = 0;
function policyAdminCall(string $method, string $path, ?array $data = null): array {
    global $base, $password;
    if (!str_starts_with($path, '/') || str_contains($path, '..')) {
        throw new InvalidArgumentException('Invalid synthetic request');
    }
    $curl = curl_init($base . $path);
    if (!$curl instanceof CurlHandle) {
        throw new RuntimeException('Cannot create local request');
    }
    $headers = ['OCS-APIREQUEST: true', 'Accept: application/json'];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
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
    $response = curl_exec($curl);
    if (!is_string($response)) {
        throw new RuntimeException('Loopback HTTP request failed: ' . curl_error($curl));
    }
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $body = json_decode($response, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : ['invalid_json' => true]];
}
function policyCheck(string $title, string $method, string $path, ?array $data,
    int $expected, ?callable $predicate = null): array {
    global $checks;
    $observed = policyAdminCall($method, $path, $data);
    $correct = $observed['status'] === $expected &&
        ($predicate === null || $predicate($observed['body']));
    echo $correct ? 'PASS: ' : 'FAIL: ', $title, ' HTTP ', $observed['status'], PHP_EOL;
    if (!$correct) {
        throw new RuntimeException('Policy HTTP test failed ' . $title . ': '
            . json_encode($observed['body']));
    }
    ++$checks;
    return $observed['body'];
}
$t = new TestHarness();
$key = '/saas/v1/admin/policies/environments.enabled';
policyCheck('instance admin can list typed registry', 'GET',
    '/saas/v1/admin/policies', null, 200,
    static fn(array $r): bool => count($r['policies'] ?? []) === 4);
policyCheck('instance policy defaults to deny and revision zero', 'GET',
    $key, null, 200, static fn(array $r): bool =>
        ($r['effectiveValue'] ?? null) === false && ($r['revision'] ?? null) === 0
        && ($r['inherited'] ?? null) === true);
policyCheck('unknown policy does not fall back to permissive default', 'GET',
    '/saas/v1/admin/policies/unknown.key', null, 404);
$t->check('ordinary finance operator cannot enumerate instance policies',
    'lfmaintestA', 'GET', '/saas/v1/admin/policies', null, 403);
$t->check('ordinary finance operator cannot set privileged instance policy',
    'lfmaintestB', 'PUT', $key, ['valueJson' => 'true', 'expectedRevision' => 0], 403);
policyCheck('instance admin sets a typed boolean at expected revision zero',
    'PUT', $key, ['valueJson' => 'true', 'expectedRevision' => 0], 200,
    static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true
        && ($r['revision'] ?? null) === 1 && ($r['sourceScope'] ?? null) === 'instance');
policyCheck('stale revision cannot overwrite policy',
    'PUT', $key, ['valueJson' => 'false', 'expectedRevision' => 0], 409,
    static fn(array $r): bool => ($r['error'] ?? '') === 'revision_conflict');
policyCheck('malformed JSON is rejected',
    'PUT', $key, ['valueJson' => '{invalid', 'expectedRevision' => 1], 422,
    static fn(array $r): bool => ($r['error'] ?? '') === 'invalid_policy_value');
policyCheck('incorrect type cannot mutate boolean',
    'PUT', $key, ['valueJson' => '"true"', 'expectedRevision' => 1], 422,
    static fn(array $r): bool => ($r['error'] ?? '') === 'invalid_policy_value');
policyCheck('unchanged revision remains valid after rejected writes', 'GET',
    $key, null, 200, static fn(array $r): bool =>
        ($r['revision'] ?? null) === 1 && ($r['effectiveValue'] ?? null) === true);
policyCheck('valid policy deny updates with matching revision',
    'PUT', $key, ['valueJson' => 'false', 'expectedRevision' => 1], 200,
    static fn(array $r): bool =>
        ($r['revision'] ?? null) === 2 && ($r['effectiveValue'] ?? null) === false);
policyCheck('stale clear rejected', 'DELETE', $key . '?expectedRevision=1',
    null, 409, static fn(array $r): bool => ($r['error'] ?? '') === 'revision_conflict');
policyCheck('revision-guarded clear restores default', 'DELETE',
    $key . '?expectedRevision=2', null, 200, static fn(array $r): bool =>
        ($r['revision'] ?? null) === 3 && ($r['inherited'] ?? null) === true
        && ($r['effectiveValue'] ?? null) === false);
policyCheck('cleared policy remains deny-by-default', 'GET', $key, null, 200,
    static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
        && ($r['sourceScope'] ?? null) === 'default');
echo "PASS: synthetic typed policy persistence and revocation lifecycle $checks assertions\n";
echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
