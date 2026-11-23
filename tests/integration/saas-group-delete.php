<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// This test deliberately creates and deletes a synthetic Nextcloud group.
// It may run only inside the disposable CI lab.
if (getenv('LF_SYNTHETIC_SAAS_GROUP_DELETE') !== 'yes') {
    throw new RuntimeException('Synthetic group deletion requires explicit CI opt-in');
}
$url = parse_url((string)getenv('LF_BASE_URL'));
if (!is_array($url) || !in_array($url['host'] ?? null, ['127.0.0.1', 'localhost'], true)
    || !str_ends_with((string)($url['path'] ?? ''), '/apps/librefinance/api')) {
    throw new RuntimeException('Synthetic group deletion requires a loopback app API');
}
require_once dirname(__DIR__, 4) . '/lib/base.php';
\OC_App::loadApp('librefinance');

use OCA\LibreFinance\Service\AccessService;
use OCA\LibreFinance\Service\EnvironmentService;
use OCA\LibreFinance\Service\GovernanceService;
use OCA\LibreFinance\Service\SaasWorkspaceService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

$checks = 0;
$assert = static function (string $name, bool $ok) use (&$checks): void {
    echo $ok ? 'PASS: ' : 'FAIL: ', $name, PHP_EOL;
    if (!$ok) {
        throw new RuntimeException('Synthetic SaaS group deletion: ' . $name);
    }
    ++$checks;
};
$denied = static function (callable $call, string $error): bool {
    try {
        $call();
    } catch (DomainException $e) {
        return $e->getMessage() === $error;
    }
    return false;
};
$db = \OC::$server->get(IDBConnection::class);
$groups = \OC::$server->get(IGroupManager::class);
$users = \OC::$server->get(IUserManager::class);
$session = \OC::$server->get(IUserSession::class);
$governance = \OC::$server->get(GovernanceService::class);
$environments = \OC::$server->get(EnvironmentService::class);
$workspaces = \OC::$server->get(SaasWorkspaceService::class);
$access = \OC::$server->get(AccessService::class);

$admin = $users->get('synthetictestadmin');
$a = $users->get('lfmaintestA');
$b = $users->get('lfmaintestB');
if ($admin === null || $a === null || $b === null) {
    throw new RuntimeException('Synthetic Nextcloud identities are not installed');
}
$gid = 'lf_synth_group_deleted';
if ($groups->get($gid) !== null) {
    throw new RuntimeException('Synthetic group already exists: refusing destructive reuse');
}

