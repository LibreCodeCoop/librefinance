<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Domain\Banking\Providers;
interface BankProviderInterface {
    /** Must equal a currently enabled Nextcloud application that owns this implementation. */
    public function appId():string;
    /** Stable bank/product identifier. */
    public function id():string;
    public function name():string;
    /**
     * Read-only, bounded and explicitly authorized by a scheduled worker.
     * Secrets resolved by a vault within provider transport, never in this context.
     * No HTTP route may call this method directly.
     * @return iterable<BankStatementEntry>
     */
    public function fetch(BankConnectionContext $context,
        \DateTimeImmutable $from,\DateTimeImmutable $through):iterable;
}
