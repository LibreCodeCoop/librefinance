<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Never invoke on production or shared Nextcloud. CI explicitly opts in.
if (getenv('LF_SYNTHETIC_SAAS_SCHEMA_CHECK') !== 'yes') {
    throw new RuntimeException('Disposable SaaS database test opt-in required');
}
require '/var/www/html/config/config.php';
if (!isset($CONFIG) || !is_array($CONFIG)) {
    throw new RuntimeException('Missing synthetic Nextcloud DB config');
}
$type = (string)($CONFIG['dbtype'] ?? '');
$name = (string)($CONFIG['dbname'] ?? '');
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Unsafe DB table prefix');
}
if ($type === 'sqlite3') {
    $db = new PDO('sqlite:' . (string)$CONFIG['datadirectory'] . '/' . $name . '.db');
} elseif (in_array($type, ['pgsql', 'mysql'], true)) {
    $driver = $type === 'mysql' ? 'mysql' : 'pgsql';
    $db = new PDO($driver . ':host=' . (string)$CONFIG['dbhost'] . ';dbname=' . $name,
        (string)$CONFIG['dbuser'], (string)$CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported synthetic database engine');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = [
    'lf_environments' => ['id', 'public_id', 'name', 'status', 'revision', 'created_at', 'updated_at'],
    'lf_environment_groups' => ['id', 'environment_id', 'group_gid', 'state', 'created_at', 'updated_at'],
    'lf_environment_spaces' => ['id', 'environment_id', 'space_id', 'review_state', 'created_at'],
    'lf_environment_audit' => ['id', 'environment_id', 'actor_uid', 'action', 'subject_type', 'subject_id', 'occurred_at'],
];
foreach ($tables as $table => $cols) {
    $db->query('SELECT ' . implode(', ', $cols) . ' FROM ' . $prefix . $table . ' WHERE 1 = 0');
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $prefix . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Legacy upgrade must not invent environment ownership: ' . $table);
    }
}
$environments = $prefix . 'lf_environments';
$groups = $prefix . 'lf_environment_groups';
$spaces = $prefix . 'lf_environment_spaces';
$now = time();
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO ' . $environments . ' (public_id,name,status,revision,created_at,updated_at) VALUES (?,?,?,?,?,?)')
        ->execute(['123e4567-e89b-42d3-a456-426614174000', 'Synthetic environment', 'active', 1, $now, $now]);
    $environmentId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO ' . $groups . ' (environment_id,group_gid,state,created_at,updated_at) VALUES (?,?,?,?,?)')
        ->execute([$environmentId, 'lf_synthetic_group_a', 'active', $now, $now]);
    $db->prepare('INSERT INTO ' . $spaces . ' (environment_id,space_id,review_state,created_at) VALUES (?,?,?,?)')
        ->execute([$environmentId, 900001, 'confirmed', $now]);

    $duplicates = [
        [$environments, '(public_id,name,status,revision,created_at,updated_at)',
            ['123e4567-e89b-42d3-a456-426614174000', 'Duplicate', 'active', 1, $now, $now]],
        [$groups, '(environment_id,group_gid,state,created_at,updated_at)',
            [$environmentId + 1, 'lf_synthetic_group_a', 'active', $now, $now]],
        [$spaces, '(environment_id,space_id,review_state,created_at)',
            [$environmentId + 1, 900001, 'confirmed', $now]],
    ];
    foreach ($duplicates as [$table, $columnSpec, $values]) {
        $db->exec('SAVEPOINT lf_unique_case');
        try {
            $placeholders = '(' . implode(',', array_fill(0, count($values), '?')) . ')';
            $db->prepare('INSERT INTO ' . $table . ' ' . $columnSpec . ' VALUES ' . $placeholders)
                ->execute($values);
            throw new RuntimeException('Missing unique constraint on ' . $table);
        } catch (PDOException $e) {
            if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
        } finally {
            $db->exec('ROLLBACK TO SAVEPOINT lf_unique_case');
            $db->exec('RELEASE SAVEPOINT lf_unique_case');
        }
    }
} finally {
    $db->rollBack();
}
foreach (array_keys($tables) as $table) {
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $prefix . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Synthetic test left persisted data in ' . $table);
    }
}
echo "PASS: environment schema, unique environment/group/ledger constraints, and rollback\n";
