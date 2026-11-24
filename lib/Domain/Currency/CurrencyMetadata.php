<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Currency;

use DomainException;

/**
 * Versioned, deliberately bounded currency metadata for rate snapshots.
 * Adding a currency or changing its scale requires a reviewed contract;
 * existing snapshots retain their stored precision. This does not activate
 * foreign-currency ledgers or pretend to be the complete ISO currency list.
 */
final class CurrencyMetadata {
    public const VERSION = 1;

    private const PRECISIONS = [
        'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CHF' => 2,
        'CLP' => 0, 'CNY' => 2, 'EUR' => 2, 'GBP' => 2,
        'JPY' => 0, 'KWD' => 3, 'USD' => 2,
    ];

    /** @return array<string,int> */
    public static function supported(): array {
        return self::PRECISIONS;
    }

    public static function precision(string $currency): int {
        return self::PRECISIONS[$currency] ?? throw new DomainException('unsupported_currency');
    }
}
