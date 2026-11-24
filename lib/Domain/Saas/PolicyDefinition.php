<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Domain\Saas;

use DomainException;

/** A versioned, typed policy contract, not an ACL or a user grant. */
final readonly class PolicyDefinition {
    /** @param list<string> $supportedScopes */
    public function __construct(
        public string $key,
        public string $type,
        public array $supportedScopes,
        public mixed $defaultValue,
        public bool $allowChildOverride,
        public int $version = 1,
    ) {
        if (preg_match('/^[a-z][A-Za-z0-9]*(?:\.[a-z][A-Za-z0-9]*)+$/D', $key) !== 1
            || strlen($key) > 100 || $version < 1) {
            throw new DomainException('invalid_policy_definition');
        }
        if (!in_array($type, ['boolean', 'ceiling', 'allowed_set'], true)) {
            throw new DomainException('invalid_policy_type');
        }
        $ordered = ['instance', 'environment', 'workspace', 'user'];
        if ($supportedScopes === [] || $supportedScopes[0] !== 'instance') {
            throw new DomainException('invalid_policy_scopes');
        }
        $previous = -1;
        foreach ($supportedScopes as $scope) {
            $position = array_search($scope, $ordered, true);
            if ($position === false || $position <= $previous) {
                throw new DomainException('invalid_policy_scopes');
            }
            $previous = $position;
        }
        $this->assertValue($defaultValue);
    }

    public function supports(string $scope): bool {
        return in_array($scope, $this->supportedScopes, true);
    }

    public function assertValue(mixed $value): void {
        $valid = match ($this->type) {
            'boolean' => is_bool($value),
            'ceiling' => is_int($value) && $value >= 0 && $value <= 9007199254740991,
            'allowed_set' => is_array($value)
                && array_is_list($value)
                && count($value) <= 256
                // Validate scalar types before uniqueness to avoid PHP
                // array-to-string coercion on malformed nested JSON.
                && array_reduce($value, static fn(bool $ok, mixed $v): bool =>
                    $ok && is_string($v) && $v !== '' && strlen($v) <= 64, true)
                && count($value) === count(array_unique($value, SORT_STRING)),
            default => false,
        };
        if (!$valid) {
            throw new DomainException('invalid_policy_value');
        }
    }
}
