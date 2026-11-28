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
        'currency.rates.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'currency.rates.manage' => ['manager', 'owner'],
        'bank.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'bank.reconcile' => ['operator', 'manager', 'owner'],
        'bank.manage' => ['manager', 'owner'],
        'invoice.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'invoice.manage' => ['operator', 'manager', 'owner'],
        'invoice.issue' => ['manager', 'owner'],
        'obligations.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'obligations.manage' => ['operator', 'manager', 'owner'],
        'imports.read' => ['manager', 'owner'],
        'imports.apply' => ['manager', 'owner'],
        'reports.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        // General-ledger export requires this explicit capability on each page.
        'reports.export' => ['auditor', 'operator', 'manager', 'owner'],
        'contacts.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'contacts.manage' => ['manager', 'owner'],
        'companies.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'companies.manage' => ['manager', 'owner'],
        'bank.import_statement' => ['operator', 'manager', 'owner'],
        'bank.use' => ['operator', 'manager', 'owner'],
        'invoices.tax.manage' => ['manager', 'owner'],
        'obligations.cancel' => ['manager', 'owner'],
        'reports.manage' => ['manager', 'owner'],
        'reports.analyze' => ['manager', 'owner'],
        'companies.resolve' => ['operator', 'manager', 'owner'],
        'items.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'items.manage' => ['manager', 'owner'],
        'categories.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'categories.manage' => ['manager', 'owner'],
        'categories.use' => ['operator', 'manager', 'owner'],
        'payment_methods.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'payment_methods.manage' => ['manager', 'owner'],
        'payment_methods.use' => ['operator', 'manager', 'owner'],
        'custom_fields.read' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'custom_fields.manage' => ['manager', 'owner'],
        'custom_fields.submit' => ['operator', 'manager', 'owner'],
        'dashboard.configure' => ['viewer', 'auditor', 'operator', 'manager', 'owner'],
        'modules.configure' => ['manager', 'owner'],
        'roles.read' => ['manager', 'owner'],
        'roles.manage' => ['owner'],
        // Reserved for a future accounting audit reader, not administrative policy history.
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
