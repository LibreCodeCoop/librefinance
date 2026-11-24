<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Currency;

use DomainException;

final readonly class RateQuote {
    public int $sourcePrecision;
    public int $targetPrecision;
    public string $rate;

    public function __construct(
        public int $spaceId,
        public string $sourceCurrency,
        public string $targetCurrency,
        string $rate,
        public string $effectiveOn,
        public string $sourceType,
        public string $sourceRef,
    ) {
        if ($spaceId < 1 || $sourceCurrency === $targetCurrency) {
            throw new DomainException('invalid_exchange_pair');
        }
        $this->sourcePrecision = CurrencyMetadata::precision($sourceCurrency);
        $this->targetPrecision = CurrencyMetadata::precision($targetCurrency);
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $effectiveOn, $date) !== 1
            || !checkdate((int)$date[2], (int)$date[3], (int)$date[1])) {
            throw new DomainException('invalid_date');
        }
        if (!in_array($sourceType, ['manual', 'imported'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,95}$/D', $sourceRef) !== 1) {
            throw new DomainException('invalid_rate_source');
        }
        ExactConversion::convert(0, $this->sourcePrecision, $this->targetPrecision, $rate);
        $this->rate = str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }

    public function convert(int $sourceMinor): int {
        return ExactConversion::convert($sourceMinor, $this->sourcePrecision, $this->targetPrecision, $this->rate);
    }

    /** Stable, explicit tuple; canonical equivalent rates replay the same fact. */
    public function fingerprint(): string {
        return hash('sha256', json_encode([
            CurrencyMetadata::VERSION, $this->spaceId,
            $this->sourceCurrency, $this->targetCurrency,
            $this->sourcePrecision, $this->targetPrecision, $this->rate,
            $this->effectiveOn, $this->sourceType, $this->sourceRef,
        ], JSON_THROW_ON_ERROR));
    }
}
