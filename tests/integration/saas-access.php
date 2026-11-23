<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

// Never run this stateful suite on any shared or production Nextcloud.
if (getenv('LF_SYNTHETIC_SAAS_ACCESS') !== 'yes') {
    throw new RuntimeException('Synthetic SaaS access test requires explicit opt-in');
}
$base = rtrim((string)getenv('LF_BASE_URL'), '/');
$parts = parse_url($base);
if (!is_array($parts) || !in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true)
    || !str_ends_with((string)($parts['path'] ?? ''), '/apps/librefinance/api')) {
    throw new RuntimeException('SaaS tests accept loopback disposable Nextcloud only');
}
$password = (string)getenv('LF_TEST_ADMIN_PASSWORD');
if ($password === '') {
    throw new RuntimeException('No synthetic instance admin password');
}
$checks = 0;
$t = new TestHarness();

function instanceRequest(string $verb, string $path, ?array $data = null): array {
    global $base, $password;
    if (!str_starts_with($path, '/') || str_contains($path, '..')) {
        throw new InvalidArgumentException('Unsafe synthetic path');
    }
    $handle = curl_init($base . $path);
    if (!$handle instanceof CurlHandle) {
        throw new RuntimeException('Cannot create local Nextcloud HTTP client');
    }
    $headers = ['OCS-APIREQUEST: true', 'Accept: application/json'];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $verb,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => 'synthetictestadmin:' . $password,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($handle);
    if (!is_string($body)) {
        throw new RuntimeException('Synthetic request failed: ' . curl_error($handle));
    }
    $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $decoded = json_decode($body, true);
    return ['code' => $code, 'body' => is_array($decoded) ? $decoded : ['invalid_json' => true]];
}

function instanceCheck(string $name, string $verb, string $path, ?array $data,
    int $expected, ?callable $validate = null): array {
    global $checks;
    $result = instanceRequest($verb, $path, $data);
    $valid = $result['code'] === $expected
        && ($validate === null || $validate($result['body']));
    echo $valid ? 'PASS: ' : 'FAIL: ', $name, ' (HTTP ', $result['code'], ')', PHP_EOL;
    if (!$valid) {
        throw new RuntimeException('Unexpected synthetic result: ' . $name . ' '
            . json_encode($result['body']));
    }
    ++$checks;
    return $result['body'];
}
function saasCheck(string $name, bool $correct): void {
    global $checks;
    echo $correct ? 'PASS: ' : 'FAIL: ', $name, PHP_EOL;
    if (!$correct) {
        throw new RuntimeException('SaaS check failed: ' . $name);
    }
    ++$checks;
}
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$groupA = 'lf_synth_saas_A';
$groupB = 'lf_synth_saas_B';
instanceCheck('enable governance in the disposable app only', 'PUT',
    '/admin/governance', ['managed' => true], 200);
