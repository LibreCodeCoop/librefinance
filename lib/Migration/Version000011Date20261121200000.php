<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Payment methods describe how money moved, not a bank/feed gateway connection. */
final class Version000011Date20261121200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
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
