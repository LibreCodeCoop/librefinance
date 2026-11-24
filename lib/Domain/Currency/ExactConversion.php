<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Currency;

use DomainException;

/**
 * Exact, bounded arithmetic for a candidate currency conversion.
 *
 * Rate is target major units per source major unit. Precision is explicit,
 * not inferred from locale. Round once, to nearest target minor unit with
 * ties away from zero. This is a pure arithmetic primitive, not an FX-rate
 * source, rate-history store, ledger mutation or accounting policy.
 */
final class ExactConversion {
    private const MAX_MINOR = 9000000000000;

    public static function convert(int $sourceMinor, int $sourcePrecision, int $targetPrecision, string $rate): int {
        if ($sourceMinor < -self::MAX_MINOR || $sourceMinor > self::MAX_MINOR
            || $sourcePrecision < 0 || $sourcePrecision > 3
            || $targetPrecision < 0 || $targetPrecision > 3) {
            throw new DomainException('invalid_currency_amount');
        }
        // Bound input before arithmetic; no exponent, sign, locale separator,
        // whitespace or leading zeroes in the integral part.
        if (strlen($rate) > 25 || !preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,12}))?$/D', $rate, $parts)) {
            throw new DomainException('invalid_exchange_rate');
        }
        $fraction = $parts[2] ?? '';
        $digits = ltrim($parts[1] . $fraction, '0');
        if ($digits === '') {
            throw new DomainException('invalid_exchange_rate');
        }
        if ($sourceMinor === 0) {
            return 0;
        }

        // The intermediate product may exceed PHP_INT_MAX even when the
        // rounded result is within the existing ledger's minor-unit bound.
        $product = self::multiply((string)abs($sourceMinor), $digits);
        $shift = $targetPrecision - $sourcePrecision - strlen($fraction);
        if ($shift >= 0) {
            $rounded = $product . str_repeat('0', $shift);
        } else {
            $places = -$shift;
            $padded = str_pad($product, $places + 1, '0', STR_PAD_LEFT);
            $rounded = substr($padded, 0, -$places);
            if (ord($padded[strlen($padded) - $places]) >= ord('5')) {
                $rounded = self::increment($rounded);
            }
        }
        $rounded = ltrim($rounded, '0');
        if ($rounded === '') {
            return 0;
        }
        $limit = (string)self::MAX_MINOR;
        if (strlen($rounded) > strlen($limit)
            || (strlen($rounded) === strlen($limit) && strcmp($rounded, $limit) > 0)) {
            throw new DomainException('currency_amount_overflow');
        }
        $result = (int)$rounded;
        return $sourceMinor < 0 ? -$result : $result;
    }

    /** Multiply two bounded unsigned integer strings using single-digit products. */
    private static function multiply(string $a, string $b): string {
        $n = strlen($a);
        $m = strlen($b);
        $out = array_fill(0, $n + $m, 0);
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $position = $i + $j + 1;
                $sum = (ord($a[$i]) - 48) * (ord($b[$j]) - 48) + $out[$position];
                $out[$position] = $sum % 10;
                $out[$position - 1] += intdiv($sum, 10);
            }
        }
        return ltrim(implode('', $out), '0') ?: '0';
    }

    private static function increment(string $digits): string {
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            if ($digits[$i] !== '9') {
                $digits[$i] = chr(ord($digits[$i]) + 1);
                return $digits;
            }
            $digits[$i] = '0';
        }
        return '1' . $digits;
    }
}
