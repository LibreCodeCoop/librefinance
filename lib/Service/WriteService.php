<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class WriteService {
    public function __construct(private IDBConnection $db) {}

    /** @param array<string, scalar|null> $values */
    public function insert(string $table, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $params = [];
        foreach ($values as $name => $value) {
            $params[$name] = $qb->createNamedParameter($value, self::typeFor($value));
        }
        $qb->insert($table)->values($params)->executeStatement();
    }

    /** @param array<string, scalar|null> $values
     *  @param array<string, scalar|null> $where
     */
    public function update(string $table, array $values, array $where): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($table);
        foreach ($values as $name => $value) {
            $qb->set($name, $qb->createNamedParameter($value, self::typeFor($value)));
        }
        foreach ($where as $name => $value) {
            $qb->andWhere($qb->expr()->eq($name, $qb->createNamedParameter($value, self::typeFor($value))));
        }
        $qb->executeStatement();
    }

    /** @param scalar|null $value */
    private static function typeFor(mixed $value): int|string {
        if (is_int($value)) {
            return IQueryBuilder::PARAM_INT;
        }
        if (is_bool($value)) {
            return IQueryBuilder::PARAM_BOOL;
        }
        return IQueryBuilder::PARAM_STR;
    }
}
