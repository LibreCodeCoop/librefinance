<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// An immutable synthetic fixture for the exact, pre-consolidation LibreFinance
// Import 0.6.1 table structure. Runs ONLY in the disposable upgrade CI database.
// The real old app must be disabled without uninstalling it before upgrading.
require '/var/www/html/config/config.php';
if (!is_array($CONFIG ?? null)) {
    throw new RuntimeException('Missing disposable Nextcloud configuration');
}
$id = filter_var(getenv('LF_UPGRADE_SPACE_ID'), FILTER_VALIDATE_INT);
if (!is_int($id) || $id < 1) {
    throw new RuntimeException('Missing synthetic space ID');
}
$type = (string)($CONFIG['dbtype'] ?? '');
$name = (string)($CONFIG['dbname'] ?? 'nextcloud');
if ($type === 'sqlite3') {
    $pdo = new PDO('sqlite:' . $CONFIG['datadirectory'] . '/' . $name . '.db');
} elseif (in_array($type, ['pgsql', 'mysql'], true)) {
    $dsn = ($type === 'mysql' ? 'mysql' : 'pgsql')
        . ':host=' . $CONFIG['dbhost'] . ';dbname=' . $name;
    $pdo = new PDO($dsn, $CONFIG['dbuser'], $CONFIG['dbpassword']);
} else {
    throw new RuntimeException('Unsupported test database driver');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = (string)($CONFIG['dbtableprefix'] ?? 'oc_');
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
    throw new RuntimeException('Invalid synthetic table prefix');
}
$pk = match ($type) {
    'sqlite3' => 'INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT',
    'pgsql' => 'SERIAL PRIMARY KEY',
    'mysql' => 'INTEGER AUTO_INCREMENT PRIMARY KEY',
};
$r = $prefix . 'lfm_records';
$a = $prefix . 'lfm_audit';
$b = $prefix . 'lfm_liquid_origins';
$pdo->exec("CREATE TABLE $r (
    id $pk, space_id INTEGER NOT NULL, source_system VARCHAR(24) NOT NULL,
    source_company_id VARCHAR(64) NOT NULL, record_kind VARCHAR(12) NOT NULL,
    source_record_id VARCHAR(80) NOT NULL, target_id INTEGER NOT NULL,
    payload_hash VARCHAR(64) NOT NULL, created_by VARCHAR(64) NOT NULL,
    created_at INTEGER NOT NULL
)");
$pdo->exec("CREATE UNIQUE INDEX lfm_record_identity ON $r
    (space_id, source_system, source_company_id, record_kind, source_record_id)");
$pdo->exec("CREATE INDEX lfm_target_reference ON $r
    (space_id, record_kind, target_id)");
$pdo->exec("CREATE TABLE $a (
    id $pk, space_id INTEGER NOT NULL, source_system VARCHAR(24) NOT NULL,
    source_company_id VARCHAR(64) NOT NULL, batch_hash VARCHAR(64) NOT NULL,
    created_count INTEGER NOT NULL, skipped_count INTEGER NOT NULL,
    actor_uid VARCHAR(64) NOT NULL, occurred_at INTEGER NOT NULL
)");
$pdo->exec("CREATE INDEX lfm_audit_recent ON $a (space_id, occurred_at)");
$pdo->exec("CREATE TABLE $b (
    id $pk, space_id INTEGER NOT NULL, target_account_id INTEGER NOT NULL,
    kind VARCHAR(8) NOT NULL, bank_name VARCHAR(150) NULL,
    account_number_cipher TEXT NULL, number_last4 VARCHAR(4) NULL,
    currency VARCHAR(3) NOT NULL, opening_balance_minor BIGINT NOT NULL,
    current_balance_minor BIGINT NOT NULL, balance_as_of VARCHAR(10) NOT NULL,
    created_at INTEGER NOT NULL
)");
$pdo->exec("CREATE UNIQUE INDEX lfm_liq_target ON $b
    (space_id, target_account_id)");
$pdo->exec("CREATE INDEX lfm_liq_asof ON $b (space_id, balance_as_of)");
$lookup = static function (string $table, string $column, string $value)
        use ($pdo, $prefix, $id): int {
    $stmt = $pdo->prepare("SELECT id FROM " . $prefix . $table
        . " WHERE space_id = ? AND " . $column . " = ?");
    $stmt->execute([$id, $value]);
    $found = $stmt->fetchColumn();
    if ($found === false || (int)$found < 1) {
        throw new RuntimeException('Missing synthetic imported target reference');
    }
    return (int)$found;
};
$contact = $lookup('lf_contacts', 'name', 'Synthetic retained contact');
$account = $lookup('lf_accounts', 'code', 'UPG_BANK_A');
$pdo->beginTransaction();
$st = $pdo->prepare("INSERT INTO $r
    (space_id,source_system,source_company_id,record_kind,source_record_id,
     target_id,payload_hash,created_by,created_at)
    VALUES (?,?,?,?,?,?,?,?,?)");
foreach ([['contact', 'c-1', $contact], ['account', 'a-1', $account]]
    as [$kind, $source, $target]) {
    $st->execute([$id,'generic','upgrade-old-import',$kind,$source,$target,
        hash('sha256',$kind . ':' . $source),'lfmaintestA',1795296000]);
}
$st = $pdo->prepare("INSERT INTO $a
    (space_id,source_system,source_company_id,batch_hash,created_count,
     skipped_count,actor_uid,occurred_at) VALUES (?,?,?,?,?,?,?,?)");
$st->execute([$id,'generic','upgrade-old-import',hash('sha256','legacy-batch'),
    2,0,'lfmaintestA',1795296000]);
$st = $pdo->prepare("INSERT INTO $b
    (space_id,target_account_id,kind,bank_name,account_number_cipher,
     number_last4,currency,opening_balance_minor,current_balance_minor,
     balance_as_of,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$st->execute([$id,$account,'bank','Synthetic bank',null,null,'BRL',
    1000,2000,'2026-11-22',1795296000]);
$pdo->commit();
echo 'PASS: seeded historic Import 0.6.1 table shapes and identities', PHP_EOL;
