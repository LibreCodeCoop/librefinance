<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Only mounted in disposable Nextcloud upgrade CI, never production.
require_once '/var/www/html/custom_apps/librefinance/tests/integration/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$user = 'lfmaintestA';
$action = getenv('LF_UPGRADE_ACTION');
if ($action === 'seed') {
    $space = $t->check('create synthetic pre-upgrade source space',
        $user, 'POST', '/spaces', [
            'name' => 'Synthetic historic upgrade ' . bin2hex(random_bytes(3)),
            'kind' => 'organization', 'currency' => 'BRL',
        ], 201);
    $id = (int)$space['id'];
    $base = '/spaces/' . $id;
    $t->check('create synthetic retained contact before core migration',
        $user, 'POST', $base . '/contacts', [
            'name' => 'Synthetic retained contact',
            'kind' => 'customer', 'email' => null, 'taxId' => null,
        ], 201);
    $t->check('create synthetic retained bank before core migration',
        $user, 'POST', $base . '/accounts', [
            'code' => 'UPG_BANK_A', 'name' => 'Synthetic source account',
            'class' => 'asset', 'isBank' => true,
        ], 201);
    echo 'SPACE_ID=' . $id, PHP_EOL;
    $t->finish();
} elseif ($action === 'verify') {
    $spaceId = filter_var(getenv('LF_UPGRADE_SPACE_ID'), FILTER_VALIDATE_INT);
    if (!is_int($spaceId) || $spaceId < 1) {
        throw new RuntimeException('Missing synthetic space ID');
    }
    $t->checkModule('new core reads retained Import identity mappings',
        $user, 'librefinance', 'GET',
        '/spaces/' . $spaceId
          . '/coverage?sourceCompanyId=upgrade-old-import&sourceSystem=generic',
        null, 200, static fn(array $v): bool =>
            ($v['kinds']['contact']['mapped'] ?? null) === 1
            && ($v['kinds']['account']['mapped'] ?? null) === 1
            && ($v['kinds']['account']['snapshotsPresent'] ?? null) === 1);
    $t->finish();
} else {
    throw new RuntimeException('Unknown disposable upgrade operation');
}
