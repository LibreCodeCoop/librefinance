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

final class Version000800Date20261123094500 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_governance_delegates')) {
            $table = $schema->createTable('lf_governance_delegates');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('group_gid', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['group_gid', 'uid'], 'lf_gov_delegate_uniq');
            $table->addIndex(['uid'], 'lf_gov_delegate_user');
        }
        return $schema;
    }
}
