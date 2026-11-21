<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Company logos are stored in Nextcloud appdata, not in arbitrary remote URLs. */
final class Version000010Date20261121193000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if ($schema->hasTable('lf_companies')) {
            $table=$schema->getTable('lf_companies');
            if (!$table->hasColumn('logo_version')) {
                $table->addColumn('logo_version','integer',['notnull'=>false]);
            }
        }
        return $schema;
    }
}
