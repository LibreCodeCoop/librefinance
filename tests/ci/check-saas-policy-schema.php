<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (getenv('LF_SYNTHETIC_SAAS_POLICY_SCHEMA') !== 'yes') {
    throw new RuntimeException('Disposable policy schema check opt-in required');
}
require '/var/www/html/config/config.php';
if (!isset($CONFIG) || !is_array($CONFIG)) {
    throw new RuntimeException('Missing synthetic Nextcloud database configuration');
}
$type = (string)($CONFIG['dbtype'] ?? '');
$name = (string)($CONFIG['dbname'] ?? '');
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Unsafe database prefix');
}
if ($type === 'sqlite3') {
    $db = new PDO('sqlite:' . (string)$CONFIG['datadirectory'] . '/' . $name . '.db');
} elseif (in_array($type, ['pgsql', 'mysql'], true)) {
    $driver = $type === 'mysql' ? 'mysql' : 'pgsql';
    $db = new PDO($driver . ':host=' . (string)$CONFIG['dbhost'] . ';dbname=' . $name,
        (string)$CONFIG['dbuser'], (string)$CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported disposable DB engine');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = [
    'lf_saas_policy_values' => ['id', 'scope_key', 'scope_type', 'policy_key', 'value_json',
        'is_cleared', 'revision', 'updated_by', 'updated_at'],
    'lf_saas_policy_audit' => ['id', 'policy_id', 'scope_key', 'policy_key', 'actor_uid',
        'action', 'old_value_json', 'new_value_json', 'previous_revision', 'new_revision', 'occurred_at'],
];
foreach ($tables as $table => $cols) {
    $db->query('SELECT ' . implode(', ', $cols) . ' FROM ' . $prefix . $table . ' WHERE 1 = 0');
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $prefix . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Unreviewed legacy policy settings must never be backfilled');
    }
}
$table = $prefix . 'lf_saas_policy_values';
$db->beginTransaction();
try {
    $now = time();
    $statement = $db->prepare('INSERT INTO ' . $table
        . ' (scope_key,scope_type,policy_key,value_json,is_cleared,revision,updated_by,updated_at)'
        . ' VALUES (?,?,?,?,?,?,?,?)');
    $args = ['instance', 'instance', 'imports.enabled', 'true', false, 1, 'synthetic', $now];
    $statement->execute($args);
    $db->exec('SAVEPOINT lf_policy_unique');
    try {
        $statement->execute($args);
        throw new RuntimeException('Policy unique index did not enforce one effective record');
    } catch (PDOException $e) {
        if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
            throw $e;
        }
    } finally {
        $db->exec('ROLLBACK TO SAVEPOINT lf_policy_unique');
        $db->exec('RELEASE SAVEPOINT lf_policy_unique');
    }
} finally {
    $db->rollBack();
}
foreach (array_keys($tables) as $table) {
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $prefix . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Synthetic policy schema check left data');
    }
}
echo "PASS: portable SaaS policy value/audit tables, uniqueness, empty upgrade and rollback\n";
