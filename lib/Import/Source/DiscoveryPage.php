<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Source;

/**
 * Only opaque IDs, typed relationship references and optional artifact digests;
 * do not transport record bodies, names, credentials or financial values.
 */
final readonly class DiscoveryPage {
    /**
     * @param list<array{id:string,references?:list<array{resource:string,id:string}>,artifactSha256?:string}> $records
     * status: ok, access_denied, timeout, unsupported, missing_capability, truncated, unavailable.
     * For ok, a null nextCursor explicitly terminates a complete collection.
     * reportedTotal is an optional source-declared count for that snapshot.
     */
    public function __construct(
        public string $status,
        public string $snapshotId,
        public array $records = [],
        public ?string $nextCursor = null,
        public ?int $reportedTotal = null,
    ) {}
}
