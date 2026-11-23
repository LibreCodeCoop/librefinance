<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\Exception as DatabaseException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * SaaS environment lifecycle deliberately excludes financial-space grants.
 *
 * An instance administrator enables a Nextcloud group and allocates its
 * durable tenant identity; a qualified subadmin/delegate activates the
 * environment. Neither action creates or exposes a ledger.
 */
final class EnvironmentService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $write,
        private GovernanceService $governance,
        private IGroupManager $groups,
        private IUserSession $session,
        private SaasPolicyRuntimeGate $policies,
    ) {}

    /**
     * List Nextcloud groups with SaaS enablement and durable environment state
     * without exposing workspaces, members, balances or fiscal metadata.
     *
     * This is a scoped *instance* administration API, not a finance grant.
     * The existing GovernanceService search enforces pagination and size.
     *
     * @return array{groups:list<array{id:string,name:string,state:string,environmentId:?string}>,nextOffset:?int}
     */
    public function listAdminGroups(string $search = '', int $offset = 0): array {
        $this->governance->requireInstanceAdmin();
        $listing = $this->governance->searchGroups($search, $offset);
        $groups = $listing['groups'];
        if ($groups === []) {
            return ['groups' => [], 'nextOffset' => $listing['nextOffset']];
        }
        $ids = array_column($groups, 'id');
        $q = $this->db->getQueryBuilder();
        $q->select('g.group_gid', 'g.state', 'e.status', 'e.public_id')
            ->from('lf_environment_groups', 'g')
            ->innerJoin('g', 'lf_environments', 'e', 'e.id = g.environment_id')
            ->where($q->expr()->in('g.group_gid',
                $q->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
        $byGid = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $byGid[(string)$row['group_gid']] = $row;
        }
        return [
            'groups' => array_map(static function (array $group) use ($byGid): array {
                $gid = (string)$group['id'];
                $stored = $byGid[$gid] ?? null;
                $state = $stored === null ? 'disabled' : (string)$stored['state'];
                if ($stored !== null && $stored['state'] !== $stored['status']) {
                    // Inconsistent lifecycle state must never display as active.
                    $state = 'suspended';
                }
                return [
                    'id' => $gid,
                    'name' => (string)$group['name'],
                    'state' => $state,
                    'environmentId' => $stored === null ? null : (string)$stored['public_id'],
                ];
            }, $groups),
            'nextOffset' => $listing['nextOffset'],
        ];
    }

    /**
     * Instance-only, immutable SaaS lifecycle history. Cursor pagination
     * follows monotonically increasing audit IDs so new events do not shift
     * already-read pages. Do not join or return financial ledger records.
     *
     * A deleted group still has a durable audit history; do not require its
     * current Nextcloud group object to exist to inspect that history.
     *
     * @return array{environmentId:string,events:list<array<string,mixed>>,nextBeforeId:?int}
     */
    public function groupAudit(string $gid, int $beforeId = 0): array {
        $this->governance->requireInstanceAdmin();
        if ($gid === '' || strlen($gid) > 64 || $beforeId < 0) {
            throw new DomainException('environment_not_found');
        }
        $row = $this->lookupByGroup($gid);
        if ($row === null) {
            throw new DomainException('environment_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'actor_uid', 'action', 'subject_type', 'subject_id', 'occurred_at')
            ->from('lf_environment_audit')
            ->where($q->expr()->eq('environment_id',
                $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(31);
        if ($beforeId > 0) {
            $q->andWhere($q->expr()->lt('id',
                $q->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $hasNext = count($rows) > 30;
        $rows = array_slice($rows, 0, 30);
        $events = array_map(static fn(array $item): array => [
            'id' => (int)$item['id'],
            'actorUid' => (string)$item['actor_uid'],
            'action' => (string)$item['action'],
            'subjectType' => (string)$item['subject_type'],
            'subjectId' => (string)$item['subject_id'],
            'occurredAt' => (int)$item['occurred_at'],
        ], $rows);
        return [
            'environmentId' => (string)$row['public_id'],
            'events' => $events,
            'nextBeforeId' => $hasNext ? (int)end($events)['id'] : null,
        ];
    }

    /** @return array{id:string,name:string,state:string,canManage:bool} */
    public function enableGroup(string $gid): array {
        $this->governance->requireInstanceAdmin();
        if (!$this->governance->enabled()) {
            throw new DomainException('governance_disabled');
        }
        $this->policies->requireInstanceEnabled('environments.enabled');
        $this->governance->requireGroup($gid);
        $existing = $this->lookupByGroup($gid);
        if ($existing !== null) {
            if ($existing['state'] === 'suspended') {
                // Never automatically reactivate a suspended identity.
                throw new DomainException('environment_suspended');
            }
            return $this->publicRow($existing, false);
        }

        $now = time();
        $uuid = self::uuid();
        $this->db->beginTransaction();
        try {
            $this->write->insert('lf_environments', [
                'public_id' => $uuid,
                'name' => (string)$this->groups->get($gid)?->getDisplayName(),
                'status' => 'enabled_pending_admin',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $environmentId = (int)$this->db->lastInsertId('*PREFIX*lf_environments');
            $this->write->insert('lf_environment_groups', [
                'environment_id' => $environmentId,
                'group_gid' => $gid,
                'state' => 'enabled_pending_admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($environmentId, 'environment.enabled', 'nextcloud_group', $gid);
            $this->db->commit();
        } catch (DatabaseException $e) {
            $this->db->rollBack();
            if ($e->getReason() !== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // A simultaneous request for the same GID is an expected race;
            // a UUID collision or unrelated unique conflict is not.
            $existing = $this->lookupByGroup($gid);
            if ($existing === null) {
                throw $e;
            }
            return $this->publicRow($existing, false);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->publicRow($this->requireByPublicId($uuid), false);
    }

    /** @return array{id:string,name:string,state:string,canManage:bool} */
    public function activate(string $publicId): array {
        if (!$this->governance->enabled()) {
            throw new DomainException('governance_disabled');
        }
        $this->policies->requireInstanceEnabled('environments.enabled');
        $row = $this->requireByPublicId($publicId);
        $gid = $row['group_gid'];
        // A Nextcloud instance admin never implicitly becomes a financial
        // environment operator or a qualified group subadministrator.
        if (!$this->governance->isDelegatedManager($gid)) {
            throw new DomainException('environment_not_found');
        }
        if ($row['state'] === 'suspended') {
            throw new DomainException('environment_suspended');
        }
        if ($row['state'] === 'active') {
            return $this->publicRow($row, true);
        }
        if ($row['state'] !== 'enabled_pending_admin') {
            throw new DomainException('invalid_state_transition');
        }
        $now = time();
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lf_environment_groups')
                ->set('state', $q->createNamedParameter('active'))
                ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('environment_id',
                    $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('state',
                    $q->createNamedParameter('enabled_pending_admin')))
                ->executeStatement();
            if ($changed === 1) {
                $q = $this->db->getQueryBuilder();
                $updated = $q->update('lf_environments')
                    ->set('status', $q->createNamedParameter('active'))
                    ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('id',
                        $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('status',
                        $q->createNamedParameter('enabled_pending_admin')))
                    ->executeStatement();
                if ($updated !== 1) {
                    throw new DomainException('concurrent_change');
                }
                $this->audit((int)$row['id'], 'environment.activated', 'nextcloud_group', $gid);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $fresh = $this->requireByPublicId($publicId);
        if ($fresh['state'] !== 'active') {
            throw new DomainException('concurrent_change');
        }
        return $this->publicRow($fresh, true);
    }

    /**
     * Scope checks are always against the current qualified group admin,
     * without creating financial ledger membership.
     *
     * @return array{id:int,publicId:string,groupGid:string}
     */
    public function requireActiveManager(string $publicId): array {
        if (!$this->governance->enabled()) {
            throw new DomainException('governance_disabled');
        }
        $this->policies->requireInstanceEnabled('environments.enabled');
        $row = $this->requireByPublicId($publicId);
        if (!$this->governance->isDelegatedManager((string)$row['group_gid'])) {
            throw new DomainException('environment_not_found');
        }
        if ((string)$row['state'] !== 'active' || (string)$row['status'] !== 'active') {
            throw new DomainException('environment_suspended');
        }
        return [
            'id' => (int)$row['id'],
            'publicId' => (string)$row['public_id'],
            'groupGid' => (string)$row['group_gid'],
        ];
    }

    /** @return array{id:string,name:string,state:string,canManage:bool} */
    public function suspendGroup(string $gid): array {
        $this->governance->requireInstanceAdmin();
        if (!$this->governance->enabled()) {
            throw new DomainException('governance_disabled');
        }
        $row = $this->lookupByGroup($gid);
        if ($row === null) {
            throw new DomainException('environment_not_found');
        }
        if ($row['state'] === 'suspended') {
            return $this->publicRow($row, false);
        }
        $now = time();
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lf_environment_groups')
                ->set('state', $q->createNamedParameter('suspended'))
                ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('environment_id',
                    $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('state',
                    $q->createNamedParameter((string)$row['state'])))
                ->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $q = $this->db->getQueryBuilder();
            $q->update('lf_environments')
                ->set('status', $q->createNamedParameter('suspended'))
                ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',
                    $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            $this->audit((int)$row['id'], 'environment.suspended', 'nextcloud_group', $gid);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->publicRow($this->lookupByGroup($gid) ?? throw new DomainException('environment_not_found'), false);
    }

    /**
     * Nextcloud has deleted this group. A later group using the same textual
     * GID is a new administrative principal, not the old SaaS environment.
     * Keep all accounting and audit records while revoking the old bindings.
     *
     * Invoked only by the server's GroupDeletedEvent, never from an HTTP
     * parameter. A missing or already-suspended environment is safe to process.
     */
    public function suspendDeletedGroup(string $gid): void {
        if ($gid === '' || strlen($gid) > 64) {
            return;
        }
        $now = time();
        $this->db->beginTransaction();
        try {
            $row = $this->lookupByGroup($gid);
            if ($row !== null) {
                $q = $this->db->getQueryBuilder();
                $q->update('lf_environment_groups')
                    ->set('state', $q->createNamedParameter('suspended'))
                    ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('group_gid', $q->createNamedParameter($gid)))
                    ->executeStatement();

                $q = $this->db->getQueryBuilder();
                $q->update('lf_environments')
                    ->set('status', $q->createNamedParameter('suspended'))
                    ->set('revision', $q->createFunction('revision + 1'))
                    ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('id',
                        $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                $this->write->insert('lf_environment_audit', [
                    'environment_id' => (int)$row['id'],
                    'actor_uid' => $this->session->getUser()?->getUID() ?? 'system',
                    'action' => 'environment.group_deleted',
                    'subject_type' => 'nextcloud_group',
                    'subject_id' => $gid,
                    'occurred_at' => $now,
                ]);
            }
            // A recreated group must not inherit historical delegated admins
            // or legacy group viewer grants from the deleted principal.
            $q = $this->db->getQueryBuilder();
            $q->delete('lf_governance_delegates')
                ->where($q->expr()->eq('group_gid', $q->createNamedParameter($gid)))
                ->executeStatement();
            $q = $this->db->getQueryBuilder();
            $q->delete('lf_group_grants')
                ->where($q->expr()->eq('group_gid', $q->createNamedParameter($gid)))
                ->executeStatement();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Instance-admin-only recovery. Reenablement is deliberately a two-stage
     * operation: this returns to pending_admin, and only a currently qualified
     * group subadmin/delegate can activate the environment afterward.
     *
     * Recovery reasons are typed codes persisted durably in the append-only
     * environment audit: (environment_id, actor_uid, action, subject_type,
     * subject_id, occurred_at). No migration or free-text audit injection.
     *
     * @return array{id:string,name:string,state:string,canManage:bool}
     */
    public function recoverGroup(string $gid, string $reasonCode): array {
        $this->governance->requireInstanceAdmin();
        if (!$this->governance->enabled()) {
            throw new DomainException('governance_disabled');
        }
        $reasons = [
            'delegation_reviewed',
            'group_admin_restored',
            'security_incident_resolved',
            'membership_corrected',
        ];
        if (!in_array($reasonCode, $reasons, true)) {
            throw new DomainException('invalid_recovery_reason');
        }
        $this->governance->requireGroup($gid);
        $row = $this->lookupByGroup($gid);
        if ($row === null) {
            throw new DomainException('environment_not_found');
        }
        if ($row['state'] !== 'suspended' || $row['status'] !== 'suspended') {
            throw new DomainException('environment_not_suspended');
        }
        $now = time();
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lf_environment_groups')
                ->set('state', $q->createNamedParameter('enabled_pending_admin'))
                ->set('updated_at',
                    $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('environment_id',
                    $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('state',
                    $q->createNamedParameter('suspended')))
                ->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $q = $this->db->getQueryBuilder();
            $updated = $q->update('lf_environments')
                ->set('status', $q->createNamedParameter('enabled_pending_admin'))
                ->set('revision', $q->createFunction('revision + 1'))
                ->set('updated_at',
                    $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',
                    $q->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('status',
                    $q->createNamedParameter('suspended')))
                ->executeStatement();
            if ($updated !== 1) {
                throw new DomainException('concurrent_change');
            }
            $this->audit((int)$row['id'], 'environment.recovered',
                'recovery_reason', $reasonCode);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $fresh = $this->lookupByGroup($gid);
        if ($fresh === null || $fresh['state'] !== 'enabled_pending_admin') {
            throw new DomainException('concurrent_change');
        }
        return $this->publicRow($fresh, false);
    }

    /**
     * Discovery never includes balances, companies, workspace or grants.
     * One multi-group user sees separate environments, never an ACL union.
     *
     * @return list<array{id:string,name:string,state:string,canManage:bool}>
     */
    public function discover(): array {
        $user = $this->session->getUser();
        if ($user === null) {
            throw new DomainException('authentication_required');
        }
        $groupIds = array_unique(array_merge(
            $this->groups->getUserGroupIds($user),
            $this->governance->delegatedGroups(),
        ));
        if ($groupIds === []) {
            return [];
        }
        $q = $this->db->getQueryBuilder();
        $q->select('e.id', 'e.public_id', 'e.name', 'e.status', 'g.group_gid', 'g.state')
            ->from('lf_environment_groups', 'g')
            ->innerJoin('g', 'lf_environments', 'e', 'e.id = g.environment_id')
            ->where($q->expr()->in('g.group_gid',
                $q->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('e.id', 'ASC');
        $items = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            if ($this->groups->get((string)$row['group_gid']) === null) {
                continue;
            }
            $items[] = $this->publicRow($row,
                $this->governance->isDelegatedManager((string)$row['group_gid']));
        }
        return $items;
    }

    /** @return array<string,mixed>|null */
    private function lookupByGroup(string $gid): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('e.id', 'e.public_id', 'e.name', 'e.status', 'g.group_gid', 'g.state')
            ->from('lf_environment_groups', 'g')
            ->innerJoin('g', 'lf_environments', 'e', 'e.id = g.environment_id')
            ->where($q->expr()->eq('g.group_gid',
                $q->createNamedParameter($gid, IQueryBuilder::PARAM_STR)));
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    private function requireByPublicId(string $publicId): array {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $publicId) !== 1) {
            throw new DomainException('environment_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('e.id', 'e.public_id', 'e.name', 'e.status', 'g.group_gid', 'g.state')
            ->from('lf_environment_groups', 'g')
            ->innerJoin('g', 'lf_environments', 'e', 'e.id = g.environment_id')
            ->where($q->expr()->eq('e.public_id',
                $q->createNamedParameter($publicId, IQueryBuilder::PARAM_STR)));
        $row = $q->executeQuery()->fetchAssociative();
        if ($row === false || $this->groups->get((string)$row['group_gid']) === null) {
            throw new DomainException('environment_not_found');
        }
        return $row;
    }

    /** @param array<string,mixed> $row
     *  @return array{id:string,name:string,state:string,canManage:bool}
     */
    private function publicRow(array $row, bool $canManage): array {
        return [
            'id' => (string)$row['public_id'],
            'name' => (string)$row['name'],
            'state' => (string)$row['state'],
            'canManage' => $canManage,
        ];
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function audit(int $environmentId, string $action, string $subjectType, string $subjectId): void {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            throw new DomainException('authentication_required');
        }
        $this->write->insert('lf_environment_audit', [
            'environment_id' => $environmentId,
            'actor_uid' => $uid,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'occurred_at' => time(),
        ]);
    }
}
