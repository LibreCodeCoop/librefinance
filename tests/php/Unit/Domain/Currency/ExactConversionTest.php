<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Currency;

use DomainException;
use OCA\LibreFinance\Domain\Currency\ExactConversion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExactConversionTest extends TestCase {
    public static function validConversions(): array {
        return [
            'unchanged BRL' => [12345, 2, 2, '1', 12345],
            'contract JPY receipt' => [5000, 0, 2, '0.0345', 17250],
            'contract JPY receivable recognition' => [10000, 0, 2, '0.0345', 34500],
            'contract JPY partial collection' => [4000, 0, 2, '0.035', 14000],
            'contract remaining JPY revaluation' => [6000, 0, 2, '0.036', 21600],
            'contract KWD carrying value' => [12345, 3, 2, '15.80', 19505],
            'contract KWD transfer gross' => [12345, 3, 2, '16.00', 19752],
            'contract functional JPY half' => [12345, 2, 0, '10', 1235],
            'contract functional KWD' => [123, 2, 3, '0.0605', 74],
            'contract KWD minor half' => [1, 3, 2, '5', 1],
            'contract negative KWD minor half' => [-1, 3, 2, '5', -1],
            'zero precision to two' => [100, 0, 2, '0.025', 250],
            'two to three' => [123, 2, 3, '1', 1230],
            'three to zero below half' => [499, 3, 0, '1', 0],
            'three to zero half' => [500, 3, 0, '1', 1],
            'negative half' => [-500, 3, 0, '1', -1],
            'negative below half' => [-499, 3, 0, '1', 0],
            'round carry' => [999, 3, 2, '1', 100],
            'half with fractional rate' => [1, 2, 2, '0.5', 1],
            'negative half rate' => [-1, 2, 2, '0.5', -1],
            'zero with valid rate' => [0, 2, 3, '0.000000000001', 0],
            'large intermediate product' => [9000000000000, 2, 2, '0.999999999999', 8999999999991],
            'exact maximum' => [9000000000000, 2, 2, '1.000000000000', 9000000000000],
            'tiny rate rounded' => [9000000000000, 0, 0, '0.000000000001', 9],
            'negative exact maximum' => [-9000000000000, 3, 3, '1', -9000000000000],
        ];
    }

    #[DataProvider('validConversions')]
    public function testExactConversion(int $amount, int $from, int $to, string $rate, int $expected): void {
        self::assertSame($expected, ExactConversion::convert($amount, $from, $to, $rate));
    }

    public static function invalidConversions(): array {
        return [
            'source above bound' => [9000000000001, 2, 2, '1', 'invalid_currency_amount'],
            'minimum PHP integer' => [PHP_INT_MIN, 2, 2, '1', 'invalid_currency_amount'],
            'negative precision' => [1, -1, 2, '1', 'invalid_currency_amount'],
            'unsupported precision' => [1, 2, 4, '1', 'invalid_currency_amount'],
            'zero rate' => [0, 2, 2, '0.000', 'invalid_exchange_rate'],
            'negative rate' => [1, 2, 2, '-1', 'invalid_exchange_rate'],
            'scientific rate' => [1, 2, 2, '1e2', 'invalid_exchange_rate'],
            'locale comma' => [1, 2, 2, '1,2', 'invalid_exchange_rate'],
            'leading zero' => [1, 2, 2, '01', 'invalid_exchange_rate'],
            'whitespace' => [1, 2, 2, '1 ', 'invalid_exchange_rate'],
            'too many decimals' => [1, 2, 2, '1.0000000000001', 'invalid_exchange_rate'],
            'too many whole digits' => [1, 2, 2, '1000000000000', 'invalid_exchange_rate'],
            'positive overflow' => [9000000000000, 2, 2, '1.000000000001', 'currency_amount_overflow'],
            'negative overflow' => [-9000000000000, 2, 2, '2', 'currency_amount_overflow'],
            'precision overflow' => [9000000000000, 0, 3, '1', 'currency_amount_overflow'],
        ];
    }

    #[DataProvider('invalidConversions')]
    public function testInvalidConversion(int $amount, int $from, int $to, string $rate, string $error): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/\A' . preg_quote($error, '/') . '\z/');
        ExactConversion::convert($amount, $from, $to, $rate);
    }
}
