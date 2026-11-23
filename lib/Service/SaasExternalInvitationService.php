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
use OCP\IUserManager;

/**
 * Explicit account-bound external invitations; no link tokens, group changes
 * or lf_members writes. Every request reconstructs its SaaS trust boundary.
 */
final class SaasExternalInvitationService {
    private const MAX_LIFETIME = 30 * 86400;
    private const MIN_LIFETIME = 300;

    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private AccessService $access,
        private SaasPolicyRuntimeGate $policies,
        private GovernanceService $governance,
        private IGroupManager $groups,
        private IUserManager $users,
    ) {}

    /** @return array<string,mixed> */
    public function create(int $spaceId, string $recipientUid, string $role,
        int $expiresAt, int $expectedRevision = 0): array {
        // roles.manage is owner-only, independently of the policy toggle.
        $this->access->requireFinancialCapability($spaceId, 'roles.manage');
        $gid = $this->activeGroup($spaceId);
        $this->policies->requireWorkspaceEnabled($spaceId, 'externalSharing.enabled');
        $actor = $this->access->uid();
        $recipientUid = trim($recipientUid);
        if ($recipientUid === '' || strlen($recipientUid) > 64
            || !$this->users->userExists($recipientUid)) {
            throw new DomainException('user_not_found');
        }
        if ($recipientUid === $actor
            || $this->groups->isInGroup($recipientUid, $gid)) {
            throw new DomainException('permission_denied');
        }
        if (!in_array($role, ['viewer', 'auditor'], true)) {
            throw new DomainException('invalid_role');
        }
        $now = time();
        if ($expiresAt < $now + self::MIN_LIFETIME
            || $expiresAt > $now + self::MAX_LIFETIME) {
            throw new DomainException('invalid_invitation_expiry');
        }
        if ($expectedRevision < 0) {
            throw new DomainException('invalid_policy_revision');
        }
        $this->db->beginTransaction();
        try {
            $old = $this->grantByRecipient($spaceId, $recipientUid);
            if ($old === null) {
                if ($expectedRevision !== 0) {
                    throw new DomainException('revision_conflict');
                }
                try {
                    $this->writer->insert('lf_saas_external_grants', [
                        'space_id' => $spaceId, 'recipient_uid' => $recipientUid,
                        'role' => $role, 'status' => 'invited',
                        'expires_at' => $expiresAt, 'revision' => 1,
                        'created_by' => $actor, 'created_at' => $now,
                        'accepted_at' => null, 'revoked_at' => null,
                        'updated_at' => $now,
                    ]);
                } catch (DatabaseException $e) {
                    if ($e->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                        throw new DomainException('revision_conflict', previous: $e);
                    }
                    throw $e;
                }
                $id = (int)$this->db->lastInsertId('*PREFIX*lf_saas_external_grants');
                $this->audit($id, $spaceId, 'invited', null, 'invited', $now);
            } else {
                $id = (int)$old['id'];
                if ($expectedRevision !== (int)$old['revision']) {
                    throw new DomainException('revision_conflict');
                }
                if ($old['status'] !== 'revoked' && (int)$old['expires_at'] > $now) {
                    throw new DomainException('invitation_conflict');
                }
                // A re-invitation requires a new explicit acceptance and
                // cannot resurrect a formerly revoked accepted grant.
                $newRevision = $expectedRevision + 1;
                $q = $this->db->getQueryBuilder();
                $changed = $q->update('lf_saas_external_grants')
                    ->set('role', $q->createNamedParameter($role))
                    ->set('status', $q->createNamedParameter('invited'))
                    ->set('expires_at', $q->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT))
                    ->set('revision', $q->createNamedParameter($newRevision, IQueryBuilder::PARAM_INT))
                    ->set('accepted_at', $q->createNamedParameter(null))
                    ->set('revoked_at', $q->createNamedParameter(null))
                    ->set('created_by', $q->createNamedParameter($actor))
                    ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('id',
                        $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('revision',
                        $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                if ($changed !== 1) {
                    throw new DomainException('revision_conflict');
                }
                $this->audit($id, $spaceId, 'reinvited', (string)$old['status'], 'invited', $now);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->one($id, $spaceId);
    }

    /** @return array<string,mixed> */
    public function accept(int $spaceId, int $id, int $expectedRevision): array {
        $actor = $this->access->uid();
        $gid = $this->activeGroup($spaceId);
        $this->policies->requireWorkspaceEnabled($spaceId, 'externalSharing.enabled');
        if ($this->groups->isInGroup($actor, $gid)) {
            throw new DomainException('permission_denied');
        }
        $this->db->beginTransaction();
        try {
            $old = $this->grant($spaceId, $id, $actor);
            if ($old === null) {
                throw new DomainException('invitation_not_found');
            }
            if ($expectedRevision !== (int)$old['revision']) {
                throw new DomainException('revision_conflict');
            }
            if ($old['status'] !== 'invited') {
                throw new DomainException('invitation_conflict');
            }
            $now = time();
            if ((int)$old['expires_at'] <= $now) {
                throw new DomainException('invitation_expired');
            }
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lf_saas_external_grants')
                ->set('status', $q->createNamedParameter('accepted'))
                ->set('accepted_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',
                    $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('status', $q->createNamedParameter('invited')))
                ->andWhere($q->expr()->eq('revision',
                    $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->gt('expires_at',
                    $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('revision_conflict');
            }
            $this->audit($id, $spaceId, 'accepted', 'invited', 'accepted', $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->one($id, $spaceId, $actor);
    }

    /** @return array<string,mixed> */
    public function revoke(int $spaceId, int $id, int $expectedRevision): array {
        // Revocation remains available even after instance policy disablement.
        $this->access->requireFinancialCapability($spaceId, 'roles.manage');
        $this->activeGroup($spaceId);
        $this->db->beginTransaction();
        try {
            $old = $this->grant($spaceId, $id);
            if ($old === null) {
                throw new DomainException('invitation_not_found');
            }
            if ((int)$old['revision'] !== $expectedRevision) {
                throw new DomainException('revision_conflict');
            }
            if ($old['status'] === 'revoked') {
                throw new DomainException('invitation_conflict');
            }
            $now = time();
            $q = $this->db->getQueryBuilder();
            $changed = $q->update('lf_saas_external_grants')
                ->set('status', $q->createNamedParameter('revoked'))
                ->set('revoked_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('revision', $q->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',
                    $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('revision',
                    $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            if ($changed !== 1) {
                throw new DomainException('revision_conflict');
            }
            $this->audit($id, $spaceId, 'revoked', (string)$old['status'], 'revoked', $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->one($id, $spaceId);
    }

    /** @return array{invitations:list<array<string,mixed>>,nextAfterId:?int} */
    public function listForOwner(int $spaceId, int $afterId = 0): array {
        $this->access->requireFinancialCapability($spaceId, 'roles.manage');
        if ($afterId < 0) {
            throw new DomainException('invalid_invitation_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_saas_external_grants')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $next = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            'invitations' => array_map(self::publicRecord(...), $rows),
            'nextAfterId' => $next ? (int)end($rows)['id'] : null,
        ];
    }

    /** @return array{invitations:list<array<string,mixed>>,nextAfterId:?int} */
    public function listForRecipient(int $afterId = 0): array {
        $actor = $this->access->uid();
        if ($afterId < 0) {
            throw new DomainException('invalid_invitation_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_saas_external_grants')
            ->where($q->expr()->eq('recipient_uid',
                $q->createNamedParameter($actor)))
            ->andWhere($q->expr()->gt('expires_at',
                $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('status',
                $q->createNamedParameter(['invited', 'accepted'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->gt('id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $next = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            'invitations' => array_map(self::publicRecord(...), $rows),
            'nextAfterId' => $next ? (int)end($rows)['id'] : null,
        ];
    }

    /** @return array{events:list<array<string,mixed>>,nextAfterId:?int} */
    public function auditTrail(int $spaceId, int $afterId = 0): array {
        // Invitation metadata is private to the accountable owner.
        $this->access->requireFinancialCapability($spaceId, 'roles.manage');
        if ($afterId < 0) {
            throw new DomainException('invalid_invitation_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'grant_id', 'actor_uid', 'action', 'previous_status', 'new_status', 'occurred_at')
            ->from('lf_saas_external_audit')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $next = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return ['events' => array_map(static fn(array $r): array => [
            'id' => (int)$r['id'], 'grantId' => (int)$r['grant_id'],
            'actorUid' => (string)$r['actor_uid'], 'action' => (string)$r['action'],
            'previousStatus' => $r['previous_status'], 'newStatus' => (string)$r['new_status'],
            'occurredAt' => (int)$r['occurred_at'],
        ], $rows), 'nextAfterId' => $next ? (int)end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    private function one(int $id, int $spaceId, ?string $recipientUid = null): array {
        $row = $this->grant($spaceId, $id, $recipientUid);
        if ($row === null) {
            throw new DomainException('invitation_not_found');
        }
        return self::publicRecord($row);
    }

    /** @return array<string,mixed>|null */
    private function grantByRecipient(int $spaceId, string $uid): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_saas_external_grants')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('recipient_uid', $q->createNamedParameter($uid)));
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function grant(int $spaceId, int $id, ?string $recipientUid = null): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_saas_external_grants')
            ->where($q->expr()->eq('id',
                $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        if ($recipientUid !== null) {
            $q->andWhere($q->expr()->eq('recipient_uid', $q->createNamedParameter($recipientUid)));
        }
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    private function audit(int $grantId, int $spaceId, string $action,
        ?string $before, string $after, int $now): void {
        $this->writer->insert('lf_saas_external_audit', [
            'grant_id' => $grantId, 'space_id' => $spaceId,
            'actor_uid' => $this->access->uid(),
            'action' => $action, 'previous_status' => $before,
            'new_status' => $after, 'occurred_at' => $now,
        ]);
    }

    /** @return non-empty-string */
    private function activeGroup(int $spaceId): string {
        if (!$this->governance->enabled()
            || $this->policies->instance('environments.enabled')['effectiveValue'] !== true) {
            throw new DomainException('space_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('es.review_state', 'e.status AS environment_status',
                'g.group_gid', 'g.state AS group_state')
            ->from('lf_environment_spaces', 'es')
            ->innerJoin('es', 'lf_environments', 'e', 'e.id = es.environment_id')
            ->innerJoin('es', 'lf_environment_groups', 'g', 'g.environment_id = e.id')
            ->innerJoin('es', 'lf_spaces', 's', 's.id = es.space_id')
            ->where($q->expr()->eq('es.space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('s.archived',
                $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
        $r = $q->executeQuery()->fetchAssociative();
        if ($r === false || $r['review_state'] !== 'confirmed'
            || $r['environment_status'] !== 'active'
            || $r['group_state'] !== 'active'
            || !is_string($r['group_gid']) || $r['group_gid'] === ''
            || $this->groups->get($r['group_gid']) === null) {
            throw new DomainException('space_not_found');
        }
        return $r['group_gid'];
    }

    /** @param array<string,mixed> $r
     *  @return array<string,mixed>
     */
    private static function publicRecord(array $r): array {
        return [
            'id' => (int)$r['id'], 'spaceId' => (int)$r['space_id'],
            'recipientUid' => (string)$r['recipient_uid'],
            'role' => (string)$r['role'], 'status' => (string)$r['status'],
            'expiresAt' => (int)$r['expires_at'], 'revision' => (int)$r['revision'],
            'createdAt' => (int)$r['created_at'],
            'acceptedAt' => $r['accepted_at'] === null ? null : (int)$r['accepted_at'],
            'revokedAt' => $r['revoked_at'] === null ? null : (int)$r['revoked_at'],
        ];
    }
}
