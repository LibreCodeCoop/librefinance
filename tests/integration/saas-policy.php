<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;
use OCP\IGroupManager;
use OCP\IUserManager;

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
    // PHP 8.5 deprecates curl_close(); the handle is released by scope.
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

function hasPolicySource(array $policy, string $scope, ?string $id): bool {
    return ($policy['sourceScope'] ?? null) === $scope
        && array_key_exists('sourceIdentifier', $policy)
        && $policy['sourceIdentifier'] === $id;
}

/** Run in a disposable Nextcloud after suites that require untouched policy revisions. */
function checkScopedPolicySources(TestHarness $t): void {
    $groups = \OC::$server->get(IGroupManager::class);
    $users = \OC::$server->get(IUserManager::class);
    $a = $users->get('lfmaintestA') ?? throw new RuntimeException('Missing synthetic user A');
    $b = $users->get('lfmaintestB') ?? throw new RuntimeException('Missing synthetic user B');
    $suffix = bin2hex(random_bytes(6));
    $governance = '/admin/governance';
    $managed = policyCheck('read original managed mode', 'GET', $governance, null, 200)['managed'];
    $sharing = '/saas/v1/admin/policies/externalSharing.enabled';
    $previousSharing = policyCheck('read original external sharing policy', 'GET', $sharing, null, 200);
    $sharingRevision = null;
    $createdGroups = [];
    $paths = [];
    $key = 'dashboard.kpis.enabled';
    policyCheck('enable managed mode for provenance fixtures', 'PUT', $governance, ['managed' => true], 200);
    try {
        foreach ([[$a, $b, 'A'], [$b, $a, 'B']] as [$owner, $manager, $label]) {
            $gid = 'lf_synth_provenance_' . $label . '_' . $suffix;
            $group = $groups->createGroup($gid) ?? throw new RuntimeException('Cannot create synthetic group');
            $createdGroups[] = $group;
            $group->addUser($owner);
            $group->addUser($manager);
            $uuid = policyCheck('enable synthetic environment ' . $label, 'PUT',
                "/saas/v1/admin/groups/$gid/enablement", ['enabled' => true], 200)['environment']['id'];
            policyCheck('delegate synthetic environment ' . $label, 'POST',
                "/admin/groups/$gid/delegates", ['uid' => $manager->getUID()], 201);
            $environment = "/saas/v1/environments/$uuid";
            $t->check('activate synthetic environment ' . $label, $manager->getUID(),
                'POST', "$environment/provision", null, 200);
            $spaceId = (int)$t->check('create separately owned workspace ' . $label, $manager->getUID(),
                'POST', "$environment/workspaces", ['name' => 'Policy provenance ' . $label,
                    'ownerUid' => $owner->getUID(), 'idempotencyKey' => 'provenance-' . $suffix], 201)['id'];
            $workspace = "/saas/v1/workspaces/$spaceId";
            $t->check('write identifies the environment that denied dashboard ' . $label,
                $manager->getUID(), 'PUT', "$environment/policies/$key",
                ['valueJson' => 'false', 'expectedRevision' => 0], 200,
                static fn(array $r): bool => hasPolicySource($r, 'environment', $uuid)
                    && $r['effectiveValue'] === false && $r['blockedBy'] === 'environment');
            $t->check('workspace inherits its own environment source ' . $label,
                $owner->getUID(), 'GET', "$workspace/policies/$key", null, 200,
                static fn(array $r): bool => hasPolicySource($r, 'environment', $uuid)
                    && $r['inherited'] === true && $r['revision'] === 0);
            $paths[$label] = [$uuid, $workspace, $spaceId];
        }
        [$envA, $workspaceA, $spaceA] = $paths['A'];
        [$envB, $workspaceB, $spaceB] = $paths['B'];
        if ($envA === $envB || $spaceA === $spaceB) {
            throw new RuntimeException('Expected independent environment and workspace identities');
        }
        $t->check('environment manager without a financial role cannot read its workspace policy',
            $a->getUID(), 'GET', "$workspaceB/policies/$key", null, 404,
            static fn(array $r): bool => $r === ['error' => 'space_not_found']);
        $formatKey = 'banking.allowedImportFormats';
        $t->check('environment narrows the allowed formats', $a->getUID(), 'PUT',
            "/saas/v1/environments/$envB/policies/$formatKey",
            ['valueJson' => '["csv"]', 'expectedRevision' => 0], 200,
            static fn(array $r): bool => hasPolicySource($r, 'environment', $envB)
                && $r['effectiveValue'] === ['csv']);
        $t->check('equal workspace intersection retains the environment source', $b->getUID(),
            'PUT', "$workspaceB/policies/$formatKey",
            ['valueJson' => '["csv"]', 'expectedRevision' => 0], 200,
            static fn(array $r): bool => hasPolicySource($r, 'environment', $envB)
                && $r['effectiveValue'] === ['csv'] && $r['inherited'] === false);
        $t->check('workspace restriction identifies only the authorized workspace', $b->getUID(),
            'PUT', "$workspaceB/policies/$formatKey",
            ['valueJson' => '[]', 'expectedRevision' => 1], 200,
            static fn(array $r): bool => hasPolicySource($r, 'workspace', (string)$spaceB)
                && $r['effectiveValue'] === [] && $r['blockedBy'] === 'workspace');
        $t->check('the other environment keeps its own effective formats', $a->getUID(),
            'GET', "$workspaceA/policies/$formatKey", null, 200,
            static fn(array $r): bool => hasPolicySource($r, 'default', null)
                && $r['effectiveValue'] === ['csv', 'ofx']);
        $t->check('matching self restriction keeps the inherited environment source', $b->getUID(),
            'PUT', "$workspaceB/policies/me/$key", ['valueJson' => 'false', 'expectedRevision' => 0], 200,
            static fn(array $r): bool => hasPolicySource($r, 'environment', $envB)
                && $r['effectiveValue'] === false && $r['revision'] === 1);
        $t->check('environment clear falls back to the registry source', $a->getUID(),
            'DELETE', "/saas/v1/environments/$envB/policies/$key?expectedRevision=1", null, 200,
            static fn(array $r): bool => hasPolicySource($r, 'default', null));
        $t->check('self restriction takes effect after ancestor clear', $b->getUID(),
            'GET', "$workspaceB/policies/me/$key", null, 200,
            static fn(array $r): bool => hasPolicySource($r, 'user', $b->getUID())
                && $r['effectiveValue'] === false && $r['blockedBy'] === 'user');

        if ($previousSharing['effectiveValue'] !== true) {
            $sharingRevision = policyCheck('enable invitations in disposable provenance lab', 'PUT',
                $sharing, ['valueJson' => 'true', 'expectedRevision' => $previousSharing['revision']], 200)['revision'];
        }
        $t->check('workspace attributes inherited sharing to the instance', $a->getUID(),
            'GET', "$workspaceA/policies/externalSharing.enabled", null, 200,
            static fn(array $r): bool => hasPolicySource($r, 'instance', 'instance')
                && $r['effectiveValue'] === true && $r['inherited'] === true);
        $invitations = "$workspaceA/invitations";
        $invite = $t->check('create invitation for an actor outside the environment', $a->getUID(),
            'POST', $invitations, ['recipientUid' => 'synthetictestadmin', 'role' => 'viewer',
                'expiresAt' => time() + 3600, 'expectedRevision' => 0], 201);
        policyCheck('pending invitation cannot read policy', 'GET', "$workspaceA/policies/$key", null, 404,
            static fn(array $r): bool => $r === ['error' => 'space_not_found']);
        policyCheck('accept exact synthetic external invitation', 'POST',
            "$invitations/{$invite['id']}/accept", ['expectedRevision' => 1], 200);
        foreach (["$workspaceA/policies/$key", "$workspaceA/policies/me/$key"] as $path) {
            policyCheck('external policy read conceals the environment UUID at ' . $path, 'GET', $path, null, 200,
                static fn(array $r): bool => hasPolicySource($r, 'environment', null)
                    && $r['effectiveValue'] === false && $r['blockedBy'] === 'environment'
                    && $r['editableByCurrentActor'] === false
                    && !str_contains(json_encode($r, JSON_THROW_ON_ERROR), $envA));
        }
        foreach (["$workspaceA/policies/effective", "$workspaceA/policies/me/effective"] as $path) {
            policyCheck('external effective list conceals the environment UUID at ' . $path, 'GET',
                $path, null, 200, static function (array $r) use ($key, $envA): bool {
                    $policies = array_column($r['policies'] ?? [], null, 'key');
                    return hasPolicySource($policies[$key] ?? [], 'environment', null)
                        && !str_contains(json_encode($r, JSON_THROW_ON_ERROR), $envA);
                });
        }
        policyCheck('external invitation grants no environment policy access', 'GET',
            "/saas/v1/environments/$envA/policies/$key", null, 404,
            static fn(array $r): bool => $r === ['error' => 'environment_not_found']);
        policyCheck('external invitation cannot read another workspace policy', 'GET',
            "$workspaceB/policies/$key", null, 404,
            static fn(array $r): bool => $r === ['error' => 'space_not_found']);
        $t->check('revoke the synthetic external invitation', $a->getUID(), 'DELETE',
            "$invitations/{$invite['id']}?expectedRevision=2", null, 200);
        policyCheck('revoked invitation cannot read policy provenance', 'GET',
            "$workspaceA/policies/$key", null, 404,
            static fn(array $r): bool => $r === ['error' => 'space_not_found']);
    } finally {
        try {
            if ($sharingRevision !== null) {
                if ($previousSharing['inherited']) {
                    policyCheck('restore inherited sharing policy', 'DELETE',
                        "$sharing?expectedRevision=$sharingRevision", null, 200);
                } else {
                    policyCheck('restore explicit sharing policy', 'PUT', $sharing,
                        ['valueJson' => 'false', 'expectedRevision' => $sharingRevision], 200);
                }
            }
        } finally {
            try {
                foreach ($createdGroups as $group) {
                    $group->delete();
                }
            } finally {
                policyCheck('restore original managed mode', 'PUT', $governance, ['managed' => $managed], 200);
            }
        }
    }
}

