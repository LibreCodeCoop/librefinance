<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Group\ISubAdmin;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Instance-admin policy and Nextcloud group-scoped delegation. */
final class GovernanceService {
    public function __construct(
        private IConfig $config,
        private IGroupManager $groups,
        private ISubAdmin $subAdmins,
        private IUserSession $session,
        private IDBConnection $db,
        private WriteService $write,
    ) {}

    public function enabled(): bool {
        return $this->config->getAppValue('librefinance', 'managed_spaces', 'no') === 'yes';
    }

    public function isInstanceAdmin(): bool {
        $user = $this->session->getUser();
        return $user !== null && $this->groups->isAdmin($user->getUID());
    }

    public function requireInstanceAdmin(): void {
        if (!$this->isInstanceAdmin()) {
            throw new DomainException('permission_denied');
        }
    }

    /** @return array{managed:bool} */
    public function settings(): array {
        $this->requireInstanceAdmin();
        return ['managed' => $this->enabled()];
    }

    /** @return array{managed:bool} */
    public function configure(bool $managed): array {
        $this->requireInstanceAdmin();
        $this->config->setAppValue('librefinance', 'managed_spaces', $managed ? 'yes' : 'no');
        return ['managed' => $managed];
    }

    public function requireCreationAllowed(): void {
        if ($this->enabled()) {
            $this->requireInstanceAdmin();
        }
    }

    /** The group attached to a financial context, if centrally governed. */
    public function groupFor(int $spaceId): ?string {
        $q = $this->db->getQueryBuilder();
        $q->select('governance_gid')->from('lf_spaces')
            ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $gid = $q->executeQuery()->fetchOne();
        return $gid === false || $gid === null || $gid === '' ? null : (string)$gid;
    }

    /** @return array{groups:list<array{id:string,name:string}>,nextOffset:?int} */
    public function searchGroups(string $search = '', int $offset = 0): array {
        $this->requireInstanceAdmin();
        $search = trim($search);
        if (mb_strlen($search) > 100 || $offset < 0 || $offset > 100000) {
            throw new DomainException('invalid_group_search');
        }
        $result = $this->groups->search($search, 26, $offset);
        $nextOffset = count($result) > 25 ? $offset + 25 : null;
        return [
            'groups' => array_map(static fn(\OCP\IGroup $group): array => [
                'id' => $group->getGID(),
                'name' => $group->getDisplayName(),
            ], array_slice($result, 0, 25)),
            'nextOffset' => $nextOffset,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function contexts(): array {
        $this->requireInstanceAdmin();
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'name', 'kind', 'governance_gid', 'archived')
            ->from('lf_spaces')->orderBy('id', 'ASC');
        $rows = $q->executeQuery()->fetchAllAssociative();
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'],
            'governanceGroup' => $r['governance_gid'], 'archived' => (bool)$r['archived'],
        ], $rows);
    }

    /** Bind an existing space without transferring or modifying its ledger. */
    public function bind(int $spaceId, string $gid): array {
        $this->requireInstanceAdmin();
        if (!$this->enabled()) throw new DomainException('governance_disabled');
        $this->requireGroup($gid);
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id', 'governance_gid')->from('lf_spaces')
                ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
            $row = $q->executeQuery()->fetchAssociative();
            if (!$row) throw new DomainException('space_not_found');
            if ($row['governance_gid'] !== null && $row['governance_gid'] !== $gid) {
                throw new DomainException('governance_group_conflict');
            }
            if ($row['governance_gid'] === null) {
                $update = $this->db->getQueryBuilder();
                $updated = $update->update('lf_spaces')
                    ->set('governance_gid', $update->createNamedParameter($gid))
                    ->where($update->expr()->eq('id',
                        $update->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($update->expr()->isNull('governance_gid'))
                    ->executeStatement();
                if ($updated !== 1) throw new DomainException('governance_group_conflict');
                $this->write->insert('lf_audit', [
                    'space_id' => $spaceId,
                    'actor_uid' => (string)$this->session->getUser()?->getUID(),
                    'action' => 'governance.group_bound',
                    'entity' => 'space',
                    'entity_id' => $spaceId,
                    'occurred_at' => time(),
                ]);
            }
            $q = $this->db->getQueryBuilder();
            $q->select('id')->from('lf_group_grants')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('group_gid', $q->createNamedParameter($gid)));
            if ($q->executeQuery()->fetchOne() === false) {
                $this->write->insert('lf_group_grants', [
                    'space_id' => $spaceId, 'group_gid' => $gid, 'role' => 'viewer',
                ]);
            }
            $this->db->commit();
            return ['spaceId' => $spaceId, 'governanceGroup' => $gid];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function requireGroup(string $gid): void {
        if ($gid === '' || strlen($gid) > 64 || $this->groups->get($gid) === null) {
            throw new DomainException('group_not_found');
        }
    }

    /** Group admin may manage only a context bound to their Nextcloud group. */
    /** @return list<string> */
    public function delegatedGroups(): array {
        $user = $this->session->getUser();
        if ($user === null) return [];
        return array_map(static fn(\OCP\IGroup $group): string => $group->getGID(),
            $this->subAdmins->getSubAdminsGroups($user));
    }

    public function isDelegatedManager(?string $gid): bool {
        $user = $this->session->getUser();
        $group = $gid === null ? null : $this->groups->get($gid);
        return $user !== null && $group !== null
            && $this->subAdmins->isSubAdminOfGroup($user, $group);
    }

    public function isInGovernanceGroup(?string $gid): bool {
        $user = $this->session->getUser();
        return $user !== null && $gid !== null
            && $this->groups->isInGroup($user->getUID(), $gid);
    }

    /** Group grants cannot be escalated across unrelated financial contexts. */
    public function checkGroupGrant(int $spaceId, string $gid): void {
        $bound = $this->groupFor($spaceId);
        if ($bound !== null && !$this->isInstanceAdmin()) {
            throw new DomainException('permission_denied');
        }
    }

    public function checkUserGrant(int $spaceId, string $uid): void {
        $bound = $this->groupFor($spaceId);
        if ($bound !== null && !$this->isInstanceAdmin()
            && !$this->groups->isInGroup($uid, $bound)) {
            throw new DomainException('permission_denied');
        }
    }
}
