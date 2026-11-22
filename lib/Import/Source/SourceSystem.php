<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Source;

/**
 * Stable source namespace, not proof that imported data came from that source.
 * The 24-byte bound matches lfm_records.source_system on every supported DB.
 */
final class SourceSystem {
    public static function valid(mixed $source): bool {
        return is_string($source)
            && preg_match('/^[a-z][a-z0-9_-]{2,23}$/D', $source) === 1;
    }
}
