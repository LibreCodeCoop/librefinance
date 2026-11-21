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

/** Preserve existing country-specific values while admitting entities without tax IDs. */
final class Version000008Date20261121132000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('lf_companies')) {
            $table = $schema->getTable('lf_companies');
            if ($table->hasColumn('tax_id')) {
                $table->changeColumn('tax_id', ['notnull' => false]);
            }
        }
        if (!$schema->hasTable('lf_custom_fields')) {
            $table = $schema->createTable('lf_custom_fields');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('entity_kind', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('field_key', 'string', ['notnull' => true, 'length' => 40]);
            $table->addColumn('label', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('field_type', 'string', ['notnull' => true, 'length' => 16]);
            $table->addColumn('required', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $table->addColumn('options_json', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->addColumn('updated_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'entity_kind', 'field_key'], 'lf_cf_key_unique');
            $table->addIndex(['space_id', 'entity_kind', 'id'], 'lf_cf_list');
        }
        if (!$schema->hasTable('lf_custom_values')) {
            $table = $schema->createTable('lf_custom_values');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('entity_kind', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('entity_id', 'integer', ['notnull' => true]);
            $table->addColumn('field_id', 'integer', ['notnull' => true]);
            $table->addColumn('value_json', 'text', ['notnull' => true]);
            $table->addColumn('updated_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'entity_kind', 'entity_id', 'field_id'], 'lf_cv_unique');
            $table->addIndex(['space_id', 'entity_kind', 'entity_id'], 'lf_cv_list');
        }
        return $schema;
    }
}
