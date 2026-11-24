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

function instanceRequest(string $verb, string $path, ?array $data = null, ?string $app = null): array {
    global $base, $password;
    if (!str_starts_with($path, '/') || str_contains($path, '..')) {
        throw new InvalidArgumentException('Unsafe synthetic path');
    }
    if ($app !== null && $app !== 'librefinance_nfse') {
        throw new InvalidArgumentException('Unexpected synthetic optional app');
    }
    $api = $app === null ? $base : str_replace('/apps/librefinance/api', '/apps/librefinance_nfse/api', $base);
    $handle = curl_init($api . $path);
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
    int $expected, ?callable $validate = null, ?string $app = null): array {
    global $checks;
    $result = instanceRequest($verb, $path, $data, $app);
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
    $t->check('invalid foreign company country cannot create an orphan ledger', $b,
        'POST', $pathA,
        ['name' => 'Invalid standalone company', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-invalid-legal-company',
            'legalName' => 'Invalid Country', 'companyCountry' => 'ZZ'], 422,
        static fn(array $r): bool => ($r['error'] ?? null) === 'invalid_company_country');
    $t->check('failed company validation left no separate accounting book', $b,
        'GET', $pathA, null, 200,
        static fn(array $r): bool => !in_array('Invalid standalone company',
            array_column($r['workspaces'] ?? [], 'name'), true));
    $t->check('legal identity cannot be slipped into a personal workspace', $b,
        'POST', $pathA,
        ['name' => 'Invalid personal company', 'ownerUid' => $a,
            'kind' => 'personal', 'idempotencyKey' => 'synthetic-invalid-personal-company',
            'legalName' => 'Unauthorised legal identity'], 422,
        static fn(array $r): bool => ($r['error'] ?? null) === 'invalid_company_kind');
    $secondA = $t->check('same environment creates A2 ledger and PT company atomically', $b,
        'POST', $pathA,
        ['name' => 'Isolated A2', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A2-allowed',
            'legalName' => 'Synthetic Example A2', 'companyCountry' => 'PT'], 201,
        static fn(array $r): bool => (int)($r['id'] ?? 0) > 0
            && (int)($r['companyId'] ?? 0) > 0);
    $spaceA2 = (int)$secondA['id'];
    $companyA2 = (int)$secondA['companyId'];
    $t->check('atomic company bootstrap replay retains the original identity', $b,
        'POST', $pathA,
        ['name' => 'Isolated A2', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A2-allowed',
            'legalName' => 'Synthetic Example A2', 'companyCountry' => 'PT'], 201,
        static fn(array $r): bool => (int)($r['id'] ?? 0) === $spaceA2
            && (int)($r['companyId'] ?? 0) === $companyA2);
    $t->check('same claim key cannot silently replace the legal identity', $b,
        'POST', $pathA,
        ['name' => 'Isolated A2', 'ownerUid' => $a,
            'idempotencyKey' => 'synthetic-workspace-A2-allowed',
            'legalName' => 'Replacement Company', 'companyCountry' => 'PT'], 409,
        static fn(array $r): bool => ($r['error'] ?? null) === 'idempotency_conflict');
    saasCheck('both ledgers in A and the ledger in B have unique IDs',
        $spaceA2 > 0 && $spaceA2 !== $spaceA && $spaceA2 !== $spaceB);

    // Report module policy is a server-side denial on BOTH generated reports
    // and persisted reports, not a visibility flag or a financial grant.
    $reportPathA1 = '/saas/v1/workspaces/' . $spaceA . '/policies/modules.reports.enabled';
    $reportEnvironmentA = '/saas/v1/environments/' . $envA
        . '/policies/modules.reports.enabled';
    $reportRouteA1 = '/spaces/' . $spaceA
        . '/profit-loss?from=2026-01-01&through=2026-12-31';
    $reportRouteA2 = '/spaces/' . $spaceA2
        . '/profit-loss?from=2026-01-01&through=2026-12-31';
    $reportRouteB = '/spaces/' . $spaceB
        . '/profit-loss?from=2026-01-01&through=2026-12-31';
    $t->checkModule('reports enabled by default in independent ledger A1', $a,
        'librefinance_reports', 'GET', $reportRouteA1, null, 200);
    $t->check('financial owner restricts report module only in A1', $a,
        'PUT', $reportPathA1,
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
            && ($r['blockedBy'] ?? null) === 'workspace');
    $t->checkModule('direct report API respects A1 policy denial', $a,
        'librefinance_reports', 'GET', $reportRouteA1, null, 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'module_disabled');
    $t->checkModule('saved report API respects A1 policy denial', $a,
        'librefinance_reports', 'GET',
        '/spaces/' . $spaceA . '/saved-reports', null, 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'module_disabled');
    $t->check('A1 reports show effectively disabled in module discovery', $a,
        'GET', '/spaces/' . $spaceA . '/modules', null, 200,
        static fn(array $r): bool => count(array_filter($r['modules'] ?? [],
            static fn(array $m): bool => ($m['id'] ?? null) === 'librefinance_reports'
                && ($m['enabled'] ?? null) === false
                && ($m['blockedByPolicy'] ?? null) === true)) === 1);
    $t->check('module flag cannot reactivate a policy-disabled report module', $a,
        'PUT', '/spaces/' . $spaceA . '/modules/librefinance_reports',
        ['enabled' => true], 200,
        static fn(array $r): bool => ($r['enabled'] ?? null) === false
            && ($r['blockedByPolicy'] ?? null) === true);
    $t->checkModule('workspace A2 reports unaffected by A1 policy', $a,
        'librefinance_reports', 'GET', $reportRouteA2, null, 200);
    $t->checkModule('environment B reports unaffected by A1 policy', $b,
        'librefinance_reports', 'GET', $reportRouteB, null, 200);
    $t->check('clearing A1 report restriction restores access', $a,
        'DELETE', $reportPathA1 . '?expectedRevision=1', null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true);
    $t->check('delegated manager restricts reports in environment A only', $b,
        'PUT', $reportEnvironmentA,
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false);
    $t->checkModule('environment denial blocks A2 report API', $a,
        'librefinance_reports', 'GET', $reportRouteA2, null, 403);
    $t->check('financial owner cannot override disabled parent reporting', $a,
        'PUT', $reportPathA1,
        ['valueJson' => 'true', 'expectedRevision' => 2], 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'policy_override_forbidden');
    $t->checkModule('environment B remains allowed under A denial', $b,
        'librefinance_reports', 'GET', $reportRouteB, null, 200);
    $t->check('environment manager lifts report restriction with CAS', $b,
        'DELETE', $reportEnvironmentA . '?expectedRevision=1', null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true);
    $t->checkModule('A2 report API recovers without financial record changes', $a,
        'librefinance_reports', 'GET', $reportRouteA2, null, 200);

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
        static fn(array $r): bool => count($r['policies'] ?? []) >= 8
            && in_array('dashboard.kpis.enabled', array_column($r['policies'] ?? [], 'key'), true)
            && in_array('banking.allowedImportFormats', array_column($r['policies'] ?? [], 'key'), true)
            && in_array('modules.reports.enabled', array_column($r['policies'] ?? [], 'key'), true)
            && in_array('regional.nfseDrafts.enabled', array_column($r['policies'] ?? [], 'key'), true));
    $t->check('A owner restricts imports in A2 without changing A1', $a,
        'PUT', $workspacePolicyA2,
        ['valueJson' => 'false', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === false
            && ($r['sourceScope'] ?? null) === 'workspace'
            && ($r['blockedBy'] ?? null) === 'workspace'
            && ($r['revision'] ?? null) === 1);
    $workspaceAuditA2 = '/saas/v1/policy-audit/workspaces/' . $spaceA2;
    $t->check('financial owner can inspect scoped A2 policy audit', $a,
        'GET', $workspaceAuditA2, null, 200,
        static fn(array $r): bool =>
            count($r['events'] ?? []) === 1
            && ($r['events'][0]['policyKey'] ?? null) === 'imports.enabled'
            && ($r['events'][0]['actorUid'] ?? null) === $a
            && ($r['events'][0]['newValueJson'] ?? null) === 'false'
            && ($r['events'][0]['previousRevision'] ?? null) === 0);
    $t->check('group administrator without finance role cannot inspect A2 policy audit',
        $b, 'GET', $workspaceAuditA2, null, 404);
    instanceCheck('instance admin cannot inspect independent financial policy audit',
        'GET', $workspaceAuditA2, null, 404);
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

    // Real bank operations enforce the intersection of allowed_set values,
    // without affecting the independent books in group B or legacy defaults.
    $bankFormatPathA1 = '/saas/v1/workspaces/' . $spaceA
        . '/policies/banking.allowedImportFormats';
    $bankFormatPathA2 = '/saas/v1/workspaces/' . $spaceA2
        . '/policies/banking.allowedImportFormats';
    $bankFormatPathB = '/saas/v1/workspaces/' . $spaceB
        . '/policies/banking.allowedImportFormats';
    $t->check('banking format policy defaults to existing CSV and OFX', $a,
        'GET', $bankFormatPathA1, null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === ['csv', 'ofx']
            && ($r['sourceScope'] ?? null) === 'default');
    $t->check('unknown actor cannot view A1 import formats', $b,
        'GET', $bankFormatPathA1, null, 404);
    $t->check('banking format policy narrows A1 to CSV only', $a,
        'PUT', $bankFormatPathA1,
        ['valueJson' => '["csv"]', 'expectedRevision' => 0], 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === ['csv']
            && ($r['revision'] ?? null) === 1
            && ($r['sourceScope'] ?? null) === 'workspace');
    $t->check('A2 sibling ledger retains both bank formats', $a,
        'GET', $bankFormatPathA2, null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === ['csv', 'ofx']);
    $t->check('different environment B retains both bank formats', $b,
        'GET', $bankFormatPathB, null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === ['csv', 'ofx']);
    $t->check('stale bank import format CAS cannot overwrite restriction', $a,
        'PUT', $bankFormatPathA1,
        ['valueJson' => '["ofx"]', 'expectedRevision' => 0], 409);
    $t->check('workspace cannot invent a new bank import format', $a,
        'PUT', $bankFormatPathA1,
        ['valueJson' => '["csv","xml"]', 'expectedRevision' => 1], 422,
        static fn(array $r): bool => ($r['error'] ?? null) === 'invalid_policy_value');
    $t->check('invalid bank format leaves the workspace restriction unchanged', $a,
        'GET', $bankFormatPathA1, null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === ['csv']
            && ($r['revision'] ?? null) === 1);
    $bankAccountsA1 = $t->check('A1 banking policy test reads its chart', $a,
        'GET', '/spaces/' . $spaceA . '/accounts', null, 200);
    $bankByCodeA1 = array_column($bankAccountsA1['accounts'] ?? [], 'id', 'code');
    $bankAccountA1 = (int)($bankByCodeA1['1000'] ?? 0);
    saasCheck('A1 synthetic bank cash account exists', $bankAccountA1 > 0);
    $csvPolicyProof = "id,date,amount,description\n"
        . "saas-policy-csv,2026-11-20,12.34,Synthetic CSV policy proof\n";
    $ofxPolicyProof = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nCHARSET:1252\n"
        . "<OFX><STMTRS><CURDEF>BRL\n<BANKTRANLIST><DTSTART>20261120\n"
        . "<STMTTRN><DTPOSTED>20261120\n<TRNAMT>-9.35\n"
        . "<FITID>saas-policy-ofx\n<MEMO>Synthetic OFX policy proof\n</STMTTRN>"
        . "</BANKTRANLIST></STMTRS></OFX>";
    $t->check('real bank CSV import works under CSV-only restriction', $a,
        'POST', '/spaces/' . $spaceA . '/imports',
        ['bankAccountId' => $bankAccountA1, 'format' => 'csv',
            'csv' => $csvPolicyProof], 201,
        static fn(array $r): bool =>
            ($r['imported'] ?? null) === 1 && ($r['skipped'] ?? null) === 0);
    $t->check('real bank OFX import blocked before financial write', $a,
        'POST', '/spaces/' . $spaceA . '/imports',
        ['bankAccountId' => $bankAccountA1, 'format' => 'ofx',
            'ofx' => $ofxPolicyProof], 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'module_disabled');
    $t->check('blocked OFX left no bank transaction record', $a,
        'GET', '/spaces/' . $spaceA . '/transactions', null, 200,
        static fn(array $r): bool => count($r['transactions'] ?? []) === 1);
    $bankFormatEnvironmentA = '/saas/v1/environments/' . $envA
        . '/policies/banking.allowedImportFormats';
    $t->check('environment manager narrows import formats to OFX', $b,
        'PUT', $bankFormatEnvironmentA,
        ['valueJson' => '["ofx"]', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === ['ofx']);
    $t->check('A1 CSV and parent OFX intersect to empty deny-all', $a,
        'GET', $bankFormatPathA1, null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === []
            && ($r['blockedBy'] ?? null) === 'workspace');
    $t->check('empty format intersection denies actual CSV import', $a,
        'POST', '/spaces/' . $spaceA . '/imports',
        ['bankAccountId' => $bankAccountA1, 'format' => 'csv',
            'csv' => $csvPolicyProof], 403);
    $t->check('clearing local A1 policy retains stricter environment OFX', $a,
        'DELETE', $bankFormatPathA1 . '?expectedRevision=1', null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === ['ofx']
            && ($r['revision'] ?? null) === 2);
    $t->check('actual OFX import now works under OFX-only restriction', $a,
        'POST', '/spaces/' . $spaceA . '/imports',
        ['bankAccountId' => $bankAccountA1, 'format' => 'ofx',
            'ofx' => $ofxPolicyProof], 201,
        static fn(array $r): bool =>
            ($r['imported'] ?? null) === 1 && ($r['skipped'] ?? null) === 0);
    $t->check('removing environment format restriction restores CSV and OFX', $b,
        'DELETE', $bankFormatEnvironmentA . '?expectedRevision=1', null, 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === ['csv', 'ofx']);
    $t->check('CSV import replay is still idempotent after policy reset', $a,
        'POST', '/spaces/' . $spaceA . '/imports',
        ['bankAccountId' => $bankAccountA1, 'format' => 'csv',
            'csv' => $csvPolicyProof], 201,
        static fn(array $r): bool =>
            ($r['imported'] ?? null) === 0 && ($r['skipped'] ?? null) === 1);

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
    $personalAudit = '/saas/v1/policy-audit/workspaces/' . $spaceA . '/me';
    $t->check('user reads only own actor-bound policy audit', $a,
        'GET', $personalAudit, null, 200,
        static fn(array $r): bool =>
            count($r['events'] ?? []) === 1
            && ($r['events'][0]['policyKey'] ?? null) === 'dashboard.kpis.enabled'
            && ($r['events'][0]['actorUid'] ?? null) === $a
            && ($r['events'][0]['newValueJson'] ?? null) === 'false'
            && ($r['events'][0]['previousRevision'] ?? null) === 0
            && ($r['events'][0]['newRevision'] ?? null) === 1);
    $t->check('nonmember cannot probe another actor policy history',
        $b, 'GET', $personalAudit, null, 404);
    $t->check('user audit cursor cannot include newer records',
        $a, 'GET', $personalAudit . '?beforeId=1', null, 200,
        static fn(array $r): bool =>
            ($r['events'] ?? null) === []
            && array_key_exists('nextBeforeId', $r)
            && $r['nextBeforeId'] === null);
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

    // External financial invitations never materialize internal group
    // membership and are denied until explicit policy and account consent.
    $invitationPath = '/saas/v1/workspaces/' . $spaceA . '/invitations';
    $recipientUid = 'synthetictestadmin';
    $invitationData = ['recipientUid' => $recipientUid, 'role' => 'viewer',
        'expiresAt' => time() + 3600, 'expectedRevision' => 0];
    $t->check('SaaS owner cannot invite outsiders with default-deny policy',
        $a, 'POST', $invitationPath, $invitationData, 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'module_disabled');
    $t->check('nonowner cannot create external financial invitations',
        $b, 'POST', $invitationPath, $invitationData, 404);
    instanceCheck('external sharing requires explicit instance enablement',
        'PUT', '/saas/v1/admin/policies/externalSharing.enabled',
        ['valueJson' => 'true', 'expectedRevision' => 0], 200,
        static fn(array $r): bool => ($r['effectiveValue'] ?? null) === true);
    $t->check('external owner cannot invite an already-group-member user',
        $a, 'POST', $invitationPath,
        ['recipientUid' => $b, 'role' => 'viewer', 'expiresAt' => time() + 3600], 403);
    $t->check('external role manager is never permitted',
        $a, 'POST', $invitationPath,
        ['recipientUid' => $recipientUid, 'role' => 'manager',
            'expiresAt' => time() + 3600], 422);
    $t->check('external invite expiry outside allowed window is rejected',
        $a, 'POST', $invitationPath,
        ['recipientUid' => $recipientUid, 'role' => 'viewer',
            'expiresAt' => time() + 60], 422);
    $invite = $t->check('owner creates pending external viewer invitation',
        $a, 'POST', $invitationPath, $invitationData, 201,
        static fn(array $r): bool =>
            ($r['status'] ?? null) === 'invited'
            && ($r['revision'] ?? null) === 1
            && ($r['recipientUid'] ?? null) === 'synthetictestadmin');
    $inviteId = (int)$invite['id'];
    $recipientAcceptPath = $invitationPath . '/' . $inviteId . '/accept';
    instanceCheck('unaccepted invite grants no journal access',
        'GET', '/spaces/' . $spaceA . '/journals', null, 404);
    instanceCheck('recipient sees only its own pending invite',
        'GET', '/saas/v1/invitations', null, 200,
        static fn(array $r): bool =>
            count($r['invitations'] ?? []) === 1
            && (int)($r['invitations'][0]['spaceId'] ?? 0) === $spaceA);
    $t->check('another tenant member cannot accept recipient invitation',
        $b, 'POST', $recipientAcceptPath, ['expectedRevision' => 1], 403);
    instanceCheck('stale invitation acceptance revision is rejected',
        'POST', $recipientAcceptPath, ['expectedRevision' => 0], 409);
    instanceCheck('recipient explicitly accepts external viewer invitation',
        'POST', $recipientAcceptPath, ['expectedRevision' => 1], 200,
        static fn(array $r): bool =>
            ($r['status'] ?? null) === 'accepted' && ($r['revision'] ?? null) === 2);
    instanceCheck('external user now reads only invited financial journal',
        'GET', '/spaces/' . $spaceA . '/journals', null, 200);
    instanceCheck('external account sees invited A1 but not A2 or B',
        'GET', '/spaces', null, 200,
        static fn(array $r): bool =>
            count(array_filter($r['spaces'] ?? [],
                static fn(array $s): bool =>
                    (int)$s['id'] === $spaceA
                    && ($s['externalInvitation'] ?? false) === true
                    && ($s['role'] ?? null) === 'viewer')) === 1
            && !in_array($spaceA2, array_column($r['spaces'] ?? [], 'id'), true)
            && !in_array($spaceB, array_column($r['spaces'] ?? [], 'id'), true));
    instanceCheck('external read-only grant cannot edit legacy viewer dashboard',
        'PUT', '/spaces/' . $spaceA . '/dashboard-layout',
        ['widgets' => []], 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'permission_denied');
    instanceCheck('external viewer can still read own dashboard layout',
        'GET', '/spaces/' . $spaceA . '/dashboard-layout', null, 200);
    instanceCheck('external viewer cannot post a financial journal',
        'POST', '/spaces/' . $spaceA . '/journals',
        ['debitId' => 1, 'creditId' => 2, 'amountMinor' => 200,
            'date' => '2026-11-23', 'memo' => 'Forbidden external posting',
            'externalKey' => 'external-denied'], 403);
    $t->check('owner lists exact accepted external invitation',
        $a, 'GET', $invitationPath, null, 200,
        static fn(array $r): bool =>
            count($r['invitations'] ?? []) === 1
            && ($r['invitations'][0]['status'] ?? '') === 'accepted');
    $t->check('outsider cannot manage finance invitations of another book',
        $b, 'GET', $invitationPath, null, 404);
    $t->check('stale revocation revision does not affect grant', $a,
        'DELETE', $invitationPath . '/' . $inviteId . '?expectedRevision=1',
        null, 409);
    $t->check('financial owner revokes accepted external grant', $a,
        'DELETE', $invitationPath . '/' . $inviteId . '?expectedRevision=2',
        null, 200,
        static fn(array $r): bool =>
            ($r['status'] ?? null) === 'revoked' && ($r['revision'] ?? null) === 3);
    instanceCheck('external access denied at next read after revocation',
        'GET', '/spaces/' . $spaceA . '/journals', null, 404);
    instanceCheck('revoked external book removed from workspace selector',
        'GET', '/spaces', null, 200,
        static fn(array $r): bool =>
            !in_array($spaceA, array_column($r['spaces'] ?? [], 'id'), true));
    $t->check('reinvite requires current revision after revocation', $a,
        'POST', $invitationPath, $invitationData, 409);
    $reinstate = $t->check('reinvite creates new pending state, never implicit access',
        $a, 'POST', $invitationPath,
        ['recipientUid' => $recipientUid, 'role' => 'auditor',
            'expiresAt' => time() + 3600, 'expectedRevision' => 3], 201,
        static fn(array $r): bool =>
            ($r['status'] ?? null) === 'invited'
            && ($r['role'] ?? null) === 'auditor'
            && ($r['revision'] ?? null) === 4);
    instanceCheck('reinvited recipient still denied before fresh acceptance',
        'GET', '/spaces/' . $spaceA . '/journals', null, 404);
    instanceCheck('parent policy denial immediately blocks acceptance',
        'PUT', '/saas/v1/admin/policies/externalSharing.enabled',
        ['valueJson' => 'false', 'expectedRevision' => 1], 200);
    instanceCheck('recipient cannot accept when ancestor disables policy',
        'POST', $recipientAcceptPath, ['expectedRevision' => 4], 403);
    $t->check('owner can revoke a pending invite under disabled policy',
        $a, 'DELETE', $invitationPath . '/' . $inviteId . '?expectedRevision=4',
        null, 200);
    $t->check('external audit retains invite, acceptance, revoke and reinvite',
        $a, 'GET', $invitationPath . '/history', null, 200,
        static fn(array $r): bool =>
            count($r['events'] ?? []) === 5
            && ($r['events'][0]['action'] ?? null) === 'invited'
            && ($r['events'][4]['action'] ?? null) === 'revoked');
    instanceCheck('restore default-deny external sharing policy',
        'DELETE', '/saas/v1/admin/policies/externalSharing.enabled?expectedRevision=2',
        null, 200,
        static fn(array $r): bool =>
            ($r['effectiveValue'] ?? null) === false
            && ($r['inherited'] ?? null) === true);

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

    $syntheticCnpj = '999999990001';
    foreach ([[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]] as $weights) {
        $sum = 0;
        foreach ($weights as $i => $weight) $sum += (int)$syntheticCnpj[$i] * $weight;
        $remainder = $sum % 11;
        $syntheticCnpj .= (string)($remainder < 2 ? 0 : 11 - $remainder);
    }
    $companyA1 = $t->check('A creates legal company linked to A1', $a, 'POST',
        '/spaces/' . $spaceA . '/companies',
        ['legalName' => 'Synthetic Example A', 'country' => 'BR', 'taxId' => $syntheticCnpj], 201);
    $t->check('A1 cannot house a second unrelated legal company', $a, 'POST',
        '/spaces/' . $spaceA . '/companies',
        ['legalName' => 'Synthetic Example B', 'country' => 'BR'], 409);
    $t->check('A2 company is already present from atomic workspace provisioning', $a,
        'GET', '/spaces/' . $spaceA2 . '/companies', null, 200,
        static fn(array $r): bool => count($r['companies'] ?? []) === 1
            && (int)($r['companies'][0]['id'] ?? 0) === $companyA2);
    $t->check('A2 rejects second company after atomic creation', $a, 'POST',
        '/spaces/' . $spaceA2 . '/companies',
        ['legalName' => 'Second Legal Company', 'country' => 'PT'], 409);
    $t->check('A1 lists only its own company identity', $a, 'GET',
        '/spaces/' . $spaceA . '/companies', null, 200,
        static fn(array $r): bool => count($r['companies'] ?? []) === 1
            && ($r['companies'][0]['legal_name'] ?? '') === 'Synthetic Example A');
    $t->check('A2 lists only its separate international company identity', $a, 'GET',
        '/spaces/' . $spaceA2 . '/companies', null, 200,
        static fn(array $r): bool => count($r['companies'] ?? []) === 1
            && ($r['companies'][0]['legal_name'] ?? '') === 'Synthetic Example A2'
            && ($r['companies'][0]['country'] ?? '') === 'PT');

    // Seed real scoped resources, then exercise direct HTTP and MCP access
    // as the environment administrator before, during and after financial grants.
    $resourceRoot = '/spaces/' . $spaceA;
    $period = '?from=2026-01-01&through=2026-12-31';
    $contactName = 'Synthetic scoped SaaS contact ' . $spaceA;
    $contact = $t->check('owner creates a scoped contact', $a, 'POST',
        $resourceRoot . '/contacts', ['name' => $contactName, 'kind' => 'both'], 201);
    $obligation = $t->checkModule('owner creates a scoped obligation', $a, 'librefinance_obligations',
        'POST', $resourceRoot . '/obligations', [
            'kind' => 'payable', 'description' => 'Synthetic scoped obligation',
            'amountMinor' => 1234, 'issuedOn' => '2026-11-20', 'dueOn' => '2026-12-05',
            'reference' => 'SYN-SAAS-' . $spaceA, 'contactId' => (int)$contact['id'],
        ], 201);
    $invoice = $t->checkModule('owner creates a scoped invoice', $a, 'librefinance_invoicing',
        'POST', $resourceRoot . '/invoices', [
            'number' => 'SYN-SAAS-' . $spaceA, 'kind' => 'invoice',
            'contactId' => (int)$contact['id'], 'issueOn' => '2026-11-20',
            'dueOn' => '2026-12-05', 'country' => 'BR',
            'lines' => [['description' => 'Synthetic scoped line', 'quantity' => 1,
                'unitPriceMinor' => 2500, 'taxRuleId' => null]],
        ], 201);
    $saved = $t->checkModule('owner saves a scoped report', $a, 'librefinance_reports',
        'POST', $resourceRoot . '/saved-reports', [
            'name' => 'Synthetic scoped report', 'kind' => 'profit_loss',
            'from' => '2026-01-01', 'through' => '2026-12-31',
        ], 201);
    $field = $t->check('owner defines a scoped company field', $a, 'POST',
        $resourceRoot . '/company-fields',
        ['key' => 'saas_scope', 'label' => 'Synthetic scope', 'type' => 'text'], 201);
    $logoRoute = $resourceRoot . '/companies/' . (int)$companyA1['id'] . '/logo';
    $t->check('owner stores scoped company image', $a, 'POST', $logoRoute,
        ['imageBase64' => trim(file_get_contents(__DIR__ . '/fixtures/logo-valid.b64'))], 200);
    $searchRoute = $resourceRoot . '/search?type=contacts&query=' . rawurlencode($contactName);
    $readRoutes = [
        'contact search' => $searchRoute,
        'invoice' => $resourceRoot . '/invoices/' . (int)$invoice['id'],
        'obligation' => $resourceRoot . '/obligations/' . (int)$obligation['id'],
        'obligation settlements' => $resourceRoot . '/obligations/' . (int)$obligation['id'] . '/settlements',
        'saved reports' => $resourceRoot . '/saved-reports',
        'profit and loss' => $resourceRoot . '/profit-loss' . $period,
        'trial balance' => $resourceRoot . '/trial-balance?through=2026-12-31',
        'cash flow' => $resourceRoot . '/cash-flow' . $period,
        'custom fields' => $resourceRoot . '/company-fields',
        'company image' => $logoRoute,
        'activity' => $resourceRoot . '/activity',
        'bank transfers' => $resourceRoot . '/transfers',
        'payment methods' => $resourceRoot . '/payment-methods',
    ];
    $managerRoutes = [
        'import inventory' => $resourceRoot . '/coverage?sourceCompanyId=synthetic',
        'AI capabilities' => $resourceRoot . '/ai/capabilities',
        'AI preview' => $resourceRoot . '/ai/preview' . $period,
    ];
    $rpcSearch = ['jsonrpc' => '2.0', 'id' => 71, 'method' => 'tools/call', 'params' => [
        'name' => 'librefinance_search', 'arguments' => [
            'spaceId' => $spaceA, 'type' => 'contacts', 'query' => $contactName],
    ]];
    $mcpDenied = static fn(array $r): bool => ($r['result']['isError'] ?? false) === true
        && ($r['result']['content'][0]['text'] ?? null) === 'access_denied'
        && !isset($r['result']['structuredContent']);
    foreach ($readRoutes + $managerRoutes as $label => $route) {
        $t->check('owner can read ' . $label, $a, 'GET', $route, null, 200);
        $t->check('environment admin cannot read ' . $label, $b, 'GET', $route, null, 404);
    }
    $t->check('MCP denies environment admin without financial grant', $b, 'POST',
        '/mcp', $rpcSearch, 200, $mcpDenied);
    $t->check('MCP owner sees only the seeded contact', $a, 'POST', '/mcp', $rpcSearch, 200,
        static fn(array $r): bool => ($r['result']['isError'] ?? true) === false
            && ($r['result']['structuredContent']['results']['contacts'][0]['name'] ?? '') === $contactName);
    $siblingRoot = '/spaces/' . $spaceA2;
    foreach ([
        '/invoices/' . (int)$invoice['id'],
        '/obligations/' . (int)$obligation['id'],
        '/companies/' . (int)$companyA1['id'] . '/logo',
    ] as $route) {
        $t->check('same owner cannot resolve foreign resource through sibling book ' . $route,
            $a, 'GET', $siblingRoot . $route, null, 404);
    }

    // Test the actual optional app through its own authenticated HTTP routes,
    // not just a core-side module flag. Core-only installation remains valid.
    $withNfse = getenv('LF_WITH_EXTENSIONS') === '1';
    if ($withNfse) {
        $nfse = 'librefinance_nfse';
        $draftA1 = '/spaces/' . $spaceA . '/drafts';
        $draftA2 = '/spaces/' . $spaceA2 . '/drafts';
        $draftB = '/spaces/' . $spaceB . '/drafts';
        $nfsePayload = ['operationKey' => 'nfse:saas:A1', 'issuerCnpj' => $syntheticCnpj,
            'municipalityCode' => '3304557', 'competence' => '2026-11-20', 'serviceCode' => '01.01',
            'description' => 'Synthetic SaaS fiscal staging only', 'amountMinor' => 1234,
            'companyId' => (int)$companyA1['id']];
        $t->checkModule('SaaS financial owner stages a non-fiscal draft linked to A1', $a, $nfse,
            'POST', $draftA1, $nfsePayload, 201,
            static fn(array $r): bool => ($r['status'] ?? null) === 'draft'
                && ($r['fiscalDocument'] ?? null) === false
                && ($r['companyId'] ?? null) === (int)$companyA1['id']);
        $t->checkModule('draft staging replay does not duplicate the record', $a, $nfse,
            'POST', $draftA1, $nfsePayload, 200,
            static fn(array $r): bool => ($r['replayed'] ?? null) === true);
        $t->checkModule('environment administrator has no implicit fiscal draft read', $b, $nfse,
            'GET', $draftA1, null, 404);
        $t->checkModule('environment administrator has no implicit fiscal draft write', $b, $nfse,
            'POST', $draftA1, [...$nfsePayload, 'operationKey'=>'nfse:saas:admin-denied'],404);
        instanceCheck('instance administrator has no implicit fiscal draft read',
            'GET', $draftA1, null, 404, null, $nfse);
        $t->checkModule('same owner cannot link a foreign legal company in A2', $a, $nfse,
            'POST', $draftA2, $nfsePayload, 404,
            static fn(array $r): bool => ($r['error'] ?? null) === 'company_not_found');
        $t->checkModule('other environment B cannot link A1 legal company', $b, $nfse,
            'POST', $draftB, $nfsePayload, 404);
        $t->checkModule('independent A2 draft list remains empty', $a, $nfse, 'GET', $draftA2,
            null, 200, static fn(array $r): bool => ($r['drafts'] ?? null) === []);
        $t->checkModule('independent B draft list remains empty', $b, $nfse, 'GET', $draftB,
            null, 200, static fn(array $r): bool => ($r['drafts'] ?? null) === []);
        $nfsePolicyA1 = '/saas/v1/workspaces/' . $spaceA . '/policies/regional.nfseDrafts.enabled';
        $nfsePolicyEnvA = '/saas/v1/environments/' . $envA . '/policies/regional.nfseDrafts.enabled';
        $t->check('financial owner disables fiscal drafts in A1 only', $a, 'PUT', $nfsePolicyA1,
            ['valueJson'=>'false','expectedRevision'=>0], 200);
        $t->checkModule('workspace restriction denies even own fiscal draft read', $a, $nfse,
            'GET', $draftA1, null, 403);
        $t->checkModule('workspace restriction denies draft creation', $a, $nfse,
            'POST', $draftA1,[...$nfsePayload,'operationKey'=>'nfse:saas:policy-denied'],403);
        $t->checkModule('sibling remains allowed under A1 restriction', $a, $nfse, 'GET', $draftA2,null,200);
        $t->check('clear workspace fiscal draft restriction', $a,'DELETE',$nfsePolicyA1 . '?expectedRevision=1',null,200);
        $t->check('environment manager disables fiscal staging throughout A', $b,'PUT',$nfsePolicyEnvA,
            ['valueJson'=>'false','expectedRevision'=>0],200);
        $t->checkModule('parent restriction denies sibling A2', $a,$nfse,'GET',$draftA2,null,403);
        $t->checkModule('parent restriction does not affect environment B', $b,$nfse,'GET',$draftB,null,200);
        $t->check('workspace cannot override environment fiscal restriction',$a,'PUT',$nfsePolicyA1,
            ['valueJson'=>'true','expectedRevision'=>2],403);
        $t->check('clear environment fiscal draft restriction',$b,'DELETE',$nfsePolicyEnvA . '?expectedRevision=1',null,200);
        $t->checkModule('only original draft survives denied writes and policy restoration',$a,$nfse,'GET',$draftA1,null,200,
            static fn(array $r): bool => count($r['drafts'] ?? []) === 1 && ($r['emissionAvailable'] ?? true) === false);
    }

    $t->check('A cannot pre-grant an outsider a dormant SaaS finance role', $a,
        'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => 'synthetictestadmin', 'role' => 'viewer'], 403,
        static fn(array $r): bool => ($r['error'] ?? null) === 'permission_denied');
    $t->check('attempted outside-group grant leaves no persistent membership', $a,
        'GET', '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool =>
            !in_array('synthetictestadmin', array_column($r['members'] ?? [], 'uid'), true));
    $t->check('A1 owner can manage members with an independent revision', $a, 'GET',
        '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool => ($r['grantRevision'] ?? null) === 0
            && ($r['canManageMembers'] ?? null) === true);
    $t->check('authorized SaaS grant without revision is rejected', $a, 'POST',
        '/spaces/' . $spaceA . '/members', ['uid' => $b, 'role' => 'viewer'], 428,
        static fn(array $r): bool => ($r['error'] ?? null) === 'grant_revision_required');
    $t->check('A explicitly grants B viewer access to A1', $a, 'POST',
        '/spaces/' . $spaceA . '/members',
        ['uid' => $b, 'role' => 'viewer', 'expectedRevision' => 0], 200);
    $t->check('first grant advances A1 revision once', $a, 'GET',
        '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool => ($r['grantRevision'] ?? null) === 1
            && count(array_filter($r['members'] ?? [],
                static fn(array $row): bool => ($row['uid'] ?? null) === $b
                    && ($row['role'] ?? null) === 'viewer')) === 1);
    $sameVersionGrantRace = $t->parallel(
        ['user' => $a, 'method' => 'POST', 'path' => '/spaces/' . $spaceA . '/members',
            'data' => ['uid' => $b, 'role' => 'viewer', 'expectedRevision' => 1]],
        ['user' => $a, 'method' => 'POST', 'path' => '/spaces/' . $spaceA . '/members',
            'data' => ['uid' => $b, 'role' => 'viewer', 'expectedRevision' => 1]],
    );
    $sameVersionCodes = array_column($sameVersionGrantRace, 'code');
    sort($sameVersionCodes);
    $t->record('two concurrent same-revision grants have one winner',
        $sameVersionCodes === [200, 409]
            && count(array_filter($sameVersionGrantRace,
                static fn(array $r): bool => ($r['body']['error'] ?? null) === 'revision_conflict')) === 1,
        'HTTP ' . implode('/', $sameVersionCodes), $sameVersionGrantRace);
    $t->check('concurrent grant preserves member role and single revision', $a,
        'GET', '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool => ($r['grantRevision'] ?? null) === 2
            && count(array_filter($r['members'] ?? [],
                static fn(array $row): bool => ($row['uid'] ?? null) === $b
                    && ($row['role'] ?? null) === 'viewer')) === 1);
    $t->check('stale grant replay is rejected without overwriting role', $a, 'POST',
        '/spaces/' . $spaceA . '/members',
        ['uid' => $b, 'role' => 'manager', 'expectedRevision' => 1], 409,
        static fn(array $r): bool => ($r['error'] ?? null) === 'revision_conflict');
    $t->check('B may now read A ledger through direct grant', $b, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 200);
    foreach ($readRoutes as $label => $route) {
        $t->check('explicit viewer may read ' . $label, $b, 'GET', $route, null, 200);
    }
    foreach ($managerRoutes as $label => $route) {
        $t->check('viewer cannot access manager-only ' . $label, $b, 'GET', $route, null, 403);
    }
    $t->check('MCP viewer sees explicitly granted contact', $b, 'POST', '/mcp', $rpcSearch, 200,
        static fn(array $r): bool => ($r['result']['isError'] ?? true) === false
            && ($r['result']['structuredContent']['results']['contacts'][0]['name'] ?? '') === $contactName);

    $t->check('B remains unable to post to A as viewer', $b, 'POST',
        '/spaces/' . $spaceA . '/journals',
        ['debitId' => $ids['1000'], 'creditId' => $ids['4000'],
            'amountMinor' => 100, 'date' => '2026-11-23',
            'memo' => 'Forbidden viewer', 'externalKey' => 'saas-B-forged'], 403);
    $t->check('B cannot elevate itself to finance manager', $b, 'POST',
        '/spaces/' . $spaceA . '/members', ['uid' => $b, 'role' => 'manager'], 403);
    if ($withNfse) {
        $t->checkModule('explicit viewer can read staged draft but not write',$b,$nfse,'GET',$draftA1,null,200,
            static fn(array $r): bool => count($r['drafts'] ?? []) === 1);
        $t->checkModule('viewer cannot stage a new fiscal draft',$b,$nfse,'POST',$draftA1,
            [...$nfsePayload,'operationKey'=>'nfse:saas:viewer-denied'],403);
    }
    $t->check('A grants B operator in A2 while B remains viewer in A1', $a,
        'POST', '/spaces/' . $spaceA2 . '/members',
        ['uid' => $b, 'role' => 'operator', 'expectedRevision' => 0], 200);
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
    if ($withNfse) {
        $a2Draft = [...$nfsePayload,'operationKey'=>'nfse:saas:A2'];
        unset($a2Draft['companyId']);
        $t->checkModule('operator in A2 can stage its own independent draft',$b,$nfse,'POST',$draftA2,$a2Draft,201,
            static fn(array $r): bool => ($r['fiscalDocument'] ?? null) === false);
        $t->checkModule('A2 operator role does not grant A1 draft write',$b,$nfse,'POST',$draftA1,
            [...$nfsePayload,'operationKey'=>'nfse:saas:operator-other-denied'],403);
    }
    $t->check('A revokes B operator membership in A2', $a,
        'DELETE', '/spaces/' . $spaceA2 . '/members/' . $b . '?expectedRevision=1', null, 200);
    $t->check('B loses A2 access after operator revocation', $b,
        'GET', '/spaces/' . $spaceA2 . '/journals', null, 404);
    if ($withNfse) {
        $t->checkModule('revocation immediately denies optional app access',$b,$nfse,'GET',$draftA2,null,404);
        $t->checkModule('revoked operator cannot replay its prior draft operation',$b,$nfse,'POST',$draftA2,$a2Draft,404);
    }
    $t->check('A2 revocation preserves B viewer access in A1', $b,
        'GET', '/spaces/' . $spaceA . '/journals', null, 200);

    $t->check('A revokes B viewer grant', $a, 'DELETE',
        '/spaces/' . $spaceA . '/members/' . $b . '?expectedRevision=2', null, 200);
    $t->check('A1 revoke advances persisted revision', $a, 'GET',
        '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool => ($r['grantRevision'] ?? null) === 3);
    $t->check('revoked member cannot be regranted using a pre-revoke revision', $a,
        'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => $b, 'role' => 'viewer', 'expectedRevision' => 2], 409);
    $t->check('B is denied on very next finance request', $b, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 404);
    if ($withNfse) {
        $t->checkModule('revoked A1 viewer denied direct fiscal route',$b,$nfse,'GET',$draftA1,null,404);
    }
    foreach ($readRoutes + $managerRoutes as $label => $route) {
        $t->check('viewer revocation denies next ' . $label . ' request', $b, 'GET', $route, null, 404);
    }
    $t->check('viewer revocation denies next MCP request', $b, 'POST', '/mcp', $rpcSearch, 200, $mcpDenied);

    $t->check('SaaS owner may explicitly grant manager below owner authority',
        $a, 'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => $b, 'role' => 'manager', 'expectedRevision' => 3], 200);
    $t->check('SaaS financial manager may inspect but not manage members',
        $b, 'GET', '/spaces/' . $spaceA . '/members', null, 200,
        static fn(array $r): bool => ($r['grantRevision'] ?? null) === 4
            && ($r['canManageMembers'] ?? null) === false);
    foreach ($managerRoutes as $label => $route) {
        $t->check('explicit manager can read ' . $label, $b, 'GET', $route, null, 200);
    }
    // Preview does not schedule AI or send any data to an external provider.
    $preview = $t->check('manager can obtain consent-bound aggregate preview', $b, 'GET',
        $resourceRoot . '/ai/preview' . $period, null, 200,
        static fn(array $r): bool => preg_match('/^[a-f0-9]{64}$/', $r['previewHash'] ?? '') === 1);

    $t->check('manager cannot reassign roles or promote itself to owner',
        $b, 'POST', '/spaces/' . $spaceA . '/members',
        ['uid' => $a, 'role' => 'manager'], 403);
    $t->check('owner revokes manager grant', $a, 'DELETE',
        '/spaces/' . $spaceA . '/members/' . $b . '?expectedRevision=4', null, 200);
    $t->check('manager revocation immediately revokes ledger read',
        $b, 'GET', '/spaces/' . $spaceA . '/journals', null, 404);
    foreach ($readRoutes + $managerRoutes as $label => $route) {
        $t->check('manager revocation denies next ' . $label . ' request', $b, 'GET', $route, null, 404);
    }
    $t->check('revoked manager cannot replay a previously obtained AI preview', $b, 'POST',
        $resourceRoot . '/ai/analyze', [
            'from' => '2026-01-01', 'through' => '2026-12-31',
            'consent' => false, 'previewHash' => $preview['previewHash'],
        ], 404);
    $t->check('revoked manager cannot inspect AI task status', $b, 'GET',
        $resourceRoot . '/ai/tasks/2147483647', null, 404);
    $t->check('manager revocation denies MCP again', $b, 'POST', '/mcp', $rpcSearch, 200, $mcpDenied);
    $t->check('owner still sees the original scoped contact', $a, 'GET', $searchRoute, null, 200,
        static fn(array $r): bool => ($r['results']['contacts'][0]['name'] ?? '') === $contactName);

    // 52 workspace rows prove the server does not silently cap at 50 and
    // applies the tenant predicate BEFORE page sizing and cursor selection.
    $t->check('tenant A list starts with two independently provisioned books',
        $b, 'GET', $pathA, null, 200,
        static fn(array $r): bool => count($r['workspaces'] ?? []) === 2
            && ($r['nextAfterId'] ?? null) === null);
    $t->check('negative workspace cursor is rejected without disclosure',
        $b, 'GET', $pathA . '?afterId=-1', null, 422,
        static fn(array $r): bool => ($r['error'] ?? null) === 'invalid_cursor');
    for ($i = 1; $i <= 50; ++$i) {
        $t->check('tenant A cursor test creates independent book ' . $i,
            $b, 'POST', $pathA, [
                'name' => 'SaaS page book ' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                'ownerUid' => $a,
                'idempotencyKey' => 'synthetic-saas-page-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
            ], 201);
    }
    $firstPage = $t->check('tenant A first page contains exactly 50 books',
        $b, 'GET', $pathA, null, 200,
        static fn(array $r): bool => count($r['workspaces'] ?? []) === 50
            && is_int($r['nextAfterId'] ?? null));
    $cursor = (int)($firstPage['nextAfterId'] ?? 0);
    $secondPage = $t->check('tenant A continuation contains remaining two books',
        $b, 'GET', $pathA . '?afterId=' . $cursor, null, 200,
        static fn(array $r): bool => count($r['workspaces'] ?? []) === 2
            && array_key_exists('nextAfterId', $r)
            && $r['nextAfterId'] === null);
    $firstIds = array_column($firstPage['workspaces'] ?? [], 'id');
    $secondIds = array_column($secondPage['workspaces'] ?? [], 'id');
    $sortedFirst = $firstIds;
    $sortedSecond = $secondIds;
    sort($sortedFirst, SORT_NUMERIC);
    sort($sortedSecond, SORT_NUMERIC);
    saasCheck('cursor pages are disjoint, sorted and complete',
        count(array_unique([...$firstIds, ...$secondIds])) === 52
            && $firstIds === $sortedFirst
            && $secondIds === $sortedSecond
            && max($firstIds) < min($secondIds));
    $t->check('tenant B enumeration never sees A paginated books', $a,
        'GET', $pathB, null, 200,
        static fn(array $r): bool => count($r['workspaces'] ?? []) === 1
            && array_key_exists('nextAfterId', $r)
            && $r['nextAfterId'] === null
            && ($r['workspaces'][0]['name'] ?? '') === 'Isolated B1');
    instanceCheck('instance admin suspends environment A without deleting postings',
        'PUT', '/saas/v1/admin/groups/' . $groupA . '/enablement',
        ['enabled' => false], 200);
    $t->check('suspended environment denies direct owner access', $a, 'GET',
        '/spaces/' . $spaceA . '/journals', null, 404);
    if ($withNfse) {
        $t->checkModule('suspended environment denies fiscal owner on next request',$a,$nfse,'GET',$draftA1,null,404);
        $t->checkModule('separate environment B retains fiscal staging capability',$b,$nfse,'GET',$draftB,null,200);
    }
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
