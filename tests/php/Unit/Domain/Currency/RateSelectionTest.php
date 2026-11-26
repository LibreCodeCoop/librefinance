<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Currency;

use DomainException;
use OCA\LibreFinance\Domain\Currency\RateSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateSelectionTest extends TestCase {
    public function testNoSelectionPreservesHistoricalRead(): void {
        self::assertNull(RateSelection::fromQuery(null, null, null, null));
    }

    public function testNormalizedPairAndExplicitSource(): void {
        $selection = RateSelection::fromQuery(' jpy ', 'kwd', '2028-02-29', 'manual');
        self::assertSame([
            'sourceCurrency' => 'JPY', 'targetCurrency' => 'KWD',
            'asOf' => '2028-02-29', 'sourceType' => 'manual',
        ], $selection?->toArray());
    }

    public function testSourceFilterIsOptional(): void {
        self::assertSame([
            'sourceCurrency' => 'BRL', 'targetCurrency' => 'USD',
            'asOf' => '0001-01-01', 'sourceType' => null,
        ], RateSelection::fromQuery('BRL', 'USD', '0001-01-01', null)?->toArray());
    }

    public static function invalidSelections(): array {
        return [
            'partial pair' => ['JPY', null, '2026-11-24', null, 'invalid_exchange_pair'],
            'source only' => [null, null, null, 'manual', 'invalid_exchange_pair'],
            'empty source' => ['', 'KWD', '2026-11-24', null, 'invalid_exchange_pair'],
            'same normalized pair' => ['jpy', ' JPY ', '2026-11-24', null, 'invalid_exchange_pair'],
            'unsupported source' => ['XYZ', 'KWD', '2026-11-24', null, 'unsupported_currency'],
            'unsupported target' => ['JPY', 'XYZ', '2026-11-24', null, 'unsupported_currency'],
            'missing cutoff' => ['JPY', 'KWD', null, null, 'invalid_date'],
            'noncanonical cutoff' => ['JPY', 'KWD', '2026-2-01', null, 'invalid_date'],
            'future invalid leap day' => ['JPY', 'KWD', '2027-02-29', null, 'invalid_date'],
            'zero year' => ['JPY', 'KWD', '0000-01-01', null, 'invalid_date'],
            'date with trailing space' => ['JPY', 'KWD', '2026-11-24 ', null, 'invalid_date'],
            'unknown origin' => ['JPY', 'KWD', '2026-11-24', 'automatic', 'invalid_rate_source'],
            'empty origin' => ['JPY', 'KWD', '2026-11-24', '', 'invalid_rate_source'],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function testRejectsInvalidSelection(?string $source, ?string $target,
        ?string $asOf, ?string $sourceType, string $error): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/^' . $error . '$/');
        RateSelection::fromQuery($source, $target, $asOf, $sourceType);
    }
}
