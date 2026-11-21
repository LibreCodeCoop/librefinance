<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000001Date20261120140000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('lf_spaces')) {
            $t = $schema->createTable('lf_spaces');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 24]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('owner_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('archived', 'boolean', ['notnull' => true, 'default' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['owner_uid'], 'lf_sp_owner');
        }

        if (!$schema->hasTable('lf_members')) {
            $t = $schema->createTable('lf_members');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('role', 'string', ['notnull' => true, 'length' => 24]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'uid'], 'lf_m_sp_uid');
            $t->addIndex(['uid'], 'lf_m_uid');
        }

        if (!$schema->hasTable('lf_group_grants')) {
            $t = $schema->createTable('lf_group_grants');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('group_gid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('role', 'string', ['notnull' => true, 'length' => 24]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'group_gid'], 'lf_gg_sp_gid');
            $t->addIndex(['group_gid'], 'lf_gg_gid');
        }

        if (!$schema->hasTable('lf_accounts')) {
            $t = $schema->createTable('lf_accounts');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('code', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('class', 'string', ['notnull' => true, 'length' => 24]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('is_bank', 'boolean', ['notnull' => true, 'default' => false]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'code'], 'lf_a_sp_code');
            $t->addIndex(['space_id', 'class'], 'lf_a_sp_class');
        }

        if (!$schema->hasTable('lf_journals')) {
            $t = $schema->createTable('lf_journals');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('effective_on', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('memo', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('external_key', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('request_hash', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('status', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('reversed_journal_id', 'integer', ['notnull' => false]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'external_key'], 'lf_j_sp_key');
            $t->addIndex(['space_id', 'id'], 'lf_j_sp_id');
            $t->addIndex(['space_id', 'effective_on'], 'lf_j_sp_date');
        }

        if (!$schema->hasTable('lf_postings')) {
            $t = $schema->createTable('lf_postings');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('journal_id', 'integer', ['notnull' => true]);
            $t->addColumn('account_id', 'integer', ['notnull' => true]);
            $t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'account_id'], 'lf_p_sp_acc');
            $t->addIndex(['journal_id'], 'lf_p_journal');
        }

        if (!$schema->hasTable('lf_audit')) {
            $t = $schema->createTable('lf_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('entity', 'string', ['notnull' => true, 'length' => 48]);
            $t->addColumn('entity_id', 'integer', ['notnull' => true]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'id'], 'lf_au_sp_id');
        }

        return $schema;
    }
}
