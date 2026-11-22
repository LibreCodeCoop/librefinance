<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Optional company identity metadata; additive to all existing finance areas. */
final class Version000400Date20261122233000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_companies')) {
            return null;
        }
        $table = $schema->getTable('lf_companies');
        foreach ([
            'contact_email' => 190,
            'contact_phone' => 60,
            'website' => 255,
            'address_line1' => 255,
            'address_line2' => 255,
            'city' => 120,
            'region' => 120,
            'postal_code' => 32,
        ] as $column => $length) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'string', [
                    'notnull' => false,
                    'length' => $length,
                ]);
            }
        }
        return $schema;
    }
}
