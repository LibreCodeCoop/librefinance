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
use OCP\IUserSession;

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
                ->innerJoin('s', 'lf_group_grants', 'g', 'g.space_id = s.id')
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
        if ((string)$space['owner_uid'] === $uid) {
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
        if (self::rank($best) < self::rank($minRole)) {
            throw new DomainException('permission_denied');
        }
        return $best;
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
