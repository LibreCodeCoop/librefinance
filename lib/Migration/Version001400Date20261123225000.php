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

/** Monotonic revision for internal SaaS ledger grants, not external invitations. */
final class Version001400Date20261123225000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_environment_spaces')) {
            return null;
        }
        $table = $schema->getTable('lf_environment_spaces');
        if (!$table->hasColumn('grants_revision')) {
            // Existing binding rows retain their identity and start at 0;
            // no ledger, financial role or historic grant is reassigned.
            $table->addColumn('grants_revision', 'integer', ['notnull' => true, 'default' => 0]);
        }
        return $schema;
    }
}
