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

final class Version000900Date20261123110000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_accounts')) return null;
        $table = $schema->getTable('lf_accounts');
        foreach ([
            'institution_name' => 150, 'institution_code' => 40,
            'branch_number' => 40, 'account_number' => 70,
            'account_type' => 32, 'iban' => 34, 'swift_bic' => 11,
        ] as $column => $length) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'string', [
                    'length' => $length, 'notnull' => false,
                ]);
            }
        }
        if (!$table->hasColumn('logo_version')) {
            $table->addColumn('logo_version', 'integer', ['notnull' => false]);
        }
        return $schema;
    }
}
