<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (getenv('LF_SYNTHETIC_SAAS_EXTERNAL_SCHEMA') !== 'yes') {
    throw new RuntimeException('External schema test requires disposable lab opt-in');
}
require '/var/www/html/config/config.php';
if (!isset($CONFIG) || !is_array($CONFIG)) {
    throw new RuntimeException('Missing disposable Nextcloud configuration');
}
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Invalid database prefix');
}
$type = (string)($CONFIG['dbtype'] ?? '');
if ($type === 'sqlite3') {
    $db = new PDO('sqlite:' . (string)$CONFIG['datadirectory'] . '/'
        . (string)$CONFIG['dbname'] . '.db');
} elseif (in_array($type, ['pgsql', 'mysql'], true)) {
    $db = new PDO(($type === 'mysql' ? 'mysql' : 'pgsql') . ':host='
        . (string)$CONFIG['dbhost'] . ';dbname=' . (string)$CONFIG['dbname'],
        (string)$CONFIG['dbuser'], (string)$CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported synthetic database');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$grant = $prefix . 'lf_saas_external_grants';
$audit = $prefix . 'lf_saas_external_audit';
$db->query('SELECT id, space_id, recipient_uid, role, status, expires_at, revision, '
    . 'created_by, created_at, accepted_at, revoked_at, updated_at FROM ' . $grant . ' WHERE 1=0');
$db->query('SELECT id, grant_id, space_id, actor_uid, action, previous_status, '
    . 'new_status, occurred_at FROM ' . $audit . ' WHERE 1=0');
if ((int)$db->query('SELECT COUNT(*) FROM ' . $grant)->fetchColumn() !== 0
    || (int)$db->query('SELECT COUNT(*) FROM ' . $audit)->fetchColumn() !== 0) {
    throw new RuntimeException('External policy migration must never infer grants from legacy data');
}
$now = time();
$db->beginTransaction();
try {
    $statement = 'INSERT INTO ' . $grant . ' (space_id,recipient_uid,role,status,'
        . 'expires_at,revision,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)';
    $values = [900001, 'synthetic', 'viewer', 'invited', $now + 3600, 1, 'synthetic', $now, $now];
    $db->prepare($statement)->execute($values);
    $db->exec('SAVEPOINT lf_external_unique');
    try {
        $db->prepare($statement)->execute($values);
        throw new RuntimeException('Expected external grant uniqueness to fail');
    } catch (PDOException $e) {
        if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
            throw $e;
        }
    } finally {
        $db->exec('ROLLBACK TO SAVEPOINT lf_external_unique');
        $db->exec('RELEASE SAVEPOINT lf_external_unique');
    }
    $db->prepare($statement)->execute([900002, 'synthetic', 'auditor',
        'invited', $now + 3600, 1, 'synthetic', $now, $now]);
    $db->prepare('INSERT INTO ' . $audit
        . ' (grant_id,space_id,actor_uid,action,new_status,occurred_at) VALUES (?,?,?,?,?,?)')
        ->execute([1, 900001, 'synthetic', 'invited', 'invited', $now]);
} finally {
    $db->rollBack();
}
if ((int)$db->query('SELECT COUNT(*) FROM ' . $grant)->fetchColumn() !== 0
    || (int)$db->query('SELECT COUNT(*) FROM ' . $audit)->fetchColumn() !== 0) {
    throw new RuntimeException('Disposable external schema fixture did not roll back');
}
echo "PASS: external grant/audit schema, uniqueness and rollback on $type\n";
