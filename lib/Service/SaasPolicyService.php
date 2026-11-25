<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCA\LibreFinance\Domain\Saas\PolicyDefinition;
use OCA\LibreFinance\Domain\Saas\PolicyRegistry;
use OCA\LibreFinance\Domain\Saas\PolicyResolver;
use OCP\DB\Exception as DatabaseException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Typed policy persistence and effective metadata for authorized actors.
 */
final class SaasPolicyService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private GovernanceService $governance,
        private IUserSession $session,
        private PolicyRegistry $registry,
        private PolicyResolver $resolver,
        private EnvironmentService $environments,
        private SaasPolicyRuntimeGate $runtime,
        private AccessService $access,
    ) {}

    /** @return array<string,mixed> */
    public function getInstance(string $key): array {
        $this->governance->requireInstanceAdmin();
        $definition = $this->registry->get($key);
        $stored = $this->lookup('instance', $key);
        $layers = $stored !== null && !(bool)$stored['is_cleared']
            ? [['scope' => 'instance', 'value' => $this->decode($definition, (string)$stored['value_json'])]]
            : [];
        $effective = $this->resolver->resolve($definition, $layers);
        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'contractVersion' => $definition->version,
            'supportedScopes' => $definition->supportedScopes,
            'allowChildOverride' => $definition->allowChildOverride,
            'effectiveValue' => $effective['effectiveValue'],
            'sourceScope' => $effective['sourceScope'],
            'sourceIdentifier' => $effective['sourceScope'] === 'instance' ? 'instance' : null,
            'blockedBy' => $effective['blockedBy'],
            'allowedValues' => $definition->type === 'boolean'
                ? [false, true] : $effective['allowedValues'],
            'maxAllowed' => $effective['maxAllowed'],
            'editableByCurrentActor' => true,
            'revision' => $stored === null ? 0 : (int)$stored['revision'],
            'inherited' => $stored === null || (bool)$stored['is_cleared'],
        ];
    }

    /** @return array{policies:list<array<string,mixed>>} */
    public function listInstance(): array {
        $this->governance->requireInstanceAdmin();
        return ['policies' => array_map(
            fn(PolicyDefinition $definition): array => $this->getInstance($definition->key),
            $this->registry->all(),
        )];
    }

    /** @return array<string,mixed> */
    public function writeInstance(string $key, mixed $value, int $expectedRevision): array {
        $this->governance->requireInstanceAdmin();
        $definition = $this->registry->get($key);
        $definition->assertValue($value);
        $this->mutate('instance', $definition, $value, false, $expectedRevision);
        return $this->getInstance($key);
    }

    /** @return array<string,mixed> */
    public function clearInstance(string $key, int $expectedRevision): array {
        $this->governance->requireInstanceAdmin();
        $definition = $this->registry->get($key);
        $this->mutate('instance', $definition, null, true, $expectedRevision);
        return $this->getInstance($key);
    }

    /**
     * Environment administrators can inspect the effective policy within
     * their own active environment, but this never conveys ledger access.
     *
     * @return array<string,mixed>
     */
    public function getEnvironment(string $uuid, string $key): array {
        $environment = $this->environments->requireActiveManager($uuid);
        $definition = $this->registry->get($key);
        $scopeKey = 'environment:' . $environment['id'];
        $stored = $this->lookup($scopeKey, $key);
        $resolved = $this->runtime->environment($environment['id'], $key);
        $parent = $this->runtime->instance($key);
        $editable = $definition->supports('environment')
            && $definition->allowChildOverride;
        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'contractVersion' => $definition->version,
            'supportedScopes' => $definition->supportedScopes,
            'allowChildOverride' => $definition->allowChildOverride,
            'effectiveValue' => $resolved['effectiveValue'],
            'sourceScope' => $resolved['sourceScope'],
            'sourceIdentifier' => match ($resolved['sourceScope']) {
                'instance' => 'instance',
                'environment' => $environment['publicId'],
                default => null,
            },
            'blockedBy' => $resolved['blockedBy'],
            'allowedValues' => $definition->type === 'boolean'
                ? ($parent['effectiveValue'] === false ? [false] : [false, true])
                : ($definition->type === 'allowed_set'
                    ? $parent['effectiveValue'] : null),
            'maxAllowed' => $definition->type === 'ceiling'
                ? $parent['effectiveValue'] : null,
            'editableByCurrentActor' => $editable,
            'revision' => $stored === null ? 0 : (int)$stored['revision'],
            'inherited' => $stored === null || (bool)$stored['is_cleared'],
        ];
    }

    /** @return array{policies:list<array<string,mixed>>} */
    public function listEnvironment(string $uuid): array {
        // Current environment authority is verified before revealing metadata.
        $this->environments->requireActiveManager($uuid);
        return ['policies' => array_map(
            fn(PolicyDefinition $definition): array => $this->getEnvironment($uuid, $definition->key),
            $this->registry->all(),
        )];
    }

    /** @return array<string,mixed> */
    public function writeEnvironment(string $uuid, string $key, mixed $value,
        int $expectedRevision): array {
        $environment = $this->environments->requireActiveManager($uuid);
        $definition = $this->registry->get($key);
        if (!$definition->supports('environment') || !$definition->allowChildOverride) {
            throw new DomainException('unsupported_scope');
        }
        $definition->assertValue($value);
        $parent = $this->runtime->instance($key)['effectiveValue'];
        $narrower = match ($definition->type) {
            'boolean' => $parent === true || $value === false,
            'ceiling' => $value <= $parent,
            'allowed_set' => array_values(array_intersect($value, $parent)) === $value,
            default => false,
        };
        if (!$narrower) {
            throw new DomainException('policy_override_forbidden');
        }
        $this->mutate('environment:' . $environment['id'],
            $definition, $value, false, $expectedRevision);
        return $this->getEnvironment($uuid, $key);
    }

    /** @return array<string,mixed> */
    public function clearEnvironment(string $uuid, string $key,
        int $expectedRevision): array {
        $environment = $this->environments->requireActiveManager($uuid);
        $definition = $this->registry->get($key);
        if (!$definition->supports('environment') || !$definition->allowChildOverride) {
            throw new DomainException('unsupported_scope');
        }
        $this->mutate('environment:' . $environment['id'],
            $definition, null, true, $expectedRevision);
        return $this->getEnvironment($uuid, $key);
    }

    /**
     * Workspace policies restrict the selected financial ledger only. A group
     * administrator without a direct financial role is never a policy editor.
     * Reads require a current financial role; writes require workspace.configure.
     *
     * @return array<string,mixed>
     */
    public function getWorkspace(int $spaceId, string $key): array {
        [$environmentId, $role] = $this->workspaceContext($spaceId, 'workspace.read');
        $definition = $this->registry->get($key);
        $stored = $this->lookup('workspace:' . $spaceId, $key);
        $resolved = $this->runtime->workspace($spaceId, $key);
        $parent = $this->runtime->environment($environmentId, $key);
        $editable = $definition->supports('workspace')
            && $definition->allowChildOverride
            && \OCA\LibreFinance\Domain\Saas\FinancialCapabilities::allows($role, 'workspace.configure');
        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'contractVersion' => $definition->version,
            'supportedScopes' => $definition->supportedScopes,
            'allowChildOverride' => $definition->allowChildOverride,
            'effectiveValue' => $resolved['effectiveValue'],
            'sourceScope' => $resolved['sourceScope'],
            'sourceIdentifier' => $this->workspaceSourceIdentifier(
                $resolved['sourceScope'], $spaceId, $environmentId),
            'blockedBy' => $resolved['blockedBy'],
            'allowedValues' => $definition->type === 'boolean'
                ? ($parent['effectiveValue'] === false ? [false] : [false, true])
                : ($definition->type === 'allowed_set' ? $parent['effectiveValue'] : null),
            'maxAllowed' => $definition->type === 'ceiling' ? $parent['effectiveValue'] : null,
            'editableByCurrentActor' => $editable,
            'revision' => $stored === null ? 0 : (int)$stored['revision'],
            'inherited' => $stored === null || (bool)$stored['is_cleared'],
        ];
    }

    /** @return array{policies:list<array<string,mixed>>} */
    public function listWorkspace(int $spaceId): array {
        $this->workspaceContext($spaceId, 'workspace.read');
        return ['policies' => array_map(
            fn(PolicyDefinition $definition): array => $this->getWorkspace($spaceId, $definition->key),
            $this->registry->all(),
        )];
    }

    /** @return array<string,mixed> */
    public function writeWorkspace(int $spaceId, string $key, mixed $value,
        int $expectedRevision): array {
        [$environmentId] = $this->workspaceContext($spaceId, 'workspace.configure');
        $definition = $this->registry->get($key);
        if (!$definition->supports('workspace') || !$definition->allowChildOverride) {
            throw new DomainException('unsupported_scope');
        }
        $definition->assertValue($value);
        $parent = $this->runtime->environment($environmentId, $key)['effectiveValue'];
        $narrower = match ($definition->type) {
            'boolean' => $parent === true || $value === false,
            'ceiling' => $value <= $parent,
            'allowed_set' => array_values(array_intersect($value, $parent)) === $value,
            default => false,
        };
        if (!$narrower) {
            throw new DomainException('policy_override_forbidden');
        }
        $this->mutate('workspace:' . $spaceId, $definition, $value, false, $expectedRevision);
        return $this->getWorkspace($spaceId, $key);
    }

    /** @return array<string,mixed> */
    public function clearWorkspace(int $spaceId, string $key, int $expectedRevision): array {
        $this->workspaceContext($spaceId, 'workspace.configure');
        $definition = $this->registry->get($key);
        if (!$definition->supports('workspace') || !$definition->allowChildOverride) {
            throw new DomainException('unsupported_scope');
        }
        $this->mutate('workspace:' . $spaceId, $definition, null, true, $expectedRevision);
        return $this->getWorkspace($spaceId, $key);
    }

    /**
     * The policy belongs to this authenticated actor in this active financial
     * workspace. No target UID or group may come from a frontend parameter.
     * Hiding dashboard cards is presentation, never an authorization grant.
     *
     * @return array<string,mixed>
     */
    public function getUser(int $spaceId, string $key): array {
        [$environmentId] = $this->workspaceContext($spaceId, 'workspace.read');
        $definition = $this->registry->get($key);
        if (!$definition->supports('user')) {
            throw new DomainException('unsupported_scope');
        }
        $uid = $this->access->uid();
        $stored = $this->lookup($this->userScopeKey($uid, $spaceId), $key);
        $resolved = $this->runtime->user($spaceId, $uid, $key);
        $parent = $this->runtime->workspace($spaceId, $key);
        // External viewers can read their display policy, but do not gain
        // editing authority merely through an accepted read-only invitation.
        $internal = $this->isInternalWorkspaceMember($spaceId, $uid);
        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'contractVersion' => $definition->version,
            'supportedScopes' => $definition->supportedScopes,
            'allowChildOverride' => $definition->allowChildOverride,
            'effectiveValue' => $resolved['effectiveValue'],
            'sourceScope' => $resolved['sourceScope'],
            'sourceIdentifier' => $this->workspaceSourceIdentifier(
                $resolved['sourceScope'], $spaceId, $environmentId, $uid, $internal),
            'blockedBy' => $resolved['blockedBy'],
            'allowedValues' => $definition->type === 'boolean'
                ? ($parent['effectiveValue'] === false ? [false] : [false, true])
                : ($definition->type === 'allowed_set' ? $parent['effectiveValue'] : null),
            'maxAllowed' => $definition->type === 'ceiling'
                ? $parent['effectiveValue'] : null,
            'editableByCurrentActor' => $internal && $definition->allowChildOverride,
            'revision' => $stored === null ? 0 : (int)$stored['revision'],
            'inherited' => $stored === null || (bool)$stored['is_cleared'],
        ];
    }

    /** @return array{policies:list<array<string,mixed>>} */
    public function listUser(int $spaceId): array {
        $this->workspaceContext($spaceId, 'workspace.read');
        $policies = [];
        foreach ($this->registry->all() as $definition) {
            if ($definition->supports('user')) {
                $policies[] = $this->getUser($spaceId, $definition->key);
            }
        }
        return ['policies' => $policies];
    }

    /** @return array<string,mixed> */
    public function writeUser(int $spaceId, string $key, mixed $value,
        int $expectedRevision): array {
        $this->workspaceContext($spaceId, 'workspace.read');
        $uid = $this->access->uid();
        $this->access->requireSaasRecipientGroupMember($spaceId, $uid);
        $definition = $this->registry->get($key);
        if (!$definition->supports('user') || !$definition->allowChildOverride) {
            throw new DomainException('unsupported_scope');
        }
        $definition->assertValue($value);
        $parent = $this->runtime->workspace($spaceId, $key)['effectiveValue'];
        $narrower = match ($definition->type) {
            'boolean' => $parent === true || $value === false,
            'ceiling' => $value <= $parent,
            'allowed_set' => array_values(array_intersect($value, $parent)) === $value,
            default => false,
        };
        if (!$narrower) {
            throw new DomainException('policy_override_forbidden');
        }
        $this->mutate($this->userScopeKey($uid, $spaceId),
            $definition, $value, false, $expectedRevision);
        return $this->getUser($spaceId, $key);
    }

    /** @return array<string,mixed> */
    public function clearUser(int $spaceId, string $key, int $expectedRevision): array {
        $this->workspaceContext($spaceId, 'workspace.read');
        $uid = $this->access->uid();
        $this->access->requireSaasRecipientGroupMember($spaceId, $uid);
        $definition = $this->registry->get($key);
        if (!$definition->supports('user') || !$definition->allowChildOverride) {
            throw new DomainException('unsupported_scope');
        }
        $this->mutate($this->userScopeKey($uid, $spaceId),
            $definition, null, true, $expectedRevision);
        return $this->getUser($spaceId, $key);
    }

    private function userScopeKey(string $uid, int $spaceId): string {
        if ($spaceId < 1 || $uid === '' || strlen($uid) > 64) {
            throw new DomainException('invalid_policy_scope');
        }
        return 'user:' . $uid . ':workspace:' . $spaceId;
    }

    private function isInternalWorkspaceMember(int $spaceId, string $uid): bool {
        try {
            $this->access->requireSaasRecipientGroupMember($spaceId, $uid);
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * Project the resolver's winning scope into the authorized API identity.
     * The caller has verified workspace access and may already know whether
     * the actor is a current internal member entitled to its environment ID.
     */
    private function workspaceSourceIdentifier(string $sourceScope, int $spaceId,
        int $environmentId, ?string $uid = null, ?bool $internal = null): ?string {
        return match ($sourceScope) {
            'instance' => 'instance',
            'workspace' => (string)$spaceId,
            'user' => $uid,
            'environment' => ($internal ?? $this->isInternalWorkspaceMember($spaceId, $this->access->uid()))
                ? $this->environmentPublicId($environmentId) : null,
            default => null,
        };
    }

    private function environmentPublicId(int $environmentId): ?string {
        $q = $this->db->getQueryBuilder();
        $q->select('public_id')->from('lf_environments')
            ->where($q->expr()->eq('id',
                $q->createNamedParameter($environmentId, IQueryBuilder::PARAM_INT)));
        $publicId = $q->executeQuery()->fetchOne();
        return is_string($publicId) ? $publicId : null;
    }

    /**
     * Resolve scope only from a server-validated finance membership and the
     * durable ledger binding. Never infer a policy scope from a client GID.
     *
     * @return array{int,string}
     */
    private function workspaceContext(int $spaceId, string $capability): array {
        $role = $this->access->requireFinancialCapability($spaceId, $capability);
        $q = $this->db->getQueryBuilder();
        $q->select('environment_id')->from('lf_environment_spaces')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('review_state',
                $q->createNamedParameter('confirmed')));
        $environmentId = $q->executeQuery()->fetchOne();
        if ($environmentId === false || (int)$environmentId < 1) {
            throw new DomainException('unsupported_scope');
        }
        return [(int)$environmentId, $role];
    }

    /**
     * Canonical storage key must be constructed exclusively from server-trusted
     * identities. Public routes reconstruct scope from authenticated resources.
     *
     * @return array<string,mixed>|null
     */
    private function lookup(string $scopeKey, string $key): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_saas_policy_values')
            ->where($q->expr()->eq('scope_key',
                $q->createNamedParameter($scopeKey, IQueryBuilder::PARAM_STR)))
            ->andWhere($q->expr()->eq('policy_key',
                $q->createNamedParameter($key, IQueryBuilder::PARAM_STR)));
        $row = $q->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    private function decode(PolicyDefinition $definition, string $json): mixed {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('invalid_policy_value');
        }
        $definition->assertValue($value);
        return $value;
    }

    private function mutate(string $scopeKey, PolicyDefinition $definition,
        mixed $value, bool $clear, int $expectedRevision): void {
        if ($expectedRevision < 0) {
            throw new DomainException('invalid_policy_revision');
        }
        $actor = $this->session->getUser()?->getUID();
        if ($actor === null) {
            throw new DomainException('authentication_required');
        }
        $valueJson = $clear ? null : json_encode($value, JSON_THROW_ON_ERROR);
        $now = time();
        $this->db->beginTransaction();
        try {
            $old = $this->lookup($scopeKey, $definition->key);
            if ($old === null) {
                if ($expectedRevision !== 0) {
                    throw new DomainException('revision_conflict');
                }
                try {
                    $this->writer->insert('lf_saas_policy_values', [
                        'scope_key' => $scopeKey,
                        'scope_type' => $scopeKey === 'instance' ? 'instance'
                            : (str_starts_with($scopeKey, 'user:') ? 'user'
                                : (str_starts_with($scopeKey, 'workspace:')
                                    ? 'workspace' : 'environment')),
                        'policy_key' => $definition->key,
                        'value_json' => $valueJson,
                        'is_cleared' => $clear,
                        'revision' => 1,
                        'updated_by' => $actor,
                        'updated_at' => $now,
                    ]);
                } catch (DatabaseException $e) {
                    if ($e->getReason() === DatabaseException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                        throw new DomainException('revision_conflict', previous: $e);
                    }
                    throw $e;
                }
                $policyId = (int)$this->db->lastInsertId('*PREFIX*lf_saas_policy_values');
                $oldJson = null;
                $newRevision = 1;
            } else {
                $policyId = (int)$old['id'];
                if ((int)$old['revision'] !== $expectedRevision) {
                    throw new DomainException('revision_conflict');
                }
                $oldJson = (bool)$old['is_cleared'] ? null : (string)$old['value_json'];
                $newRevision = $expectedRevision + 1;
                $q = $this->db->getQueryBuilder();
                $changed = $q->update('lf_saas_policy_values')
                    ->set('value_json', $q->createNamedParameter($valueJson))
                    ->set('is_cleared', $q->createNamedParameter($clear, IQueryBuilder::PARAM_BOOL))
                    ->set('revision', $q->createNamedParameter($newRevision, IQueryBuilder::PARAM_INT))
                    ->set('updated_by', $q->createNamedParameter($actor))
                    ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('id',
                        $q->createNamedParameter($policyId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('revision',
                        $q->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                if ($changed !== 1) {
                    throw new DomainException('revision_conflict');
                }
            }
            $this->writer->insert('lf_saas_policy_audit', [
                'policy_id' => $policyId,
                'scope_key' => $scopeKey,
                'policy_key' => $definition->key,
                'actor_uid' => $actor,
                'action' => $clear ? 'clear' : 'set',
                'old_value_json' => $oldJson,
                'new_value_json' => $valueJson,
                'previous_revision' => $expectedRevision,
                'new_revision' => $newRevision,
                'occurred_at' => $now,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
