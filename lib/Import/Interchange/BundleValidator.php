<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Interchange;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use OCA\LibreFinance\Import\Source\SourceSystem;
use stdClass;

/**
 * Pure, bounded dry-run of a source-neutral bundle. No filesystem, DB, HTTP,
 * credential, destination mapping or posting authority. The caller supplies
 * already-authorized bytes; all error codes deliberately omit source content.
 */
final class BundleValidator {
    public const FORMAT = 'librefinance.interchange.v1';
    public const CHUNK_FORMAT = 'librefinance.interchange.chunk.v1';
    private const MAX_MANIFEST = 524288;
    private const MAX_CHUNK = 1048576;
    private const MAX_ARTIFACT = 4194304;
    private const MAX_BYTES = 16777216;
    private const MAX_RECORDS = 10000;
    private const MAX_CHUNKS = 256;
    private const MAX_ARTIFACTS = 256;
    private const RESOURCE_STATES = ['complete', 'empty', 'missing_capability',
        'access_denied', 'truncated', 'timeout', 'unsupported', 'unavailable', 'invalid_response', 'snapshot_changed', 'excluded'];
    private const KINDS = ['master_data', 'configuration', 'bank_evidence',
        'accounting_entry', 'obligation', 'commercial_document', 'settlement',
        'fiscal_authorization', 'source_only'];