try {
    $envA = instanceCheck('enable environment A', 'PUT',
        '/saas/v1/admin/groups/' . $groupA . '/enablement',
        ['enabled' => true], 200)['environment']['id'];
    $envB = instanceCheck('enable environment B', 'PUT',
        '/saas/v1/admin/groups/' . $groupB . '/enablement',
        ['enabled' => true], 200)['environment']['id'];
    saasCheck('distinct immutable SaaS identities', is_string($envA)
        && is_string($envB) && $envA !== $envB);
    instanceCheck('delegate environment A to B without finance grant', 'POST',
        '/admin/groups/' . $groupA . '/delegates', ['uid' => $b], 201);
    instanceCheck('delegate environment B to A without finance grant', 'POST',
        '/admin/groups/' . $groupB . '/delegates', ['uid' => $a], 201);
    $t->check('B activates A as delegated group admin', $b, 'POST',
        '/saas/v1/environments/' . $envA . '/provision', null, 200);
    $t->check('A activates B as delegated group admin', $a, 'POST',
        '/saas/v1/environments/' . $envB . '/provision', null, 200);

    instanceCheck('instance limits independently counted workspaces per environment',
        'PUT', '/saas/v1/admin/policies/workspaces.maxCount',
        ['valueJson' => '1', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === 1);
    $pathA = '/saas/v1/environments/' . $envA . '/workspaces';
    $pathB = '/saas/v1/environments/' . $envB . '/workspaces';
    $t->check('regular group member cannot provision financial workspace', $a,
        'POST', $pathA, ['name' => 'Forged A',
            'ownerUid' => $a, 'idempotencyKey' => 'synthetic-no-grant-001'], 404);
    $aSpace = $t->check('B provisions workspace owned by A with no B ledger grant', $b,
        'POST', $pathA,
        ['name' => 'Isolated A1', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A1-001'], 201);
    $spaceA = (int)$aSpace['id'];
    $t->check('workspace provisioning is idempotent', $b, 'POST', $pathA,
        ['name' => 'Isolated A1', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A1-001'], 201,
        static fn(array $r): bool => (int)($r['id'] ?? 0) === $spaceA);
    $t->check('reusing key for another ledger is rejected', $b, 'POST', $pathA,
        ['name' => 'Different A1', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A1-001'], 409);
    $t->check('second independent ledger exceeds tenant A quota', $b,
        'POST', $pathA,
        ['name' => 'A2 exceeds quota', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A2-002'], 409,
        static fn(array $r): bool => ($r['error'] ?? '') === 'workspace_limit_exceeded');
    $bSpace = $t->check('A provisions independent workspace owned by B', $a,
        'POST', $pathB,
        ['name' => 'Isolated B1', 'ownerUid' => $b,
            'idempotencyKey' => 'synthetic-workspace-B1-001'], 201);
    $spaceB = (int)$bSpace['id'];
    saasCheck('workspace IDs are not reused between environments',
        $spaceA > 0 && $spaceB > 0 && $spaceA !== $spaceB);
    instanceCheck('clearing instance workspace limit restores inheritance',
        'DELETE', '/saas/v1/admin/policies/workspaces.maxCount?expectedRevision=1',
        null, 200, static fn(array $r): bool =>
            ($r['inherited'] ?? null) === true);
    // This setting is resolved for the active environment; a user in both
    // groups never receives a union of permissive child scopes.
    $policyPathA = '/saas/v1/environments/' . $envA . '/policies/imports.enabled';
    $t->check('environment A administrator restricts imports only for A', $b,
        'PUT', $policyPathA,
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'environment');
    $t->check('A financial owner sees imports blocked by own environment',
        $a, 'GET', '/spaces/' . $spaceA . '/modules', null, 200,
        static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
            static fn(array $m): bool => ($m['id'] ?? '') === 'librefinance_import'
                && ($m['enabled'] ?? null) === false)) === 1);
    $t->check('B financial owner in different environment remains eligible',
        $b, 'GET', '/spaces/' . $spaceB . '/modules', null, 200,
        static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
            static fn(array $m): bool => ($m['id'] ?? '') === 'librefinance_import'
                && ($m['enabled'] ?? null) === true)) === 1);
    $t->check('A import HTTP request is denied by environment policy',
        $a, 'POST', '/spaces/' . $spaceA . '/preview',
        ['exportJson' => 'invalid'], 403,
        static fn(array $r): bool => ($r['error'] ?? '') === 'module_disabled');
    $t->check('clear A restriction without modifying B policy', $b,
        'DELETE', $policyPathA . '?expectedRevision=1',
        null, 200, static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true);
    instanceCheck('legacy instance-admin context list omits new SaaS books',
        'GET', '/admin/contexts', null, 200,
        static fn(array $r): bool =>
            !in_array($spaceA, array_column($r['contexts'] ?? [], 'id'), true)
            && !in_array($spaceB, array_column($r['contexts'] ?? [], 'id'), true));
    instanceCheck('legacy bind cannot create group-wide viewer grants in SaaS',
        'PUT', '/admin/contexts/' . $spaceA . '/group',
        ['gid' => $groupA], 403);
    $t->check('group admin cannot self-promote to financial owner', $a,
        'POST', $pathB, ['name' => 'Self promoted',
            'ownerUid' => $a, 'idempotencyKey' => 'synthetic-self-grant-001'], 409);

    // These routes exercise the *real* previously bypassed AccessService.
    $t->check('A sees only its own finance workspace', $a, 'GET',
        '/spaces', null, 200, static fn(array $r): bool =>
            in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true));
    $t->check('B sees only its own finance workspace', $b, 'GET',
        '/spaces', null, 200, static fn(array $r): bool =>
            in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true));
    instanceCheck('Nextcloud instance admin cannot list SaaS financial books', 'GET',
        '/spaces', null, 200, static fn(array $r): bool =>
            !in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true));
    $t->check('B group admin cannot read ledger A', $b, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 404);
    $t->check('A environment admin cannot read ledger B', $a, 'GET',
        '/spaces/' . $spaceB . '/journals', null, 404);
    instanceCheck('instance admin cannot read ledger A by forged ID', 'GET',
        '/spaces/' . $spaceA . '/journals', null, 404);
    instanceCheck('instance admin cannot write ledger A by forged ID', 'POST',
        '/spaces/' . $spaceA . '/journals',
        ['debitId' => 1, 'creditId' => 2, 'amountMinor' => 100,
         'date' => '2026-11-23', 'memo' => 'Denied',
         'externalKey' => 'synthetic-admin-unauthorized'], 404);

    $accounts = $t->check('A can list its ledger accounts', $a, 'GET',
        '/spaces/' . $spaceA . '/accounts', null, 200,
        static fn(array $r): bool => count($r['accounts'] ?? []) === 4);
    $ids = array_column($accounts['accounts'], 'id', 'code');
    $t->check('A can post balanced journal only to A1', $a, 'POST',
        '/spaces/' . $spaceA . '/journals',
        ['debitId' => $ids['1000'], 'creditId' => $ids['4000'],
            'amountMinor' => 12300, 'date' => '2026-11-23',
            'memo' => 'Synthetic isolated A',
            'externalKey' => 'saas-A1-once'], 201);
    $t->check('B ledger never receives A posting', $b, 'GET',
        '/spaces/' . $spaceB . '/journals', null, 200,
        static fn(array $r): bool => count($r['journals'] ?? []) === 0);
    $t->check('B cannot confer access to A ledger by group sharing', $b,
        'POST', '/spaces/' . $spaceB . '/groups',
        ['gid' => $groupA, 'role' => 'viewer'], 403);

    $t->check('A creates legal company linked to A1', $a, 'POST',
        '/spaces/' . $spaceA . '/companies',
        ['legalName' => 'Synthetic Example A', 'country' => 'BR'], 201);
    $t->check('A1 cannot house a second unrelated legal company', $a, 'POST',
        '/spaces/' . $spaceA . '/companies',
        ['legalName' => 'Synthetic Example B', 'country' => 'BR'], 409);

    $t->check('A explicitly grants B viewer access to A1', $a, 'POST',
        '/spaces/' . $spaceA . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
    $t->check('B may now read A ledger through direct grant', $b, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 200);
    $t->check('B remains unable to post to A as viewer', $b, 'POST',
        '/spaces/' . $spaceA . '/journals',
        ['debitId' => $ids['1000'], 'creditId' => $ids['4000'],
            'amountMinor' => 100, 'date' => '2026-11-23',
            'memo' => 'Forbidden viewer', 'externalKey' => 'saas-B-forged'], 403);
    $t->check('B cannot elevate itself to finance manager', $b, 'POST',
        '/spaces/' . $spaceA . '/members', ['uid' => $b, 'role' => 'manager'], 403);

    $t->check('A revokes B viewer grant', $a, 'DELETE',
        '/spaces/' . $spaceA . '/members/' . $b, null, 200);
    $t->check('B is denied on very next finance request', $b, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 404);
    $t->check('SaaS owner may explicitly grant manager below owner authority',
        $a, 'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => $b, 'role' => 'manager'], 200);
    $t->check('manager can inspect assigned workspace membership',
        $b, 'GET', '/spaces/' . $spaceA . '/members', null, 200);
    $t->check('manager cannot reassign roles or promote itself to owner',
        $b, 'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => $a, 'role' => 'manager'], 403);
    $t->check('owner revokes manager grant', $a, 'DELETE',
        '/spaces/' . $spaceA . '/members/' . $b, null, 200);
    $t->check('manager revocation immediately revokes ledger read',
        $b, 'GET', '/spaces/' . $spaceA . '/journals', null, 404);
    instanceCheck('instance admin suspends environment A without deleting postings',
        'PUT', '/saas/v1/admin/groups/' . $groupA . '/enablement',
        ['enabled' => false], 200);
    $t->check('suspended environment denies direct owner access', $a, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 404);
    $t->check('other environment B remains fully functional', $b, 'GET',
        '/spaces/' . $spaceB . '/accounts', null, 200);
} finally {
    instanceCheck('restore legacy governance mode', 'PUT',
        '/admin/governance', ['managed' => false], 200);
}
// The global managed toggle is a server-side financial kill switch for SaaS;
// it must not change historic postings or legacy finance workspaces.
$t->check('global managed-mode suspension denies active SaaS owner ledger B', $b,
    'GET', '/spaces/' . $spaceB . '/journals', null, 404);
$t->check('global managed-mode suspension filters SaaS books from workspace lists', $b,
    'GET', '/spaces', null, 200,
    static fn(array $r): bool =>
        !in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true)
        && !in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true));
echo 'PASS: synthetic SaaS financial isolation assertions ', $checks, PHP_EOL;
$t->finish();
