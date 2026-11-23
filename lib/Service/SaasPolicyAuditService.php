<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only, scope-filtered audit of hierarchical SaaS policy mutations.
 *
 * Storage keys are created only after server-owned authorization. The caller
 * never provides a UID, DB environment ID, group GID or arbitrary scope key.
 * The audit is separate from immutable accounting journal records.
 */
final class SaasPolicyAuditService {
    private const PAGE_SIZE = 30;

    public function __construct(
        private IDBConnection $db,
        private GovernanceService $governance,
        private EnvironmentService $environments,
        private AccessService $access,
    ) {}

    /** @return array{events:list<array<string,mixed>>,nextBeforeId:?int} */
    public function instance(int $beforeId = 0): array {
        $this->governance->requireInstanceAdmin();
        return $this->read('instance', $beforeId);
    }

    /** @return array{events:list<array<string,mixed>>,nextBeforeId:?int} */
    public function environment(string $environmentId, int $beforeId = 0): array {
        $environment = $this->environments->requireActiveManager($environmentId);
        return $this->read('environment:' . (int)$environment['id'], $beforeId);
    }

    /** @return array{events:list<array<string,mixed>>,nextBeforeId:?int} */
    public function workspace(int $spaceId, int $beforeId = 0): array {
        $this->access->requireFinancialCapability($spaceId, 'workspace.configure');
        $this->requireSaasScope($spaceId);
        return $this->read('workspace:' . $spaceId, $beforeId);
    }

    /** @return array{events:list<array<string,mixed>>,nextBeforeId:?int} */
    public function user(int $spaceId, int $beforeId = 0): array {
        $this->access->requireFinancialCapability($spaceId, 'workspace.read');
        $this->requireSaasScope($spaceId);
        $uid = $this->access->uid();
        return $this->read('user:' . $uid . ':workspace:' . $spaceId, $beforeId);
    }

    private function requireSaasScope(int $spaceId): void {
        if ($spaceId < 1 || !$this->access->isSaasWorkspace($spaceId)) {
            throw new DomainException('unsupported_scope');
        }
    }

    /**
     * Authorization is already decided before querying the filtered scope.
     * Keyset pagination stays stable if another actor writes while browsing.
     *
     * @return array{events:list<array<string,mixed>>,nextBeforeId:?int}
     */
    private function read(string $scopeKey, int $beforeId): array {
        if ($beforeId < 0 || strlen($scopeKey) > 180) {
            throw new DomainException('invalid_policy_cursor');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'policy_key', 'actor_uid', 'action',
                'old_value_json', 'new_value_json',
                'previous_revision', 'new_revision', 'occurred_at')
            ->from('lf_saas_policy_audit')
            ->where($q->expr()->eq('scope_key',
                $q->createNamedParameter($scopeKey)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);
        if ($beforeId > 0) {
            $q->andWhere($q->expr()->lt('id',
                $q->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $events = array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'policyKey' => (string)$r['policy_key'],
            'actorUid' => (string)$r['actor_uid'],
            'action' => (string)$r['action'],
            'oldValueJson' => $r['old_value_json'] === null ? null : (string)$r['old_value_json'],
            'newValueJson' => $r['new_value_json'] === null ? null : (string)$r['new_value_json'],
            'previousRevision' => (int)$r['previous_revision'],
            'newRevision' => (int)$r['new_revision'],
            'occurredAt' => (int)$r['occurred_at'],
        ], $rows);
        return [
            'events' => $events,
            'nextBeforeId' => $more ? (int)end($events)['id'] : null,
        ];
    }
}
