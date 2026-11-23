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
    $auditPathA = '/saas/v1/admin/groups/' . $groupA . '/audit';
    $auditPathB = '/saas/v1/admin/groups/' . $groupB . '/audit';
    instanceCheck('instance admin reads only lifecycle audit of tenant A',
        'GET', $auditPathA, null, 200,
        static fn(array $r): bool => ($r['environmentId'] ?? null) === $envA
            && ($r['events'][0]['action'] ?? null) === 'environment.enabled'
            && ($r['events'][0]['actorUid'] ?? null) === 'synthetictestadmin'
            && ($r['events'][0]['subjectId'] ?? null) === $groupA
            && is_int($r['events'][0]['occurredAt'] ?? null)
            && array_key_exists('nextBeforeId', $r)
            && $r['nextBeforeId'] === null
            && !array_key_exists('journal', $r['events'][0] ?? [])
            && !array_key_exists('balanceMinor', $r['events'][0] ?? []));
    instanceCheck('admin audit of tenant B never reveals tenant A group',
        'GET', $auditPathB, null, 200,
        static fn(array $r): bool => ($r['environmentId'] ?? null) === $envB
            && count($r['events'] ?? []) === 1
            && ($r['events'][0]['subjectId'] ?? null) === $groupB);
    $t->check('ordinary SaaS member cannot enumerate admin lifecycle audit',
        $a, 'GET', $auditPathA, null, 403);
    instanceCheck('unknown group audit does not disclose other group events',
        'GET', '/saas/v1/admin/groups/nonexistent_synthetic_gid/audit', null, 404);
    instanceCheck('audit cursor beyond latest returns no events', 'GET',
        $auditPathA . '?beforeId=1', null, 200,
        static fn(array $r): bool => ($r['events'] ?? null) === []
            && array_key_exists('nextBeforeId', $r)
            && $r['nextBeforeId'] === null);
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
    $secondA = $t->check('same environment provisions a second independent ledger', $b,
        'POST', $pathA,
        ['name' => 'Isolated A2', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A2-allowed'], 201);
    $spaceA2 = (int)$secondA['id'];
    saasCheck('both ledgers in A and the ledger in B have unique IDs',
        $spaceA2 > 0 && $spaceA2 !== $spaceA && $spaceA2 !== $spaceB);

    // Financial roles, not group administration, own workspace-level policy
    // editing. A denial in A2 must never disable imports in A1 or tenant B.
    $workspacePolicyA1 = '/saas/v1/workspaces/' . $spaceA . '/policies/imports.enabled';
    $workspacePolicyA2 = '/saas/v1/workspaces/' . $spaceA2 . '/policies/imports.enabled';
    $t->check('A owner sees inherited workspace policy', $a, 'GET',
        $workspacePolicyA2, null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true
            && ($r['sourceScope'] ?? null) === 'default'
            && ($r['editableByCurrentActor'] ?? null) === true);
    $t->check('B group admin without financial role cannot inspect A2 policy', $b,
        'GET', $workspacePolicyA2, null, 404);
    instanceCheck('instance admin cannot inspect independent workspace policies',
        'GET', $workspacePolicyA2, null, 404);
    $t->check('A owner can read workspace policy catalogue', $a,
        'GET', '/saas/v1/workspaces/' . $spaceA2 . '/policies/effective', null, 200,
        static fn(array $r): bool => count($r['policies'] ?? []) === 4);
    $t->check('A owner restricts imports in A2 without changing A1', $a,
        'PUT', $workspacePolicyA2,
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'workspace'
            && ($r['blockedBy'] ?? null) === 'workspace'
            && ($r['revision'] ?? null) === 1);
    $t->check('A cannot write A2 policy from stale revision', $a,
        'PUT', $workspacePolicyA2,
        ['valueJson' => 'false', 'expectedRevision' => 0], 409,
        static fn(array $r): bool => ($r['error'] ?? null) === 'revision_conflict');
    $t->check('A1 remains import-enabled in the same environment', $a, 'GET',
        '/spaces/' . $spaceA . '/modules', null, 200,
        static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
            static fn(array $m): bool => ($m['id'] ?? null) === 'librefinance_import'
                && ($m['enabled'] ?? null) === true)) === 1);
    $t->check('A2 import module honors its workspace policy at next request', $a,
        'GET', '/spaces/' . $spaceA2 . '/modules', null, 200,
        static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
            static fn(array $m): bool => ($m['id'] ?? null) === 'librefinance_import'
                && ($m['enabled'] ?? null) === false)) === 1);
    $t->check('group admin cannot modify an owned ledger policy', $b,
        'PUT', $workspacePolicyA2,
        ['valueJson' => 'true', 'expectedRevision' => 1], 404);
    $t->check('foreign ledger ID denied for current owner', $a,
        'PUT', '/saas/v1/workspaces/' . $spaceB . '/policies/imports.enabled',
        ['valueJson' => 'false', 'expectedRevision' => 0], 404);
    $t->check('workspace cannot modify environment-only company count limit',
        $a, 'PUT', '/saas/v1/workspaces/' . $spaceA . '/policies/workspaces.maxCount',
        ['valueJson' => '1', 'expectedRevision' => 0], 422,
        static fn(array $r): bool => ($r['error'] ?? null) === 'unsupported_scope');
    $t->check('A clears A2 restriction with revision check', $a,
        'DELETE', $workspacePolicyA2 . '?expectedRevision=1', null, 200,
        static fn(array $r): bool => ($r['inherited'] ?? null) === true
            && ($r['revision'] ?? null) === 2
            && ($r['effectiveValue'] ?? null) === true);

    // This setting is resolved for the active environment; a user in both
    // groups never receives a union of permissive child scopes.
    $policyPathA = '/saas/v1/environments/' . $envA . '/policies/imports.enabled';
    $t->check('environment A administrator restricts imports only for A', $b,
        'PUT', $policyPathA,
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'environment');
    $t->check('financial owner cannot loosen environment prohibition from workspace A2',
        $a, 'PUT', $workspacePolicyA2,
        ['valueJson' => 'true', 'expectedRevision' => 2], 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'policy_override_forbidden');
    $t->check('workspace A2 effective policy identifies environment restriction',
        $a, 'GET', $workspacePolicyA2, null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'environment'
            && ($r['blockedBy'] ?? null) === 'environment');
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
            && !in_array($spaceA2, array_column($r['contexts'] ?? [], 'id'), true)
            && !in_array($spaceB, array_column($r['contexts'] ?? [], 'id'), true));
    instanceCheck('legacy bind cannot create group-wide viewer grants in SaaS',
        'PUT', '/admin/contexts/' . $spaceA . '/group',
        ['gid' => $groupA], 403);
    $t->check('group admin cannot self-promote to financial owner', $a,
        'POST', $pathB, ['name' => 'Self promoted',
            'ownerUid' => $a, 'idempotencyKey' => 'synthetic-self-grant-001'], 403);

    // These routes exercise the *real* previously bypassed AccessService.
    $t->check('A sees only its own finance workspace', $a, 'GET',
        '/spaces', null, 200, static fn(array $r): bool =>
            in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true)
            && in_array($spaceA2, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true));
    $t->check('B sees only its own finance workspace', $b, 'GET',
        '/spaces', null, 200, static fn(array $r): bool =>
            in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceA2, array_column($r['spaces'] ?? [], 'id'), true));
    instanceCheck('Nextcloud instance admin cannot list SaaS financial books', 'GET',
        '/spaces', null, 200, static fn(array $r): bool =>
            !in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceA2, array_column($r['spaces'] ?? [], 'id'), true)
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

    // The self-user presentation layer is distinct per identity and book.
    // A request cannot supply another UID, nor use a group admin role as ACL.
    $mineA = '/saas/v1/workspaces/' . $spaceA
        . '/policies/me/dashboard.kpis.enabled';
    $mineA2 = '/saas/v1/workspaces/' . $spaceA2
        . '/policies/me/dashboard.kpis.enabled';
    $mineB = '/saas/v1/workspaces/' . $spaceB
        . '/policies/me/dashboard.kpis.enabled';
    $t->check('A sees inherited own dashboard visibility', $a, 'GET', $mineA,
        null, 200, static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === true
            && ($r['sourceScope'] ?? null) === 'default'
            && ($r['revision'] ?? null) === 0
            && ($r['editableByCurrentActor'] ?? null) === true);
    $t->check('user policy list reveals only supported self-user keys', $a,
        'GET', '/saas/v1/workspaces/' . $spaceA . '/policies/me/effective',
        null, 200, static fn(array $r): bool =>
            array_column($r['policies'] ?? [], 'key') === ['dashboard.kpis.enabled']);
    $t->check('B cannot read A self policy without financial membership', $b,
        'GET', $mineA, null, 404);
    $t->check('A cannot inspect another environments user policy', $a,
        'GET', $mineB, null, 404);
    $t->check('user presentation preference cannot change external sharing', $a,
        'PUT', '/saas/v1/workspaces/' . $spaceA
            . '/policies/me/externalSharing.enabled',
        ['valueJson' => 'true', 'expectedRevision' => 0], 422);
    $t->check('user hides own SaaS dashboard indicators with CAS', $a,
        'PUT', $mineA, ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'user'
            && ($r['revision'] ?? null) === 1);
    $t->check('user presentation denial does not contaminate other books', $a,
        'GET', $mineA2, null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true);
    $t->check('competing stale self-user write is rejected', $a,
        'PUT', $mineA, ['valueJson' => 'true', 'expectedRevision' => 0], 409);
    $parentDashboard = '/saas/v1/workspaces/' . $spaceA
        . '/policies/dashboard.kpis.enabled';
    $t->check('workspace owner can restrict all dashboard summaries', $a,
        'PUT', $parentDashboard, ['valueJson' => 'false', 'expectedRevision' => 0],
        200, static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false);
    $t->check('user is forbidden to reenable denied parent', $a,
        'PUT', $mineA, ['valueJson' => 'true', 'expectedRevision' => 1], 403);
    $t->check('read reports inherited workspace blocker exactly', $a,
        'GET', $mineA, null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === false
            && ($r['blockedBy'] ?? null) === 'workspace');
    $t->check('restore workspace parent without touching self setting', $a,
        'DELETE', $parentDashboard . '?expectedRevision=1', null, 200);
    $t->check('self-setting remains denied when parent restored', $a,
        'GET', $mineA, null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'user');
    $t->check('self-user clear restores inherited allowed dashboard', $a,
        'DELETE', $mineA . '?expectedRevision=1', null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === true
            && ($r['inherited'] ?? null) === true
            && ($r['revision'] ?? null) === 2);

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
    $accountsA2 = $t->check('A2 has an independent account chart', $a, 'GET',
        '/spaces/' . $spaceA2 . '/accounts', null, 200,
        static fn(array $r): bool => count($r['accounts'] ?? []) === 4);
    $a2Ids = array_column($accountsA2['accounts'], 'id', 'code');
    $t->check('A2 refuses A1 account IDs despite the same financial owner',
        $a, 'POST', '/spaces/' . $spaceA2 . '/journals',
        ['debitId' => $ids['1000'], 'creditId' => $ids['4000'],
            'amountMinor' => 500, 'date' => '2026-11-23',
            'memo' => 'Cross-workspace account ID denied',
            'externalKey' => 'saas-forged-cross-account'], 404);
    $t->check('A posts only to independent A2 ledger', $a, 'POST',
        '/spaces/' . $spaceA2 . '/journals',
        ['debitId' => $a2Ids['1000'], 'creditId' => $a2Ids['4000'],
            'amountMinor' => 8700, 'date' => '2026-11-23',
            'memo' => 'Synthetic isolated A2',
            'externalKey' => 'saas-A2-once'], 201);
    $t->check('workspace selector marks only A current SaaS financial books', $a,
        'GET', '/spaces', null, 200,
        static fn(array $r): bool =>
            count(array_filter($r['spaces'] ?? [],
                static fn(array $s): bool =>
                    in_array((int)$s['id'], [$spaceA, $spaceA2], true)
                    && ($s['saasManaged'] ?? null) === true)) === 2);
    $t->check('A1 retains only its own journal after A2 posts', $a, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 200,
        static fn(array $r): bool => count($r['journals'] ?? []) === 1);
    $t->check('A1 cash account balance is isolated at 12300 minor units', $a,
        'GET', '/spaces/' . $spaceA . '/accounts', null, 200,
        static fn(array $r): bool =>
            (int)(array_column($r['accounts'] ?? [], 'balance_minor', 'code')['1000'] ?? 0) === 12300);
    $t->check('A2 cash account balance is independently 8700 minor units', $a,
        'GET', '/spaces/' . $spaceA2 . '/accounts', null, 200,
        static fn(array $r): bool =>
            (int)(array_column($r['accounts'] ?? [], 'balance_minor', 'code')['1000'] ?? 0) === 8700);
    $t->check('B ledger never receives A postings', $b, 'GET',
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
    $t->check('A registers a separate legal company in A2', $a, 'POST',
        '/spaces/' . $spaceA2 . '/companies',
        ['legalName' => 'Synthetic Example A2', 'country' => 'PT'], 201);
    $t->check('A1 lists only its own company identity', $a, 'GET',
        '/spaces/' . $spaceA . '/companies', null, 200,
        static fn(array $r): bool => count($r['companies'] ?? []) === 1
            && ($r['companies'][0]['legal_name'] ?? '') === 'Synthetic Example A');
    $t->check('A2 lists only its separate international company identity', $a, 'GET',
        '/spaces/' . $spaceA2 . '/companies', null, 200,
        static fn(array $r): bool => count($r['companies'] ?? []) === 1
            && ($r['companies'][0]['legal_name'] ?? '') === 'Synthetic Example A2'
            && ($r['companies'][0]['country'] ?? '') === 'PT');

    $t->check('A cannot pre-grant an outsider a dormant SaaS finance role', $a,
        'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => 'synthetictestadmin', 'role' => 'viewer'], 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'permission_denied');
    $t->check('attempted outside-group grant leaves no persistent membership', $a,
        'GET', '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool =>
            !in_array('synthetictestadmin', array_column($r['members'] ?? [], 'uid'), true));
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
    $t->check('A grants B operator in A2 while B remains viewer in A1', $a,
        'POST', '/spaces/' . $spaceA2 . '/members',
        ['uid' => $b, 'role' => 'operator'], 200);
    $t->check('B can post to A2 with its independent operator role', $b,
        'POST', '/spaces/' . $spaceA2 . '/journals',
        ['debitId' => $a2Ids['1000'], 'creditId' => $a2Ids['4000'],
            'amountMinor' => 600, 'date' => '2026-11-23',
            'memo' => 'Synthetic A2 operator', 'externalKey' => 'saas-B-A2-operator'], 201);
    $t->check('A2 operator does not elevate B viewer role in A1', $b,
        'POST', '/spaces/' . $spaceA . '/journals',
        ['debitId' => $ids['1000'], 'creditId' => $ids['4000'],
            'amountMinor' => 700, 'date' => '2026-11-23',
            'memo' => 'Denied cross-ledger role', 'externalKey' => 'saas-B-A1-forged'], 403);
    $t->check('B operator cannot manage A2 role assignments', $b,
        'POST', '/spaces/' . $spaceA2 . '/members',
        ['uid' => $a, 'role' => 'viewer'], 403);
    $t->check('A revokes B operator membership in A2', $a,
        'DELETE', '/spaces/' . $spaceA2 . '/members/' . $b, null, 200);
    $t->check('B loses A2 access after operator revocation', $b,
        'GET', '/spaces/' . $spaceA2 . '/journals', null, 404);
    $t->check('A2 revocation preserves B viewer access in A1', $b,
        'GET', '/spaces/' . $spaceA . '/journals', null, 200);

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
