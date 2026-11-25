<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Currency;

use DomainException;
use OCA\LibreFinance\Domain\Currency\CurrencyMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyMetadataTest extends TestCase {

    public function testVersion(): void {
        $this->assertSame(1, CurrencyMetadata::VERSION);
    }

    public static function supportedCurrenciesProvider(): array {
        return [
            'AUD' => ['AUD', 2],
            'BRL' => ['BRL', 2],
            'CAD' => ['CAD', 2],
            'CHF' => ['CHF', 2],
            'CLP' => ['CLP', 0],
            'CNY' => ['CNY', 2],
            'EUR' => ['EUR', 2],
            'GBP' => ['GBP', 2],
            'JPY' => ['JPY', 0],
            'KWD' => ['KWD', 3],
            'USD' => ['USD', 2],
        ];
    }

    #[DataProvider('supportedCurrenciesProvider')]
    public function testPrecisionForSupportedCurrencies(string $currency, int $expectedScale): void {
        $this->assertSame($expectedScale, CurrencyMetadata::precision($currency));
    }

    public function testSupportedReturnsExpectedMapping(): void {
        $expected = [
            'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CHF' => 2,
            'CLP' => 0, 'CNY' => 2, 'EUR' => 2, 'GBP' => 2,
            'JPY' => 0, 'KWD' => 3, 'USD' => 2,
        ];
        $this->assertSame($expected, CurrencyMetadata::supported());
    }

    public static function unsupportedCurrenciesProvider(): array {
        return [
            'lowercase_usd' => ['usd'],
            'lowercase_eur' => ['eur'],
            'with_whitespace' => [' USD '],
            'leading_whitespace' => [' USD'],
            'trailing_whitespace' => ['USD '],
            'unknown_code' => ['XYZ'],
            'empty_string' => [''],
            'malformed_code' => ['US1'],
        ];
    }

    #[DataProvider('unsupportedCurrenciesProvider')]
    public function testPrecisionRejectsUnknownLowercaseWhitespaceAndMalformedCodes(string $currency): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/^unsupported_currency$/');
        CurrencyMetadata::precision($currency);
    }
}
