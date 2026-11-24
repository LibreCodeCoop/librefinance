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
            new PolicyDefinition('environments.enabled', 'boolean', ['instance'], true, false),
            new PolicyDefinition('externalSharing.enabled', 'boolean',
                ['instance', 'environment', 'workspace'], false, true),
            new PolicyDefinition('imports.enabled', 'boolean',
                ['instance', 'environment', 'workspace'], true, true),
            // Restrict the actual banking CSV/OFX importer without changing
            // existing default behavior or authorizing imports by itself.
            new PolicyDefinition('banking.allowedImportFormats', 'allowed_set',
                ['instance', 'environment', 'workspace'], ['csv', 'ofx'], true,
                allowedSetValues: ['csv', 'ofx']),
            // Core report APIs must obey policy even when their module flag is enabled.
            new PolicyDefinition('modules.reports.enabled', 'boolean',
                ['instance', 'environment', 'workspace'], true, true),
            // Optional NFS-e integration only prepares fiscal drafts. Policy
            // never grants finance access or authorizes fiscal issuance.
            new PolicyDefinition('regional.nfseDrafts.enabled', 'boolean',
                ['instance', 'environment', 'workspace'], true, true),
            new PolicyDefinition('workspaces.maxCount', 'ceiling',
                ['instance', 'environment'], 9007199254740991, true),
            // Presentation only: hiding KPI cards is not a financial ACL.
            // This is the first self-service user-level policy; no ledger
            // operation may infer a role or authorization from this flag.
            new PolicyDefinition('dashboard.kpis.enabled', 'boolean',
                ['instance', 'environment', 'workspace', 'user'], true, true),
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
