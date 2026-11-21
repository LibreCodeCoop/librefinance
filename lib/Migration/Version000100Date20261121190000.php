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
 * Pre-release consolidated baseline. Creates the complete schema on a fresh
 * Nextcloud installation and safely completes known development schemas.
 *
 * Older migration files are intentionally superseded; existing financial
 * records are never dropped, truncated or replaced.
 */
final class Version000100Date20261121190000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        // Consolidated development schema: Version000001Date20261120140000.php
        if (!$schema->hasTable('lf_spaces')) {
            $t = $schema->createTable('lf_spaces');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 24]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('owner_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('archived', 'boolean', ['notnull' => true, 'default' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['owner_uid'], 'lf_sp_owner');
        }

        if (!$schema->hasTable('lf_members')) {
            $t = $schema->createTable('lf_members');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('role', 'string', ['notnull' => true, 'length' => 24]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'uid'], 'lf_m_sp_uid');
            $t->addIndex(['uid'], 'lf_m_uid');
        }

        if (!$schema->hasTable('lf_group_grants')) {
            $t = $schema->createTable('lf_group_grants');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('group_gid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('role', 'string', ['notnull' => true, 'length' => 24]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'group_gid'], 'lf_gg_sp_gid');
            $t->addIndex(['group_gid'], 'lf_gg_gid');
        }

        if (!$schema->hasTable('lf_accounts')) {
            $t = $schema->createTable('lf_accounts');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('code', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('class', 'string', ['notnull' => true, 'length' => 24]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('is_bank', 'boolean', ['notnull' => true, 'default' => false]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'code'], 'lf_a_sp_code');
            $t->addIndex(['space_id', 'class'], 'lf_a_sp_class');
        }

        if (!$schema->hasTable('lf_journals')) {
            $t = $schema->createTable('lf_journals');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('effective_on', 'string', ['notnull' => true, 'length' => 10]);
            $t->addColumn('memo', 'string', ['notnull' => true, 'length' => 255]);
            $t->addColumn('external_key', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('request_hash', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('status', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('reversed_journal_id', 'integer', ['notnull' => false]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);

            $t->addColumn('category_id', 'integer', ['notnull' => false]);
            $t->addColumn('notes_md', 'text', ['notnull' => false]);
            $t->addColumn('payment_method_id', 'integer', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'external_key'], 'lf_j_sp_key');
            $t->addIndex(['space_id', 'id'], 'lf_j_sp_id');
            $t->addIndex(['space_id', 'effective_on'], 'lf_j_sp_date');

            $t->addIndex(['space_id', 'payment_method_id'], 'lf_j_sp_payment');}

        if (!$schema->hasTable('lf_postings')) {
            $t = $schema->createTable('lf_postings');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('journal_id', 'integer', ['notnull' => true]);
            $t->addColumn('account_id', 'integer', ['notnull' => true]);
            $t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'account_id'], 'lf_p_sp_acc');
            $t->addIndex(['journal_id'], 'lf_p_journal');
        }

        if (!$schema->hasTable('lf_audit')) {
            $t = $schema->createTable('lf_audit');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('action', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('entity', 'string', ['notnull' => true, 'length' => 48]);
            $t->addColumn('entity_id', 'integer', ['notnull' => true]);
            $t->addColumn('occurred_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'id'], 'lf_au_sp_id');
        }

        // Consolidated development schema: Version000002Date20261120170000.php
        if (!$schema->hasTable('lf_contacts')) {
            $t = $schema->createTable('lf_contacts');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 160]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('email', 'string', ['notnull' => false, 'length' => 190]);
            $t->addColumn('tax_id', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id', 'id'], 'lf_c_sp_id');
            $t->addIndex(['space_id', 'kind'], 'lf_c_sp_kind');
        }

        if (!$schema->hasTable('lf_items')) {
            $t = $schema->createTable('lf_items');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('sku', 'string', ['notnull' => true, 'length' => 48]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 160]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('description', 'text', ['notnull' => false]);
            $t->addColumn('unit_price_minor', 'bigint', ['notnull' => true]);
            $t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'sku'], 'lf_i_sp_sku');
            $t->addIndex(['space_id', 'id'], 'lf_i_sp_id');
        }

        // Consolidated development schema: Version000003Date20261120180000.php
        if (!$schema->hasTable('lf_module_flags')) {
            $t = $schema->createTable('lf_module_flags');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('module_id', 'string', ['notnull' => true, 'length' => 64]);
            $t->addColumn('enabled', 'boolean', ['notnull' => true, 'default' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'module_id'], 'lf_m_space_id');
        }

        // Consolidated development schema: Version000004Date20261120200000.php
        if (!$schema->hasTable('lf_categories')) {
            $t = $schema->createTable('lf_categories');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('code', 'string', ['notnull' => true, 'length' => 40]);
            $t->addColumn('name', 'string', ['notnull' => true, 'length' => 150]);
            $t->addColumn('kind', 'string', ['notnull' => true, 'length' => 12]);
            $t->addColumn('parent_id', 'integer', ['notnull' => false]);
            $t->addColumn('account_id', 'integer', ['notnull' => false]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);

            $t->addColumn('color', 'string', ['notnull' => true, 'length' => 7, 'default' => '#64748B']);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'code'], 'lf_cat_space_code');
            $t->addIndex(['space_id', 'parent_id'], 'lf_cat_space_parent');
            $t->addIndex(['space_id', 'id'], 'lf_cat_space_id');
        }

        // Consolidated development schema: Version000005Date20261120223000.php
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

        // Consolidated development schema: Version000006Date20261120225000.php
        if ($schema->hasTable('lf_journals')) {
            $table = $schema->getTable('lf_journals');
            if (!$table->hasColumn('category_id')) {
                $table->addColumn('category_id', 'integer', ['notnull' => false]);
            }
        }

        // Consolidated development schema: Version000007Date20261121100000.php
        if (!$schema->hasTable('lf_companies')) {
            $t = $schema->createTable('lf_companies');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('space_id', 'integer', ['notnull' => true]);
            $t->addColumn('legal_name', 'string', ['notnull' => true, 'length' => 180]);
            $t->addColumn('trade_name', 'string', ['notnull' => false, 'length' => 180]);
            $t->addColumn('country', 'string', ['notnull' => true, 'length' => 2]);
            $t->addColumn('tax_id', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('municipal_registration', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('state_registration', 'string', ['notnull' => false, 'length' => 40]);
            $t->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);

            $t->addColumn('logo_version', 'integer', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['space_id', 'country', 'tax_id'], 'lfc_sp_ct_tax');
            $t->addIndex(['space_id', 'id'], 'lfc_sp_id');
        }

        // Consolidated development schema: Version000008Date20261121132000.php
        if ($schema->hasTable('lf_companies')) {
            $table = $schema->getTable('lf_companies');
            if ($table->hasColumn('tax_id')) {
                $table->changeColumn('tax_id', ['notnull' => false]);
            }
        }
        if (!$schema->hasTable('lf_custom_fields')) {
            $table = $schema->createTable('lf_custom_fields');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('entity_kind', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('field_key', 'string', ['notnull' => true, 'length' => 40]);
            $table->addColumn('label', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('field_type', 'string', ['notnull' => true, 'length' => 16]);
            $table->addColumn('required', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $table->addColumn('options_json', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->addColumn('updated_at', 'integer', ['notnull' => true]);

            $table->addColumn('visible', 'boolean', ['notnull' => true, 'default' => true]);
            $table->addColumn('help_text', 'string', ['notnull' => false, 'length' => 255]);
            $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('column_span', 'integer', ['notnull' => true, 'default' => 12]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'entity_kind', 'field_key'], 'lf_cf_key_unique');
            $table->addIndex(['space_id', 'entity_kind', 'id'], 'lf_cf_list');
        }
        if (!$schema->hasTable('lf_custom_values')) {
            $table = $schema->createTable('lf_custom_values');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('space_id', 'integer', ['notnull' => true]);
            $table->addColumn('entity_kind', 'string', ['notnull' => true, 'length' => 24]);
            $table->addColumn('entity_id', 'integer', ['notnull' => true]);
            $table->addColumn('field_id', 'integer', ['notnull' => true]);
            $table->addColumn('value_json', 'text', ['notnull' => true]);
            $table->addColumn('updated_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_id', 'entity_kind', 'entity_id', 'field_id'], 'lf_cv_unique');
            $table->addIndex(['space_id', 'entity_kind', 'entity_id'], 'lf_cv_list');
        }

        // Consolidated development schema: Version000009Date20261121163000.php
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

        // Consolidated development schema: Version000010Date20261121193000.php
        if ($schema->hasTable('lf_companies')) {
            $table=$schema->getTable('lf_companies');
            if (!$table->hasColumn('logo_version')) {
                $table->addColumn('logo_version','integer',['notnull'=>false]);
            }
        }

        // Consolidated development schema: Version000011Date20261121200000.php
        if (!$schema->hasTable('lf_payment_methods')) {
            $t=$schema->createTable('lf_payment_methods');
            $t->addColumn('id','integer',['notnull'=>true,'autoincrement'=>true]);
            $t->addColumn('space_id','integer',['notnull'=>true]);
            $t->addColumn('name','string',['notnull'=>true,'length'=>120]);
            $t->addColumn('kind','string',['notnull'=>true,'length'=>32]);
            $t->addColumn('bank_account_id','integer',['notnull'=>false]);
            $t->addColumn('active','boolean',['notnull'=>true,'default'=>true]);
            $t->addColumn('created_at','integer',['notnull'=>true]);
            $t->addColumn('updated_at','integer',['notnull'=>true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['space_id','id'],'lfpm_sp_id');
            $t->addIndex(['space_id','bank_account_id'],'lfpm_bank');
        }
        if ($schema->hasTable('lf_journals')) {
            $t=$schema->getTable('lf_journals');
            if (!$t->hasColumn('payment_method_id')) {
                $t->addColumn('payment_method_id','integer',['notnull'=>false]);
                $t->addIndex(['space_id','payment_method_id'],'lf_j_sp_payment');
            }
        }

        return $schema;
    }
}
