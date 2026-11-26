<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Obligations;

use DomainException;
use OCA\LibreFinance\Domain\Obligations\RecurrenceSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceScheduleTest extends TestCase {
    public static function validCases(): array {
        return [
            'monthly simple' => ['2026-01-15', 3, 1, 'clamp', ['2026-02-15', '2026-03-15', '2026-04-15']],
            'step two months' => ['2026-01-15', 2, 2, 'clamp', ['2026-03-15', '2026-05-15']],
            'leap year anchor recovery' => ['2024-02-29', 1, 12, 'clamp', ['2025-02-28']],
            'nonleap anchor recovery clamp' => ['2023-01-31', 1, 1, 'clamp', ['2023-02-28']],
            'max count' => ['2026-01-01', 24, 1, 'clamp', ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01', '2027-06-01', '2027-07-01', '2027-08-01', '2027-09-01', '2027-10-01', '2027-11-01', '2027-12-01', '2028-01-01']],
            'leap clamp recovers original day' => ['2028-01-31', 3, 1, 'clamp', ['2028-02-29', '2028-03-31', '2028-04-30']],
            'nonleap clamp recovers original day' => ['2027-01-31', 2, 1, 'clamp', ['2027-02-28', '2027-03-31']],
            'early Gregorian leap year' => ['0004-01-31', 2, 1, 'clamp', ['0004-02-29', '0004-03-31']],
        ];
    }

    #[DataProvider('validCases')]
    public function testDatesValid(string $date, int $count, int $stepMonths, string $endOfMonth, array $expected): void {
        self::assertSame($expected, RecurrenceSchedule::dates($date, $count, $stepMonths, $endOfMonth));
    }

    public static function invalidCases(): array {
        return [
            'count zero' => ['2026-01-15', 0, 1, 'clamp', 'invalid_recurrence'],
            'count twenty five' => ['2026-01-15', 25, 1, 'clamp', 'invalid_recurrence'],
            'step zero' => ['2026-01-15', 1, 0, 'clamp', 'invalid_recurrence'],
            'step thirteen' => ['2026-01-15', 1, 13, 'clamp', 'invalid_recurrence'],
            'unsupported policy' => ['2026-01-15', 1, 1, 'unsupported', 'invalid_recurrence'],
            'reject missing day' => ['2023-01-31', 1, 1, 'reject', 'recurrence_day_unavailable'],
            'terminal LF' => ["2026-01-15\n", 1, 1, 'clamp', 'invalid_recurrence'],
            'year zero' => ['0000-01-15', 1, 1, 'clamp', 'invalid_recurrence'],
            'overflow year 10000' => ['9999-12-31', 1, 1, 'clamp', 'invalid_recurrence'],
            'invalid date format' => ['2026-02-30', 1, 1, 'clamp', 'invalid_recurrence'],
            'malformed date' => ['26-1-1', 1, 1, 'clamp', 'invalid_recurrence'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function testDatesInvalid(string $date, int $count, int $stepMonths, string $endOfMonth, string $error): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\A' . preg_quote($error, '/') . '\z/');
        RecurrenceSchedule::dates($date, $count, $stepMonths, $endOfMonth);
    }
}
