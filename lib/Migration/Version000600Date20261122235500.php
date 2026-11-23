<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Separate routine read access from form visibility. Existing fields remain
 * readable to the same audience as before the upgrade.
 */
final class Version000600Date20261122235500 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('lf_custom_fields')) return null;
        $table = $schema->getTable('lf_custom_fields');
        if (!$table->hasColumn('api_visible')) {
            $table->addColumn('api_visible', 'boolean', [
                'notnull' => true, 'default' => true,
            ]);
        }
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Previous API policy withheld fields with visible=false from
        // non-manager actors. Preserve that policy for upgraded spaces.
        $q = $this->db->getQueryBuilder();
        $q->update('lf_custom_fields')
            ->set('api_visible', $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($q->expr()->eq('visible',
                $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->executeStatement();
    }
}
