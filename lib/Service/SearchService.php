<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Deterministic, read-only search across a single authorized finance space.
 *
 * This service does not send data to an AI provider. MCP and HTTP clients
 * receive only the same scoped records available to a Nextcloud viewer.
 */
final class SearchService {
    private const SOURCES = [
        'contacts' => ['lf_contacts', ['id', 'name', 'kind', 'active']],
        'categories' => ['lf_categories', ['id', 'code', 'name', 'kind', 'active']],
        'items' => ['lf_items', ['id', 'sku', 'name', 'kind', 'active']],
    ];

    public function __construct(
        private IDBConnection $db,
        private AccessService $access,
    ) {}

    /** @return array<string,mixed> */
    public function search(int $spaceId, string $query, string $type = 'all'): array {
        $this->access->requireRole($spaceId, 'viewer');
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $query)) {
            throw new DomainException('invalid_search_query');
        }
        if ($type !== 'all' && !isset(self::SOURCES[$type])) {
            throw new DomainException('invalid_search_type');
        }

        // SQL wildcard characters are not accepted as query operators.
        // Escape them explicitly so user content remains a literal substring.
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
        $sources = $type === 'all' ? self::SOURCES : [$type => self::SOURCES[$type]];
        $results = [];
        foreach ($sources as $name => [$table, $columns]) {
            $qb = $this->db->getQueryBuilder();
            $qb->select(...$columns)->from($table)
                ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->andWhere("name LIKE " . $qb->createNamedParameter('%' . $literal . '%') . " ESCAPE '!'")
                ->orderBy('id', 'ASC')->setMaxResults(20);
            $results[$name] = $qb->executeQuery()->fetchAllAssociative();
            foreach ($results[$name] as &$row) {
                $row['id'] = (int)$row['id'];
                $row['active'] = (bool)$row['active'];
            }
            unset($row);
        }
        return ['query' => $query, 'type' => $type, 'results' => $results, 'limitPerType' => 20];
    }
}
