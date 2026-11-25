<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit;

use InvalidArgumentException;
use OCA\LibreFinance\PublicAPI\ModuleDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleDescriptorTest extends TestCase {
    public function testLegacyConstructor(): void {
        $descriptor = new ModuleDescriptor('librefinance_example', 'Example', 'Synthetic module', 'librefinance_example.page.index');

        self::assertSame(1, $descriptor->contractVersion);
        self::assertTrue($descriptor->isCompatible());
    }

    public function testExplicitVersionPreservesPermissionFloor(): void {
        $descriptor = new ModuleDescriptor('librefinance_example', 'Example', 'Synthetic module', 'librefinance_example.page.index', 'manager', 1);

        self::assertTrue($descriptor->isCompatible());
        self::assertSame('manager', $descriptor->requiredRole);
    }

    public function testFutureVersionIsIncompatible(): void {
        $descriptor = new ModuleDescriptor('librefinance_example', 'Example', 'Synthetic module', 'librefinance_example.page.index', 'viewer', 2);

        self::assertSame(2, $descriptor->contractVersion);
        self::assertFalse($descriptor->isCompatible());
    }

    public function testBuiltInRoute(): void {
        $descriptor = new ModuleDescriptor('librefinance_reports', 'Reports', 'Synthetic reports', 'librefinance.page.index');

        self::assertTrue($descriptor->isCompatible());
    }

    public static function invalidDescriptors(): array {
        return [
            'zero version' => [['librefinance_example', 'Example', 'Description', 'librefinance_example.page.index', 'viewer', 0]],
            'negative version' => [['librefinance_example', 'Example', 'Description', 'librefinance_example.page.index', 'viewer', -1]],
            'version overflow' => [['librefinance_example', 'Example', 'Description', 'librefinance_example.page.index', 'viewer', 65536]],
            'foreign app ID' => [['unrelated_app', 'Example', 'Description', 'unrelated_app.page.index']],
            'foreign app route' => [['librefinance_example', 'Example', 'Description', 'librefinance_other.page.index']],
            'invalid permission floor' => [['librefinance_example', 'Example', 'Description', 'librefinance_example.page.index', 'owner']],
            'missing label' => [['librefinance_example', '', 'Description', 'librefinance_example.page.index']],
        ];
    }

    #[DataProvider('invalidDescriptors')]
    public function testRejectsInvalidDescriptor(array $arguments): void {
        $this->expectException(InvalidArgumentException::class);
        new ModuleDescriptor(...$arguments);
    }
}
