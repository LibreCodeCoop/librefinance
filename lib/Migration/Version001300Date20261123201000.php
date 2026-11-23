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
 * External financial access is an explicitly accepted, revocable capability,
 * never an implicit lf_members row or Nextcloud group membership.
 */
final class Version001300Date20261123201000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_saas_external_grants')) {
            $t = $schema->createTable('lf_saas_external_grants');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('recipient_uid', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('role', 'string', ['length' => 16, 'notnull' => true]);
            $t->addColumn('status', 'string', ['length' => 16, 'notnull' => true]);
            $t->addColumn('expires_at', 'integer', ['notnull' => true]);
            $t->addColumn('revision', 'integer', ['notnull' => true, 'default' => 1]);
            $t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('accepted_at', 'integer', ['notnull' => false]);
            $t->addColumn('revoked_at', 'integer', ['notnull' => false]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            // One logical grant per account and book: both initial inserts and
            // renewal races are protected by uniqueness plus revision CAS.
            $t->addUniqueIndex(['space_id', 'recipient_uid'], 'lf_ext_sp_user');
            $t->addIndex(['recipient_uid', 'status', 'expires_at'], 'lf_ext_user_active');
            $t->addIndex(['space_id', 'id'], 'lf_ext_space_cursor');
        }
        if (!$schema->hasTable('lf_saas_external_audit')) {
            $t = $schema->createTable('lf_saas_external_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('grant_id', 'integer', ['notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('action', 'string', ['length' => 24, 'notnull' => true]);
            $t->addColumn('previous_status', 'string', ['length' => 16, 'notnull' => false]);
            $t->addColumn('new_status', 'string', ['length' => 16, 'notnull' => true]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'id'], 'lf_ext_aud_space');
            $t->addIndex(['grant_id', 'id'], 'lf_ext_aud_grant');
        }
        return $schema;
    }
}
