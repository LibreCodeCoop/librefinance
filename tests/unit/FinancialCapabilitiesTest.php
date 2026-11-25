<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit;

use DomainException;
use OCA\LibreFinance\Domain\Saas\FinancialCapabilities as Access;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinancialCapabilitiesTest extends TestCase {
    private const READER = [
        'workspace.read', 'ledger.read', 'accounts.read', 'bank.read', 'invoice.read',
        'obligations.read', 'reports.read', 'contacts.read', 'companies.read', 'items.read',
        'categories.read', 'payment_methods.read', 'custom_fields.read', 'dashboard.configure', 'currency.rates.read',
    ];
    private const OPERATOR = [
        'ledger.post', 'bank.reconcile', 'invoice.manage', 'obligations.manage',
        'bank.import_statement', 'bank.use', 'companies.resolve', 'categories.use',
        'payment_methods.use', 'custom_fields.submit',
    ];
    private const MANAGER = [
        'workspace.configure', 'ledger.reverse', 'accounts.manage', 'bank.manage',
        'invoice.issue', 'imports.read', 'imports.apply', 'contacts.manage',
        'companies.manage', 'invoices.tax.manage', 'obligations.cancel', 'reports.manage',
        'reports.analyze', 'items.manage', 'categories.manage', 'payment_methods.manage',
        'custom_fields.manage', 'modules.configure', 'roles.read', 'currency.rates.manage',
    ];

    private const MATRIX = [
        'viewer' => self::READER,
        'auditor' => [...self::READER, 'audit.read', 'reports.export'],
        'operator' => [...self::READER, ...self::OPERATOR, 'reports.export'],
        'manager' => [...self::READER, ...self::OPERATOR, ...self::MANAGER, 'audit.read', 'reports.export'],
        'owner' => [...self::READER, ...self::OPERATOR, ...self::MANAGER, 'audit.read', 'reports.export', 'roles.manage'],
        'instance_admin' => [],
        'group_subadmin' => [],
        'unknown' => [],
    ];

    public static function explicitPermissions(): iterable {
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
            yield "$role:$capability" => [$role, $capability, $allowed];
        }
    }

    #[DataProvider('explicitPermissions')]
    public function testExplicitPermissions(string $role, string $capability, bool $allowed): void {
        self::assertSame($allowed, Access::allows($role, $capability));
    }

    public function testAllRegistryCapabilitiesAreCovered(): void {
        $all = Access::all();
        $expected = self::MATRIX['owner'];
        sort($all);
        sort($expected);

        self::assertSame($expected, $all);
    }

    public static function permissionMatrix(): iterable {
        // The expected operation list is independently specified, not derived from Access::all().
        foreach (self::MATRIX as $role => $allowed) {
            foreach (self::MATRIX['owner'] as $capability) {
                yield "$role:$capability" => [$role, $capability, in_array($capability, $allowed, true)];
            }
        }
    }

    #[DataProvider('permissionMatrix')]
    public function testPermissionMatrix(string $role, string $capability, bool $allowed): void {
        self::assertSame($allowed, Access::allows($role, $capability));
    }

    public static function roleCapabilities(): iterable {
        foreach (self::MATRIX as $role => $allowed) {
            yield $role => [$role, $allowed];
        }
    }

    #[DataProvider('roleCapabilities')]
    public function testExactCapabilityList(string $role, array $allowed): void {
        $actual = Access::forRole($role);
        sort($actual);
        sort($allowed);

        self::assertSame($allowed, $actual);
    }

    public function testViewerCannotPost(): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Apermission_denied\z/');
        Access::requireAllowed('viewer', 'ledger.post');
    }

    public function testUnknownCapabilityFailsClosed(): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Aunknown_financial_capability\z/');
        Access::allows('owner', 'ledger.god_mode');
    }

    public function testOnlyOwnerCanGrantRoles(): void {
        self::assertContains('roles.manage', Access::forRole('owner'));
        self::assertNotContains('roles.manage', Access::forRole('manager'));
    }
}
