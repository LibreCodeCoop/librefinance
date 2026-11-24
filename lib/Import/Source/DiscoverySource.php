<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Source;

/**
 * Read-only adapter contract. An authorized caller supplies the adapter;
 * the collector never opens a network connection or accepts credentials.
 * The adapter must bind every page to the same source snapshot/cut-off and
 * independently enforce source scope, timeouts and the requested page size.
 */
interface DiscoverySource {
    /**
     * @return array{sourceSystem:string,sourceVersion:string,cutoff:string,snapshotId:string,
     *     resources:array<string,string>,features:array<string,bool>}
     * Resource capabilities: supported, unsupported, missing_capability, excluded.
     */
    public function describe(): array;

    public function workspaces(string $snapshotId, ?string $cursor, int $limit): DiscoveryPage;

    public function records(string $snapshotId, string $workspaceId, string $resource,
        ?string $cursor, int $limit): DiscoveryPage;
}
