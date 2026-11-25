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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyDefinitionTest extends TestCase {
    public function testInvalidScopeDefinition(): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_policy_scopes\z/');
        new PolicyDefinition('a.b', 'boolean', ['environment', 'instance'], false, true);
    }

    public static function invalidPolicyValues(): array {
        return [
            'unknown bank format' => ['banking.allowedImportFormats', ['xml']],
            'nested bank format' => ['banking.allowedImportFormats', ['csv', ['ofx']]],
            'report string flag' => ['modules.reports.enabled', 'true'],
            'fiscal draft string flag' => ['regional.nfseDrafts.enabled', 'false'],
        ];
    }

    #[DataProvider('invalidPolicyValues')]
    public function testRejectsInvalidPolicyValues(string $key, mixed $value): void {
        $policy = (new PolicyRegistry())->get($key);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\Ainvalid_policy_value\z/');
        $policy->assertValue($value);
    }
}
