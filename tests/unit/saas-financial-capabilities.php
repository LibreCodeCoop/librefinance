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
// Exhaustive independently specified role matrix, including non-hierarchical
// auditor capabilities. Adding a registry operation requires an explicit test.
$reader = [
    'workspace.read', 'ledger.read', 'accounts.read', 'bank.read', 'invoice.read',
    'obligations.read', 'reports.read', 'contacts.read', 'companies.read', 'items.read',
    'categories.read', 'payment_methods.read', 'custom_fields.read', 'dashboard.configure', 'currency.rates.read',
];
$operator = [
    'ledger.post', 'bank.reconcile', 'invoice.manage', 'obligations.manage',
    'bank.import_statement', 'bank.use', 'companies.resolve', 'categories.use',
    'payment_methods.use', 'custom_fields.submit',
];
$manager = [
    'workspace.configure', 'ledger.reverse', 'accounts.manage', 'bank.manage',
    'invoice.issue', 'imports.read', 'imports.apply', 'contacts.manage',
    'companies.manage', 'invoices.tax.manage', 'obligations.cancel', 'reports.manage',
    'reports.analyze', 'items.manage', 'categories.manage', 'payment_methods.manage',
    'custom_fields.manage', 'modules.configure', 'roles.read', 'currency.rates.manage',
];
$matrix = [
    'viewer' => $reader,
    'auditor' => [...$reader, 'audit.read', 'reports.export'],
    'operator' => [...$reader, ...$operator, 'reports.export'],
    'manager' => [...$reader, ...$operator, ...$manager, 'audit.read', 'reports.export'],
    'owner' => [...$reader, ...$operator, ...$manager, 'audit.read', 'reports.export', 'roles.manage'],
    'instance_admin' => [], 'group_subadmin' => [], 'unknown' => [],
];
$all = Access::all();
$expected = $matrix['owner'];
sort($all);
sort($expected);
expect($all === $expected, true, 'all registry capabilities explicitly covered');
foreach ($matrix as $role => $allowed) {
    foreach ($all as $capability) {
        expect(Access::allows($role, $capability), in_array($capability, $allowed, true),
            "$role:$capability exhaustive");
    }
    $actual = Access::forRole($role);
    sort($actual);
    sort($allowed);
    expect($actual === $allowed, true, "$role exact capability list");
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
