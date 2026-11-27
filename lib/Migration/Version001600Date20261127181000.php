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

final class Version001600Date20261127181000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('lfi_invoices');
        if (!$table->hasIndex('lfi_invoice_party_cursor')) {
            $table->addIndex(['space_id', 'kind', 'contact_id', 'currency', 'id'], 'lfi_invoice_party_cursor');
        }
        return $schema;
    }
}
