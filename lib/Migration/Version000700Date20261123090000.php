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

/** Retain existing personal spaces while allowing centrally governed ones. */
final class Version000700Date20261123090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_spaces')) return null;
        $table = $schema->getTable('lf_spaces');
        if (!$table->hasColumn('governance_gid')) {
            $table->addColumn('governance_gid', 'string', ['length' => 64, 'notnull' => false]);
            $table->addIndex(['governance_gid'], 'lf_sp_gov_gid');
        }
        return $schema;
    }
}
