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
 * Typed, optimistic policy state and append-only administrative audit.
 * No legacy module flags or financial grants are migrated implicitly.
 */
final class Version001100Date20261123180000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_saas_policy_values')) {
            $t = $schema->createTable('lf_saas_policy_values');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('scope_key', 'string', ['notnull' => true, 'length' => 180]);
            $t->addColumn('scope_type', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('policy_key', 'string', ['notnull' => true, 'length' => 100]);
            $t->addColumn('value_json', 'text', ['notnull' => false]);
            $t->addColumn('is_cleared', 'boolean', ['notnull' => true, 'default' => false]);
            $t->addColumn('revision', 'integer', ['notnull' => true, 'default' => 1]);
            $t->addColumn('updated_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['scope_key', 'policy_key'], 'lf_pol_scope_key');
            $t->addIndex(['scope_type', 'scope_key'], 'lf_pol_sc_type');
        }
        if (!$schema->hasTable('lf_saas_policy_audit')) {
            $t = $schema->createTable('lf_saas_policy_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('policy_id', 'integer', ['notnull' => true]);
            $t->addColumn('scope_key', 'string', ['notnull' => true, 'length' => 180]);
            $t->addColumn('policy_key', 'string', ['notnull' => true, 'length' => 100]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('old_value_json', 'text', ['notnull' => false]);
            $t->addColumn('new_value_json', 'text', ['notnull' => false]);
            $t->addColumn('previous_revision', 'integer', ['notnull' => true]);
            $t->addColumn('new_revision', 'integer', ['notnull' => true]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['scope_key', 'id'], 'lf_pol_aud_scope');
            $t->addIndex(['actor_uid', 'id'], 'lf_pol_aud_actor');
        }
        return $schema;
    }
}
