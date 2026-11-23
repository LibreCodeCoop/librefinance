<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (getenv('LF_SYNTHETIC_SAAS_WORKSPACE_SCHEMA') !== 'yes') {
    throw new RuntimeException('Disposable SaaS schema check requires explicit opt-in');
}
require '/var/www/html/config/config.php';
if (!isset($CONFIG) || !is_array($CONFIG)) {
    throw new RuntimeException('Synthetic Nextcloud database configuration unavailable');
}
$type = (string)($CONFIG['dbtype'] ?? '');
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Unsafe database prefix');
}
if ($type === 'sqlite3') {
    $db = new PDO('sqlite:' . (string)$CONFIG['datadirectory'] . '/'
        . (string)$CONFIG['dbname'] . '.db');
} elseif (in_array($type, ['pgsql', 'mysql'], true)) {
    $db = new PDO(($type === 'mysql' ? 'mysql' : 'pgsql') . ':host='
        . (string)$CONFIG['dbhost'] . ';dbname=' . (string)$CONFIG['dbname'],
        (string)$CONFIG['dbuser'], (string)$CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported disposable DB engine');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = [
    'lf_saas_company_workspaces' => [
        'id', 'space_id', 'company_id', 'linked_by', 'linked_at',
    ],
    'lf_saas_space_claims' => [
        'id', 'environment_id', 'idempotency_key', 'request_hash',
        'space_id', 'created_by', 'created_at',
    ],
];
foreach ($tables as $table => $columns) {
    $db->query('SELECT ' . implode(', ', $columns) . ' FROM ' . $prefix . $table . ' WHERE 1=0');
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $prefix . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Existing financial data must not be inferred into ' . $table);
    }
}
$company = $prefix . 'lf_saas_company_workspaces';
$claims = $prefix . 'lf_saas_space_claims';
$now = time();
$db->beginTransaction();
try {
    $addCompany = $db->prepare('INSERT INTO ' . $company
        . ' (space_id,company_id,linked_by,linked_at) VALUES (?,?,?,?)');
    $addCompany->execute([900001, 800001, 'synthetic-actor', $now]);
    $addClaim = $db->prepare('INSERT INTO ' . $claims
        . ' (environment_id,idempotency_key,request_hash,space_id,created_by,created_at)'
        . ' VALUES (?,?,?,?,?,?)');
    $addClaim->execute([700001, 'synthetic-key-0001', str_repeat('a', 64),
        900001, 'synthetic-actor', $now]);

    foreach ([
        [$addCompany, [900001, 800002, 'synthetic-actor', $now]],
        [$addCompany, [900002, 800001, 'synthetic-actor', $now]],
        [$addClaim, [700001, 'synthetic-key-0001', str_repeat('a', 64),
            900002, 'synthetic-actor', $now]],
        [$addClaim, [700002, 'synthetic-key-0002', str_repeat('a', 64),
            900001, 'synthetic-actor', $now]],
    ] as [$statement, $values]) {
        $db->exec('SAVEPOINT lf_saas_unique_case');
        try {
            $statement->execute($values);
            throw new RuntimeException('Missing unique constraint on SaaS company/ledger');
        } catch (PDOException $e) {
            if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
        } finally {
            $db->exec('ROLLBACK TO SAVEPOINT lf_saas_unique_case');
            $db->exec('RELEASE SAVEPOINT lf_saas_unique_case');
        }
    }
} finally {
    $db->rollBack();
}
foreach (array_keys($tables) as $table) {
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $prefix . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Synthetic SaaS schema fixture leaked records');
    }
}
echo "PASS: SaaS company/ledger constraints, idempotency and rollback\n";
