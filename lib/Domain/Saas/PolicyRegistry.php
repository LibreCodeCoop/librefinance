<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Saas;

use DomainException;

/**
 * Registry defaults do not activate a feature or grant financial access.
 * Exact keys and scopes must be reviewed before the runtime API is enabled.
 */
final class PolicyRegistry {
    /** @var array<string, PolicyDefinition> */
    private array $definitions;

    public function __construct() {
        $specs = [
            new PolicyDefinition('environments.enabled', 'boolean', ['instance'], false, false),
            new PolicyDefinition('externalSharing.enabled', 'boolean',
                ['instance', 'environment', 'workspace'], false, true),
            new PolicyDefinition('imports.enabled', 'boolean',
                ['instance', 'environment', 'workspace'], true, true),
            new PolicyDefinition('workspaces.maxCount', 'ceiling',
                ['instance', 'environment'], PHP_INT_MAX, true),
        ];
        $this->definitions = [];
        foreach ($specs as $spec) {
            $this->definitions[$spec->key] = $spec;
        }
    }

    public function get(string $key): PolicyDefinition {
        return $this->definitions[$key] ?? throw new DomainException('unknown_policy_key');
    }

    /** @return list<PolicyDefinition> */
    public function all(): array {
        return array_values($this->definitions);
    }
}
