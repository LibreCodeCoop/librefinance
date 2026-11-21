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

/**
 * Contact and item catalogs, with organization/space scoping.
 *
 * Every object is scoped by the same financial space identifier as the ledger.
 * No implied fiscal document, bank operation or accounting posting is created.
 */
final class Version000002Date20261120170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('lf_contacts')) {
            $t = $schema->createTable('lf_contacts');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 160]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('email', 'string', ['notnull' => false, 'length' => 190]);
            $t->addColumn('tax_id', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'id'], 'lf_c_sp_id');
            $t->addIndex(['space_id', 'kind'], 'lf_c_sp_kind');
        }

        if (!$schema->hasTable('lf_items')) {
            $t = $schema->createTable('lf_items');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('sku', 'string', ['notnull' => true, 'length' => 48]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 160]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('description', 'text', ['notnull' => false]);
            $t->addColumn('unit_price_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'sku'], 'lf_i_sp_sku');
            $t->addIndex(['space_id', 'id'], 'lf_i_sp_id');
        }

        return $schema;
    }
}
