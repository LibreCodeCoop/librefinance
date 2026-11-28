<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Banking;

use DomainException;

/** Preserve the strict import error contract while identifying a logical data row. */
final class StatementParseException extends DomainException {
    public function __construct(DomainException $cause, public readonly int $row) {
        parent::__construct($cause->getMessage(), previous: $cause);
    }
}
