<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Only for disposable upgrade CI: read the isolated Nextcloud DB directly.
// Never run on a shared Nextcloud instance or with production credentials.
require '/var/www/html/config/config.php';
if (!isset($CONFIG) || !is_array($CONFIG)) {
    throw new RuntimeException('Missing disposable Nextcloud configuration');
}
$spaceId = filter_var(getenv('LF_UPGRADE_SPACE_ID'), FILTER_VALIDATE_INT);
if (!is_int($spaceId) || $spaceId < 1) {
    throw new RuntimeException('Missing synthetic upgrade space ID');
}
$dbType = $CONFIG['dbtype'] ?? '';
$dbName = (string)($CONFIG['dbname'] ?? 'nextcloud');
if ($dbType === 'sqlite3') {
    $dataDirectory = (string)($CONFIG['datadirectory'] ?? '/var/www/html/data');
    $dsn = 'sqlite:' . $dataDirectory . '/' . $dbName . '.db';
    $db = new PDO($dsn);
} elseif (in_array($dbType, ['pgsql', 'mysql'], true)) {
    $driver = $dbType === 'mysql' ? 'mysql' : 'pgsql';
    $dsn = $driver . ':host=' . $CONFIG['dbhost'] . ';dbname=' . $dbName;
    $db = new PDO($dsn, (string)$CONFIG['dbuser'], (string)$CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported disposable database driver');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Invalid synthetic table prefix');
}
$tables = [
    ['mapping', 'lfm_records', '*', false],
    ['audit', 'lfm_audit', '*', false],
    ['balance', 'lfm_liquid_origins', '*', false],
    ['contact', 'lf_contacts', 'id,space_id,name,kind,email,tax_id,active', true],
    ['account', 'lf_accounts', 'id,space_id,code,name,class,currency,is_bank,active', true],
];
foreach ($tables as [$label, $table, $columns, $scoped]) {
    $sql = 'SELECT ' . $columns . ' FROM ' . $prefix . $table;
    $conditions = [];
    $params = [];
    if ($scoped) {
        $conditions[] = 'space_id = ?';
        $params[] = $spaceId;
    }
    if ($label === 'account') {
        $conditions[] = 'code = ?';
        $params[] = 'UPG_BANK_A';
    }
    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $stmt = $db->prepare($sql . ' ORDER BY id ASC');
    $stmt->execute($params);
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        ksort($row, SORT_STRING);
        echo $label, '|', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
}
