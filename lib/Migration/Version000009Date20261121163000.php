<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Non-destructive field studio metadata and category presentation upgrades. */
final class Version000009Date20261121163000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('lf_categories')) {
            $table = $schema->getTable('lf_categories');
            if (!$table->hasColumn('color')) {
                $table->addColumn('color', 'string', ['notnull' => true, 'length' => 7, 'default' => '#64748B']);
            }
        }
        if ($schema->hasTable('lf_journals')) {
            $table = $schema->getTable('lf_journals');
            if (!$table->hasColumn('notes_md')) {
                $table->addColumn('notes_md', 'text', ['notnull' => false]);
            }
        }
        if ($schema->hasTable('lf_custom_fields')) {
            $table = $schema->getTable('lf_custom_fields');
            if (!$table->hasColumn('visible')) {
                $table->addColumn('visible', 'boolean', ['notnull' => true, 'default' => true]);
            }
            if (!$table->hasColumn('help_text')) {
                $table->addColumn('help_text', 'string', ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('sort_order')) {
                $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            }
            if (!$table->hasColumn('column_span')) {
                $table->addColumn('column_span', 'integer', ['notnull' => true, 'default' => 12]);
            }
        }
        return $schema;
    }
}
