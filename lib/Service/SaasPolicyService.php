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
 * Instance policy persistence. Environment/workspace/user policy administration
 * requires the later server-authenticated environment ownership contracts.
 *
 * Policy writes never create financial grants or bypass financial ACL.
 */
final class SaasPolicyService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private GovernanceService $governance,
        private IUserSession $session,
        private PolicyRegistry $registry,
        private PolicyResolver $resolver,
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
     * Canonical storage key must be constructed exclusively from server-trusted
     * identities. Non-instance scopes are not opened to public HTTP here.
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
                        'scope_type' => 'instance',
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
