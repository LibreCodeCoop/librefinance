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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only typed policy resolution for server-owned scopes.
 *
 * No user-facing endpoint accepts raw scope keys or numeric environment IDs.
 * The financial ACL must be checked independently by the invoking service.
 * There is no cached security state: each call reads current revisions.
 */
final class SaasPolicyRuntimeGate {
    public function __construct(
        private IDBConnection $db,
        private PolicyRegistry $registry,
        private PolicyResolver $resolver,
    ) {}

    /** @return array<string,mixed> */
    public function instance(string $key): array {
        $definition = $this->registry->get($key);
        return $this->resolver->resolve($definition,
            $this->layers($definition));
    }

    /** @return array<string,mixed> */
    public function environment(int $environmentId, string $key): array {
        if ($environmentId < 1) {
            throw new DomainException('invalid_policy_scope');
        }
        $definition = $this->registry->get($key);
        return $this->resolver->resolve($definition,
            $this->layers($definition, $environmentId));
    }

    /** @return array<string,mixed> */
    public function workspace(int $spaceId, string $key): array {
        if ($spaceId < 1) {
            throw new DomainException('invalid_policy_scope');
        }
        $definition = $this->registry->get($key);
        $environmentId = null;
        $q = $this->db->getQueryBuilder();
        $q->select('environment_id')->from('lf_environment_spaces')
            ->where($q->expr()->eq('space_id',
                $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $found = $q->executeQuery()->fetchOne();
        if ($found !== false) {
            $environmentId = (int)$found;
            if ($environmentId < 1) {
                throw new DomainException('invalid_policy_scope');
            }
        }
        return $this->resolver->resolve($definition,
            $this->layers($definition, $environmentId, $found !== false ? $spaceId : null));
    }

    public function requireInstanceEnabled(string $key): void {
        if ($this->instance($key)['effectiveValue'] !== true) {
            throw new DomainException('module_disabled');
        }
    }

    public function requireWorkspaceEnabled(int $spaceId, string $key): void {
        if ($this->workspace($spaceId, $key)['effectiveValue'] !== true) {
            throw new DomainException('module_disabled');
        }
    }

    /**
     * @return list<array{scope:string,value:mixed}>
     */
    private function layers(PolicyDefinition $definition,
        ?int $environmentId = null, ?int $spaceId = null): array {
        $layers = [];
        foreach ([
            'instance' => 'instance',
            'environment' => $environmentId !== null ? 'environment:' . $environmentId : null,
            'workspace' => $spaceId !== null ? 'workspace:' . $spaceId : null,
        ] as $scope => $scopeKey) {
            if ($scopeKey === null || !$definition->supports($scope)) {
                continue;
            }
            $q = $this->db->getQueryBuilder();
            $q->select('value_json', 'is_cleared')
                ->from('lf_saas_policy_values')
                ->where($q->expr()->eq('scope_key',
                    $q->createNamedParameter($scopeKey, IQueryBuilder::PARAM_STR)))
                ->andWhere($q->expr()->eq('scope_type',
                    $q->createNamedParameter($scope, IQueryBuilder::PARAM_STR)))
                ->andWhere($q->expr()->eq('policy_key',
                    $q->createNamedParameter($definition->key, IQueryBuilder::PARAM_STR)));
            $row = $q->executeQuery()->fetchAssociative();
            if ($row === false || (bool)$row['is_cleared']) {
                continue;
            }
            if (!is_string($row['value_json'])) {
                throw new DomainException('invalid_policy_value');
            }
            try {
                $value = json_decode($row['value_json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DomainException('invalid_policy_value');
            }
            $definition->assertValue($value);
            $layers[] = ['scope' => $scope, 'value' => $value];
        }
        return $layers;
    }
}
