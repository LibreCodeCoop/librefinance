<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;
use OCA\LibreFinance\Service\EnvironmentService;
use OCA\LibreFinance\Service\GovernanceService;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

if (getenv('LF_SYNTHETIC_SAAS_IMAGES') !== 'yes') {
    throw new RuntimeException('SaaS image test requires disposable CI opt-in');
}
$t = new TestHarness();
$fixture = file_get_contents(__DIR__ . '/fixtures/logo-valid.b64');
$imageBase64 = $fixture === false ? '' : trim($fixture);
$png = base64_decode($imageBase64, true);
if ($png === false || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
    throw new RuntimeException('Missing synthetic PNG fixture');
}

require_once dirname(__DIR__, 4) . '/lib/base.php';
\OC_App::loadApp('librefinance');
$groups = \OC::$server->get(IGroupManager::class);
$users = \OC::$server->get(IUserManager::class);
$session = \OC::$server->get(IUserSession::class);
$subAdmins = \OC::$server->get(ISubAdmin::class);
$governance = \OC::$server->get(GovernanceService::class);
$environments = \OC::$server->get(EnvironmentService::class);
$owner = $users->get('lfmaintestA');
$viewer = $users->get('lfmaintestB');
$admin = $users->get('synthetictestadmin');
if ($admin === null || $owner === null || $viewer === null) {
    throw new RuntimeException('Synthetic Nextcloud identities are required');
}
$ownerUid = $owner->getUID();
$viewerUid = $viewer->getUID();
$gid = 'lf_synth_images_' . bin2hex(random_bytes(6));
if ($groups->get($gid) !== null) {
    throw new RuntimeException('Refusing to reuse an existing group');
}
$session->setUser($admin);
$wasManaged = $governance->enabled();
$group = null;
try {
    $governance->configure(true);
    $group = $groups->createGroup($gid);
    if ($group === null) {
        throw new RuntimeException('Could not create the synthetic group');
    }
    $group->addUser($owner);
    $group->addUser($viewer);
    $subAdmins->createSubAdmin($viewer, $group);
    $environment = $environments->enableGroup($gid);
    $environmentPath = '/saas/v1/environments/' . $environment['id'];
    $t->check('provision synthetic image environment', $viewerUid, 'POST',
        $environmentPath . '/provision', null, 200);
    $workspace = $t->check('create synthetic workspace with a legal company', $viewerUid, 'POST',
        $environmentPath . '/workspaces', [
            'name' => 'Synthetic image workspace',
            'ownerUid' => $ownerUid,
            'idempotencyKey' => 'synthetic-images-001',
            'legalName' => 'Synthetic image company',
            'companyCountry' => 'PT',
        ], 201);
    $spacePath = '/spaces/' . (int)$workspace['id'];
    $bank = $t->check('create synthetic bank account', $ownerUid, 'POST', $spacePath . '/accounts', [
        'code' => 'BANK-IMAGE', 'name' => 'Synthetic image account', 'class' => 'asset', 'isBank' => true,
    ], 201);
    $routes = [
        'company' => $spacePath . '/companies/' . (int)$workspace['companyId'] . '/logo',
        'bank' => $spacePath . '/accounts/' . (int)$bank['id'] . '/logo',
    ];

    $t->check('grant an explicit financial viewer role', $ownerUid, 'POST', $spacePath . '/members',
        ['uid' => $viewerUid, 'role' => 'viewer', 'expectedRevision' => 0], 200);
    $imageUrls = [];
    foreach ($routes as $kind => $route) {
        $upload = $t->check("store the synthetic $kind PNG", $ownerUid, 'POST', $route,
            ['imageBase64' => $imageBase64], 200,
            static fn(array $r): bool => is_int($r['logoVersion'] ?? null));
        $imageUrl = $route . '?v=' . $upload['logoVersion'];
        $imageUrls[$kind] = $imageUrl;
        $response = $t->requestImage($viewerUid, $imageUrl);
        $t->record("viewer receives the exact $kind PNG", $response['code'] === 200 && $response['body'] === $png,
            'HTTP ' . $response['code'] . ', ' . strlen($response['body']) . ' response bytes');
        $t->record("$kind image uses PNG content type", strtolower($response['contentType']) === 'image/png',
            $response['contentType']);
        $directives = array_map('trim', explode(',', strtolower(implode(',', $response['cacheControl']))));
        $t->record("$kind image is private and cannot be stored by caches",
            in_array('private', $directives, true) && in_array('no-store', $directives, true),
            implode(', ', $response['cacheControl']));
    }

    $t->check('revoke the financial role while retaining group administration', $ownerUid, 'DELETE',
        $spacePath . '/members/' . $viewerUid . '?expectedRevision=1', null, 200);
    foreach ($imageUrls as $kind => $imageUrl) {
        $response = $t->requestImage($viewerUid, $imageUrl);
        $mime = strtolower(trim(explode(';', $response['contentType'], 2)[0]));
        $t->record("revoked viewer receives only a JSON error for the same $kind URL",
            $response['code'] === 404 && $mime === 'application/json'
                && json_decode($response['body'], true) === ['error' => 'space_not_found'],
            'HTTP ' . $response['code'] . ', ' . $response['contentType']);
        $response = $t->requestImage($ownerUid, $imageUrl);
        $t->record("owner still receives the exact $kind PNG", $response['code'] === 200 && $response['body'] === $png,
            'HTTP ' . $response['code'] . ', ' . strlen($response['body']) . ' response bytes');
    }
} finally {
    try {
        if ($group !== null) {
            foreach ($subAdmins->getGroupsSubAdmins($group) as $subAdmin) {
                $subAdmins->deleteSubAdmin($subAdmin, $group);
            }
            if (!$group->delete()) {
                throw new RuntimeException('Could not delete the synthetic group');
            }
        }
    } finally {
        $governance->configure($wasManaged);
        $session->setUser(null);
    }
}
$t->finish();
