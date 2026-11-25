<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit;

use DomainException;
use OCA\LibreFinance\Domain\Currency\RateQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateQuoteTest extends TestCase {
    private const QUOTE = [1, 'JPY', 'KWD', '0.002000', '2026-11-24', 'manual', 'synthetic-a'];

    public function testExactConversionAndCanonicalRate(): void {
        $quote = new RateQuote(...self::QUOTE);

        self::assertSame(0, $quote->sourcePrecision);
        self::assertSame(3, $quote->targetPrecision);
        self::assertSame('0.002', $quote->rate);
        self::assertSame(2000, $quote->convert(1000));
        self::assertSame(-2000, $quote->convert(-1000));
    }

    public function testEquivalentDecimalFingerprint(): void {
        $quote = new RateQuote(...self::QUOTE);
        $copy = new RateQuote(...array_replace(self::QUOTE, [3 => '0.002']));

        self::assertSame($quote->fingerprint(), $copy->fingerprint());
    }

    public static function fingerprintChanges(): array {
        return [
            'workspace' => [0, 2],
            'rate' => [3, '0.003'],
            'date' => [4, '2026-11-25'],
            'source' => [5, 'imported'],
            'reference' => [6, 'synthetic-b'],
        ];
    }

    #[DataProvider('fingerprintChanges')]
    public function testFingerprintDistinguishesFields(int $index, int|string $value): void {
        $quote = new RateQuote(...self::QUOTE);
        $other = new RateQuote(...array_replace(self::QUOTE, [$index => $value]));

        self::assertNotSame($quote->fingerprint(), $other->fingerprint());
    }

    public static function invalidQuoteFields(): array {
        return [
            'zero workspace' => [0, 0],
            'lowercase currency' => [1, 'jpy'],
            'unknown currency' => [1, 'ZZZ'],
            'identical currencies' => [2, 'JPY'],
            'zero rate' => [3, '0'],
            'scientific notation' => [3, '1e-3'],
            'invalid date' => [4, '2026-02-30'],
            'zero year' => [4, '0000-01-01'],
            'unknown source' => [5, 'online'],
            'empty reference' => [6, ''],
            'URL reference' => [6, 'https://example.invalid'],
            'long reference' => [6, str_repeat('x', 97)],
        ];
    }

    #[DataProvider('invalidQuoteFields')]
    public function testRejectsInvalidFields(int $index, int|string $value): void {
        $this->expectException(DomainException::class);
        new RateQuote(...array_replace(self::QUOTE, [$index => $value]));
    }
}
