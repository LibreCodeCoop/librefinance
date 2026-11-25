<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Saas;

use DomainException;
use OCA\LibreFinance\Domain\Saas\PolicyDefinition;
use OCA\LibreFinance\Domain\Saas\PolicyRegistry;
use OCA\LibreFinance\Domain\Saas\PolicyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyResolverTest extends TestCase {
    private PolicyRegistry $registry;
    private PolicyResolver $resolver;

    protected function setUp(): void {
        $this->registry = new PolicyRegistry();
        $this->resolver = new PolicyResolver();
    }

    private static function layer(string $scope, mixed $value): array {
        return ['scope' => $scope, 'value' => $value];
    }

    public function testExternalSharingDefaultFailsClosed(): void {
        $result = $this->resolver->resolve($this->registry->get('externalSharing.enabled'), []);

        self::assertFalse($result['effectiveValue']);
        self::assertSame('default', $result['sourceScope']);
    }

    public function testChildCannotBypassParentDenial(): void {
        $result = $this->resolver->resolve($this->registry->get('externalSharing.enabled'), [
            self::layer('instance', true), self::layer('environment', false), self::layer('workspace', true),
        ]);

        self::assertFalse($result['effectiveValue']);
        self::assertSame('environment', $result['blockedBy']);
        self::assertSame('environment', $result['sourceScope']);
        self::assertSame([false], $result['allowedValues']);
        self::assertFalse($result['editableByCurrentActor']);
    }

    public function testExplicitParentAllowsChild(): void {
        $result = $this->resolver->resolve($this->registry->get('externalSharing.enabled'), [
            self::layer('instance', true), self::layer('environment', true),
        ]);

        self::assertTrue($result['effectiveValue']);
        self::assertSame('instance', $result['sourceScope']);
        self::assertSame([false, true], $result['allowedValues']);
    }

    public function testMissingInstanceOptInCannotBeCircumvented(): void {
        $result = $this->resolver->resolve($this->registry->get('externalSharing.enabled'), [
            self::layer('environment', true),
        ]);

        self::assertFalse($result['effectiveValue']);
        self::assertSame('default', $result['blockedBy']);
        self::assertSame('default', $result['sourceScope']);
    }

    public function testChildCeilingTightens(): void {
        $result = $this->resolver->resolve($this->registry->get('workspaces.maxCount'), [
            self::layer('instance', 8), self::layer('environment', 3),
        ]);

        self::assertSame(3, $result['effectiveValue']);
        self::assertSame('environment', $result['sourceScope']);
        self::assertSame(3, $result['maxAllowed']);
    }

    public function testChildCeilingCannotWiden(): void {
        $result = $this->resolver->resolve($this->registry->get('workspaces.maxCount'), [
            self::layer('instance', 2), self::layer('environment', 10),
        ]);

        self::assertSame(2, $result['effectiveValue']);
        self::assertSame('instance', $result['blockedBy']);
    }

    public function testZeroCeilingDenies(): void {
        $result = $this->resolver->resolve($this->registry->get('workspaces.maxCount'), [
            self::layer('instance', 0),
        ]);

        self::assertSame('instance', $result['blockedBy']);
    }

    private function regionPolicy(): PolicyDefinition {
        return new PolicyDefinition('regions.allowed', 'allowed_set',
            ['instance', 'environment', 'workspace'], ['BR', 'PT', 'US'], true);
    }

    public function testSetsOnlyIntersect(): void {
        $result = $this->resolver->resolve($this->regionPolicy(), [
            self::layer('instance', ['BR', 'PT', 'US']),
            self::layer('environment', ['BR', 'PT', 'XX']),
            self::layer('workspace', ['PT', 'FR']),
        ]);

        self::assertSame(['PT'], $result['effectiveValue']);
        self::assertSame('workspace', $result['sourceScope']);
    }

    public function testEqualIntersectionRetainsAncestorOrderAndSource(): void {
        $result = $this->resolver->resolve($this->regionPolicy(), [
            self::layer('instance', ['BR', 'PT', 'US']),
            self::layer('environment', ['BR', 'PT']),
            self::layer('workspace', ['PT', 'BR']),
        ]);

        self::assertSame(['BR', 'PT'], $result['effectiveValue']);
        self::assertSame('environment', $result['sourceScope']);
    }

    public function testEmptyIntersectionDenies(): void {
        $result = $this->resolver->resolve($this->regionPolicy(), [
            self::layer('instance', ['BR']), self::layer('environment', ['US']),
        ]);

        self::assertSame([], $result['effectiveValue']);
        self::assertSame('environment', $result['blockedBy']);
        self::assertSame('environment', $result['sourceScope']);
    }

    public function testInstanceOnlyPolicyRejectsChildEvenIfDeclared(): void {
        $policy = new PolicyDefinition('modules.enabled', 'boolean',
            ['instance', 'environment'], true, false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Apolicy_override_forbidden\z/');
        $this->resolver->resolve($policy, [
            self::layer('instance', true), self::layer('environment', false),
        ]);
    }

    public function testBankFormatDefaultsPreserveCsvAndOfx(): void {
        $policy = $this->registry->get('banking.allowedImportFormats');

        self::assertSame(['csv', 'ofx'], $this->resolver->resolve($policy, [])['effectiveValue']);
    }

    public function testBankFormatIntersectionCannotWidenEnvironment(): void {
        $result = $this->resolver->resolve($this->registry->get('banking.allowedImportFormats'), [
            self::layer('instance', ['csv', 'ofx']),
            self::layer('environment', ['ofx']),
            self::layer('workspace', ['csv', 'ofx']),
        ]);

        self::assertSame(['ofx'], $result['effectiveValue']);
        self::assertSame('environment', $result['sourceScope']);
    }

    public function testEmptyBankFormatIntersectionDeniesAll(): void {
        $result = $this->resolver->resolve($this->registry->get('banking.allowedImportFormats'), [
            self::layer('instance', ['ofx']), self::layer('environment', ['csv']),
        ]);

        self::assertSame([], $result['effectiveValue']);
    }

    public static function optionalModulePolicies(): array {
        return [
            'reports' => ['modules.reports.enabled'],
            'fiscal drafts' => ['regional.nfseDrafts.enabled'],
        ];
    }

    #[DataProvider('optionalModulePolicies')]
    public function testModuleDefaultsAndEnvironmentRestrictions(string $key): void {
        $policy = $this->registry->get($key);
        self::assertTrue($this->resolver->resolve($policy, [])['effectiveValue']);

        $result = $this->resolver->resolve($policy, [
            self::layer('instance', true), self::layer('environment', false), self::layer('workspace', true),
        ]);
        self::assertFalse($result['effectiveValue']);
        self::assertSame('environment', $result['blockedBy']);
    }

    public function testDashboardEnabledByDefault(): void {
        $policy = $this->registry->get('dashboard.kpis.enabled');

        self::assertTrue($this->resolver->resolve($policy, [])['effectiveValue']);
    }

    public function testUserMayHideOwnDashboardCards(): void {
        $result = $this->resolver->resolve($this->registry->get('dashboard.kpis.enabled'), [
            self::layer('instance', true), self::layer('environment', true),
            self::layer('workspace', true), self::layer('user', false),
        ]);

        self::assertFalse($result['effectiveValue']);
        self::assertSame('user', $result['sourceScope']);
    }

    public function testUserCannotBypassWorkspaceDashboardRestriction(): void {
        $result = $this->resolver->resolve($this->registry->get('dashboard.kpis.enabled'), [
            self::layer('instance', true), self::layer('environment', true),
            self::layer('workspace', false), self::layer('user', true),
        ]);

        self::assertFalse($result['effectiveValue']);
        self::assertSame('workspace', $result['blockedBy']);
        self::assertSame('workspace', $result['sourceScope']);
    }

    public static function invalidResolutions(): array {
        return [
            'instance-only policy rejects environment' => ['environments.enabled', [
                self::layer('instance', true), self::layer('environment', true),
            ], 'unsupported_scope'],
            'malformed flag' => ['externalSharing.enabled', [
                self::layer('instance', 'false'),
            ], 'invalid_policy_value'],
            'duplicate instance' => ['externalSharing.enabled', [
                self::layer('instance', true), self::layer('instance', false),
            ], 'invalid_policy_order'],
            'reverse order' => ['externalSharing.enabled', [
                self::layer('workspace', false), self::layer('environment', true),
            ], 'invalid_policy_order'],
            'duplicate environment' => ['externalSharing.enabled', [
                self::layer('environment', true), self::layer('environment', false),
            ], 'invalid_policy_order'],
            'negative quota' => ['workspaces.maxCount', [
                self::layer('instance', -1),
            ], 'invalid_policy_value'],
            'noninteger quota' => ['workspaces.maxCount', [
                self::layer('instance', 1.1),
            ], 'invalid_policy_value'],
            'unsafe JSON integer quota' => ['workspaces.maxCount', [
                self::layer('instance', 9007199254740992),
            ], 'invalid_policy_value'],
            'workspace cannot inject bank format' => ['banking.allowedImportFormats', [
                self::layer('instance', ['csv', 'ofx']), self::layer('workspace', ['csv', 'xml']),
            ], 'invalid_policy_value'],
            'user cannot change external sharing' => ['externalSharing.enabled', [
                self::layer('instance', true), self::layer('user', true),
            ], 'unsupported_scope'],
            'user cannot change company count' => ['workspaces.maxCount', [
                self::layer('instance', 2), self::layer('user', 99),
            ], 'unsupported_scope'],
        ];
    }

    #[DataProvider('invalidResolutions')]
    public function testRejectsInvalidResolution(string $key, array $layers, string $error): void {
        $policy = $this->registry->get($key);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\A' . preg_quote($error, '/') . '\z/');
        $this->resolver->resolve($policy, $layers);
    }

    public static function invalidRegionLayers(): array {
        return [
            'duplicate set value' => [[self::layer('instance', ['BR', 'BR'])], 'invalid_policy_value'],
            'nested set value' => [[self::layer('instance', [['BR']])], 'invalid_policy_value'],
            'unsupported user scope' => [[self::layer('instance', ['BR']), self::layer('user', ['BR'])], 'unsupported_scope'],
        ];
    }

    #[DataProvider('invalidRegionLayers')]
    public function testRejectsInvalidRegionLayers(array $layers, string $error): void {
        $policy = $this->regionPolicy();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\A' . preg_quote($error, '/') . '\z/');
        $this->resolver->resolve($policy, $layers);
    }
}
