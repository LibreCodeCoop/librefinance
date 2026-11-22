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
 * One additive migration to take ownership of the former four optional
 * finance domains. Existing data and physical table names remain unchanged.
 * This class deliberately does not drop, rename or overwrite any table.
 */
final class Version000200Date20261122120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        // reports: preserve the original table and index names.
// Consolidated development schema: Version000001Date20261121134000.php
        if (!$schema->hasTable('lfr_saved_reports')) {
            $table = $schema->createTable('lfr_saved_reports');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 120]);
            $table->addColumn('kind', 'string', ['notnull' => true, 'length' => 32]);
            $table->addColumn('from_on', 'string', ['notnull' => false, 'length' => 10]);
            $table->addColumn('through_on', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $table->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->addColumn('updated_at', 'integer', ['notnull' => true]);

            $table->addColumn('columns_json', 'text', ['notnull' => false]);
            $table->addColumn('class_filter', 'string', ['length' => 16, 'notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_id', 'id'], 'lfr_saved_sp_id');
        }

        // Consolidated development schema: Version000002Date20261121193000.php
        if ($schema->hasTable('lfr_saved_reports')) {
            $table = $schema->getTable('lfr_saved_reports');
            if (!$table->hasColumn('columns_json')) {
                $table->addColumn('columns_json', 'text', ['notnull' => false]);
            }
            if (!$table->hasColumn('class_filter')) {
                $table->addColumn('class_filter', 'string', ['length' => 16, 'notnull' => false]);
            }
        }

        // obligations: preserve the original table and index names.
