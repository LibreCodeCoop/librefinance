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

final class Version001700Date20261128080500 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('lf_contacts');
        $columns = ['contact_person' => 160, 'phone' => 60, 'website' => 255];
        foreach (['billing_', 'shipping_'] as $prefix) {
            foreach (['line1' => 255, 'line2' => 255, 'city' => 120, 'region' => 120,
                'postal_code' => 32, 'country' => 2] as $column => $length) {
                $columns[$prefix . $column] = $length;
            }
        }
        foreach ($columns as $column => $length) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'string', ['notnull' => false, 'length' => $length]);
            }
        }
        return $schema;
    }
}
