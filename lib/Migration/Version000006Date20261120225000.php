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

/** Optional immutable category reference on posted journals. */
final class Version000006Date20261120225000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('lf_journals')) {
            $table = $schema->getTable('lf_journals');
            if (!$table->hasColumn('category_id')) {
                $table->addColumn('category_id', 'integer', ['notnull' => false]);
            }
        }
        return $schema;
    }
}
