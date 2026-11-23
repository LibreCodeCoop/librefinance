<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

final class SpaceService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private AccessService $access,
        private IUserManager $users,
        private IGroupManager $groups,
        private GovernanceService $governance,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function list(): array {
        return $this->access->accessibleSpaces();
    }

    /** @return array<string,mixed> */
    public function create(string $name, string $kind, string $currency, string $color = '#64748B', ?string $governanceGroup = null): array {
        $this->governance->requireCreationAllowed();
        if ($governanceGroup !== null) {
            $this->governance->requireInstanceAdmin();
            $this->governance->requireGroup($governanceGroup);
        }
        $uid = $this->access->uid();
        $name = trim($name);
        $currency = strtoupper(trim($currency));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainException('invalid_name');
        }
        if (!in_array($kind, ['personal', 'collective', 'organization'], true)) {
            throw new DomainException('invalid_space_kind');
        }
        self::validateColor($color);
        if ($currency !== 'BRL') {
            throw new DomainException('invalid_currency');
        }

        $this->db->beginTransaction();
        try {
            $this->writer->insert('lf_spaces', [
                'name' => $name,
                'kind' => $kind,
                'currency' => $currency,
                'owner_uid' => $uid,
                'governance_gid' => $governanceGroup,
                'created_at' => time(),
                'archived' => 0,
                'accent_color' => strtoupper($color),
            ]);
            $spaceId = $this->db->lastInsertId('*PREFIX*lf_spaces');
            $this->writer->insert('lf_members', ['space_id' => $spaceId, 'uid' => $uid, 'role' => 'owner']);
            if ($governanceGroup !== null) {
                $this->writer->insert('lf_group_grants', [
                    'space_id' => $spaceId, 'group_gid' => $governanceGroup, 'role' => 'viewer',
                ]);
            }
            foreach ([
                ['1000', 'Caixa', 'asset', true],
                ['3000', 'Patrimônio líquido', 'equity', false],
                ['4000', 'Receitas', 'revenue', false],
                ['5000', 'Despesas', 'expense', false],
            ] as [$code, $label, $class, $bank]) {
                $this->writer->insert('lf_accounts', [
                    'space_id' => $spaceId, 'code' => $code,
                    'name' => $label, 'class' => $class, 'currency' => $currency,
                    'is_bank' => $bank ? 1 : 0, 'active' => 1,
                ]);
            }
            foreach ([['Dinheiro', 'cash'], ['Transferência bancária', 'bank_transfer']]
                as [$methodName, $methodKind]) {
                $this->writer->insert('lf_payment_methods', [
                    'space_id' => $spaceId, 'name' => $methodName, 'kind' => $methodKind,
                    'bank_account_id' => null, 'active' => true,
                    'created_at' => time(), 'updated_at' => time(),
                ]);
            }
            $this->audit($spaceId, 'space.created', 'space', $spaceId);
            $this->db->commit();
            return ['id' => $spaceId, 'name' => $name, 'kind' => $kind, 'currency' => $currency, 'accent_color' => strtoupper($color), 'role' => 'owner'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private static function validateColor(string $color): void {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/D', $color) !== 1) {
            throw new DomainException('invalid_space_color');
        }
    }

    /** @return array<int,array<string,mixed>> Archived workspaces owned by the caller. */
    public function archived(): array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'name', 'kind', 'currency', 'created_at')
            ->from('lf_spaces')
            ->where($q->expr()->eq('owner_uid',
                $q->createNamedParameter($this->access->uid(), IQueryBuilder::PARAM_STR)))
            ->andWhere($q->expr()->eq('archived',
                $q->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('id', 'DESC');
        $rows = $q->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['role'] = 'owner';
        }
        unset($row);
        // Legacy archived views must not reveal a newly bound SaaS ledger
        // after a group, owner role or environment has been revoked.
        $safe = [];
        foreach ($rows as $row) {
            $spaceId = (int)$row['id'];
            if ($this->access->isSaasWorkspace($spaceId)) {
                try {
                    $this->access->requireSaasOwnerIncludingArchived($spaceId);
                } catch (DomainException) {
                    continue;
                }
            }
            $safe[] = $row;
        }
        return $safe;
    }

    /** @return array<string,mixed> */
    public function rename(int $spaceId, string $name, ?string $color = null): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainException('invalid_name');
        }
        if ($color !== null) self::validateColor($color);
        $this->db->beginTransaction();
        try {
            $record = $this->owned($spaceId);
            if ((bool)$record['archived']) {
                throw new DomainException('space_archived');
            }
            if ((string)$record['name'] === $name &&
                ($color === null || strtoupper((string)$record['accent_color']) === strtoupper($color))) {
                // MariaDB reports zero changed rows for an unchanged value.
                // A harmless retry of a rename must not be a conflict.
                $this->db->commit();
                return ['id' => $spaceId, 'name' => $name,
                    'accent_color' => $record['accent_color'], 'archived' => false];
            }
            $q = $this->db->getQueryBuilder();
            $q->update('lf_spaces')->set('name', $q->createNamedParameter($name));
            if ($color !== null) {
                $q->set('accent_color', $q->createNamedParameter(strtoupper($color)));
            }
            $q->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('archived',
                    $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
            if ($this->access->isSaasWorkspace($spaceId)
                || (!$this->governance->enabled() && $this->governance->groupFor($spaceId) === null)) {
                $q->andWhere($q->expr()->eq('owner_uid',
                    $q->createNamedParameter($this->access->uid(), IQueryBuilder::PARAM_STR)));
            }
            $changed = $q->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $this->audit($spaceId, 'space.renamed', 'space', $spaceId);
            $this->db->commit();
            return ['id' => $spaceId, 'name' => $name,
                'accent_color' => $color === null ? $record['accent_color'] : strtoupper($color),
                'archived' => false];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function archive(int $spaceId): array {
        return $this->setArchived($spaceId, true);
    }

    /** @return array<string,mixed> */
    public function restore(int $spaceId): array {
        return $this->setArchived($spaceId, false);
    }

    /** @return array<string,mixed> */
    private function setArchived(int $spaceId, bool $target): array {
        $this->db->beginTransaction();
        try {
            $row = $this->owned($spaceId);
            if ((bool)$row['archived'] === $target) {
                throw new DomainException($target ? 'space_already_archived' : 'space_not_archived');
            }
            // Archiving only hides the workspace and suspends authorization.
            // Financial entries are never deleted; the owner may restore them.
            $q = $this->db->getQueryBuilder();
            $q->update('lf_spaces')
                ->set('archived', $q->createNamedParameter($target, IQueryBuilder::PARAM_BOOL))
                ->where($q->expr()->eq('id',
                    $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('archived',
                    $q->createNamedParameter(!$target, IQueryBuilder::PARAM_BOOL)));
            if ($this->access->isSaasWorkspace($spaceId)
                || (!$this->governance->enabled() && $this->governance->groupFor($spaceId) === null)) {
                $q->andWhere($q->expr()->eq('owner_uid',
                    $q->createNamedParameter($this->access->uid(), IQueryBuilder::PARAM_STR)));
            }
            $changed = $q->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('concurrent_change');
            }
            $this->audit($spaceId, $target ? 'space.archived' : 'space.restored', 'space', $spaceId);
            $this->db->commit();
            return ['id' => $spaceId, 'name' => (string)$row['name'], 'archived' => $target];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function owned(int $spaceId): array {
        if ($spaceId < 1) {
            throw new DomainException('space_not_found');
        }
        if ($this->access->isSaasWorkspace($spaceId)) {
            // Instance administrator is not a financial owner; a workspace
            // owner with current active environment membership is.
            $this->access->requireSaasOwnerIncludingArchived($spaceId);
        } elseif ($this->governance->enabled() || $this->governance->groupFor($spaceId) !== null) {
            $this->governance->requireInstanceAdmin();
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'name', 'archived', 'accent_color')->from('lf_spaces')
            ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        if ($this->access->isSaasWorkspace($spaceId)
                || (!$this->governance->enabled() && $this->governance->groupFor($spaceId) === null)) {
            $q->andWhere($q->expr()->eq('owner_uid',
                $q->createNamedParameter($this->access->uid(), IQueryBuilder::PARAM_STR)));
        }
        $record = $q->executeQuery()->fetchAssociative();
        if ($record === false) {
            throw new DomainException('space_not_found');
        }
        return $record;
    }

    public function shareUser(int $spaceId, string $uid, string $role): void {
        if ($this->access->isSaasWorkspace($spaceId)) {
            $this->access->requireFinancialCapability($spaceId, 'roles.manage');
        } else {
            $this->access->requireRole($spaceId, 'manager');
        }
        AccessService::validateGrantRole($role);
        $uid = trim($uid);
        $this->governance->checkUserGrant($spaceId, $uid);
        if (!$this->access->isSaasWorkspace($spaceId)
            && $this->governance->groupFor($spaceId) !== null
            && $role === 'manager' && !$this->governance->isInstanceAdmin()) {
            throw new DomainException('permission_denied');
        }
        if ($uid === '' || !$this->users->userExists($uid)) {
            throw new DomainException('user_not_found');
        }
        if ($this->access->isSaasWorkspace($spaceId)) {
            // Never materialize an out-of-tenant financial role that could
            // silently activate if the recipient joins the group later.
            $this->access->requireSaasRecipientGroupMember($spaceId, $uid);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid')->from('lf_spaces')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        if ($qb->executeQuery()->fetchOne() === $uid) {
            throw new DomainException('cannot_change_owner');
        }
        $this->db->beginTransaction();
        try {
            $existing = $this->db->getQueryBuilder();
            $existing->select('id')->from('lf_members')
                ->where($existing->expr()->eq('space_id', $existing->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($existing->expr()->eq('uid', $existing->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));
            $id = $existing->executeQuery()->fetchOne();
            if ($id === false) {
                $this->writer->insert('lf_members', ['space_id' => $spaceId, 'uid' => $uid, 'role' => $role]);
            } else {
                $this->writer->update('lf_members', ['role' => $role], ['id' => (int)$id, 'space_id' => $spaceId]);
            }
            $this->audit($spaceId, 'membership.granted', 'member', (int)($id === false ? $this->db->lastInsertId('*PREFIX*lf_members') : $id));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function shareGroup(int $spaceId, string $gid, string $role): void {
        $this->access->requireRole($spaceId, 'manager');
        if ($this->access->isSaasWorkspace($spaceId)) {
            throw new DomainException('permission_denied');
        }
        AccessService::validateGrantRole($role);
        $gid = trim($gid);
        $this->governance->checkGroupGrant($spaceId, $gid);
        if ($gid === '' || $this->groups->get($gid) === null) {
            throw new DomainException('group_not_found');
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id')->from('lf_group_grants')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('group_gid', $q->createNamedParameter($gid, IQueryBuilder::PARAM_STR)));
            $id = $q->executeQuery()->fetchOne();
            if ($id === false) {
                $this->writer->insert('lf_group_grants', ['space_id' => $spaceId, 'group_gid' => $gid, 'role' => $role]);
            } else {
                $this->writer->update('lf_group_grants', ['role' => $role], ['id' => (int)$id, 'space_id' => $spaceId]);
            }
            $this->audit($spaceId, 'group.granted', 'group_grant', (int)($id === false ? $this->db->lastInsertId('*PREFIX*lf_group_grants') : $id));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function revokeUser(int $spaceId, string $uid): void {
        if ($this->access->isSaasWorkspace($spaceId)) {
            $this->access->requireFinancialCapability($spaceId, 'roles.manage');
        } else {
            $this->access->requireRole($spaceId, 'manager');
        }
        $this->governance->checkUserGrant($spaceId, $uid);
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id', 'role')->from('lf_members')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('uid', $q->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));
            $member = $q->executeQuery()->fetchAssociative();
            if (!$member) {
                throw new DomainException('membership_not_found');
            }
            if ($member['role'] === 'owner') {
                throw new DomainException('cannot_change_owner');
            }
            $q = $this->db->getQueryBuilder();
            $q->delete('lf_members')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter((int)$member['id'], IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            $this->audit($spaceId, 'membership.revoked', 'member', (int)$member['id']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function revokeGroup(int $spaceId, string $gid): void {
        $this->access->requireRole($spaceId, 'manager');
        if ($this->access->isSaasWorkspace($spaceId)) {
            throw new DomainException('permission_denied');
        }
        $this->governance->checkGroupGrant($spaceId, $gid);
        if ($this->governance->groupFor($spaceId) === $gid) {
            throw new DomainException('permission_denied');
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id')->from('lf_group_grants')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('group_gid', $q->createNamedParameter($gid, IQueryBuilder::PARAM_STR)));
            $id = $q->executeQuery()->fetchOne();
            if ($id === false) {
                throw new DomainException('membership_not_found');
            }
            $q = $this->db->getQueryBuilder();
            $q->delete('lf_group_grants')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            $this->audit($spaceId, 'group.revoked', 'group_grant', (int)$id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function members(int $spaceId): array {
        if ($this->access->isSaasWorkspace($spaceId)) {
            $this->access->requireFinancialCapability($spaceId, 'roles.read');
        } else {
            $this->access->requireRole($spaceId, 'manager');
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('uid', 'role')->from('lf_members')
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $members = $qb->executeQuery()->fetchAllAssociative();
        $bound = $this->governance->groupFor($spaceId);
        if ($bound !== null && !$this->governance->isInstanceAdmin()) {
            $members = array_values(array_filter($members,
                fn(array $row): bool => $this->groups->isInGroup((string)$row['uid'], $bound)));
        }
        return $members;
    }

    /** @return array<int, array<string, mixed>> */
    public function groupGrants(int $spaceId): array {
        if ($this->access->isSaasWorkspace($spaceId)) {
            $this->access->requireFinancialCapability($spaceId, 'roles.read');
            return []; // Group membership never conveys financial grants in SaaS.
        }
        $this->access->requireRole($spaceId, 'manager');
        $qb = $this->db->getQueryBuilder();
        $qb->select('group_gid', 'role')->from('lf_group_grants')
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $bound = $this->governance->groupFor($spaceId);
        if ($bound !== null && !$this->governance->isInstanceAdmin()) {
            $qb->andWhere($qb->expr()->eq('group_gid', $qb->createNamedParameter($bound)));
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function audit(int $spaceId, string $action, string $entity, int $entityId): void {
        $this->writer->insert('lf_audit', [
            'space_id' => $spaceId, 'actor_uid' => $this->access->uid(),
            'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
            'occurred_at' => time(),
        ]);
    }
}
