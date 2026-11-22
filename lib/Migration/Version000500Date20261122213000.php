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

/** Non-destructive, separate visual accents for a finance space and its legal entities. */
final class Version000500Date20261122213000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        foreach ([
            ['lf_spaces', 'accent_color'],
            ['lf_companies', 'brand_color'],
        ] as [$name, $column]) {
            if (!$schema->hasTable($name)) {
                continue;
            }
            $table = $schema->getTable($name);
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'string', [
                    'length' => 7, 'notnull' => true, 'default' => '#64748B',
                ]);
            }
        }
        return $schema;
    }
}
