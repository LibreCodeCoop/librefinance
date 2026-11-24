<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Interchange;

use DomainException;

/**
 * Offline transport operation only. Repartition validated, complete source
 * records without applying destination mappings or modifying source facts.
 */
final class BundleRepacker {
    public function __construct(private readonly BundleValidator $validator = new BundleValidator()) {
    }

    /**
     * @param array<string,string> $chunks
     * @param array<string,string> $artifacts
     * @return array{manifest:string,chunks:array<string,string>,artifacts:array<string,string>}
     */
    public function repack(string $manifestBytes, array $chunks, array $artifacts,
        int $recordsPerChunk): array {
        if ($recordsPerChunk < 1 || $recordsPerChunk > 250) {
            throw new DomainException('invalid_interchange_chunk_size');
        }
        $before = $this->validator->inspect($manifestBytes, $chunks, $artifacts);
        if ($before['status'] !== 'complete') {
            throw new DomainException('interchange_repack_requires_complete_bundle');
        }
        $manifest = json_decode($manifestBytes, false, 32, JSON_THROW_ON_ERROR);
        $descriptors = [];
        $output = [];
        foreach ($manifest->chunks as $desc) {
            $body = json_decode($chunks[$desc->id], false, 32, JSON_THROW_ON_ERROR);
            foreach (array_chunk($body->records, $recordsPerChunk) as $records) {
                if (count($descriptors) >= 256) {
                    throw new DomainException('interchange_bundle_limit');
                }
                $id = 'repacked-' . (count($descriptors) + 1);
                $bytes = $this->encode([
                    'format' => BundleValidator::CHUNK_FORMAT,
                    'id' => $id,
                    'workspaceId' => $desc->workspaceId,
                    'resource' => $desc->resource,
                    'records' => $records,
                ]);
                $descriptors[] = [
                    'id' => $id,
                    'workspaceId' => $desc->workspaceId,
                    'resource' => $desc->resource,
                    'count' => count($records),
                    'bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                ];
                $output[$id] = $bytes;
            }
        }
        $manifest->chunks = $descriptors;
        $encoded = $this->encode($manifest);
        $after = $this->validator->inspect($encoded, $output, $artifacts, null, $before);
        if ($after['status'] !== 'complete' || $after['recordIndex'] !== $before['recordIndex']
            || $after['conflicts'] !== 0 || $after['absentFromSnapshot'] !== 0
            || $after['unchanged'] !== $before['observedRecords']) {
            throw new DomainException('interchange_repack_not_lossless');
        }
        return ['manifest' => $encoded, 'chunks' => $output, 'artifacts' => $artifacts];
    }

    private function encode(mixed $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }
}