    /**
     * @param array<string,string> $chunks Exact compact UTF-8 JSON bytes by chunk ID.
     * @param array<string,string> $artifacts Exact opaque bytes by artifact ID.
     * @param ?array<string,mixed> $checkpoint Previous dry-run of the SAME manifest.
     *        It is a resumption hint, never evidence in lieu of resupplied bytes.
     * @param ?array<string,mixed> $previous Complete, authorized prior inventory
     *        from the same source instance, for conflict detection only.
     * @return array<string,mixed>
     */
    public function inspect(string $manifestBytes, array $chunks, array $artifacts = [],
        ?array $checkpoint = null, ?array $previous = null): array {
        $manifest = $this->decode($manifestBytes, self::MAX_MANIFEST);
        if (($manifest->format ?? null) !== self::FORMAT) {
            $this->fail('unsupported_interchange_format');
        }
        $this->keys($manifest, ['format', 'source', 'snapshotId', 'cutoff', 'workspaces', 'chunks', 'artifacts']);
        $source = $manifest->source;
        $this->keys($source, ['system', 'instanceId', 'version']);
        if (!SourceSystem::valid($source->system) || !$this->id($source->instanceId)
            || !$this->id($source->version, 64) || !$this->id($manifest->snapshotId)
            || !$this->timestamp($manifest->cutoff)) {
            $this->fail('invalid_interchange_source');
        }
        $manifestHash = hash('sha256', $manifestBytes);
        if ($checkpoint !== null && ($checkpoint['manifestSha256'] ?? null) !== $manifestHash) {
            $this->fail('interchange_checkpoint_mismatch');
        }
        $workspaceRows = $this->list($manifest->workspaces, 100);
        if ($workspaceRows === []) $this->fail('invalid_interchange_workspaces');
        $resources = [];
        $complete = true;
        foreach ($workspaceRows as $workspace) {
            $this->keys($workspace, ['id', 'resources']);
            if (!$this->id($workspace->id) || isset($resources[$workspace->id])) {
                $this->fail('invalid_interchange_workspace');
            }
            $resources[$workspace->id] = [];
            $declarations = $this->list($workspace->resources, 100);
            if ($declarations === []) $this->fail('invalid_interchange_resources');
            foreach ($declarations as $r) {
                $this->keys($r, ['resource', 'status', 'count']);
                if (!$this->resource($r->resource) || isset($resources[$workspace->id][$r->resource])
                    || !in_array($r->status, self::RESOURCE_STATES, true)) {
                    $this->fail('invalid_interchange_resource');
                }
                if ($r->status === 'complete') {
                    if (!$this->boundedInt($r->count, 1, self::MAX_RECORDS)) $this->fail('invalid_interchange_count');
                } elseif ($r->status === 'empty') {
                    if ($r->count !== 0) $this->fail('invalid_interchange_count');
                } else {
                    if ($r->count !== null) $this->fail('invalid_interchange_count');
                    $complete = false;
                }
                $resources[$workspace->id][$r->resource] = ['status' => $r->status,
                    'count' => $r->count, 'described' => 0];
            }
        }
        $declaredBytes = strlen($manifestBytes);
        $declaredRecords = 0;
        $descriptors = [];
        foreach ($this->list($manifest->chunks, self::MAX_CHUNKS) as $desc) {
            $this->keys($desc, ['id','workspaceId','resource','count','bytes','sha256']);
            if (!$this->id($desc->id) || isset($descriptors[$desc->id])
                || !$this->id($desc->workspaceId) || !$this->resource($desc->resource)
                || !isset($resources[$desc->workspaceId][$desc->resource])
                || !$this->boundedInt($desc->count, 1, 250)
                || !$this->boundedInt($desc->bytes, 1, self::MAX_CHUNK)
                || !$this->digest($desc->sha256)) {
                $this->fail('invalid_interchange_chunk_descriptor');
            }
            $descriptors[$desc->id] = $desc;
            $declaredBytes += $desc->bytes;
            $declaredRecords += $desc->count;
            $resources[$desc->workspaceId][$desc->resource]['described'] += $desc->count;
        }
        foreach ($resources as $byResource) {
            foreach ($byResource as $r) {
                if ($r['status'] === 'excluded' && $r['described'] !== 0) {
                    $this->fail('interchange_excluded_resource_content');
                }
                if ($r['count'] !== null && $r['count'] !== $r['described']) {
                    $this->fail('interchange_resource_count_mismatch');
                }
            }
        }
        $artifactDescriptors = [];
        foreach ($this->list($manifest->artifacts, self::MAX_ARTIFACTS) as $desc) {
            $this->keys($desc, ['id','workspaceId','bytes','sha256','mediaType']);
            if (!$this->id($desc->id) || isset($artifactDescriptors[$desc->id])
                || !$this->id($desc->workspaceId) || !isset($resources[$desc->workspaceId])
                || !$this->boundedInt($desc->bytes, 0, self::MAX_ARTIFACT)
                || !$this->digest($desc->sha256)
                || !is_string($desc->mediaType)
                || preg_match('/^[a-z0-9][a-z0-9.+-]{0,63}\/[a-z0-9][a-z0-9.+-]{0,63}$/D', $desc->mediaType) !== 1) {
                $this->fail('invalid_interchange_artifact_descriptor');
            }
            $artifactDescriptors[$desc->id] = $desc;
            $declaredBytes += $desc->bytes;
        }
        if ($declaredBytes > self::MAX_BYTES || $declaredRecords > self::MAX_RECORDS) {
            $this->fail('interchange_bundle_limit');
        }
        if (count($chunks) > count($descriptors) || count($artifacts) > count($artifactDescriptors)) {
            $this->fail('interchange_undeclared_content');
        }
        foreach ($chunks as $id => $bytes) {
            if (!isset($descriptors[$id]) || !is_string($bytes)) $this->fail('interchange_undeclared_content');
        }
        foreach ($artifacts as $id => $bytes) {
            if (!isset($artifactDescriptors[$id]) || !is_string($bytes)) $this->fail('interchange_undeclared_content');
        }
        $missingChunks = [];
        $missingArtifacts = [];
        $verifiedChunks = [];
        $verifiedArtifacts = [];
        $index = [];
        $relations = [];
        $usedArtifacts = [];
        $order = 0;
        foreach ($descriptors as $id => $desc) {
            if (!array_key_exists($id, $chunks)) {
                $missingChunks[] = $desc->id;
                $order += $desc->count;
                continue;
            }
            $bytes = $chunks[$id];
            $this->verifyBytes($bytes, $desc);
            $chunk = $this->decode($bytes, self::MAX_CHUNK);
            $this->keys($chunk, ['format','id','workspaceId','resource','records']);
            if ($chunk->format !== self::CHUNK_FORMAT || $chunk->id !== $desc->id
                || $chunk->workspaceId !== $desc->workspaceId || $chunk->resource !== $desc->resource) {
                $this->fail('interchange_chunk_scope_mismatch');
            }
            $records = $this->list($chunk->records, 250);
            if (count($records) !== $desc->count) $this->fail('interchange_chunk_count_mismatch');
            foreach ($records as $record) {
                $this->record($record, $desc, $manifest->cutoff);
                $key = $this->key($desc->workspaceId, $desc->resource, $record->id);
                if (isset($index[$key])) $this->fail('interchange_duplicate_identity');
                $index[$key] = ['workspaceId' => $desc->workspaceId, 'resource' => $desc->resource,
                    'id' => $record->id, 'version' => $record->version, 'kind' => $record->kind,
                    'state' => $record->state,
                    'order' => $order++];
                foreach ($record->references as $ref) {
                    if ($ref->workspaceId !== $desc->workspaceId
                        || !isset($resources[$ref->workspaceId][$ref->resource])) {
                        $this->fail('interchange_reference_scope_mismatch');
                    }
                    $target = $this->key($ref->workspaceId, $ref->resource, $ref->id);
                    if ($key === $target && $ref->type === 'parent') $this->fail('interchange_parent_order');
                    $relations[] = [$key, $target, $ref->type];
                }
                $attachmentDigests = [];
                foreach ($record->artifacts as $artifact) {
                    $target = $artifactDescriptors[$artifact->id] ?? null;
                    if ($target === null || $target->workspaceId !== $desc->workspaceId) {
                        $this->fail('interchange_artifact_scope_mismatch');
                    }
                    $usedArtifacts[$artifact->id] = true;
                    $attachmentDigests[] = [$artifact->id, $target->sha256];
                }
                // A changed attachment is a changed source record, even with a stable artifact ID.
                $index[$key]['sha256'] = hash('sha256', $this->encode([
                    $this->sorted($record), $attachmentDigests,
                ]));
                if (count($relations) > 40000) $this->fail('interchange_bundle_limit');
            }
            $verifiedChunks[] = ['id' => $desc->id, 'sha256' => $desc->sha256];
        }
        foreach ($artifactDescriptors as $id => $desc) {
            if (!array_key_exists($id, $artifacts)) {
                $missingArtifacts[] = $desc->id;
                continue;
            }
            $this->verifyBytes($artifacts[$id], $desc);
            $verifiedArtifacts[] = ['id' => $desc->id, 'sha256' => $desc->sha256];
        }
        $allBytes = $missingChunks === [] && $missingArtifacts === [];
        $unverifiedReferences = 0;
        foreach ($relations as [$key, $target, $type]) {
            if (!isset($index[$target])) {
                // Missing bytes or an explicitly incomplete resource cannot prove absence.
                [$w, $r] = explode('/', $target, 3);
                $targetResource = $resources[$w][$r];
                if ($allBytes && in_array($targetResource['status'], ['complete','empty'], true)) {
                    $this->fail('interchange_missing_reference');
                }
                ++$unverifiedReferences;
            } elseif ($type === 'parent' && $index[$target]['order'] >= $index[$key]['order']) {
                $this->fail('interchange_parent_order');
            }
        }
        if ($allBytes) {
            foreach ($artifactDescriptors as $id => $desc) {
                if (!isset($usedArtifacts[$id])) $this->fail('interchange_unreferenced_artifact');
            }
        }
        $complete = $complete && $allBytes && $unverifiedReferences === 0;
        // Order is only a local verification aid. Export a stable index of
        // identities and content, not raw financial records or artifact bytes.
        foreach ($index as &$item) unset($item['order']);
        unset($item);
        ksort($index, SORT_STRING);
        $sourceIdentity = ['system' => $source->system, 'instanceId' => $source->instanceId];
        $conflicts = 0;
        $unchanged = 0;
        $absentFromSnapshot = 0;
        if ($previous !== null) {
            if (($previous['status'] ?? null) !== 'complete'
                || ($previous['sourceIdentity']['system'] ?? null) !== $sourceIdentity['system']
                || ($previous['sourceIdentity']['instanceId'] ?? null) !== $sourceIdentity['instanceId']
                || !$this->timestamp($previous['cutoff'] ?? null)
                || $previous['cutoff'] > $manifest->cutoff
                || !is_array($previous['recordIndex'] ?? null)
                || count($previous['recordIndex']) > self::MAX_RECORDS) {
                $this->fail('interchange_previous_scope_mismatch');
            }
            foreach ($previous['recordIndex'] as $key => $old) {
                if (!is_string($key) || !is_array($old) || !$this->id($old['version'] ?? null)
                    || !$this->digest($old['sha256'] ?? null)
                    || !$this->id($old['workspaceId'] ?? null) || !$this->resource($old['resource'] ?? null)
                    || !$this->id($old['id'] ?? null)
                    || $key !== $this->key($old['workspaceId'], $old['resource'], $old['id'])) {
                    $this->fail('invalid_interchange_previous_index');
                }
                if (!isset($index[$key])) {
                    if ($complete && isset($resources[$old['workspaceId']][$old['resource']])) {
                        ++$absentFromSnapshot;
                    }
                    continue;
                }
                if ($index[$key]['version'] === $old['version'] && $index[$key]['sha256'] === $old['sha256']) {
                    ++$unchanged;
                } else {
                    // Changed historical facts never authorize an overwrite.
                    ++$conflicts;
                }
            }
        }
        return [
            'format' => self::FORMAT, 'status' => $complete ? 'complete' : 'incomplete',
            'sourceIdentity' => $sourceIdentity, 'sourceVersion' => $source->version,
            'cutoff' => $manifest->cutoff, 'sourceAttested' => false, 'applyAuthorized' => false,
            'workspaceCount' => count($resources), 'observedRecords' => count($index),
            'unverifiedReferences' => $unverifiedReferences, 'conflicts' => $conflicts,
            'unchanged' => $unchanged, 'absentFromSnapshot' => $absentFromSnapshot, 'mappingRequired' => true,
            'recordIndex' => $complete ? $index : [],
            'checkpoint' => ['manifestSha256' => $manifestHash, 'verifiedChunks' => $verifiedChunks,
                'verifiedArtifacts' => $verifiedArtifacts, 'missingChunks' => $missingChunks,
                'missingArtifacts' => $missingArtifacts],
        ];
    }

