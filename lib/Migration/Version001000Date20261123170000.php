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
 * Introduce environment identity without inferring legacy ledger ownership.
 * A later, explicitly reviewed operation will classify and link old spaces.
 */
final class Version001000Date20261123170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_environments')) {
            $t = $schema->createTable('lf_environments');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('public_id', 'string', ['notnull' => true, 'length' => 36]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('status', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('revision', 'integer', ['notnull' => true, 'default' => 1]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['public_id'], 'lf_env_public_id');
            $t->addIndex(['status', 'id'], 'lf_env_status');
        }
        if (!$schema->hasTable('lf_environment_groups')) {
            $t = $schema->createTable('lf_environment_groups');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('environment_id', 'integer', ['notnull' => true]);
            $t->addColumn('group_gid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('state', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['group_gid'], 'lf_env_group_gid');
            $t->addUniqueIndex(['environment_id'], 'lf_env_group_env');
        }
        if (!$schema->hasTable('lf_environment_spaces')) {
            $t = $schema->createTable('lf_environment_spaces');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('environment_id', 'integer', ['notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('review_state', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id'], 'lf_env_sp_unique');
            $t->addIndex(['environment_id', 'space_id'], 'lf_env_sp_scope');
        }
        if (!$schema->hasTable('lf_environment_audit')) {
            $t = $schema->createTable('lf_environment_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('environment_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('subject_type', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('subject_id', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['environment_id', 'id'], 'lf_env_aud_scope');
            $t->addIndex(['actor_uid', 'id'], 'lf_env_aud_actor');
        }
        return $schema;
    }
}
