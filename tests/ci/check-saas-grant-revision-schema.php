<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (getenv('LF_SYNTHETIC_SAAS_GRANT_SCHEMA') !== 'yes') {
    throw new RuntimeException('Grant revision schema requires a disposable lab opt-in');
}
require '/var/www/html/config/config.php';
if (!isset($CONFIG) || !is_array($CONFIG)) {
    throw new RuntimeException('Synthetic Nextcloud configuration missing');
}
$type = (string)($CONFIG['dbtype'] ?? '');
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Invalid database table prefix');
}
if ($type === 'sqlite3') {
    $db = new PDO('sqlite:' . (string)$CONFIG['datadirectory'] . '/'
        . (string)$CONFIG['dbname'] . '.db');
} elseif (in_array($type, ['pgsql', 'mysql'], true)) {
    $db = new PDO(($type === 'mysql' ? 'mysql' : 'pgsql') . ':host='
        . (string)$CONFIG['dbhost'] . ';dbname=' . (string)$CONFIG['dbname'],
        (string)$CONFIG['dbuser'], (string)$CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported disposable database engine');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$table = $prefix . 'lf_environment_spaces';
$db->query('SELECT space_id, grants_revision FROM ' . $table . ' WHERE 1=0');
if ((int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
    throw new RuntimeException('Grant CAS fixture requires a clean disposable install');
}
$db->beginTransaction();
try {
    // The new non-null column must default to zero on all supported engines.
    $db->prepare('INSERT INTO ' . $table
        . ' (environment_id,space_id,review_state,created_at) VALUES (?,?,?,?)')
        ->execute([700001, 900001, 'confirmed', time()]);
    $read = $db->prepare('SELECT grants_revision FROM ' . $table . ' WHERE space_id=?');
    $read->execute([900001]);
    if ((int)$read->fetchColumn() !== 0) {
        throw new RuntimeException('New SaaS workspace grant revision must start at zero');
    }
    $claim = 'UPDATE ' . $table
        . ' SET grants_revision=grants_revision+1 WHERE space_id=? AND grants_revision=?';
    if ($db->prepare($claim)->execute([900001, 0]) !== true) {
        throw new RuntimeException('Cannot claim synthetic grant revision');
    }
    $read->execute([900001]);
    if ((int)$read->fetchColumn() !== 1) {
        throw new RuntimeException('Atomic grant revision did not increment');
    }
    $stale = $db->prepare($claim);
    $stale->execute([900001, 0]);
    if ($stale->rowCount() !== 0) {
        throw new RuntimeException('A stale grant writer unexpectedly passed CAS');
    }
} finally {
    $db->rollBack();
}
if ((int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
    throw new RuntimeException('Synthetic CAS fixture leaked any environment data');
}
echo "PASS: SaaS member grant revision default, CAS and rollback on $type\n";