    private function record(mixed $r, stdClass $desc, string $cutoff): void {
        $this->keys($r, ['id','version','kind','state','createdAt','updatedAt',
            'money','references','artifacts','fields']);
        if (!$this->id($r->id) || !$this->id($r->version)
            || !in_array($r->kind, self::KINDS, true)
            || !in_array($r->state, ['active','archived','deleted'], true)
            || !$this->optionalTimestamp($r->createdAt, $cutoff)
            || !$this->optionalTimestamp($r->updatedAt, $cutoff)
            || ($r->createdAt !== null && $r->updatedAt !== null && $r->updatedAt < $r->createdAt)) {
            $this->fail('invalid_interchange_record');
        }
        $expected = match ($desc->resource) {
            'journal_entries', 'journal_lines' => 'accounting_entry',
            'bank_statements', 'bank_statement_lines', 'reconciliations' => 'bank_evidence',
            'obligations' => 'obligation',
            'invoices', 'invoice_lines', 'bills', 'bill_lines' => 'commercial_document',
            'settlements', 'payments' => 'settlement',
            'fiscal_authorizations' => 'fiscal_authorization',
            default => null,
        };
        if ($expected !== null && !in_array($r->kind, [$expected, 'source_only'], true)) {
            $this->fail('interchange_semantic_kind_mismatch');
        }
        $seen = [];
        foreach ($this->list($r->money, 32) as $m) {
            $this->keys($m, ['role','minor','currency','scale']);
            if (!$this->resource($m->role) || isset($seen[$m->role])
                || !is_string($m->minor) || preg_match('/^(?:0|-?[1-9][0-9]{0,37})$/D', $m->minor) !== 1
                || !is_string($m->currency) || preg_match('/^[A-Z]{3}$/D', $m->currency) !== 1
                || !$this->boundedInt($m->scale, 0, 9)) {
                $this->fail('invalid_interchange_money');
            }
            $seen[$m->role] = true;
        }
        $seen = [];
        foreach ($this->list($r->references, 32) as $ref) {
            $this->keys($ref, ['workspaceId','resource','id','role','type']);
            if (!$this->id($ref->workspaceId) || !$this->resource($ref->resource)
                || !$this->id($ref->id) || !$this->resource($ref->role)
                || !in_array($ref->type, ['parent','reference'], true)) $this->fail('invalid_interchange_reference');
            $key = $this->key($ref->workspaceId,$ref->resource,$ref->id).'/'.$ref->role.'/'.$ref->type;
            if (isset($seen[$key])) $this->fail('invalid_interchange_reference');
            $seen[$key] = true;
        }
        $seen = [];
        foreach ($this->list($r->artifacts, 16) as $a) {
            $this->keys($a, ['id','role']);
            if (!$this->id($a->id) || !$this->resource($a->role) || isset($seen[$a->id])) {
                $this->fail('invalid_interchange_artifact_reference');
            }
            $seen[$a->id] = true;
        }
        if (!$r->fields instanceof stdClass) $this->fail('invalid_interchange_fields');
        $this->field($r->fields, 0);
    }

