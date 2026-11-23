<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Saas;

use DomainException;

/**
 * Pure restrictive policy resolution; callers must derive the target scopes
 * from server-authenticated identities and separately enforce financial ACL.
 */
final class PolicyResolver {
    /**
     * Layers must be ordered ancestor to child and carry their authoritative
     * scope. A missing instance override uses the restrictive registry default.
     *
     * @param list<array{scope:string,value:mixed}> $layers
     * @return array{effectiveValue:mixed,sourceScope:string,blockedBy:?string,allowedValues:?array,maxAllowed:?int,editableByCurrentActor:bool,version:int}
     */
    public function resolve(PolicyDefinition $definition, array $layers): array {
        $effective = $definition->defaultValue;
        $source = 'default';
        $blockedBy = null;
        $seen = [];
        $previous = -1;
        $order = ['instance' => 0, 'environment' => 1, 'workspace' => 2, 'user' => 3];
        foreach ($layers as $layer) {
            if (!is_array($layer) || !isset($layer['scope']) || !array_key_exists('value', $layer)
                || !is_string($layer['scope'])) {
                throw new DomainException('invalid_policy_layer');
            }
            $scope = $layer['scope'];
            if (!$definition->supports($scope)) {
                throw new DomainException('unsupported_scope');
            }
            $position = $order[$scope];
            if ($position <= $previous || isset($seen[$scope])) {
                throw new DomainException('invalid_policy_order');
            }
            if ($position > 0 && !$definition->allowChildOverride) {
                throw new DomainException('policy_override_forbidden');
            }
            $definition->assertValue($layer['value']);
            $previous = $position;
            $seen[$scope] = true;
            $value = $layer['value'];
            if ($scope === 'instance') {
                // The registry default is a fallback until an instance owner
                // explicitly sets policy, not an immutable hidden ancestor.
                $effective = $value;
                $source = $scope;
                $blockedBy = null;
                continue;
            }
            switch ($definition->type) {
                case 'boolean':
                    if ($effective === false) {
                        $blockedBy ??= $source;
                    } elseif ($value === false) {
                        $effective = false;
                        $source = $scope;
                        $blockedBy = $scope;
                    }
                    break;
                case 'ceiling':
                    if ($value < $effective) {
                        $effective = $value;
                        $source = $scope;
                    } elseif ($value > $effective) {
                        $blockedBy ??= $source;
                    }
                    if ($effective === 0) {
                        $blockedBy ??= $source;
                    }
                    break;
                case 'allowed_set':
                    $intersection = array_values(array_intersect($effective, $value));
                    if ($intersection !== $effective) {
                        $effective = $intersection;
                        $source = $scope;
                    }
                    if ($intersection === []) {
                        $blockedBy ??= $source;
                    }
                    break;
            }
        }
        if ($definition->type === 'boolean' && $effective === false) {
            $blockedBy ??= $source;
        }
        if ($definition->type === 'allowed_set' && $effective === []) {
            $blockedBy ??= $source;
        }
        if ($definition->type === 'ceiling' && $effective === 0) {
            $blockedBy ??= $source;
        }
        return [
            'effectiveValue' => $effective,
            'sourceScope' => $source,
            'blockedBy' => $blockedBy,
            'allowedValues' => $definition->type === 'boolean'
                ? ($effective ? [false, true] : [false])
                : ($definition->type === 'allowed_set' ? $effective : null),
            'maxAllowed' => $definition->type === 'ceiling' ? $effective : null,
            // Authority to edit is evaluated separately by server-side
            // PolicyAuthorizationService; never inferred from a UI request.
            'editableByCurrentActor' => false,
            'version' => $definition->version,
        ];
    }
}
