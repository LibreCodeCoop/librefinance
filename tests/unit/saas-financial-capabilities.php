<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/Domain/Saas/FinancialCapabilities.php';

use OCA\LibreFinance\Domain\Saas\FinancialCapabilities as Access;

$checks = 0;
function expect(bool $actual, bool $expected, string $case): void {
    global $checks;
    if ($actual !== $expected) {
        throw new RuntimeException("$case: expected " . ($expected ? 'allow' : 'deny'));
    }
    ++$checks;
}
foreach ([
    ['viewer', 'ledger.read', true],
    ['viewer', 'ledger.post', false],
    ['viewer', 'accounts.manage', false],
    ['auditor', 'ledger.read', true],
    ['auditor', 'audit.read', true],
    ['auditor', 'ledger.post', false],
    ['auditor', 'ledger.reverse', false],
    ['operator', 'ledger.post', true],
    ['operator', 'ledger.reverse', false],
    ['operator', 'roles.manage', false],
    ['manager', 'ledger.reverse', true],
    ['manager', 'roles.read', true],
    ['manager', 'roles.manage', false],
    ['owner', 'roles.manage', true],
    ['owner', 'bank.reconcile', true],
    // Administrative Nextcloud identities are never financial roles.
    ['instance_admin', 'ledger.read', false],
    ['group_subadmin', 'ledger.read', false],
    ['', 'ledger.read', false],
] as [$role, $capability, $allowed]) {
    expect(Access::allows($role, $capability), $allowed, "$role:$capability");
}
try {
    Access::requireAllowed('viewer', 'ledger.post');
    throw new RuntimeException('Viewer was able to post');
} catch (DomainException $e) {
    expect($e->getMessage() === 'permission_denied', true, 'viewer write throws permission_denied');
}
try {
    Access::allows('owner', 'ledger.god_mode');
    throw new RuntimeException('Unknown capability allowed');
} catch (DomainException $e) {
    expect($e->getMessage() === 'unknown_financial_capability', true, 'unknown capabilities fail closed');
}
expect(in_array('roles.manage', Access::forRole('owner'), true), true, 'owner grant capability');
expect(in_array('roles.manage', Access::forRole('manager'), true), false, 'manager cannot promote');
echo "SaaS financial capability matrix: $checks named allow/deny assertions passed\n";
