<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (getenv('LF_SYNTHETIC_SAAS_NATIVE_SUBADMIN') !== 'yes') {
    throw new RuntimeException('Native subadmin test requires disposable CI opt-in');
}
require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;
use OCA\LibreFinance\Service\EnvironmentService;
use OCA\LibreFinance\Service\GovernanceService;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

$t = new TestHarness();
require_once dirname(__DIR__, 4) . '/lib/base.php';
\OC_App::loadApp('librefinance');
$groups = \OC::$server->get(IGroupManager::class);
$users = \OC::$server->get(IUserManager::class);
$session = \OC::$server->get(IUserSession::class);
$subAdmins = \OC::$server->get(ISubAdmin::class);
$governance = \OC::$server->get(GovernanceService::class);
$environments = \OC::$server->get(EnvironmentService::class);
$a = $users->get('lfmaintestA');
$b = $users->get('lfmaintestB');
$admin = $users->get('synthetictestadmin');
if ($a === null || $b === null || $admin === null) {
    throw new RuntimeException('Synthetic identities are required');
}
$gids = ['lf_synth_native_A', 'lf_synth_native_B', 'lf_synth_native_C', 'lf_synth_native_D'];
foreach ($gids as $gid) {
    if ($groups->get($gid) !== null) {
        throw new RuntimeException('Refusing to reuse existing group ' . $gid);
    }
}
$session->setUser($admin);
$wasManaged = $governance->enabled();
$created = [];
try {
    $governance->configure(true);
    foreach ($gids as $gid) {
        $group = $groups->createGroup($gid);
        if ($group === null) {
            throw new RuntimeException('Could not create synthetic group');
        }
        $created[$gid] = $group;
        $group->addUser($a);
        $group->addUser($b);
    }
    [$ga, $gb, $gc, $gd] = array_values($created);
    $subAdmins->createSubAdmin($a, $ga);
    $subAdmins->createSubAdmin($b, $gb);
    $subAdmins->createSubAdmin($a, $gc);
    $ea = (string)$environments->enableGroup($gids[0])['id'];
    $eb = (string)$environments->enableGroup($gids[1])['id'];
    $ed = (string)$environments->enableGroup($gids[3])['id'];
    $listing = $environments->listAdminGroups('lf_synth_native_')['groups'];
    $states = array_column($listing, 'state', 'id');
    if ($states !== array_intersect_key($states, array_flip($gids))
        || count($states) !== 4
        || ($states[$gids[2]] ?? null) !== 'disabled'
        || ($states[$gids[3]] ?? null) !== 'enabled_pending_admin') {
        throw new RuntimeException('Independent disabled and pending states required');
    }
    $base = '/saas/v1/environments';
    $aUid = $a->getUID();
    $bUid = $b->getUID();
    $t->check('native admin A discovers only own management authority', $aUid, 'GET', $base, null, 200,
        static function (array $r) use ($ea, $eb, $ed): bool {
            $items = array_column($r['environments'] ?? [], null, 'id');
            return ($items[$ea]['canManage'] ?? null) === true
                && ($items[$eb]['canManage'] ?? null) === false
                && ($items[$ed]['canManage'] ?? null) === false;
        });
    $t->check('adminless enabled group denies ordinary member provisioning', $aUid,
        'POST', "$base/$ed/provision", null, 404);
    $t->check('native admin A cannot provision B', $aUid,
        'POST', "$base/$eb/provision", null, 404);
    $t->check('native admin B cannot provision A', $bUid,
        'POST', "$base/$ea/provision", null, 404);
    $t->check('native admin A provisions without LibreFinance delegation', $aUid,
        'POST', "$base/$ea/provision", null, 200,
        static fn(array $r): bool => ($r['environment']['id'] ?? null) === $ea
            && ($r['environment']['state'] ?? null) === 'active');
    $t->check('native admin B provisions a distinct environment', $bUid,
        'POST', "$base/$eb/provision", null, 200,
        static fn(array $r): bool => ($r['environment']['id'] ?? null) === $eb
            && ($r['environment']['state'] ?? null) === 'active' && $eb !== $ea);
    $t->check('native admin A reuses existing environment', $aUid,
        'POST', "$base/$ea/provision", null, 200,
        static fn(array $r): bool => ($r['environment']['id'] ?? null) === $ea);
    $book = $t->check('native admin creates separately owned workspace', $aUid,
        'POST', "$base/$ea/workspaces",
        ['name' => 'Native admin synthetic book A', 'ownerUid' => $bUid,
            'idempotencyKey' => 'native-subadmin-a-001', 'kind' => 'collective', 'currency' => 'BRL'], 201);
    $spaceId = (int)$book['id'];
    $t->check('native admin cannot read financial ledger by role alone', $aUid,
        'GET', "/spaces/$spaceId/journals", null, 404);
    $t->check('explicit financial owner can read ledger', $bUid,
        'GET', "/spaces/$spaceId/journals", null, 200);
    $t->check('native admin A lists only its environment workspaces', $aUid,
        'GET', "$base/$ea/workspaces", null, 200,
        static fn(array $r): bool => array_column($r['workspaces'] ?? [], 'id') === [$spaceId]);
    $t->check('native admin B cannot enumerate A workspaces even as financial owner', $bUid,
        'GET', "$base/$ea/workspaces", null, 404);
    $subAdmins->deleteSubAdmin($a, $ga);
    $t->check('native subadmin revocation denies next administrative request', $aUid,
        'GET', "$base/$ea/workspaces", null, 404);
    $t->check('financial owner retains its ledger after other actor admin revocation', $bUid,
        'GET', "/spaces/$spaceId/journals", null, 200);
    // Public Nextcloud APIs can leave a subadmin assignment without group
    // membership (e.g. an external membership backend changed independently).
    // A stale assignment is not current group authority.
    $ga->removeUser($a);
    $subAdmins->createSubAdmin($a, $ga);
    $t->check('stale native subadmin assignment without membership cannot manage', $aUid,
        'GET', "$base/$ea/workspaces", null, 404);
    $t->check('stale native assignment does not expose environment discovery', $aUid,
        'GET', $base, null, 200,
        static fn(array $r): bool => !in_array($ea, array_column($r['environments'] ?? [], 'id'), true));
    // The financial owner is still explicitly granted in this book, but
    // losing current group membership must conceal its data immediately.
    $ga->removeUser($b);
    $t->check('financial owner removed from group loses direct ledger access', $bUid,
        'GET', "/spaces/$spaceId/journals", null, 404);
    $t->check('removed financial owner cannot discover former workspace', $bUid,
        'GET', '/spaces', null, 200,
        static fn(array $r): bool => !in_array($spaceId, array_column($r['spaces'] ?? [], 'id'), true));
    $t->check('removed financial owner cannot read exchange-rate evidence', $bUid,
        'GET', "/spaces/$spaceId/exchange-rates", null, 404);
    $t->check('native admin B is unaffected by A revocation', $bUid,
        'GET', "$base/$eb/workspaces", null, 200);
} finally {
    $session->setUser($admin);
    foreach ($created as $group) {
        foreach ($subAdmins->getGroupsSubAdmins($group) as $subAdmin) {
            $subAdmins->deleteSubAdmin($subAdmin, $group);
        }
        $group->delete();
    }
    $governance->configure($wasManaged);
    $session->setUser(null);
}
$t->finish();