if (!$schema->hasTable('lfob_obligations')) {
            $t = $schema->createTable('lfob_obligations');
            $t->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 12]);
            $t->addColumn('description', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('reference', 'string', ['notnull' => true, 'length' => 80]);
            $t->addColumn('contact_id', 'integer', ['notnull' => false]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('paid_minor', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('issued_on', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('due_on', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('status', 'string', ['notnull' => true, 'length' => 12]);
            $t->addColumn('version', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'reference'], 'lfob_sp_ref');
            $t->addIndex(['space_id', 'id'], 'lfob_sp_id');
            $t->addIndex(['space_id', 'due_on'], 'lfob_sp_due');
        }
        if (!$schema->hasTable('lfob_settlements')) {
            $t = $schema->createTable('lfob_settlements');
            $t->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('obligation_id', 'integer', ['notnull' => true]);
            $t->addColumn('operation_key', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('request_hash', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('paid_on', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('note', 'string', ['notnull' => false, 'length' => 255]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'operation_key'], 'lfobs_sp_key');
            $t->addIndex(['space_id', 'obligation_id'], 'lfobs_sp_ob');
        }
        if (!$schema->hasTable('lfob_audit')) {
            $t = $schema->createTable('lfob_audit');
            $t->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('obligation_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 40]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'obligation_id'], 'lfoba_sp_ob');
        }

        // banking: preserve the original table and index names.
// Consolidated development schema: Version000001Date20261120200000.php
        if (!$schema->hasTable('lfb_transactions')) {
            $t = $schema->createTable('lfb_transactions');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('bank_account_id', 'integer', ['notnull' => true]);
            $t->addColumn('external_id', 'string', ['notnull' => true, 'length' => 128]);
            $t->addColumn('booking_date', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('description', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('status', 'string', ['notnull' => true, 'length' => 12]);
            $t->addColumn('matched_journal_id', 'integer', ['notnull' => false]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'bank_account_id', 'external_id'], 'lfb_sp_acc_ext');
            $t->addUniqueIndex(['space_id', 'bank_account_id', 'matched_journal_id'], 'lfb_sp_acc_mat');
            $t->addIndex(['space_id', 'id'], 'lfb_sp_id');
        }
        if (!$schema->hasTable('lfb_audit')) {
            $t = $schema->createTable('lfb_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('transaction_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 48]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'transaction_id'], 'lfba_sp_tx');
        }

        // Consolidated development schema: Version000002Date20261121170000.php
        if (!$schema->hasTable('lfb_transfers')) {
            $t = $schema->createTable('lfb_transfers');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('external_id', 'string', ['notnull' => true, 'length' => 128]);
            $t->addColumn('from_account_id', 'integer', ['notnull' => true]);
            $t->addColumn('to_account_id', 'integer', ['notnull' => true]);
            $t->addColumn('booking_date', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('description', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('status', 'string', ['notnull' => true, 'length' => 24]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'external_id'], 'lfbt_space_external');
            $t->addIndex(['space_id', 'id'], 'lfbt_space_id');
            $t->addIndex(['space_id', 'from_account_id'], 'lfbt_space_from');
            $t->addIndex(['space_id', 'to_account_id'], 'lfbt_space_to');
        }
        if (!$schema->hasTable('lfb_transfer_audit')) {
            $t = $schema->createTable('lfb_transfer_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('transfer_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 48]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'transfer_id'], 'lfbta_space_transfer');
        }

        // Financial invoice obligations share one authoritative payment command.
        // Historical standalone invoices and obligations are never guessed,
        // overwritten or auto-matched during an upgrade.
        if (!$schema->hasTable('lf_invoice_obligation_links')) {
            $t = $schema->createTable('lf_invoice_obligation_links');
            $t->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('invoice_id', 'integer', ['notnull' => true]);
            $t->addColumn('obligation_id', 'integer', ['notnull' => true]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'invoice_id'], 'lfio_sp_invoice');
            $t->addUniqueIndex(['space_id', 'obligation_id'], 'lfio_sp_obligation');
        }
        if ($schema->hasTable('lfob_settlements')) {
            $t = $schema->getTable('lfob_settlements');
            if (!$t->hasColumn('journal_id')) {
                $t->addColumn('journal_id', 'integer', ['notnull' => false]);
            }
            if (!$t->hasIndex('lfobs_sp_journal')) {
                $t->addUniqueIndex(['space_id', 'journal_id'], 'lfobs_sp_journal');
            }
        }

        // invoicing: preserve the original table and index names.
// Consolidated development schema: Version000001Date20261120210000.php
        if (!$schema->hasTable('lfi_tax_rules')) {
            $t=$schema->createTable('lfi_tax_rules');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('code','string',['notnull'=>true,'length'=>40]);
            $t->addColumn('label','string',['notnull'=>true,'length'=>150]);
            $t->addColumn('country','string',['notnull'=>true,'length'=>2]);
            $t->addColumn('region','string',['notnull'=>false,'length'=>32]);
            $t->addColumn('kind','string',['notnull'=>true,'length'=>16]);
            $t->addColumn('rate_bps','integer',['notnull'=>true]);
            $t->addColumn('inclusive','boolean',['notnull'=>true,'default'=>false]);
            $t->addColumn('valid_from','string',['notnull'=>true,'length'=>10]);
            $t->addColumn('valid_through','string',['notnull'=>false,'length'=>10]);
            $t->addColumn('source_ref','string',['notnull'=>true,'length'=>255]);
            $t->addColumn('created_by','string',['notnull'=>true,'length'=>64]);
            $t->addColumn('created_at','integer',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id','code','valid_from'],'lfi_tax_revision');
            $t->addIndex(['space_id','id'],'lfi_tax_spid');
        }
        if (!$schema->hasTable('lfi_invoices')) {
            $t=$schema->createTable('lfi_invoices');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('number','string',['notnull'=>true,'length'=>80]);
            $t->addColumn('kind','string',['notnull'=>true,'length'=>12]);
            $t->addColumn('status','string',['notnull'=>true,'length'=>20]);
            $t->addColumn('contact_id','integer',['notnull'=>false]);
            $t->addColumn('currency','string',['notnull'=>true,'length'=>3]);
            $t->addColumn('issue_on','string',['notnull'=>true,'length'=>10]);
            $t->addColumn('due_on','string',['notnull'=>true,'length'=>10]);
            $t->addColumn('subtotal_minor','bigint',['notnull'=>true]);
            $t->addColumn('tax_minor','bigint',['notnull'=>true]);
            $t->addColumn('withheld_minor','bigint',['notnull'=>true]);
            $t->addColumn('total_minor','bigint',['notnull'=>true]);
            $t->addColumn('paid_minor','bigint',['notnull'=>true,'default'=>0]);
            $t->addColumn('version','integer',['notnull'=>true,'default'=>0]);
            $t->addColumn('created_by','string',['notnull'=>true,'length'=>64]);
            $t->addColumn('created_at','integer',['notnull'=>true]);
            $t->addColumn('issued_at','integer',['notnull'=>false]);

            $t->addColumn('country', 'string', ['notnull' => false, 'length' => 2]);
            $t->addColumn('region', 'string', ['notnull' => false, 'length' => 32]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id','kind','number'],'lfi_invoice_ref');
            $t->addIndex(['space_id','id'],'lfi_invoice_spid');
            $t->addIndex(['space_id','status','due_on'],'lfi_invoice_due');
        }
        if (!$schema->hasTable('lfi_invoice_lines')) {
            $t=$schema->createTable('lfi_invoice_lines');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('invoice_id','integer',['notnull'=>true]);
            $t->addColumn('line_no','integer',['notnull'=>true]);
            $t->addColumn('description','string',['notnull'=>true,'length'=>255]);
            $t->addColumn('quantity','integer',['notnull'=>true]);
            $t->addColumn('unit_price_minor','bigint',['notnull'=>true]);
            $t->addColumn('tax_rule_id','integer',['notnull'=>false]);
            $t->addColumn('tax_snapshot','text',['notnull'=>false]);
            $t->addColumn('base_minor','bigint',['notnull'=>true]);
            $t->addColumn('tax_minor','bigint',['notnull'=>true]);
            $t->addColumn('withheld_minor','bigint',['notnull'=>true]);
            $t->addColumn('total_minor','bigint',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id','invoice_id','line_no'],'lfi_inv_line_order');
            $t->addIndex(['space_id','invoice_id'],'lfi_inv_line_lookup');
        }
        if (!$schema->hasTable('lfi_allocations')) {
            $t=$schema->createTable('lfi_allocations');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('invoice_id','integer',['notnull'=>true]);
            $t->addColumn('bank_account_id','integer',['notnull'=>true]);
            $t->addColumn('journal_id','integer',['notnull'=>true]);
            $t->addColumn('amount_minor','bigint',['notnull'=>true]);
            $t->addColumn('created_by','string',['notnull'=>true,'length'=>64]);
            $t->addColumn('created_at','integer',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id','bank_account_id','journal_id'],'lfi_alloc_journal');
            $t->addIndex(['space_id','invoice_id'],'lfi_alloc_invoice');
        }
        if (!$schema->hasTable('lfi_audit')) {
            $t=$schema->createTable('lfi_audit');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('invoice_id','integer',['notnull'=>true]);
            $t->addColumn('action','string',['notnull'=>true,'length'=>50]);
            $t->addColumn('actor_uid','string',['notnull'=>true,'length'=>64]);
            $t->addColumn('occurred_at','integer',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id','invoice_id'],'lfi_audit_scope');
        }

        // Consolidated development schema: Version000002Date20261120224000.php
        if(!$schema->hasTable('lfi_tax_series')){
            $t=$schema->createTable('lfi_tax_series');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('code','string',['notnull'=>true,'length'=>40]);
            $t->addColumn('lock_version','integer',['notnull'=>true,'default'=>0]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id','code'],'lfi_tax_series_key');
        }

        // Consolidated development schema: Version000003Date20261120224500.php
        if ($schema->hasTable('lfi_invoices')) {
            $t=$schema->getTable('lfi_invoices');
            if (!$t->hasColumn('country')) {
                $t->addColumn('country', 'string', ['notnull'=>false, 'length'=>2]);
            }
            if (!$t->hasColumn('region')) {
                $t->addColumn('region', 'string', ['notnull'=>false, 'length'=>32]);
            }
        }
        return $schema;
    }
}
