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
 * Provision an independent accounting ledger only after explicit
 * environment-admin intent and a distinct, qualified financial owner.
 *
 * Environment admins are never auto-enrolled as ledger members and a
 * Nextcloud group does not get an automatic viewer grant.
 */
final class SaasWorkspaceService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private EnvironmentService $environments,
        private AccessService $access,
        private IGroupManager $groups,
        private IUserManager $users,
        private SaasPolicyRuntimeGate $policies,
    ) {}

    /** @return array{id:int,name:string,kind:string,currency:string,ownerUid:string,environmentId:string} */
    public function create(string $environmentUuid, string $name, string $kind,
        string $currency, string $ownerUid, string $idempotencyKey,
        string $color = '#64748B'): array {
        $environment = $this->environments->requireActiveManager($environmentUuid);
        $actor = $this->access->uid();
        $gid = $environment['groupGid'];

        $name = trim($name);
        $currency = strtoupper(trim($currency));
        $color = strtoupper($color);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainException('invalid_name');
        }
        if (!in_array($kind, ['personal', 'collective', 'organization'], true)) {
            throw new DomainException('invalid_space_kind');
        }
        if (preg_match('/^#[0-9A-F]{6}$/D', $color) !== 1) {
            throw new DomainException('invalid_space_color');
        }
        if ($currency !== 'BRL') {
            throw new DomainException('invalid_currency');
        }
        if ($ownerUid === '' || mb_strlen($ownerUid) > 64
            || $this->users->get($ownerUid) === null
            || !$this->groups->isInGroup($ownerUid, $gid)) {
            throw new DomainException('invalid_initial_owner');
        }
        if ($ownerUid === $actor) {
            throw new DomainException('self_promotion_forbidden');
        }
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $idempotencyKey) !== 1) {
            throw new DomainException('invalid_idempotency_key');
        }

        $fingerprint = hash('sha256', json_encode([
            $name, $kind, $currency, $color, $ownerUid,
        ], JSON_THROW_ON_ERROR));
        $existing = $this->claim((int)$environment['id'], $idempotencyKey);
        if ($existing !== null) {
            return $this->existing($existing, $fingerprint, $environmentUuid);
        }
        $now = time();
        $this->db->beginTransaction();
        try {
            // Lock the environment row before checking the active quota.
            // Concurrent writers must serialize on the same tenant, not
            // independently count slots and exceed its policy limit.
            $replayed = $this->reserveWorkspaceSlot(
                (int)$environment['id'], $environmentUuid, $idempotencyKey);
            if ($replayed !== null) {
                $found = $this->existing($replayed, $fingerprint, $environmentUuid);
                $this->db->commit();
                return $found;
            }
            if (!$this->groups->isInGroup($ownerUid, $gid)) {
                throw new DomainException('invalid_initial_owner');
            }
            $this->writer->insert('lf_spaces', [
                'name' => $name,
                'kind' => $kind,
                'currency' => $currency,
                'owner_uid' => $ownerUid,
                'governance_gid' => $gid,
                'created_at' => $now,
                'archived' => 0,
                'accent_color' => $color,
            ]);
            $spaceId = (int)$this->db->lastInsertId('*PREFIX*lf_spaces');
            $this->writer->insert('lf_members', [
                'space_id' => $spaceId, 'uid' => $ownerUid, 'role' => 'owner',
            ]);
            $this->writer->insert('lf_environment_spaces', [
                'environment_id' => (int)$environment['id'],
                'space_id' => $spaceId,
                'review_state' => 'confirmed',
                'created_at' => $now,
            ]);
            foreach ([
                ['1000', 'Caixa', 'asset', true],
                ['3000', 'Patrimônio líquido', 'equity', false],
                ['4000', 'Receitas', 'revenue', false],
                ['5000', 'Despesas', 'expense', false],
            ] as [$code, $label, $class, $bank]) {
                $this->writer->insert('lf_accounts', [
                    'space_id' => $spaceId,
                    'code' => $code,
                    'name' => $label,
                    'class' => $class,
                    'currency' => $currency,
                    'is_bank' => $bank ? 1 : 0,
                    'active' => 1,
                ]);
            }
            foreach ([['Dinheiro', 'cash'], ['Transferência bancária', 'bank_transfer']]
                as [$methodName, $methodKind]) {
                $this->writer->insert('lf_payment_methods', [
                    'space_id' => $spaceId,
                    'name' => $methodName,
                    'kind' => $methodKind,
                    'bank_account_id' => null,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->writer->insert('lf_saas_space_claims', [
                'environment_id' => (int)$environment['id'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $fingerprint,
                'space_id' => $spaceId,
                'created_by' => $actor,
                'created_at' => $now,
            ]);
            $this->writer->insert('lf_audit', [
                'space_id' => $spaceId,
                'actor_uid' => $actor,
                'action' => 'space.saas_created',
                'entity' => 'space',
                'entity_id' => $spaceId,
                'occurred_at' => $now,
            ]);
            $this->writer->insert('lf_environment_audit', [
                'environment_id' => (int)$environment['id'],
                'actor_uid' => $actor,
                'action' => 'workspace.created',
                'subject_type' => 'space',
                'subject_id' => (string)$spaceId,
                'occurred_at' => $now,
            ]);
            // Re-check server-side administrative authority after all writes,
            // immediately before commit; revocation cannot be trusted from
            // an earlier HTTP request or an in-memory UI flag.
            $this->environments->requireActiveManager($environmentUuid);
            if (!$this->groups->isInGroup($ownerUid, $gid)) {
                throw new DomainException('invalid_initial_owner');
            }
            $this->db->commit();
            return [
                'id' => $spaceId, 'name' => $name, 'kind' => $kind,
                'currency' => $currency, 'ownerUid' => $ownerUid,
                'environmentId' => $environmentUuid,
            ];
        } catch (DatabaseException $e) {
            $this->db->rollBack();
            if ($e->getReason() !== DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            $existing = $this->claim((int)$environment['id'], $idempotencyKey);
            if ($existing === null) {
                throw $e;
            }
            return $this->existing($existing, $fingerprint, $environmentUuid);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed>|null Existing idempotent claim when raced. */
    private function reserveWorkspaceSlot(int $environmentId, string $environmentUuid,
        string $idempotencyKey): ?array {
        $q = $this->db->getQueryBuilder();
        $changed = $q->update('lf_environments')
            ->set('revision', $q->createFunction('revision + 1'))
            ->where($q->expr()->eq('id',
                $q->createNamedParameter($environmentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('status',
                $q->createNamedParameter('active')))
            ->executeStatement();
        if ($changed !== 1) {
            throw new DomainException('environment_suspended');
        }
        $this->environments->requireActiveManager($environmentUuid);
        // Serialize duplicate-key check after acquiring the environment
        // row lock. A valid retry must succeed even when the quota is full.
        $replayed = $this->claim($environmentId, $idempotencyKey);
        if ($replayed !== null) {
            return $replayed;
        }
        $q = $this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(*)'), 'occupied')
            ->from('lf_environment_spaces')
            ->where($q->expr()->eq('environment_id',
                $q->createNamedParameter($environmentId, IQueryBuilder::PARAM_INT)));
        // Pending-review legacy books count towards the limit too; otherwise
        // an unresolved ledger could be used to exceed tenant quotas.
        $used = (int)$q->executeQuery()->fetchOne();
        $maxAllowed = $this->policies->environment($environmentId,
            'workspaces.maxCount')['effectiveValue'];
        if (!is_int($maxAllowed) || $maxAllowed < 0 || $used >= $maxAllowed) {
            throw new DomainException('workspace_limit_exceeded');
        }
        return null;
    }

    /**
     * Paginate after applying the active environment and ownership boundary.
     * A stable space-id cursor avoids offset drift when other managers create
     * books concurrently; no page can include a foreign environment's book.
     * @return array{workspaces:list<array{id:int,name:string,ownerUid:string,kind:string}>,nextAfterId:?int}
     */
    public function listForManager(string $environmentUuid, int $afterId = 0): array {
        $environment = $this->environments->requireActiveManager($environmentUuid);
        if ($afterId < 0) {
            throw new DomainException('invalid_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('s.id', 's.name', 's.owner_uid', 's.kind')
            ->from('lf_environment_spaces', 'es')
            ->innerJoin('es', 'lf_spaces', 's', 's.id = es.space_id')
            ->where($q->expr()->eq('es.environment_id',
                $q->createNamedParameter($environment['id'], IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('es.review_state',
                $q->createNamedParameter('confirmed')))
            ->andWhere($q->expr()->eq('s.archived',
                $q->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($q->expr()->gt('s.id',
                $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(51);
        $rows = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            'workspaces' => array_map(static fn(array $row): array => [
                'id' => (int)$row['id'], 'name' => (string)$row['name'],
                'ownerUid' => (string)$row['owner_uid'], 'kind' => (string)$row['kind'],
            ], $rows),
            'nextAfterId' => $hasMore ? (int)end($rows)['id'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function claim(int $environmentId, string $key): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('space_id', 'request_hash')
            ->from('lf_saas_space_claims')
            ->where($q->expr()->eq('environment_id',
                $q->createNamedParameter($environmentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('idempotency_key',
                $q->createNamedParameter($key)));
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $claim
     *  @return array{id:int,name:string,kind:string,currency:string,ownerUid:string,environmentId:string}
     */
    private function existing(array $claim, string $fingerprint, string $environmentUuid): array {
        if (!hash_equals((string)$claim['request_hash'], $fingerprint)) {
            throw new DomainException('idempotency_conflict');
        }
        $id = (int)$claim['space_id'];
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'name', 'kind', 'currency', 'owner_uid')
            ->from('lf_spaces')
            ->where($q->expr()->eq('id',
                $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $row = $q->executeQuery()->fetchAssociative();
        if ($row === false) {
            throw new DomainException('concurrent_change');
        }
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'kind' => (string)$row['kind'],
            'currency' => (string)$row['currency'],
            'ownerUid' => (string)$row['owner_uid'],
            'environmentId' => $environmentUuid,
        ];
    }
}
