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

/** Legal-entity registry is distinct from Nextcloud user/group financial spaces. */
final class Version000007Date20261121100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_companies')) {
            $t = $schema->createTable('lf_companies');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('legal_name', 'string', ['notnull' => true, 'length' => 180]);
            $t->addColumn('trade_name', 'string', ['notnull' => false, 'length' => 180]);
            $t->addColumn('country', 'string', ['notnull' => true, 'length' => 2]);
            $t->addColumn('tax_id', 'string', ['notnull' => true, 'length' => 40]);
            $t->addColumn('municipal_registration', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('state_registration', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'country', 'tax_id'], 'lfc_sp_ct_tax');
            $t->addIndex(['space_id', 'id'], 'lfc_sp_id');
        }
        return $schema;
    }
}
