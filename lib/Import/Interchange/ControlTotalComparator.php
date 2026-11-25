<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Import\Interchange;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Compares already-authorized, independently computed historical control totals
 * against a caller-approved scope. A match is limited to the declared controls.
 *
 * The caller supplies the workspace pairing, required controls, and common
 * UTC cutoff independently of both sets, and agrees on each total's definition
 * and sign convention before computing it. An explicitly calculated zero is
 * "0". An unavailable total is omitted and makes the comparison unverified
 * when required. Each side is limited to 256 scope rows and 32 control rows
 * per scope. The comparator performs no arithmetic, mapping or attestation.
 */
final class ControlTotalComparator {
    private const MAX_SCOPES = 256;
    private const MAX_CONTROLS = 32;
    private const SUPPORTED_CONTROLS = [
        'ledger_debit_total',
        'ledger_credit_total',
        'bank_opening_balance',
        'bank_closing_balance',
        'obligation_outstanding',
        'settlement_net',
    ];

    /**
     * A complete comparison is matched or different. Missing, ambiguous or
     * out-of-scope evidence produces unverified, even if all comparable values
     * match. A side at a different cutoff produces only a cutoff diagnostic
     * for that side. Diagnostics omit monetary values.
     *
     * @param list<array{sourceWorkspaceId: string, destinationWorkspaceId: string,
     *     currency: string, scale: int, requiredControls: list<string>}> $requiredScopes
     * @param array{cutoff: string, scopes: list<array{workspaceId: string, currency: string,
     *     scale: int, controls: list<array{name: string, minor: string}>}>} $source
     * @param array{cutoff: string, scopes: list<array{workspaceId: string, currency: string,
     *     scale: int, controls: list<array{name: string, minor: string}>}>} $destination
     * @return array{status: 'matched'|'different'|'unverified', diagnostics: list<array<string,mixed>>}
     * @throws DomainException On malformed inputs or an invalid comparison scope.
     */
    public function compare(string $cutoff, array $requiredScopes, array $source, array $destination): array {
        $this->validateCutoff($cutoff);
        $plan = $this->indexPlan($requiredScopes);
        $diagnostics = [];
        $sourceControls = $this->inspectSet($source, $cutoff, 'source', $plan, $diagnostics);
        $destinationControls = $this->inspectSet($destination, $cutoff, 'destination', $plan, $diagnostics);
        $status = $diagnostics === [] ? 'matched' : 'unverified';

        foreach ($sourceControls as $scopeKey => $controls) {
            foreach ($controls as $name => $minor) {
                $otherMinor = $destinationControls[$scopeKey][$name] ?? null;
                if ($otherMinor !== null && $minor !== $otherMinor) {
                    $diagnostics[] = ['code' => 'changed_control', 'scope' => $plan[$scopeKey]['scope'], 'control' => $name];
                    if ($status === 'matched') {
                        $status = 'different';
                    }
                }
            }
        }

        return ['status' => $status, 'diagnostics' => $diagnostics];
    }

    private function indexPlan(array $scopes): array {
        $this->validateList($scopes, self::MAX_SCOPES);
        if ($scopes === []) {
            throw new DomainException('empty_control_comparison_scope');
        }
        $plan = [];
        $destinations = [];
        foreach ($scopes as $row) {
            $this->validateKeys($row, ['sourceWorkspaceId', 'destinationWorkspaceId', 'currency', 'scale', 'requiredControls']);
            $sourceKey = $this->scopeKey($row['sourceWorkspaceId'], $row['currency'], $row['scale']);
            $destinationKey = $this->scopeKey($row['destinationWorkspaceId'], $row['currency'], $row['scale']);
            if (isset($plan[$sourceKey]) || isset($destinations[$destinationKey])) {
                throw new DomainException('duplicate_required_control_scope');
            }

            $names = $row['requiredControls'];
            $this->validateList($names, self::MAX_CONTROLS);
            if ($names === []) {
                throw new DomainException('empty_required_controls');
            }
            $required = [];
            foreach ($names as $name) {
                if (!in_array($name, self::SUPPORTED_CONTROLS, true)) {
                    throw new DomainException('unsupported_required_control');
                }
                if (isset($required[$name])) {
                    throw new DomainException('duplicate_required_control');
                }
                $required[$name] = true;
            }
            ksort($required, SORT_STRING);
            $scope = [
                'sourceWorkspaceId' => $row['sourceWorkspaceId'],
                'destinationWorkspaceId' => $row['destinationWorkspaceId'],
                'currency' => $row['currency'],
                'scale' => $row['scale'],
            ];
            $plan[$sourceKey] = ['scope' => $scope, 'destinationKey' => $destinationKey, 'required' => $required];
            $destinations[$destinationKey] = true;
        }
        ksort($plan, SORT_STRING);
        return $plan;
    }

