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
    $ga->addUser($admin);
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
    $ledger = "/spaces/$spaceId";
    $chart = $t->check('financial owner can read the chart of accounts', $bUid,
        'GET', "$ledger/accounts", null, 200);
    $ids = array_column($chart['accounts'], 'id', 'code');
    $entry = [
        'debitId' => $ids['1000'], 'creditId' => $ids['4000'], 'amountMinor' => 12300,
        'date' => '2026-11-23', 'memo' => 'Synthetic native readiness',
        'externalKey' => 'native-readiness-posting',
    ];
    $posted = $t->check('financial owner posts a balanced synthetic journal', $bUid,
        'POST', "$ledger/journals", $entry, 201,
        static fn(array $r): bool => ($r['replayed'] ?? null) === false);
    $journals = $t->check('explicit financial owner can read the posted journal', $bUid,
        'GET', "$ledger/journals", null, 200, static function (array $r) use ($ids, $posted): bool {
            $journals = $r['journals'] ?? [];
            $postings = array_column($journals[0]['postings'] ?? [], 'amountMinor', 'accountId');
            return count($journals) === 1 && ($journals[0]['id'] ?? null) === $posted['id']
                && count($postings) === 2 && ($postings[$ids['1000']] ?? null) === 12300
                && ($postings[$ids['4000']] ?? null) === -12300;
        });
    $normalizePostings = static function (array $response): array {
        foreach ($response['journals'] as &$journal) {
            usort($journal['postings'], static fn(array $left, array $right): int =>
                $left['accountId'] <=> $right['accountId']);
        }
        unset($journal);
        return $response;
    };
    $journals = $normalizePostings($journals);
    $balances = $t->check('financial owner can read posted balances', $bUid,
        'GET', "$ledger/accounts", null, 200, static function (array $r): bool {
            $balances = array_column($r['accounts'] ?? [], 'balance_minor', 'code');
            return ($balances['1000'] ?? null) === 12300 && ($balances['4000'] ?? null) === -12300;
        });
    $workspaces = $t->check('native admin A lists only its environment workspaces', $aUid,
        'GET', "$base/$ea/workspaces", null, 200,
        static fn(array $r): bool => array_column($r['workspaces'] ?? [], 'id') === [$spaceId]
            && ($r['workspaces'][0]['ownerUid'] ?? null) === $bUid);
    $t->check('native admin B cannot enumerate A workspaces even as financial owner', $bUid,
        'GET', "$base/$ea/workspaces", null, 404);
    $auditBefore = $environments->groupAudit($gids[0]);

    $checkReadiness = static function (string $name, string $state) use ($t, $environments, $ga, $ea): void {
        $listing = array_column($environments->listAdminGroups('lf_synth_native_')['groups'], null, 'id');
        $row = $listing[$ga->getGID()] ?? [];
        $t->record($name, ($row['state'] ?? null) === $state && ($row['environmentId'] ?? null) === $ea,
            $state . ' with original environment UUID', $row);
    };
    $governance->grantDelegate($gids[0], $aUid);
    $subAdmins->deleteSubAdmin($a, $ga);
    $checkReadiness('explicit delegation still qualifies after native revocation', 'active');
    $t->check('explicit delegate can still list the same environment workspaces', $aUid,
        'GET', "$base/$ea/workspaces", null, 200, static fn(array $r): bool => $r === $workspaces);
    $governance->revokeDelegate($gids[0], $aUid);
    $checkReadiness('loss of the last explicit delegate returns the group to pending', 'enabled_pending_admin');
    $delegatesBefore = $governance->delegates($gids[0]);
    try {
        $governance->grantDelegate($gids[0], $admin->getUID());
        $selfGrantDenied = false;
    } catch (DomainException $e) {
        $selfGrantDenied = $e->getMessage() === 'self_promotion_forbidden';
    }
    $t->record('instance admin cannot create its own environment delegation', $selfGrantDenied,
        'new self-delegation denied');
    $t->record('denied self-delegation does not persist any grant',
        $governance->delegates($gids[0]) === $delegatesBefore, 'delegation list unchanged');
    $checkReadiness('denied self-delegation leaves the original environment pending', 'enabled_pending_admin');
    if (!$selfGrantDenied) {
        // Remove a mistakenly created grant so the remaining cases stay independent.
        $governance->revokeDelegate($gids[0], $admin->getUID());
    }
    $enabledAgain = $environments->enableGroup($gids[0]);
    $t->record('instance admin cannot clear pending by re-enabling the group',
        $enabledAgain['id'] === $ea && $enabledAgain['state'] === 'enabled_pending_admin'
            && $enabledAgain['canManage'] === false,
        'idempotent enablement leaves readiness pending', $enabledAgain);
    try {
        $environments->activate($ea);
        $instanceDenied = false;
    } catch (DomainException $e) {
        $instanceDenied = $e->getMessage() === 'environment_not_found';
    }
    $t->record('instance admin cannot self-promote by provisioning', $instanceDenied, 'activation authorization');
    $t->check('ordinary member cannot self-promote by provisioning', $aUid,
        'POST', "$base/$ea/provision", null, 404);
    $t->check('former administrator cannot list workspaces while readiness is pending', $aUid,
        'GET', "$base/$ea/workspaces", null, 404);
    $isPending = static function (array $r) use ($ea, $eb): bool {
        $items = array_column($r['environments'] ?? [], null, 'id');
        return ($r['contractVersion'] ?? null) === 1
            && ($items[$ea]['state'] ?? null) === 'enabled_pending_admin'
            && ($items[$ea]['canManage'] ?? null) === false
            && ($items[$eb]['state'] ?? null) === 'active';
    };
    $t->check('financial owner discovers pending readiness independently of B', $bUid,
        'GET', $base, null, 200, $isPending);
    $t->check('financial owner still discovers the workspace as owner', $bUid, 'GET', '/spaces', null, 200,
        static fn(array $r): bool => (array_column($r['spaces'] ?? [], 'role', 'id')[$spaceId] ?? null) === 'owner');
    $t->check('financial owner can replay the original posting while readiness is pending', $bUid,
        'POST', "$ledger/journals", $entry, 201,
        static fn(array $r): bool => ($r['replayed'] ?? null) === true && ($r['id'] ?? null) === $posted['id']);
    $t->check('financial owner can read the same journal while readiness is pending', $bUid,
        'GET', "$ledger/journals", null, 200, static fn(array $r): bool => $normalizePostings($r) === $journals);
    $t->check('financial owner can read the same balances while readiness is pending', $bUid,
        'GET', "$ledger/accounts", null, 200, static fn(array $r): bool => $r === $balances);

    $subAdmins->createSubAdmin($a, $ga);
    $checkReadiness('restoring a native subadmin makes the original environment ready', 'active');
    $t->check('restored native admin provisions the same activated environment idempotently', $aUid,
        'POST', "$base/$ea/provision", null, 200,
        static fn(array $r): bool => ($r['environment']['id'] ?? null) === $ea
            && ($r['environment']['state'] ?? null) === 'active'
            && ($r['environment']['canManage'] ?? null) === true);
    $t->check('restored native admin lists the same workspace and owner', $aUid,
        'GET', "$base/$ea/workspaces", null, 200, static fn(array $r): bool => $r === $workspaces);
    $t->check('restored native admin still has no ledger access', $aUid,
        'GET', "$ledger/journals", null, 404);
    $t->check('recovery leaves the posted balances unchanged', $bUid,
        'GET', "$ledger/accounts", null, 200, static fn(array $r): bool => $r === $balances);
    $t->record('readiness changes and idempotent provisioning leave lifecycle audit intact',
        $environments->groupAudit($gids[0]) === $auditBefore, 'same lifecycle history');

    $a->setEnabled(false);
    try {
        $checkReadiness('disabled native subadmin does not count toward readiness', 'enabled_pending_admin');
    } finally {
        $a->setEnabled(true);
    }

    $subAdmins->deleteSubAdmin($a, $ga);
    $checkReadiness('loss of the last native subadmin returns the group to pending', 'enabled_pending_admin');
    $t->check('native subadmin revocation denies next administrative request', $aUid,
        'GET', "$base/$ea/workspaces", null, 404);
    $t->check('financial owner retains its ledger after other actor admin revocation', $bUid,
        'GET', "/spaces/$spaceId/journals", null, 200);
    // Public Nextcloud APIs can leave a subadmin assignment without group
    // membership (e.g. an external membership backend changed independently).
    // A stale assignment is not current group authority.
    $governance->grantDelegate($gids[0], $aUid);
    $ga->removeUser($a);
    $subAdmins->createSubAdmin($a, $ga);
    $checkReadiness('stale native and explicit assignments do not count toward readiness', 'enabled_pending_admin');
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
