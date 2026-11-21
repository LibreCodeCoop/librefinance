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

final class Version000004Date20261120200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_categories')) {
            $t = $schema->createTable('lf_categories');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('code', 'string', ['notnull' => true, 'length' => 40]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 12]);
            $t->addColumn('parent_id', 'integer', ['notnull' => false]);
            $t->addColumn('account_id', 'integer', ['notnull' => false]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'code'], 'lf_cat_space_code');
            $t->addIndex(['space_id', 'parent_id'], 'lf_cat_space_parent');
            $t->addIndex(['space_id', 'id'], 'lf_cat_space_id');
        }
        return $schema;
    }
}
