<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Currency;

use DomainException;

/**
 * Explicit lookup context, not a selected quote or posting authorization.
 */
final readonly class RateSelection {
    private function __construct(
        public string $sourceCurrency,
        public string $targetCurrency,
        public string $asOf,
        public ?string $sourceType,
    ) {}

    public static function fromQuery(?string $sourceCurrency, ?string $targetCurrency,
        ?string $asOf, ?string $sourceType): ?self {
        if ($sourceCurrency === null && $targetCurrency === null && $asOf === null && $sourceType === null) {
            return null;
        }
        $source = strtoupper(trim($sourceCurrency ?? ''));
        $target = strtoupper(trim($targetCurrency ?? ''));
        if ($source === '' || $target === '' || $source === $target) {
            throw new DomainException('invalid_exchange_pair');
        }
        CurrencyMetadata::precision($source);
        CurrencyMetadata::precision($target);
        if ($asOf === null
            || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $asOf, $date) !== 1
            || !checkdate((int)$date[2], (int)$date[3], (int)$date[1])) {
            throw new DomainException('invalid_date');
        }
        if ($sourceType !== null && !in_array($sourceType, ['manual', 'imported'], true)) {
            throw new DomainException('invalid_rate_source');
        }
        return new self($source, $target, $asOf, $sourceType);
    }

    /** @return array{sourceCurrency:string,targetCurrency:string,asOf:string,sourceType:?string} */
    public function toArray(): array {
        return [
            'sourceCurrency' => $this->sourceCurrency,
            'targetCurrency' => $this->targetCurrency,
            'asOf' => $this->asOf,
            'sourceType' => $this->sourceType,
        ];
    }
}
