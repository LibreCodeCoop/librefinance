<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Invoicing;

use DomainException;
use OCA\LibreFinance\Domain\Invoicing\TaxCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase {

    #[DataProvider('validCalculationsProvider')]
    public function testCalculate(int $quantity, int $unitPriceMinor, ?array $rule, array $expected): void {
        $result = TaxCalculator::calculate($quantity, $unitPriceMinor, $rule);
        self::assertSame($expected, $result);
    }

    public static function validCalculationsProvider(): array {
        return [
            'no_rule' => [
                2,
                1000,
                null,
                ['baseMinor' => 2000, 'taxMinor' => 0, 'withheldMinor' => 0, 'totalMinor' => 2000]
            ],
            'zero_price' => [
                5,
                0,
                ['rate_bps' => 2000, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 0, 'taxMinor' => 0, 'withheldMinor' => 0, 'totalMinor' => 0]
            ],
            'zero_rate' => [
                3,
                500,
                ['rate_bps' => 0, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 1500, 'taxMinor' => 0, 'withheldMinor' => 0, 'totalMinor' => 1500]
            ],
            'exclusive_vat' => [
                2,
                10000,
                ['rate_bps' => 2000, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 20000, 'taxMinor' => 4000, 'withheldMinor' => 0, 'totalMinor' => 24000]
            ],
            'inclusive_vat' => [
                1,
                12000,
                ['rate_bps' => 2000, 'kind' => 'vat', 'inclusive' => true],
                ['baseMinor' => 10000, 'taxMinor' => 2000, 'withheldMinor' => 0, 'totalMinor' => 12000]
            ],
            'withholding' => [
                1,
                10000,
                ['rate_bps' => 1000, 'kind' => 'withholding', 'inclusive' => false],
                ['baseMinor' => 10000, 'taxMinor' => 0, 'withheldMinor' => 1000, 'totalMinor' => 9000]
            ],
            'half_cent_rounding_up' => [
                1,
                125,
                ['rate_bps' => 2000, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 125, 'taxMinor' => 25, 'withheldMinor' => 0, 'totalMinor' => 150]
            ],
            'half_cent_rounding_down' => [
                1,
                115,
                ['rate_bps' => 2000, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 115, 'taxMinor' => 23, 'withheldMinor' => 0, 'totalMinor' => 138]
            ],
            'rounding_up_5000_bps' => [
                1,
                1,
                ['rate_bps' => 5000, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 1, 'taxMinor' => 1, 'withheldMinor' => 0, 'totalMinor' => 2]
            ],
            'rounding_down_4999_bps' => [
                1,
                1,
                ['rate_bps' => 4999, 'kind' => 'vat', 'inclusive' => false],
                ['baseMinor' => 1, 'taxMinor' => 0, 'withheldMinor' => 0, 'totalMinor' => 1]
            ],
            'valid_boundary_max_minor' => [
                1,
                TaxCalculator::MAX_MINOR,
                null,
                ['baseMinor' => TaxCalculator::MAX_MINOR, 'taxMinor' => 0, 'withheldMinor' => 0, 'totalMinor' => TaxCalculator::MAX_MINOR]
            ],
            'valid_boundary_max_quantity' => [
                100000,
                90000000,
                null,
                ['baseMinor' => 9000000000000, 'taxMinor' => 0, 'withheldMinor' => 0, 'totalMinor' => 9000000000000]
            ]
        ];
    }

    #[DataProvider('invalidLineAmountProvider')]
    public function testCalculateInvalidLineAmount(int $quantity, int $unitPriceMinor, ?array $rule): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/^invalid_line_amount$/');
        TaxCalculator::calculate($quantity, $unitPriceMinor, $rule);
    }

    public static function invalidLineAmountProvider(): array {
        return [
            'quantity_too_low' => [0, 100, null],
            'quantity_too_high' => [100001, 100, null],
            'unit_price_negative' => [1, -1, null],
            'unit_price_too_high' => [1, TaxCalculator::MAX_MINOR + 1, null],
            'gross_overflow' => [2, TaxCalculator::MAX_MINOR, null],
        ];
    }

    #[DataProvider('invalidTaxRuleProvider')]
    public function testCalculateInvalidTaxRule(int $quantity, int $unitPriceMinor, ?array $rule): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/^invalid_tax_rule$/');
        TaxCalculator::calculate($quantity, $unitPriceMinor, $rule);
    }

    public static function invalidTaxRuleProvider(): array {
        return [
            'rate_negative' => [1, 100, ['rate_bps' => -1, 'kind' => 'vat', 'inclusive' => false]],
            'rate_too_high' => [1, 100, ['rate_bps' => 100001, 'kind' => 'vat', 'inclusive' => false]],
            'invalid_kind' => [1, 100, ['rate_bps' => 1000, 'kind' => 'unknown', 'inclusive' => false]],
            'inclusive_withholding' => [1, 100, ['rate_bps' => 1000, 'kind' => 'withholding', 'inclusive' => true]],
        ];
    }

    #[DataProvider('invalidInvoiceTotalProvider')]
    public function testCalculateInvalidInvoiceTotal(int $quantity, int $unitPriceMinor, ?array $rule): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/^invalid_invoice_total$/');
        TaxCalculator::calculate($quantity, $unitPriceMinor, $rule);
    }

    public static function invalidInvoiceTotalProvider(): array {
        return [
            'total_overflow' => [
                1,
                TaxCalculator::MAX_MINOR,
                ['rate_bps' => 10000, 'kind' => 'vat', 'inclusive' => false]
            ],
            'withheld_exceeding_base' => [
                1,
                100,
                ['rate_bps' => 20000, 'kind' => 'withholding', 'inclusive' => false]
            ],
        ];
    }
}
