<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Source;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded inventory of the adapter's accessible snapshot, not origin
 * attestation, financial equivalence, or proof of inaccessible resources.
 */
final class DiscoveryInventory {
    private const RESOURCES = [
        'companies', 'currencies', 'taxes', 'accounts', 'bank_accounts', 'categories', 'items', 'contacts',
        'invoices', 'invoice_lines', 'bills', 'bill_lines', 'settlements',
        'transactions', 'transfers', 'reconciliations', 'payments', 'journal_entries', 'journal_lines',
        'employees', 'departments', 'funding_sources', 'budget_lines', 'budget_revisions',
        'dimensions', 'commitments', 'funding_allocations',
        'reports', 'dashboard_settings', 'custom_fields', 'attachments', 'fiscal_artifacts',
    ];
    private const FAILURES = [
        'access_denied', 'timeout', 'unsupported', 'missing_capability',
        'truncated', 'unavailable',
    ];
    private int $remainingPages;
    private int $remainingRecords;
    private int $remainingReferences;

    public function __construct(
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 5000,
        private readonly int $maxRecords = 20000,
    ) {
        if ($pageSize < 1 || $pageSize > 250 || $maxPages < 1 || $maxPages > 5000
            || $maxRecords < 1 || $maxRecords > 20000) {
            throw new InvalidArgumentException('Invalid discovery limits');
        }
    }

    /** @return array<string,mixed> */
    public function collect(DiscoverySource $source): array {
        $this->remainingPages = $this->maxPages;
        $this->remainingRecords = $this->maxRecords;
        $this->remainingReferences = 40000;
        try {
            $meta = $source->describe();
        } catch (\Throwable) {
            throw new RuntimeException('Source discovery metadata unavailable');
        }
        $this->validateMetadata($meta);
        $snapshot = $meta['snapshotId'];
        $capabilities = $meta['resources'];
        foreach (self::RESOURCES as $resource) {
            $capabilities[$resource] ??= 'missing_capability';
        }
        ksort($capabilities, SORT_STRING);
        $features = $meta['features'];
        ksort($features, SORT_STRING);

        [$workspaceResult, $workspaceRecords] = $this->walk($snapshot,
            fn(?string $cursor, int $limit): DiscoveryPage => $source->workspaces($snapshot, $cursor, $limit),
            100, true);
        $complete = $this->isComplete($workspaceResult);
        $inventory = [];
        foreach ($workspaceRecords as $workspace) {
            $workspaceId = $workspace['id'];
            $results = [];
            $records = [];
            foreach ($capabilities as $resource => $capability) {
                if ($capability !== 'supported') {
                    $result = $this->summary($capability, 0, null, null);
                    $rows = [];
                } else {
                    [$result, $rows] = $this->walk($snapshot,
                        fn(?string $cursor, int $limit): DiscoveryPage =>
                            $source->records($snapshot, $workspaceId, $resource, $cursor, $limit));
                }
                $result['mappingRequired'] = !in_array($resource, self::RESOURCES, true);
                $results[$resource] = $result;
                $records[$resource] = $rows;
                $complete = $complete && $this->isComplete($result);
            }
            // Verify child/parent references only against complete collections.
            // Incomplete or inaccessible collections cannot prove absence.
            foreach ($records as $resource => $rows) {
                $referenceCount = 0;
                $artifactCount = 0;
                $unresolved = 0;
                $unverified = 0;
                foreach ($rows as $row) {
                    $artifactCount += (int)$row['hasArtifactDigest'];
                    foreach ($row['references'] as $reference) {
                        ++$referenceCount;
                        $target = $reference['resource'];
                        if (!isset($results[$target]) || !$this->isComplete($results[$target])) {
                            ++$unverified;
                        } elseif (!isset($records[$target]['id:' . $reference['id']])) {
                            ++$unresolved;
                        }
                    }
                }
                $results[$resource]['referenceCount'] = $referenceCount;
                $results[$resource]['artifactDigestCount'] = $artifactCount;
                $results[$resource]['unresolvedReferences'] = $unresolved;
                $results[$resource]['unverifiedReferences'] = $unverified;
                $complete = $complete && $unresolved === 0 && $unverified === 0;
            }
            $inventory[] = ['workspaceId' => $workspaceId, 'resources' => $results];
        }

        return [
            'format' => 'librefinance.discovery.v1',
            'sourceSystem' => $meta['sourceSystem'],
            'sourceVersion' => $meta['sourceVersion'],
            'cutoff' => $meta['cutoff'],
            'scope' => 'accessible_workspaces_only',
            'sourceAttested' => false,
            'complete' => $complete,
            'features' => $features,
            'capabilities' => $capabilities,
            'workspaces' => $workspaceResult,
            'inventory' => $inventory,
        ];
    }

    /** @param array<string,mixed> $meta */
    private function validateMetadata(array $meta): void {
        $date = $meta['cutoff'] ?? null;
        $parsed = is_string($date) && strlen($date) === 20
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, new DateTimeZone('UTC')) : false;
        if (!SourceSystem::valid($meta['sourceSystem'] ?? null)
            || !is_string($meta['sourceVersion'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/D', $meta['sourceVersion']) !== 1
            || $parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $date
            || !is_string($meta['snapshotId'] ?? null)
            || $meta['snapshotId'] === '' || strlen($meta['snapshotId']) > 512
            || !is_array($meta['resources'] ?? null) || count($meta['resources']) > 100
            || !is_array($meta['features'] ?? null) || count($meta['features']) > 100) {
            throw new RuntimeException('Invalid source discovery metadata');
        }
        foreach ($meta['resources'] as $resource => $status) {
            if (!$this->validResource($resource) || !in_array($status,
                ['supported', 'unsupported', 'missing_capability', 'excluded'], true)) {
                throw new RuntimeException('Invalid source discovery capability');
            }
        }
        foreach ($meta['features'] as $feature => $enabled) {
            if (!$this->validResource($feature) || !is_bool($enabled)) {
                throw new RuntimeException('Invalid source discovery feature');
            }
        }
    }

    private function validResource(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1;
    }

    private function validId(mixed $value): bool {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value) === 1;
    }

