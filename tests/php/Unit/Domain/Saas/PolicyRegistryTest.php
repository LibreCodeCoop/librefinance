<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Saas;

use DomainException;
use OCA\LibreFinance\Domain\Saas\PolicyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyRegistryTest extends TestCase {
    public function testRegistryRejectsUnknownKey(): void {
        $registry = new PolicyRegistry();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Aunknown_policy_key\z/');
        $registry->get('financial.allAdmin');
    }

    public static function registeredUserScopes(): array {
        return [
            'bank format' => ['banking.allowedImportFormats', false],
            'reports' => ['modules.reports.enabled', false],
            'fiscal drafts' => ['regional.nfseDrafts.enabled', false],
            'dashboard' => ['dashboard.kpis.enabled', true],
        ];
    }

    #[DataProvider('registeredUserScopes')]
    public function testRegisteredPolicyUserScope(string $key, bool $expected): void {
        $policy = (new PolicyRegistry())->get($key);

        self::assertSame($expected, $policy->supports('user'));
    }
}