$session->setUser($admin);
$wasManaged = $governance->enabled();
if (!$wasManaged) {
    $governance->configure(true);
}
$group = null;
try {
    $group = $groups->createGroup($gid);
    if ($group === null) {
        throw new RuntimeException('Could not create synthetic group');
    }
    $group->addUser($a);
    $group->addUser($b);
    $environment = $environments->enableGroup($gid);
    $uuid = (string)$environment['id'];
    $assert('synthetic Nextcloud group obtains opaque environment identity',
        (bool)preg_match('/^[0-9a-f-]{36}$/', $uuid));

    $governance->grantDelegate($gid, $b->getUID());
    $session->setUser($b);
    $activated = $environments->activate($uuid);
    $assert('qualified delegate activates the isolated environment',
        ($activated['state'] ?? null) === 'active');
    $workspace = $workspaces->create($uuid, 'Preserved synthetic ledger',
        'collective', 'BRL', $a->getUID(), 'synthetic-delete-001');
    $spaceId = (int)$workspace['id'];
    $assert('financial workspace has a durable ledger identity',
        $spaceId > 0);

    $session->setUser($a);
    $assert('financial owner can read workspace before group deletion',
        $access->requireFinancialCapability($spaceId, 'ledger.read') === 'owner');
    $session->setUser($admin);
    // A pre-existing legacy grant to the deleted group must not become a
    // permission for a newly-created group with the same textual GID.
    $seed = $db->getQueryBuilder();
    $seed->insert('lf_group_grants')->values([
        'space_id' => $seed->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT),
        'group_gid' => $seed->createNamedParameter($gid),
        'role' => $seed->createNamedParameter('viewer'),
    ])->executeStatement();

    $query = $db->getQueryBuilder();
    $query->select('id', 'revision', 'status')->from('lf_environments')
        ->where($query->expr()->eq('public_id', $query->createNamedParameter($uuid)));
    $before = $query->executeQuery()->fetchAssociative();
    $assert('environment is active before group deletion',
        $before !== false && $before['status'] === 'active');
    $assert('Nextcloud emits deletion of real synthetic group', $group->delete());
    $group = null;

    $query = $db->getQueryBuilder();
    $query->select('e.status', 'e.revision', 'g.state')
        ->from('lf_environments', 'e')
        ->innerJoin('e', 'lf_environment_groups', 'g', 'g.environment_id = e.id')
        ->where($query->expr()->eq('e.public_id', $query->createNamedParameter($uuid)));
    $after = $query->executeQuery()->fetchAssociative();
    $assert('Nextcloud event immediately suspends environment and group binding',
        $after !== false && $after['status'] === 'suspended'
            && $after['state'] === 'suspended'
            && (int)$after['revision'] > (int)$before['revision']);

    $query = $db->getQueryBuilder();
    $query->selectAlias($query->createFunction('COUNT(*)'), 'remaining')
        ->from('lf_governance_delegates')
        ->where($query->expr()->eq('group_gid', $query->createNamedParameter($gid)));
    $assert('deleted group immediately loses its administrative delegates',
        (int)$query->executeQuery()->fetchOne() === 0);

    $query = $db->getQueryBuilder();
    $query->selectAlias($query->createFunction('COUNT(*)'), 'remaining')
        ->from('lf_group_grants')
        ->where($query->expr()->eq('group_gid', $query->createNamedParameter($gid)));
    $assert('deleted group loses legacy viewer grants',
        (int)$query->executeQuery()->fetchOne() === 0);

    $query = $db->getQueryBuilder();
    $query->select('action', 'actor_uid')->from('lf_environment_audit')
        ->where($query->expr()->eq('environment_id',
            $query->createNamedParameter((int)$before['id'], IQueryBuilder::PARAM_INT)))
        ->andWhere($query->expr()->eq('action',
            $query->createNamedParameter('environment.group_deleted')))
        ->orderBy('id', 'DESC')->setMaxResults(1);
    $audit = $query->executeQuery()->fetchAssociative();
    $assert('group deletion has an attributable append-only audit event',
        $audit !== false && $audit['actor_uid'] === $admin->getUID());

    $query = $db->getQueryBuilder();
    $query->select('owner_uid')->from('lf_spaces')
        ->where($query->expr()->eq('id',
            $query->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
    $assert('deleted group never deletes or reassigns its financial ledger',
        $query->executeQuery()->fetchOne() === $a->getUID());

    $group = $groups->createGroup($gid);
    if ($group === null) {
        throw new RuntimeException('Could not recreate synthetic group');
    }
    $group->addUser($a);
    $group->addUser($b);
    $assert('same textual GID cannot automatically reactivate old environment',
        $denied(static fn() => $environments->enableGroup($gid), 'environment_suspended'));
    $session->setUser($a);
    $assert('even direct ledger owner is blocked in recreated group',
        $denied(static fn() => $access->requireFinancialCapability($spaceId, 'ledger.read'),
            'space_not_found'));
    $session->setUser($b);
    $assert('new group member cannot activate old environment without delegation',
        $denied(static fn() => $environments->activate($uuid), 'environment_not_found'));

    $session->setUser($admin);
    $recovered = $environments->recoverGroup($gid, 'group_admin_restored');
    $assert('explicit audited recovery preserves the original environment UUID',
        ($recovered['id'] ?? null) === $uuid
            && ($recovered['state'] ?? null) === 'enabled_pending_admin');
    $governance->grantDelegate($gid, $b->getUID());
    $session->setUser($b);
    $assert('qualified delegate may activate only after recovery',
        ($environments->activate($uuid)['state'] ?? null) === 'active');
    $assert('group administrator gains no implicit access to accounting ledger',
        $denied(static fn() => $access->requireFinancialCapability($spaceId, 'ledger.read'),
            'space_not_found'));
    $session->setUser($a);
    $assert('original owner can resume unchanged ledger after audited recovery',
        $access->requireFinancialCapability($spaceId, 'ledger.read') === 'owner');
} finally {
    $session->setUser($admin);
    $existing = $groups->get($gid);
    if ($existing !== null) {
        $existing->delete();
    }
    if (!$wasManaged) {
        $governance->configure(false);
    }
}
echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
