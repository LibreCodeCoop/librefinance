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
 * Explicit company-to-ledger uniqueness and idempotent workspace bootstrap.
 * No historical companies or ledgers are inferred, split or reassigned.
 */
final class Version001200Date20261123190000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_saas_company_workspaces')) {
            $t = $schema->createTable('lf_saas_company_workspaces');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('company_id', 'integer', ['notnull' => true]);
            $t->addColumn('linked_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('linked_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id'], 'lf_saas_cw_space');
            $t->addUniqueIndex(['company_id'], 'lf_saas_cw_company');
        }
        if (!$schema->hasTable('lf_saas_space_claims')) {
            $t = $schema->createTable('lf_saas_space_claims');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('environment_id', 'integer', ['notnull' => true]);
            $t->addColumn('idempotency_key', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('request_hash', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['environment_id', 'idempotency_key'], 'lf_saas_sc_unique');
            $t->addUniqueIndex(['space_id'], 'lf_saas_sc_space');
        }
        return $schema;
    }
}