if (in_array('--provenance', $argv, true)) {
    // Bootstrap before printing anything, as required by the Nextcloud CLI.
    require_once dirname(__DIR__, 4) . '/lib/base.php';
    \OC_App::loadApp('librefinance');
    $t = new TestHarness();
    checkScopedPolicySources($t);
    echo "PASS: $checks synthetic administrator and external policy assertions\n";
    $t->finish();
    exit;
}

$t = new TestHarness();
$key = '/saas/v1/admin/policies/environments.enabled';
policyCheck('instance admin can list typed registry', 'GET',
    '/saas/v1/admin/policies', null, 200,
    static fn(array $r): bool => count($r['policies'] ?? []) >= 8
        && in_array('dashboard.kpis.enabled', array_column($r['policies'] ?? [], 'key'), true)
        && in_array('banking.allowedImportFormats', array_column($r['policies'] ?? [], 'key'), true)
        && in_array('modules.reports.enabled', array_column($r['policies'] ?? [], 'key'), true)
        && in_array('regional.nfseDrafts.enabled', array_column($r['policies'] ?? [], 'key'), true));
policyCheck('instance policies inherit enabled SaaS feature under separately disabled managed mode', 'GET',
    $key, null, 200, static fn(array $r): bool =>
        ($r['effectiveValue'] ?? null) === true && ($r['revision'] ?? null) === 0
        && ($r['inherited'] ?? null) === true && hasPolicySource($r, 'default', null));
