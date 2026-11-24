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

final class Version001500Date20261124184600 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_fx_rates')) {
            $table = $schema->createTable('lf_fx_rates');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('metadata_version', 'integer', ['notnull' => true]);
            $table->addColumn('source_currency', 'string', ['notnull' => true, 'length' => 3]);
            $table->addColumn('target_currency', 'string', ['notnull' => true, 'length' => 3]);
            $table->addColumn('source_precision', 'smallint', ['notnull' => true]);
            $table->addColumn('target_precision', 'smallint', ['notnull' => true]);
            $table->addColumn('rate', 'string', ['notnull' => true, 'length' => 25]);
            $table->addColumn('effective_on', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('source_type', 'string', ['notnull' => true, 'length' => 16]);
            $table->addColumn('source_ref', 'string', ['notnull' => true, 'length' => 96]);
            $table->addColumn('fingerprint', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'fingerprint'], 'lf_fx_sp_fingerprint');
            $table->addIndex(['space_id', 'id'], 'lf_fx_sp_cursor');
        }
        return $schema;
    }
}