    /**
     * @param callable(?string,int): DiscoveryPage $fetch
     * @return array{array<string,mixed>,array<string,array{id:string,references:list<array{resource:string,id:string}>,hasArtifactDigest:bool,digest:string}>}
     */
    private function walk(string $snapshot, callable $fetch, int $collectionLimit = 20000,
        bool $workspaceList = false): array {
        $cursor = null;
        $seenCursors = [];
        $rows = [];
        $total = null;
        $status = 'truncated';
        while ($this->remainingPages > 0 && $this->remainingRecords > 0 && count($rows) < $collectionLimit) {
            --$this->remainingPages;
            $limit = min($this->pageSize, $this->remainingRecords, $collectionLimit - count($rows));
            try {
                $page = $fetch($cursor, $limit);
            } catch (\Throwable) {
                // Never echo a transport exception, cursor or credential.
                $status = 'unavailable';
                break;
            }
            if ($page->status !== 'ok') {
                $status = in_array($page->status, self::FAILURES, true)
                    ? $page->status : 'invalid_response';
                break;
            }
            if ($page->snapshotId !== $snapshot) {
                $status = 'snapshot_changed';
                break;
            }
            if (!array_is_list($page->records) || count($page->records) > $limit
                || ($page->reportedTotal !== null && ($page->reportedTotal < 0
                    || ($total !== null && $total !== $page->reportedTotal)))) {
                $status = 'invalid_response';
                break;
            }
            $total ??= $page->reportedTotal;
            foreach ($page->records as $record) {
                $row = $this->normalizeRecord($record, $workspaceList);
                if ($row === null || isset($rows['id:' . $row['id']])) {
                    $status = 'invalid_response';
                    break 2;
                }
                $referenceCount = count($row['references']);
                if ($referenceCount > $this->remainingReferences) {
                    $status = 'truncated';
                    break 2;
                }
                $rows['id:' . $row['id']] = $row;
                --$this->remainingRecords;
                $this->remainingReferences -= $referenceCount;
            }
            if ($total !== null && count($rows) > $total) {
                $status = 'invalid_response';
                break;
            }
            if ($page->nextCursor === null) {
                $status = $total !== null && count($rows) !== $total
                    ? 'truncated' : ($rows === [] ? 'empty' : 'complete');
                break;
            }
            if ($page->records === [] || $page->nextCursor === '' || strlen($page->nextCursor) > 512
                || isset($seenCursors['cursor:' . $page->nextCursor])) {
                $status = 'truncated';
                break;
            }
            $cursor = $page->nextCursor;
            $seenCursors['cursor:' . $cursor] = true;
        }
        ksort($rows, SORT_STRING);
        $digest = null;
        if (in_array($status, ['complete', 'empty'], true)) {
            $hash = hash_init('sha256');
            foreach ($rows as $row) {
                hash_update($hash, $row['digest']);
            }
            $digest = hash_final($hash);
        }
        return [$this->summary($status, count($rows), $total, $digest), $rows];
    }

    /** @return ?array{id:string,references:list<array{resource:string,id:string}>,hasArtifactDigest:bool,digest:string} */
    private function normalizeRecord(mixed $record, bool $workspaceList): ?array {
        if (!is_array($record) || !$this->validId($record['id'] ?? null)
            || array_diff(array_keys($record), ['id', 'references', 'artifactSha256']) !== []) {
            return null;
        }
        $refs = $record['references'] ?? [];
        $artifact = $record['artifactSha256'] ?? null;
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 16
            || ($artifact !== null && (!is_string($artifact) || preg_match('/^[a-f0-9]{64}$/D', $artifact) !== 1))
            || ($workspaceList && ($refs !== [] || $artifact !== null))) {
            return null;
        }
        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_array($ref) || count($ref) !== 2 || !$this->validResource($ref['resource'] ?? null)
                || !$this->validId($ref['id'] ?? null)) {
                return null;
            }
            $key = $ref['resource'] . '/' . $ref['id'];
            if (isset($normalized[$key])) {
                return null;
            }
            $normalized[$key] = ['resource' => $ref['resource'], 'id' => $ref['id']];
        }
        ksort($normalized, SORT_STRING);
        $references = array_values($normalized);
        return ['id' => $record['id'], 'references' => $references, 'hasArtifactDigest' => $artifact !== null,
            'digest' => hash('sha256', json_encode([$record['id'], $references, $artifact], JSON_THROW_ON_ERROR))];
    }

    /** @return array<string,mixed> */
    private function summary(string $status, int $observed, ?int $total, ?string $digest): array {
        return ['status' => $status, 'count' => in_array($status, ['complete', 'empty'], true) ? $observed : null,
            'observed' => $observed, 'reportedTotal' => $total, 'sha256' => $digest];
    }

    /** @param array<string,mixed> $summary */
    private function isComplete(array $summary): bool {
        return in_array($summary['status'], ['complete', 'empty'], true);
    }
}
