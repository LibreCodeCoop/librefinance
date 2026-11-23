<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Saas;

use DomainException;

/**
 * Operation capabilities are independent from the SaaS policy engine.
 * Per-workspace grants supply a role; this registry evaluates what that role
 * is allowed to do. No group, subadmin or instance admin gets an implicit role.
 */
final class FinancialCapabilities {
    /** @var array<string,list<string>> */
    private const ALLOW = [
        'workspace.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'workspace.configure' => ['manager', 'owner'],
        'ledger.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'ledger.post' => ['operator', 'manager', 'owner'],
        'ledger.reverse' => ['manager', 'owner'],
        'accounts.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'accounts.manage' => ['manager', 'owner'],
        'bank.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'bank.reconcile' => ['operator', 'manager', 'owner'],
        'bank.manage' => ['manager', 'owner'],
        'invoice.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'invoice.manage' => ['operator', 'manager', 'owner'],
        'invoice.issue' => ['manager', 'owner'],
        'obligations.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'obligations.manage' => ['operator', 'manager', 'owner'],
        'imports.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'imports.apply' => ['manager', 'owner'],
        'reports.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'reports.export' => ['auditor', 'operator', 'manager', 'owner'],
        'contacts.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'contacts.manage' => ['operator', 'manager', 'owner'],
        'companies.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'companies.manage' => ['manager', 'owner'],
        'roles.read' => ['manager', 'owner'],
        'roles.manage' => ['owner'],
        'audit.read' => ['auditor', 'manager', 'owner'],
    ];

    public static function allows(string $role, string $capability): bool {
        $roles = self::ALLOW[$capability] ?? throw new DomainException('unknown_financial_capability');
        return in_array($role, $roles, true);
    }

    public static function requireAllowed(string $role, string $capability): void {
        if (!self::allows($role, $capability)) {
            throw new DomainException('permission_denied');
        }
    }

    /** @return list<string> */
    public static function all(): array {
        return array_keys(self::ALLOW);
    }

    /** @return list<string> */
    public static function forRole(string $role): array {
        return array_values(array_filter(self::all(),
            static fn(string $capability): bool => in_array($role, self::ALLOW[$capability], true)));
    }
}