policyCheck('unknown policy does not fall back to permissive default', 'GET',
    '/saas/v1/admin/policies/unknown.key', null, 404);
policyCheck('instance cannot configure unsupported statement format', 'PUT',
    '/saas/v1/admin/policies/banking.allowedImportFormats',
    ['valueJson' => '["csv","xml"]', 'expectedRevision' => 0], 422,
    static fn(array $r): bool => ($r['error'] ?? null) === 'invalid_policy_value');
policyCheck('unsupported format write leaves instance policy revision unchanged', 'GET',
    '/saas/v1/admin/policies/banking.allowedImportFormats', null, 200,
    static fn(array $r): bool => ($r['revision'] ?? null) === 0
        && ($r['effectiveValue'] ?? null) === ['csv', 'ofx']);
$t->check('ordinary finance operator cannot enumerate instance policies',
    'lfmaintestA', 'GET', '/saas/v1/admin/policies', null, 403);
$t->check('ordinary finance operator cannot set privileged instance policy',
    'lfmaintestB', 'PUT', $key, ['valueJson' => 'true', 'expectedRevision' => 0], 403);
policyCheck('instance admin sets a typed boolean at expected revision zero',
    'PUT', $key, ['valueJson' => 'false', 'expectedRevision' => 0], 200,
    static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
        && ($r['revision'] ?? null) === 1 && hasPolicySource($r, 'instance', 'instance')
        && ($r['blockedBy'] ?? null) === 'instance');
policyCheck('stale revision cannot overwrite policy',
    'PUT', $key, ['valueJson' => 'true', 'expectedRevision' => 0], 409,
    static fn(array $r): bool => ($r['error'] ?? '') === 'revision_conflict');
policyCheck('malformed JSON is rejected',
    'PUT', $key, ['valueJson' => '{invalid', 'expectedRevision' => 1], 422,
    static fn(array $r): bool => ($r['error'] ?? '') === 'invalid_policy_value');
