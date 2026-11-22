<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Banking\Providers;
use InvalidArgumentException;
final readonly class BankStatementEntry {
    public function __construct(public string $externalId,public string $date,
        public int $amountMinor,public string $description) {
        if(!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/',$externalId) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) ||
            ($checked=\DateTimeImmutable::createFromFormat('!Y-m-d',$date))===false ||
            $checked->format('Y-m-d')!==$date ||
            $amountMinor===0 || abs($amountMinor)>9000000000000 ||
            trim($description)==='' || mb_strlen($description)>255) {
            throw new InvalidArgumentException('invalid_bank_statement_entry');
        }
    }
}
