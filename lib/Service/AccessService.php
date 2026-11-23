<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCA\LibreFinance\Domain\Saas\FinancialCapabilities;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IRequest;

final class AccessService {
    private const LEVELS = [
        'viewer' => 10,
        'auditor' => 15,
        'operator' => 20,
        'manager' => 30,
        'owner' => 40,
    ];

    public function __construct(
        private IDBConnection $db,
        private IUserSession $session,
        private IGroupManager $groups,
        private GovernanceService $governance,
        private SaasPolicyRuntimeGate $policies,
        private IRequest $request,
    ) {}

    public function uid(): string {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new DomainException('authentication_required');
        }
        return $uid;
    }

    /** @return array<int, array<string, mixed>> */
    public function accessibleSpaces(): array {
        $uid = $this->uid();
        $result = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.*', 'm.role')
            ->from('lf_spaces', 's')
            ->innerJoin('s', 'lf_members', 'm', 'm.space_id = s.id')
            ->where($qb->expr()->eq('m.uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('s.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $row['id'] = (int)$row['id'];
            $result[(int)$row['id']] = $row;
        }

        $user = $this->session->getUser();
        $gids = $user !== null ? $this->groups->getUserGroupIds($user) : [];
        if ($gids !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('s.*', 'g.role')
                ->from('lf_spaces', 's')
                ->innerJoin('s', 'lf_group_grants', 'g',
                    'g.space_id = s.id AND (s.governance_gid IS NULL OR s.governance_gid = g.group_gid)')
                ->where($qb->expr()->in('g.group_gid', $qb->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('s.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                $id = (int)$row['id'];
                if (!isset($result[$id]) || self::rank((string)$row['role']) > self::rank((string)$result[$id]['role'])) {
                    $row['id'] = $id;
                    $result[$id] = $row;
                }
            }
        }
        foreach ($result as $id => $space) {
            if (!$this->governance->isInstanceAdmin()
                && (($this->governance->enabled() && $space['governance_gid'] === null)
                    || ($space['governance_gid'] !== null
                        && !$this->governance->isInGovernanceGroup((string)$space['governance_gid'])
                        && !$this->governance->isDelegatedManager((string)$space['governance_gid'])))) {
                unset($result[$id]);
            }
        }
        foreach ($result as &$space) {
            if ($space['governance_gid'] !== null
                && !$this->governance->isInstanceAdmin()) {
                if ($space['role'] === 'owner') $space['role'] = 'viewer';
                elseif ($space['role'] === 'manager') $space['role'] = 'operator';
            }
        }
        unset($space);
        // Nextcloud group subadmins need not be ordinary members of their
        // managed group; discover those contexts without leaking other groups.
        if ($user !== null) {
            $subadminGroups = $this->governance->delegatedGroups();
            if ($subadminGroups !== []) {
                $q = $this->db->getQueryBuilder();
                $q->select('s.*')->from('lf_spaces', 's')
                    ->where($q->expr()->in('s.governance_gid',
                        $q->createNamedParameter($subadminGroups, IQueryBuilder::PARAM_STR_ARRAY)))
                    ->andWhere($q->expr()->eq('s.archived',
                        $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
                foreach ($q->executeQuery()->fetchAllAssociative() as $space) {
                    $space['id'] = (int)$space['id'];
                    $space['role'] = 'manager';
                    $result[$space['id']] = $space;
                }
            }
        }
        // A global administrator can provision/recover every context without
        // granting themselves a user membership inside each organization.
        if ($this->governance->isInstanceAdmin() && $this->governance->enabled()) {
            $q = $this->db->getQueryBuilder();
            $q->select('s.*')->from('lf_spaces', 's')
                ->where($q->expr()->eq('s.archived', $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
            foreach ($q->executeQuery()->fetchAllAssociative() as $space) {
                $space['id'] = (int)$space['id'];
                $space['role'] = 'owner';
                $result[$space['id']] = $space;
            }
        }
        // SaaS scopes are a strict exception to legacy managed-space roles:
        // no instance-admin, delegated-subadmin or group grant may manufacture
        // financial access. One query for bindings and one for memberships,
        // avoiding an N+1 lookup for every listed workspace.
        $this->filterSaasListing($result, $uid, $gids);
        // Accepted external invitations discover only their exact workspace,
        // never the whole Nextcloud group or another tenant's financial data.
        $this->addExternalListing($result, $uid, $gids);
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    public function requireRole(int $spaceId, string $minRole = 'viewer'): string {
        $uid = $this->uid();
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid', 'archived')->from('lf_spaces')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $space = $qb->executeQuery()->fetchAssociative();
        if (!$space || (bool)$space['archived']) {
            throw new DomainException('space_not_found');
        }
        // The new environment-to-workspace binding is authoritative and must
        // be checked BEFORE the legacy global-admin and group-admin bypasses.
        $saasScope = $this->saasScope($spaceId);
        if ($saasScope !== null) {
            // Policy restrictions are not grants; even direct workspace roles
            // are suspended when the instance disables SaaS environments.
            if ($this->policies->instance('environments.enabled')['effectiveValue'] !== true) {
                throw new DomainException('space_not_found');
            }
            if (($saasScope['review_state'] ?? null) !== 'confirmed') {
                throw new DomainException('space_not_found');
            }
            // Turning off instance-wide managed mode suspends new SaaS access,
            // not only provisioning. The app must never keep using stale ACLs.
            if (!$this->governance->enabled()) {
                throw new DomainException('space_not_found');
            }
            if ((string)($saasScope['environment_status'] ?? '') !== 'active'
                || (string)($saasScope['group_state'] ?? '') !== 'active') {
                throw new DomainException('space_not_found');
            }
            $gid = (string)($saasScope['group_gid'] ?? '');
            $user = $this->session->getUser();
            if ($gid === '' || $this->groups->get($gid) === null || $user === null) {
                throw new DomainException('space_not_found');
            }
            $isMember = in_array($gid, $this->groups->getUserGroupIds($user), true);
            if ($isMember) {
                $q = $this->db->getQueryBuilder();
                $q->select('role')->from('lf_members')
                    ->where($q->expr()->eq('space_id',
                        $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('uid',
                        $q->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));
                $role = $q->executeQuery()->fetchOne();
            } else {
                // A number of legacy user-preference writes still authorize
                // using the viewer role. External invitation roles are strictly
                // read-only across those paths, not just ledger.post.
                if (!in_array($this->request->getMethod(), ['GET', 'HEAD'], true)) {
                    throw new DomainException('permission_denied');
                }
                $role = $this->externalAcceptedRole($spaceId, $uid);
            }
            if (!is_string($role) || self::rank($role) === 0) {
                throw new DomainException('space_not_found');
            }
            if (self::rank($role) < self::rank($minRole)) {
                // Membership is known, but the selected operation is
                // forbidden. Do not mask a valid viewer as a missing space.
                throw new DomainException('permission_denied');
            }
            return $role;
        }
        if ($this->governance->isInstanceAdmin() && $this->governance->enabled()) {
            return 'owner';
        }
        $bound = $this->governance->groupFor($spaceId);
        if ($bound === null && $this->governance->enabled()) {
            throw new DomainException('space_not_found');
        }
        if ($bound !== null) {
            if ($this->governance->isDelegatedManager($bound)) {
                return 'manager';
            }
            if (!$this->governance->isInGovernanceGroup($bound)) {
                throw new DomainException('space_not_found');
            }
        }
        if ($bound === null && (string)$space['owner_uid'] === $uid) {
            return 'owner';
        }

        $best = null;
        $qb = $this->db->getQueryBuilder();
        $qb->select('role')->from('lf_members')
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));
        $member = $qb->executeQuery()->fetchOne();
        if ($member !== false) {
            $best = (string)$member;
        }

        $user = $this->session->getUser();
        $gids = $user !== null ? $this->groups->getUserGroupIds($user) : [];
        if ($bound !== null) {
            // A grant from a second group must not confer privilege in a
            // context governed by the first group.
            $gids = in_array($bound, $gids, true) ? [$bound] : [];
        }
        if ($gids !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('role')->from('lf_group_grants')
                ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('group_gid', $qb->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)));
            foreach ($qb->executeQuery()->fetchFirstColumn() as $role) {
                if ($best === null || self::rank((string)$role) > self::rank($best)) {
                    $best = (string)$role;
                }
            }
        }

        if ($best === null) {
            throw new DomainException('space_not_found');
        }
        // Legacy owner membership cannot turn into global governance control
        // merely because an instance admin bound that old space to a group.
        if ($bound !== null && $best === 'owner') {
            $best = 'viewer';
        } elseif ($bound !== null && $best === 'manager') {
            // Management comes only from the Nextcloud group-subadmin role.
            $best = 'operator';
        }
        if (self::rank($best) < self::rank($minRole)) {
            throw new DomainException('permission_denied');
        }
        return $best;
    }

    /**
     * A SaaS binding is authoritative. Do not infer it from legacy GIDs.
     */
    public function isSaasWorkspace(int $spaceId): bool {
        return $this->saasScope($spaceId) !== null;
    }

    /**
     * Owner-only lifecycle access also permits an archived space. Ordinary
     * requireRole() intentionally denies financial reads from archived spaces.
     */
    public function requireSaasOwnerIncludingArchived(int $spaceId): void {
        $uid = $this->uid();
        $scope = $this->saasScope($spaceId);
        if ($scope === null || ($scope['review_state'] ?? null) !== 'confirmed'
            || $this->policies->instance('environments.enabled')['effectiveValue'] !== true
            || !$this->governance->enabled()
            || ($scope['environment_status'] ?? null) !== 'active'
            || ($scope['group_state'] ?? null) !== 'active') {
            throw new DomainException('space_not_found');
        }
        $gid = (string)($scope['group_gid'] ?? '');
        $user = $this->session->getUser();
        if ($gid === '' || $this->groups->get($gid) === null || $user === null
            || !in_array($gid, $this->groups->getUserGroupIds($user), true)) {
            throw new DomainException('space_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('s.id')->from('lf_spaces', 's')
            ->innerJoin('s', 'lf_members', 'm', 'm.space_id = s.id')
            ->where($q->expr()->eq('s.id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('s.owner_uid',
                $q->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->andWhere($q->expr()->eq('m.uid',
                $q->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->andWhere($q->expr()->eq('m.role',
                $q->createNamedParameter('owner')));
        if ($q->executeQuery()->fetchOne() === false) {
            throw new DomainException('space_not_found');
        }
    }

    public function requireFinancialCapability(int $spaceId, string $capability): string {
        $role = $this->requireRole($spaceId, 'viewer');
        FinancialCapabilities::requireAllowed($role, $capability);
        return $role;
    }

    /**
     * Internal SaaS role grants are only for current Nextcloud group members.
     * An out-of-group recipient must use a separate, explicit, audited external
     * invitation; persisting a dormant lf_members row is not an invitation.
     */
    public function requireSaasRecipientGroupMember(int $spaceId, string $recipientUid): void {
        $scope = $this->saasScope($spaceId);
        $gid = (string)($scope['group_gid'] ?? '');
        if ($scope === null || ($scope['review_state'] ?? null) !== 'confirmed'
            || ($scope['environment_status'] ?? null) !== 'active'
            || ($scope['group_state'] ?? null) !== 'active'
            || $gid === '' || $this->groups->get($gid) === null
            || !$this->groups->isInGroup($recipientUid, $gid)) {
            throw new DomainException('permission_denied');
        }
    }

    /** @return array<string,mixed>|null */
    private function saasScope(int $spaceId): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('es.space_id', 'es.review_state', 'e.status AS environment_status',
                'g.group_gid', 'g.state AS group_state')
            ->from('lf_environment_spaces', 'es')
            ->leftJoin('es', 'lf_environments', 'e', 'e.id = es.environment_id')
            ->leftJoin('es', 'lf_environment_groups', 'g', 'g.environment_id = es.environment_id')
            ->where($q->expr()->eq('es.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * The user may be in multiple groups, but their financial roles cannot
     * combine. Filter each environment's ledger using its unique binding and
     * direct membership, never the inherited group/subadmin grants.
     *
     * @param array<int,array<string,mixed>> $spaces
     * @param list<string> $gids
     */
    private function filterSaasListing(array &$spaces, string $uid, array $gids): void {
        if ($spaces === []) {
            return;
        }
        $ids = array_map('intval', array_keys($spaces));
        $q = $this->db->getQueryBuilder();
        $q->select('es.space_id', 'es.review_state', 'e.status AS environment_status',
                'g.group_gid', 'g.state AS group_state')
            ->from('lf_environment_spaces', 'es')
            ->leftJoin('es', 'lf_environments', 'e', 'e.id = es.environment_id')
            ->leftJoin('es', 'lf_environment_groups', 'g', 'g.environment_id = es.environment_id')
            ->where($q->expr()->in('es.space_id',
                $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $bindings = $q->executeQuery()->fetchAllAssociative();
        if ($bindings === []) {
            return;
        }
        $boundIds = array_map(static fn(array $row): int => (int)$row['space_id'], $bindings);
        $q = $this->db->getQueryBuilder();
        $q->select('space_id', 'role')->from('lf_members')
            ->where($q->expr()->eq('uid', $q->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->andWhere($q->expr()->in('space_id',
                $q->createNamedParameter($boundIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $memberRoles = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $membership) {
            $memberRoles[(int)$membership['space_id']] = (string)$membership['role'];
        }
        $managedEnabled = $this->governance->enabled()
            && $this->policies->instance('environments.enabled')['effectiveValue'] === true;
        foreach ($bindings as $binding) {
            $spaceId = (int)$binding['space_id'];
            $gid = (string)($binding['group_gid'] ?? '');
            $role = $memberRoles[$spaceId] ?? null;
            if (!$managedEnabled || ($binding['review_state'] ?? null) !== 'confirmed'
                || $gid === '' || $this->groups->get($gid) === null
                || !in_array($gid, $gids, true)
                || ($binding['environment_status'] ?? null) !== 'active'
                || ($binding['group_state'] ?? null) !== 'active'
                || $role === null || self::rank($role) === 0) {
                unset($spaces[$spaceId]);
            } else {
                $spaces[$spaceId]['role'] = $role;
                // Only current, authorized financial members receive the
                // marker. It is presentation metadata, not a permission grant.
                $spaces[$spaceId]['saasManaged'] = true;
            }
        }
    }

    /**
     * External grants are evaluated on every protected request. A revoked
     * grant or a newly restrictive ancestor immediately denies the next call.
     */
    private function externalAcceptedRole(int $spaceId, string $uid): ?string {
        if ($this->policies->workspace($spaceId, 'externalSharing.enabled')['effectiveValue'] !== true) {
            return null;
        }
        $q = $this->db->getQueryBuilder();
        $q->select('role')->from('lf_saas_external_grants')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('recipient_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('status', $q->createNamedParameter('accepted')))
            ->andWhere($q->expr()->gt('expires_at',
                $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
        $role = $q->executeQuery()->fetchOne();
        return in_array($role, ['viewer', 'auditor'], true) ? $role : null;
    }

    /**
     * @param array<int,array<string,mixed>> $spaces
     * @param list<string> $gids
     */
    private function addExternalListing(array &$spaces, string $uid, array $gids): void {
        if (!$this->governance->enabled()
            || $this->policies->instance('environments.enabled')['effectiveValue'] !== true) {
            return;
        }
        $q = $this->db->getQueryBuilder();
        $q->select('s.*', 'x.role', 'es.environment_id', 'eg.group_gid')
            ->from('lf_saas_external_grants', 'x')
            ->innerJoin('x', 'lf_spaces', 's', 's.id = x.space_id')
            ->innerJoin('x', 'lf_environment_spaces', 'es', 'es.space_id = x.space_id')
            ->innerJoin('x', 'lf_environments', 'e', 'e.id = es.environment_id')
            ->innerJoin('x', 'lf_environment_groups', 'eg', 'eg.environment_id = es.environment_id')
            ->where($q->expr()->eq('x.recipient_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('x.status', $q->createNamedParameter('accepted')))
            ->andWhere($q->expr()->in('x.role', $q->createNamedParameter(
                ['viewer', 'auditor'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->gt('x.expires_at',
                $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('s.archived',
                $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($q->expr()->eq('es.review_state',
                $q->createNamedParameter('confirmed')))
            ->andWhere($q->expr()->eq('e.status', $q->createNamedParameter('active')))
            ->andWhere($q->expr()->eq('eg.state', $q->createNamedParameter('active')));
        $external = [];
        $environmentBySpace = [];
        $groupExists = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $id = (int)$row['id'];
            $gid = (string)$row['group_gid'];
            if ($gid === '' || in_array($gid, $gids, true)) {
                continue;
            }
            if (!array_key_exists($gid, $groupExists)) {
                $groupExists[$gid] = $this->groups->get($gid) !== null;
            }
            if (!$groupExists[$gid]) {
                continue;
            }
            $environmentBySpace[$id] = (int)$row['environment_id'];
            unset($row['environment_id'], $row['group_gid']);
            $row['id'] = $id;
            $row['saasManaged'] = true;
            $row['externalInvitation'] = true;
            $external[$id] = $row;
        }
        $allowed = $this->policies->workspaceBooleanBatch($environmentBySpace, 'externalSharing.enabled');
        foreach ($external as $id => $row) {
            if (($allowed[$id] ?? false) === true && !isset($spaces[$id])) {
                $spaces[$id] = $row;
            }
        }
    }

    public static function rank(string $role): int {
        return self::LEVELS[$role] ?? 0;
    }

    public static function validateGrantRole(string $role): void {
        if (!in_array($role, ['viewer', 'auditor', 'operator', 'manager'], true)) {
            throw new DomainException('invalid_role');
        }
    }
}
