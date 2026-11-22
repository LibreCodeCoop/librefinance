<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Banking\Providers;
use InvalidArgumentException;
final readonly class BankConnectionContext {
    public function __construct(public int $spaceId,public int $bankAccountId,
        public string $providerId,public string $currency,
        public string $credentialReference) {
        if($spaceId<1 || $bankAccountId<1 ||
            !preg_match('/^[a-z][a-z0-9_]{1,40}$/',$providerId) ||
            !preg_match('/^[A-Z]{3}$/',$currency) ||
            $credentialReference!=="{$providerId}/space-{$spaceId}/account-{$bankAccountId}") {
            throw new InvalidArgumentException('invalid_bank_connection_context');
        }
    }
}
