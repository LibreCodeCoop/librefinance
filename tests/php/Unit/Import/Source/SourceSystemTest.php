<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Import\Source;

use OCA\LibreFinance\Import\Source\SourceSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceSystemTest extends TestCase {
    #[DataProvider('validSourceProvider')]
    public function testValidSourceSystems(mixed $source, bool $expected): void {
        $this->assertSame($expected, SourceSystem::valid($source));
    }

    #[DataProvider('rejectedSourceProvider')]
    public function testRejectedSourceSystems(mixed $source, bool $expected): void {
        $this->assertSame($expected, SourceSystem::valid($source));
    }

    public static function validSourceProvider(): array {
        return [
            'akaunting' => ['akaunting', true],
            'generic' => ['generic', true],
            'gnucash' => ['gnucash', true],
            'source_123' => ['source_123', true],
            'my-erp' => ['my-erp', true],
            'min_length' => ['abc', true],
            'max_length' => [str_repeat('x', 24), true],
        ];
    }

    public static function rejectedSourceProvider(): array {
        return [
            'empty' => ['', false],
            'too_short_1' => ['a', false],
            'too_short_2' => ['ab', false],
            'uppercase' => ['Akaunting', false],
            'path_escape' => ['../escape', false],
            'slash' => ['my/erp', false],
            'unsafe_chars' => ['unsafe.example.com', false],
            'newline_injection' => ["foo\nX-Company: 1", false],
            'terminal_newline' => ["foo\n", false],
            'too_long' => [str_repeat('x', 25), false],
            'single_char_x' => ['x', false],
            'null_value' => [null, false],
            'int_value' => [123, false],
            'array_value' => [[], false],
            'bool_value' => [false, false],
        ];
    }
}