    private function inspectSet(array $set, string $cutoff, string $side, array $plan, array &$diagnostics): array {
        $this->validateKeys($set, ['cutoff', 'scopes']);
        $this->validateCutoff($set['cutoff']);
        $this->validateList($set['scopes'], self::MAX_SCOPES);
        $observed = [];
        foreach ($set['scopes'] as $row) {
            $this->validateKeys($row, ['workspaceId', 'currency', 'scale', 'controls']);
            $key = $this->scopeKey($row['workspaceId'], $row['currency'], $row['scale']);
            $controls = $this->indexControls($row['controls']);
            $observed[$key] = [
                'scope' => ['workspaceId' => $row['workspaceId'], 'currency' => $row['currency'], 'scale' => $row['scale']],
                'controls' => isset($observed[$key]) ? null : $controls,
            ];
        }
        if ($set['cutoff'] !== $cutoff) {
            $diagnostics[] = ['code' => 'cutoff_mismatch', 'side' => $side, 'expectedCutoff' => $cutoff,
                'actualCutoff' => $set['cutoff']];
            return [];
        }

        $usable = [];
        foreach ($plan as $sourceKey => $entry) {
            $key = $side === 'source' ? $sourceKey : $entry['destinationKey'];
            $scope = $entry['scope'];
            if (!isset($observed[$key])) {
                $diagnostics[] = ['code' => 'missing_scope', 'side' => $side, 'scope' => $scope];
                continue;
            }
            $controls = $observed[$key]['controls'];
            unset($observed[$key]);
            if ($controls === null) {
                $diagnostics[] = ['code' => 'duplicate_scope', 'side' => $side, 'scope' => $scope];
                continue;
            }
            foreach ($entry['required'] as $name => $_) {
                if (!array_key_exists($name, $controls)) {
                    $diagnostics[] = ['code' => 'missing_control', 'side' => $side, 'scope' => $scope, 'control' => $name];
                } elseif ($controls[$name] !== null) {
                    $usable[$sourceKey][$name] = $controls[$name];
                }
            }
            foreach ($controls as $name => $minor) {
                $code = match (true) {
                    $minor === null => 'duplicate_control',
                    !in_array($name, self::SUPPORTED_CONTROLS, true) => 'unsupported_control',
                    !isset($entry['required'][$name]) => 'unexpected_control',
                    default => null,
                };
                if ($code !== null) {
                    $diagnostics[] = ['code' => $code, 'side' => $side, 'scope' => $scope, 'control' => $name];
                }
            }
        }
        ksort($observed, SORT_STRING);
        foreach ($observed as $row) {
            if ($row['controls'] === null) {
                $diagnostics[] = ['code' => 'duplicate_scope', 'side' => $side, 'scope' => $row['scope']];
            }
            $diagnostics[] = ['code' => 'unexpected_scope', 'side' => $side, 'scope' => $row['scope']];
        }
        return $usable;
    }

    private function indexControls(mixed $rows): array {
        $this->validateList($rows, self::MAX_CONTROLS);
        $controls = [];
        foreach ($rows as $row) {
            $this->validateKeys($row, ['name', 'minor']);
            $name = $row['name'];
            $minor = $row['minor'];
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1) {
                throw new DomainException('invalid_control_name');
            }
            if (!is_string($minor) || preg_match('/^(?:0|-?[1-9][0-9]{0,37})$/D', $minor) !== 1) {
                throw new DomainException('invalid_control_minor_units');
            }
            // Null marks an ambiguous total. No occurrence wins.
            $controls[$name] = array_key_exists($name, $controls) ? null : $minor;
        }
        ksort($controls, SORT_STRING);
        return $controls;
    }

    private function scopeKey(mixed $workspaceId, mixed $currency, mixed $scale): string {
        if (!is_string($workspaceId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $workspaceId) !== 1
            || !is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || !is_int($scale) || $scale < 0 || $scale > 9) {
            throw new DomainException('invalid_control_scope_identity');
        }
        return $workspaceId . '/' . $currency . '/' . $scale;
    }

    private function validateCutoff(mixed $cutoff): void {
        if (!is_string($cutoff) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $cutoff) !== 1) {
            throw new DomainException('invalid_control_cutoff');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $cutoff, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $cutoff) {
            throw new DomainException('invalid_control_cutoff');
        }
    }

    private function validateList(mixed $rows, int $limit): void {
        if (!is_array($rows) || count($rows) > $limit || !array_is_list($rows)) {
            throw new DomainException('invalid_control_comparison_list');
        }
    }

    private function validateKeys(mixed $row, array $keys): void {
        if (!is_array($row) || count($row) !== count($keys)) {
            throw new DomainException('invalid_control_comparison_structure');
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                throw new DomainException('invalid_control_comparison_structure');
            }
        }
    }
}
