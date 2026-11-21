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

final class Version000003Date20261120180000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_module_flags')) {
            $t = $schema->createTable('lf_module_flags');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('module_id', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('enabled', 'boolean', ['notnull' => true, 'default' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'module_id'], 'lf_m_space_id');
        }
        return $schema;
    }
}
