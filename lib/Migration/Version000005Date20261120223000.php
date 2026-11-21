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
 * Core-owned journal claims survive module enable/disable and prevent a
 * reversal from silently invalidating reconciliations or invoice allocations.
 */
final class Version000005Date20261120223000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_journal_claims')) {
            $t = $schema->createTable('lf_journal_claims');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('journal_id', 'integer', ['notnull' => true]);
            $t->addColumn('module_id', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('reference', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('claimed_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('claimed_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'journal_id', 'module_id'], 'lf_claim_unique');
            $t->addIndex(['space_id', 'journal_id'], 'lf_claim_journal');
        }
        return $schema;
    }
}
