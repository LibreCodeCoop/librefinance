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
 * Pre-release consolidated baseline. Creates the complete schema on a fresh
 * Nextcloud installation and safely completes known development schemas.
 *
 * Original import-app migrations are not removed; existing financial
 * records are never dropped, truncated or replaced.
 */
final class Version000300Date20261122160000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        // Consolidated development schema: Version000001Date20261120230000.php
        if (!$schema->hasTable('lfm_records')) {
            $table = $schema->createTable('lfm_records');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('source_system', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('source_company_id', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('record_kind', 'string', ['notnull' => true, 'length' => 12]);
            $table->addColumn('source_record_id', 'string', ['notnull' => true, 'length' => 80]);
            $table->addColumn('target_id', 'integer', ['notnull' => true]);
            $table->addColumn('payload_hash', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'source_system', 'source_company_id',
                'record_kind', 'source_record_id'], 'lfm_record_identity');
            $table->addIndex(['space_id', 'record_kind', 'target_id'], 'lfm_target_reference');
        }
        if (!$schema->hasTable('lfm_audit')) {
            $table = $schema->createTable('lfm_audit');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('source_system', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('source_company_id', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('batch_hash', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_count', 'integer', ['notnull' => true]);
            $table->addColumn('skipped_count', 'integer', ['notnull' => true]);
            $table->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_id', 'occurred_at'], 'lfm_audit_recent');
        }

        // Consolidated development schema: Version000002Date20261121120000.php
        if (!$schema->hasTable('lfm_liquid_origins')) {
            $table = $schema->createTable('lfm_liquid_origins');
            $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('target_account_id', 'integer', ['notnull' => true]);
            $table->addColumn('kind', 'string', ['notnull' => true, 'length' => 8]);
            $table->addColumn('bank_name', 'string', ['notnull' => false, 'length' => 150]);
            // Authenticated ciphertext, never plaintext account number.
            $table->addColumn('account_number_cipher', 'text', ['notnull' => false]);
            $table->addColumn('number_last4', 'string', ['notnull' => false, 'length' => 4]);
            $table->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $table->addColumn('opening_balance_minor', 'bigint', ['notnull' => true]);
            $table->addColumn('current_balance_minor', 'bigint', ['notnull' => true]);
            $table->addColumn('balance_as_of', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'target_account_id'], 'lfm_liq_target');
            $table->addIndex(['space_id', 'balance_as_of'], 'lfm_liq_asof');
        }

        return $schema;
    }
}
