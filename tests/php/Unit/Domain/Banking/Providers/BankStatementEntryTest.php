<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Banking\Providers;

use InvalidArgumentException;
use OCA\LibreFinance\Domain\Banking\Providers\BankStatementEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankStatementEntryTest extends TestCase {

    #[DataProvider('validProvider')]
    public function testValidEntry(string $externalId, string $date, int $amountMinor, string $description): void {
        $entry = new BankStatementEntry($externalId, $date, $amountMinor, $description);
        self::assertSame($externalId, $entry->externalId);
        self::assertSame($date, $entry->date);
        self::assertSame($amountMinor, $entry->amountMinor);
        self::assertSame($description, $entry->description);
    }

    public static function validProvider(): array {
        return [
            'year zero and positive validProvider control' => ['EXT-0001', '0001-01-01', 100, 'Year one control'],
            'standard positive' => ['EXT-123.45_67:89', '2026-06-06', 1000, 'Salary'],
            'negative amount' => ['ID1', '2026-06-06', -500, 'Expense'],
            'leap day' => ['LEAP-2024', '2024-02-29', 250, 'Leap day transaction'],
            'max length id' => [str_repeat('a', 128), '2026-01-01', 100, 'Description'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidEntry(string $externalId, string $date, int $amountMinor, string $description): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_bank_statement_entry');
        new BankStatementEntry($externalId, $date, $amountMinor, $description);
    }

    public static function invalidProvider(): array {
        return [
            'year zero' => ['EXT0', '0000-01-01', 100, 'Year zero description'],
            'terminal newline in external id' => ["synthetic-id\n", '2026-06-06', 100, 'Description'],
            'malformed external id chars' => ['ext id with space', '2026-06-06', 100, 'Description'],
            'too long external id' => [str_repeat('a', 129), '2026-06-06', 100, 'Description'],
            'empty external id' => ['', '2026-06-06', 100, 'Description'],
            'malformed date format' => ['EXT1', '06/06/2026', 100, 'Description'],
            'impossible date' => ['EXT1', '2026-02-30', 100, 'Description'],
            'invalid leap day' => ['EXT1', '2023-02-29', 100, 'Description'],
            'zero amount' => ['EXT1', '2026-06-06', 0, 'Description'],
            'amount out of bound high' => ['EXT1', '2026-06-06', 9000000000001, 'Description'],
            'amount out of bound low' => ['EXT1', '2026-06-06', -9000000000001, 'Description'],
            'empty description' => ['EXT1', '2026-06-06', 100, ''],
            'whitespace description' => ['EXT1', '2026-06-06', 100, '   '],
            'too long description' => ['EXT1', '2026-06-06', 100, str_repeat('a', 256)],
        ];
    }
}
