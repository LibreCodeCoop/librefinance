<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Read-only comparison of every historical row in the disposable fixture DB.
if (getenv('LF_SYNTHETIC_SAAS_UPGRADE') !== 'yes') {
    throw new RuntimeException('Synthetic upgrade opt-in required');
}
require_once '/var/www/html/lib/base.php';
$db = \OC::$server->get(\OCP\IDBConnection::class);
$coreTables = ['lf_spaces', 'lf_members', 'lf_group_grants', 'lf_accounts', 'lf_journals',
    'lf_postings', 'lf_audit', 'lf_companies'];
// Require the populated fixture on both sides so an empty snapshot cannot pass by equality.
$expectedCounts = [
    'lf_journal_claims' => 2,
    'lf_contacts' => 1,
    'lfb_transactions' => 2,
    'lfb_audit' => 3,
    'lfi_tax_series' => 1,
    'lfi_tax_rules' => 1,
    'lfi_invoices' => 1,
    'lfi_invoice_lines' => 2,
    'lfi_allocations' => 1,
    'lfi_audit' => 3,
    'lf_invoice_obligation_links' => 1,
    'lfob_obligations' => 1,
    'lfob_settlements' => 1,
    'lfob_audit' => 2,
    'lfr_saved_reports' => 2,
];
$tables = [...$coreTables, ...array_keys($expectedCounts)];
foreach ($tables as $table) {
    $qb = $db->getQueryBuilder();
    $result = $qb->select('*')->from($table)->orderBy('id','ASC')->executeQuery();
    $rowCount = 0;
    while (($row = $result->fetchAssociative()) !== false) {
        ++$rowCount;
        ksort($row,SORT_STRING);
        echo $table, '|', json_encode($row,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
    $result->closeCursor();
    if (isset($expectedCounts[$table]) && $rowCount !== $expectedCounts[$table]) {
        throw new RuntimeException("Expected {$expectedCounts[$table]} synthetic rows in $table, got $rowCount");
    }
}
if (getenv('LF_UPGRADE_PHASE') === 'after') {
    foreach (['lf_environment_spaces','lf_saas_company_workspaces','lf_environments'] as $table) {
        $qb = $db->getQueryBuilder();
        $count = $qb->selectAlias($qb->func()->count('*'),'count')->from($table)->executeQuery()->fetchOne();
        if ((int)$count !== 0) throw new RuntimeException('Upgrade inferred new SaaS ownership');
    }
}