policyCheck('incorrect type cannot mutate boolean',
    'PUT', $key, ['valueJson' => '"true"', 'expectedRevision' => 1], 422,
    static fn(array $r): bool => ($r['error'] ?? '') === 'invalid_policy_value');
policyCheck('unchanged revision remains valid after rejected writes', 'GET',
    $key, null, 200, static fn(array $r): bool =>
        ($r['revision'] ?? null) === 1 && ($r['effectiveValue'] ?? null) === false);
policyCheck('valid policy deny updates with matching revision',
    'PUT', $key, ['valueJson' => 'true', 'expectedRevision' => 1], 200,
    static fn(array $r): bool =>
        ($r['revision'] ?? null) === 2 && ($r['effectiveValue'] ?? null) === true);
policyCheck('stale clear rejected', 'DELETE', $key . '?expectedRevision=1',
    null, 409, static fn(array $r): bool => ($r['error'] ?? '') === 'revision_conflict');
policyCheck('revision-guarded clear restores default', 'DELETE',
    $key . '?expectedRevision=2', null, 200, static fn(array $r): bool =>
        ($r['revision'] ?? null) === 3 && ($r['inherited'] ?? null) === true
        && ($r['effectiveValue'] ?? null) === true);
policyCheck('cleared environment feature inherits compatibility default', 'GET', $key, null, 200,
    static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true
        && hasPolicySource($r, 'default', null));
policyCheck('instance admin reads append-only typed policy history', 'GET',
    '/saas/v1/policy-audit/instance', null, 200,
    static fn(array $r): bool =>
        count($r['events'] ?? []) >= 3
        && ($r['events'][0]['action'] ?? null) === 'clear'
        && ($r['events'][0]['actorUid'] ?? null) === 'synthetictestadmin'
        && ($r['events'][0]['policyKey'] ?? null) === 'environments.enabled'
        && ($r['events'][0]['previousRevision'] ?? null) === 2
        && ($r['events'][0]['newRevision'] ?? null) === 3
        && ($r['nextBeforeId'] ?? null) === null);
$t->check('finance operator cannot enumerate instance policy audit',
    'lfmaintestA', 'GET', '/saas/v1/policy-audit/instance', null, 403);
policyCheck('policy audit cursor below first record is empty', 'GET',
    '/saas/v1/policy-audit/instance?beforeId=1', null, 200,
    static fn(array $r): bool =>
        ($r['events'] ?? null) === []
        && array_key_exists('nextBeforeId', $r)
        && $r['nextBeforeId'] === null);
// A typed, instance-level restriction must affect actual module discovery
// and request handling immediately, not just the policy JSON response.
$workspace = $t->check('synthetic finance owner creates import-policy workspace',
    'lfmaintestA', 'POST', '/spaces',
    ['name' => 'Synthetic policy authorization', 'kind' => 'personal',
        'currency' => 'BRL'], 201);
$spaceId = (int)$workspace['id'];
$t->check('import module initially enabled under inherited instance policy',
    'lfmaintestA', 'GET', '/spaces/' . $spaceId . '/modules', null, 200,
    static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
        static fn(array $m): bool => ($m['id'] ?? '') === 'librefinance_import'
            && ($m['enabled'] ?? null) === true)) === 1);
$importsKey = '/saas/v1/admin/policies/imports.enabled';
policyCheck('instance admin disables all imports immediately', 'PUT',
    $importsKey, ['valueJson' => 'false', 'expectedRevision' => 0], 200,
    static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false);
$t->check('disabled imports disappear as enabled module', 'lfmaintestA',
    'GET', '/spaces/' . $spaceId . '/modules', null, 200,
    static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
        static fn(array $m): bool => ($m['id'] ?? '') === 'librefinance_import'
            && ($m['enabled'] ?? null) === false)) === 1);
$t->check('server denies real import preview when instance forbids imports',
    'lfmaintestA', 'POST', '/spaces/' . $spaceId . '/preview',
    ['exportJson' => 'invalid'], 403,
    static fn(array $r): bool => ($r['error'] ?? '') === 'module_disabled');
policyCheck('clearing instance import restriction restores inheritance',
    'DELETE', $importsKey . '?expectedRevision=1', null, 200,
    static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true);
$t->check('next request sees restored import capability', 'lfmaintestA',
    'GET', '/spaces/' . $spaceId . '/modules', null, 200,
    static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
        static fn(array $m): bool => ($m['id'] ?? '') === 'librefinance_import'
            && ($m['enabled'] ?? null) === true)) === 1);
echo "PASS: synthetic typed policy persistence and revocation lifecycle $checks assertions\n";
echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
