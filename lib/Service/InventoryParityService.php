<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use OCA\LibreFinance\Import\Source\SourceSystem;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Compare an EXTERNALLY collected per-company source inventory with durable
 * source IDs and actual destination records. This is intentionally COUNT
 * COVERAGE ONLY; self-reported totals never constitute source attestation,
 * balance reconciliation or end-to-end functional equivalence.
 */
final class InventoryParityService {
    private const MODULE = 'librefinance_import';
    private const BANKING = 'librefinance_banking';
    private const FORMAT = 'librefinance.inventory.v1';
    private const MAX_BYTES = 32768;
    private const MAX_RESOURCES = 120;

    /** Origin resource => identity kind => native Nextcloud table. */
    private const MAPPINGS = [
        'companies' => ['company', 'lf_companies'],
        'accounts' => ['account', 'lf_accounts'],
        'contacts' => ['contact', 'lf_contacts'],
        'categories' => ['category', 'lf_categories'],
        'transactions' => ['transaction', 'lfb_transactions'],
        'transfers' => ['transfer', 'lfb_transfers'],
    ];

    public function __construct(
        private FinanceApi $finance,
        private IDBConnection $db,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(int $spaceId, string $inventoryJson): array {
        $this->finance->requireModuleCapability($spaceId, self::MODULE, 'imports.read');
        $source = $this->parse($inventoryJson);
        $bankEnabled = true;
        try {
            $this->finance->requireModuleCapability($spaceId, self::BANKING, 'bank.manage');
        } catch (DomainException $error) {
            if (in_array($error->getMessage(), [
                'module_not_found', 'module_disabled',
            ], true)) {
                $bankEnabled = false;
            } else {
                throw $error;
            }
        }

        $kinds = [];
        foreach (array_keys($source['counts']) as $resource) {
            if (isset(self::MAPPINGS[$resource])
                && ($bankEnabled || !in_array($resource,
                    ['transactions', 'transfers'], true))) {
                $kinds[] = self::MAPPINGS[$resource][0];
            }
        }
        $mapped = [];
        if ($kinds !== []) {
            $q = $this->db->getQueryBuilder();
            $q->select('record_kind')
                ->selectAlias($q->createFunction('COUNT(id)'), 'total')
                ->from('lfm_records')
                ->where($q->expr()->eq('space_id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('source_system',
                    $q->createNamedParameter($source['system'])))
                ->andWhere($q->expr()->eq('source_company_id',
                    $q->createNamedParameter($source['companyId'])))
                ->andWhere($q->expr()->in('record_kind',
                    $q->createNamedParameter($kinds, IQueryBuilder::PARAM_STR_ARRAY)))
                ->groupBy('record_kind');
            foreach ($q->executeQuery()->fetchAllAssociative() as $r) {
                $mapped[(string)$r['record_kind']] = (int)$r['total'];
            }
        }

        $rows = [];
        $totalSource = 0;
        $totalPresent = 0;
        $countGaps = 0;
        $unsupported = 0;
        $orphaned = 0;
        foreach ($source['counts'] as $resource => $expected) {
            $totalSource += $expected;
            $supported = isset(self::MAPPINGS[$resource])
                && ($bankEnabled || !in_array($resource,
                    ['transactions', 'transfers'], true));
            $present = 0;
            $mappedCount = 0;
            if ($supported) {
                [$kind, $targetTable] = self::MAPPINGS[$resource];
                $mappedCount = $mapped[$kind] ?? 0;
                $q = $this->db->getQueryBuilder();
                $q->selectAlias($q->createFunction('COUNT(t.id)'), 'total')
                    ->from('lfm_records', 'm')
                    ->leftJoin('m', $targetTable, 't',
                        't.id = m.target_id AND t.space_id = m.space_id')
                    ->where($q->expr()->eq('m.space_id',
                        $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('m.source_system',
                        $q->createNamedParameter($source['system'])))
                    ->andWhere($q->expr()->eq('m.source_company_id',
                        $q->createNamedParameter($source['companyId'])))
                    ->andWhere($q->expr()->eq('m.record_kind',
                        $q->createNamedParameter($kind)));
                $present = (int)$q->executeQuery()->fetchOne();
            }
            $missing = max(0, $expected - $present);
            $extra = max(0, $present - $expected);
            $broken = max(0, $mappedCount - $present);
            $totalPresent += $present;
            $orphaned += $broken;
            if ($missing > 0 || $extra > 0 || $broken > 0) {
                ++$countGaps;
            }
            if (!$supported && $expected > 0) {
                ++$unsupported;
            }
            $rows[] = [
                'resource' => $resource,
                'sourceCount' => $expected,
                'mapped' => $mappedCount,
                'targetPresent' => $present,
                'missing' => $missing,
                'extra' => $extra,
                'brokenReferences' => $broken,
                'importSupported' => $supported,
                'status' => !$supported
                    ? ($expected === 0 ? 'not_required' : 'not_implemented')
                    : ($missing || $extra || $broken ? 'gap' : 'counts_match'),
            ];
        }
        return [
            'format' => self::FORMAT,
            'source' => [
                'system' => $source['system'],
                'companyId' => $source['companyId'],
                'asOf' => $source['asOf'],
            ],
            'manifestHash' => $source['digest'],
            'resources' => $rows,
            'summary' => [
                'resourcesExamined' => count($rows),
                'sourceRecordsDeclared' => $totalSource,
                'destinationRecordsPresent' => $totalPresent,
                'resourcesWithCountDifferences' => $countGaps,
                'resourcesWithUnimplementedImports' => $unsupported,
                'brokenSourceMappings' => $orphaned,
            ],
            'sourceInventoryIndependentlyVerified' => false,
            'sourceIdentitiesFullyCompared' => false,
            'financialBalancesReconciled' => false,
            'fiscalAuthorizationVerified' => false,
            'functionalParityCertified' => false,
            'readOnly' => true,
            'notes' => [
                'The source manifest is untrusted until independently attested.',
                'Matching record counts cannot prove matching identities or values.',
                'Deleted and historical source records need a separately scoped inventory.',
                'No source data, credentials, manifest or journal postings were stored.',
            ],
        ];
    }

    /** @return array{system:string,companyId:string,asOf:string,counts:array<string,int>,digest:string} */
    private function parse(string $json): array {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new DomainException('invalid_inventory_size');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('invalid_inventory_json');
        }
        if (!is_array($data) || array_is_list($data)
            || !self::keysOnly($data, ['format', 'source', 'counts'])
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_array($data['source'] ?? null)
            || array_is_list($data['source'])
            || !self::keysOnly($data['source'], ['system', 'companyId', 'asOf'])
            || !SourceSystem::valid($data['source']['system'] ?? null)
            || !is_string($data['source']['companyId'] ?? null)
            || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D',
                $data['source']['companyId']) !== 1
            || !is_string($data['source']['asOf'] ?? null)
            || !is_array($data['counts'] ?? null)
            || array_is_list($data['counts']) || $data['counts'] === []
            || count($data['counts']) > self::MAX_RESOURCES) {
            throw new DomainException('invalid_inventory');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['source']['asOf']);
        if ($date === false || $date->format('Y-m-d') !== $data['source']['asOf']) {
            throw new DomainException('invalid_inventory_date');
        }
        $normalized = [];
        foreach ($data['counts'] as $key => $count) {
            if (!is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,48}$/D', $key) !== 1
                || !is_int($count) || $count < 0 || $count > 1000000000) {
                throw new DomainException('invalid_inventory_resource');
            }
            $normalized[$key] = $count;
        }
        ksort($normalized, SORT_STRING);
        $result = [
            'system' => $data['source']['system'],
            'companyId' => $data['source']['companyId'],
            'asOf' => $data['source']['asOf'],
            'counts' => $normalized,
        ];
        $result['digest'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
        return $result;
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private static function keysOnly(array $data, array $keys): bool {
        return array_diff(array_keys($data), $keys) === [];
    }
}