    private function field(mixed $value, int $depth): void {
        if ($depth > 6) $this->fail('invalid_interchange_fields');
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            if (count($fields) > 64) $this->fail('invalid_interchange_fields');
            foreach ($fields as $key => $v) {
                if (!$this->resource($key) || preg_match('/password|passphrase|secret|credential|token|private[._-]?key|api[._-]?key|authorization[._-]?header/', $key)) {
                    $this->fail('invalid_interchange_fields');
                }
                $this->field($v, $depth+1);
            }
        } elseif (is_array($value)) {
            foreach ($this->list($value, 64) as $v) $this->field($v, $depth+1);
        } elseif (is_string($value)) {
            if (strlen($value) > 4096) $this->fail('invalid_interchange_fields');
        } elseif (!is_bool($value) && $value !== null
            && !$this->boundedInt($value, -9007199254740991, 9007199254740991)) {
            $this->fail('invalid_interchange_fields');
        }
    }

    private function decode(string $bytes, int $limit): stdClass {
        if ($bytes === '' || strlen($bytes) > $limit) $this->fail('invalid_interchange_size');
        try {
            $data = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            // Require compact transport encoding; re-encoding detects repeated
            // keys, ambiguous numeric spelling and noncanonical escape forms.
            if (!$data instanceof stdClass || $this->encode($data) !== $bytes) $this->fail('invalid_interchange_encoding');
        } catch (JsonException) {
            $this->fail('invalid_interchange_json');
        }
        return $data;
    }

    private function encode(mixed $v): string {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    private function sorted(mixed $v): mixed {
        if ($v instanceof stdClass) {
            $fields = get_object_vars($v);
            ksort($fields, SORT_STRING);
            foreach ($fields as &$value) $value = $this->sorted($value);
            unset($value);
            return (object)$fields;
        }
        return is_array($v) ? array_map(fn($x) => $this->sorted($x), $v) : $v;
    }

    private function keys(mixed $obj, array $expected): void {
        if (!$obj instanceof stdClass) $this->fail('invalid_interchange_structure');
        $keys = array_keys(get_object_vars($obj));
        sort($keys, SORT_STRING); sort($expected, SORT_STRING);
        if ($keys !== $expected) $this->fail('invalid_interchange_structure');
    }
    private function list(mixed $v, int $limit): array {
        if (!is_array($v) || !array_is_list($v) || count($v) > $limit) $this->fail('invalid_interchange_list');
        return $v;
    }
    private function boundedInt(mixed $v, int $min, int $max): bool {
        return is_int($v) && $v >= $min && $v <= $max;
    }
    private function id(mixed $v, int $limit = 128): bool {
        return is_string($v) && strlen($v) <= $limit
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $v) === 1;
    }
    private function resource(mixed $v): bool {
        return is_string($v) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $v) === 1;
    }
    private function digest(mixed $v): bool {
        return is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v) === 1;
    }
    private function timestamp(mixed $v): bool {
        if (!is_string($v) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $v) !== 1) return false;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $v, new DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d\TH:i:s\Z') === $v;
    }
    private function optionalTimestamp(mixed $v, string $cutoff): bool {
        return $v === null || ($this->timestamp($v) && $v <= $cutoff);
    }
    private function key(string $workspace, string $resource, string $id): string {
        return $workspace.'/'.$resource.'/'.$id;
    }
    private function verifyBytes(string $bytes, stdClass $desc): void {
        if (strlen($bytes) !== $desc->bytes || !hash_equals($desc->sha256, hash('sha256', $bytes))) {
            $this->fail('interchange_content_integrity');
        }
    }
    private function fail(string $code): never {
        throw new DomainException($code);
    }
}
