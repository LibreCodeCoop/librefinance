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
$tables = ['lf_spaces','lf_members','lf_group_grants','lf_accounts','lf_journals',
    'lf_postings','lf_journal_claims','lf_audit','lf_companies'];
foreach ($tables as $table) {
    $qb = $db->getQueryBuilder();
    $result = $qb->select('*')->from($table)->orderBy('id','ASC')->executeQuery();
    while (($row = $result->fetchAssociative()) !== false) {
        ksort($row,SORT_STRING);
        echo $table, '|', json_encode($row,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
    $result->closeCursor();
}
if (getenv('LF_UPGRADE_PHASE') === 'after') {
    foreach (['lf_environment_spaces','lf_saas_company_workspaces','lf_environments'] as $table) {
        $qb = $db->getQueryBuilder();
        $count = $qb->selectAlias($qb->func()->count('*'),'count')->from($table)->executeQuery()->fetchOne();
        if ((int)$count !== 0) throw new RuntimeException('Upgrade inferred new SaaS ownership');
    }
}
