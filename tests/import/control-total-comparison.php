<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\LibreFinance\Import\Interchange\ControlTotalComparator;

require_once dirname(__DIR__, 2) . '/lib/Import/Interchange/ControlTotalComparator.php';

function scope(string $workspaceId, string $currency, int $scale, array $controls): array {
    return ['workspaceId' => $workspaceId, 'currency' => $currency, 'scale' => $scale, 'controls' => $controls];
}

function control(string $name, string $minor): array {
    return ['name' => $name, 'minor' => $minor];
}

$checks = 0;
function expect(string $label, mixed $expected, mixed $actual): void {
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . PHP_EOL . var_export(['expected' => $expected, 'actual' => $actual], true));
    }
    ++$checks;
    echo 'PASS: ', $label, PHP_EOL;
}

function reject(string $label, string $code, callable $compare): void {
    try {
        $compare();
    } catch (DomainException $e) {
        expect($label, $code, $e->getMessage());
        return;
    }
    throw new RuntimeException('Unexpected acceptance: ' . $label);
}

$cutoff = '2025-02-01T00:00:00Z';
$cash = ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a', 'currency' => 'BRL', 'scale' => 2];
$otherCash = ['sourceWorkspaceId' => 'book-b', 'destinationWorkspaceId' => 'ledger-b', 'currency' => 'BRL', 'scale' => 2];
$closing = 'bank_closing_balance';
$opening = 'bank_opening_balance';
$plan = [
    $cash + ['requiredControls' => [$opening, $closing]],
    $otherCash + ['requiredControls' => [$closing]],
    ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a', 'currency' => 'JPY',
        'scale' => 0, 'requiredControls' => [$closing]],
    ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a', 'currency' => 'KWD',
        'scale' => 3, 'requiredControls' => ['obligation_outstanding', 'settlement_net']],
];

// Independently specified synthetic totals at the same cutoff.
$source = ['cutoff' => $cutoff, 'scopes' => [
    scope('book-a', 'BRL', 2, [control($opening, '0'), control($closing, '-125')]),
    scope('book-b', 'BRL', 2, [control($closing, '125')]),
    scope('book-a', 'JPY', 0, [control($closing, '125')]),
    scope('book-a', 'KWD', 3, [
        control('obligation_outstanding', '12345678901234567890123456789012345678'),
        control('settlement_net', '-125'),
    ]),
]];
$destination = ['cutoff' => $cutoff, 'scopes' => [
    scope('ledger-b', 'BRL', 2, [control($closing, '125')]),
    scope('ledger-a', 'KWD', 3, [
        control('settlement_net', '-125'),
        control('obligation_outstanding', '12345678901234567890123456789012345678'),
    ]),
    scope('ledger-a', 'JPY', 0, [control($closing, '125')]),
    scope('ledger-a', 'BRL', 2, [control($closing, '-125'), control($opening, '0')]),
]];
$comparator = new ControlTotalComparator();
$compare = fn(array $left, array $right): array => $comparator->compare($cutoff, $plan, $left, $right);
expect('order-independent match across books and currencies at scales 0, 2 and 3',
    ['status' => 'matched', 'diagnostics' => []], $compare($source, $destination));

$missing = $destination;
array_pop($missing['scopes'][3]['controls']);
expect('a missing record differs from a verified zero', ['status' => 'unverified', 'diagnostics' => [
    ['code' => 'missing_control', 'side' => 'destination', 'scope' => $cash, 'control' => $opening],
]], $compare($source, $missing));
$alsoMissing = $source;
array_shift($alsoMissing['scopes'][0]['controls']);
expect('equal remaining values cannot hide a required control missing on both sides', ['status' => 'unverified', 'diagnostics' => [
    ['code' => 'missing_control', 'side' => 'source', 'scope' => $cash, 'control' => $opening],
    ['code' => 'missing_control', 'side' => 'destination', 'scope' => $cash, 'control' => $opening],
]], $compare($alsoMissing, $missing));

$missing = $destination;
array_shift($missing['scopes']);
$alsoMissing = $source;
array_splice($alsoMissing['scopes'], 1, 1);
expect('agreeing on every remaining book cannot hide a book omitted on both sides', ['status' => 'unverified', 'diagnostics' => [
    ['code' => 'missing_scope', 'side' => 'source', 'scope' => $otherCash],
    ['code' => 'missing_scope', 'side' => 'destination', 'scope' => $otherCash],
]], $compare($alsoMissing, $missing));

$changed = $destination;
$changed['scopes'][0]['controls'][0]['minor'] = '-125';
$changed['scopes'][3]['controls'][0]['minor'] = '125';
$changeA = ['code' => 'changed_control', 'scope' => $cash, 'control' => $closing];
$changeB = ['code' => 'changed_control', 'scope' => $otherCash, 'control' => $closing];
expect('offsetting differences in separate books remain two differences',
    ['status' => 'different', 'diagnostics' => [$changeA, $changeB]], $compare($source, $changed));
array_pop($changed['scopes'][3]['controls']);
expect('an incomplete comparison still reports unambiguous differences',
    ['status' => 'unverified', 'diagnostics' => [
        ['code' => 'missing_control', 'side' => 'destination', 'scope' => $cash, 'control' => $opening],
        $changeA,
        $changeB,
    ]], $compare($source, $changed));

$changed = $destination;
$changed['scopes'][1]['controls'][1]['minor'] = '12345678901234567890123456789012345679';
$result = $compare($source, $changed);
expect('a one-minor-unit difference at 38 digits is exact', 'different', $result['status']);
expect('the difference identifies the KWD control', [
    ['code' => 'changed_control', 'scope' => ['sourceWorkspaceId' => 'book-a', 'destinationWorkspaceId' => 'ledger-a',
        'currency' => 'KWD', 'scale' => 3], 'control' => 'obligation_outstanding'],
], $result['diagnostics']);

$changed = $destination;
$changed['scopes'][2]['controls'][0]['minor'] = '124';
$changed['scopes'][3]['controls'][0]['minor'] = '-124';
expect('offsetting minor units in BRL and JPY remain separate differences',
    ['status' => 'different', 'diagnostics' => [
        $changeA,
        ['code' => 'changed_control', 'scope' => array_replace($cash, ['currency' => 'JPY', 'scale' => 0]),
            'control' => $closing],
    ]], $compare($source, $changed));

foreach ([['currency', 'USD'], ['scale', 3], ['workspaceId', 'book-a']] as [$field, $value]) {
    $changed = $destination;
    $changed['scopes'][3][$field] = $value;
    expect('a different ' . $field . ' cannot be inferred from identical amounts', ['status' => 'unverified', 'diagnostics' => [
        ['code' => 'missing_scope', 'side' => 'destination', 'scope' => $cash],
        ['code' => 'unexpected_scope', 'side' => 'destination',
            'scope' => array_replace(['workspaceId' => 'ledger-a', 'currency' => 'BRL', 'scale' => 2], [$field => $value])],
    ]], $compare($source, $changed));
}

$laterCutoff = '2025-02-02T00:00:00Z';
$changed = $destination;
$changed['cutoff'] = $laterCutoff;
$cutoffDiagnostic = ['code' => 'cutoff_mismatch', 'side' => 'destination',
    'expectedCutoff' => $cutoff, 'actualCutoff' => $laterCutoff];
expect('equal values at a different cutoff are unverified',
    ['status' => 'unverified', 'diagnostics' => [$cutoffDiagnostic]], $compare($source, $changed));
$laterSource = array_replace($source, ['cutoff' => $laterCutoff]);
expect('two matching actual cutoffs still must match the approved cutoff', ['status' => 'unverified', 'diagnostics' => [
    array_replace($cutoffDiagnostic, ['side' => 'source']), $cutoffDiagnostic,
]], $compare($laterSource, $changed));

$duplicate = $destination;
$duplicate['scopes'][] = $duplicate['scopes'][3];
expect('a repeated scope is ambiguous even when identical', ['status' => 'unverified', 'diagnostics' => [
    ['code' => 'duplicate_scope', 'side' => 'destination', 'scope' => $cash],
]], $compare($source, $duplicate));
$duplicate = $destination;
$duplicate['scopes'][3]['controls'][] = control($closing, '600');
expect('a repeated control has no winning value', ['status' => 'unverified', 'diagnostics' => [
    ['code' => 'duplicate_control', 'side' => 'destination', 'scope' => $cash, 'control' => $closing],
]], $compare($source, $duplicate));

foreach (['fiscal_total' => 'unsupported_control', 'ledger_debit_total' => 'unexpected_control'] as $name => $code) {
    $expanded = $destination;
    $expanded['scopes'][3]['controls'][] = control($name, '0');
    expect($name . ' is reported even with otherwise matching controls', ['status' => 'unverified', 'diagnostics' => [
        ['code' => $code, 'side' => 'destination', 'scope' => $cash, 'control' => $name],
    ]], $compare($source, $expanded));
}

reject('an empty plan cannot yield a vacuous match', 'empty_control_comparison_scope',
    fn() => $comparator->compare($cutoff, [], $source, $destination));
foreach ([
    'empty_required_controls' => [],
    'duplicate_required_control' => [$closing, $closing],
    'unsupported_required_control' => ['fiscal_total'],
] as $code => $requiredControls) {
    reject('invalid plan fails closed (' . $code . ')', $code,
        fn() => $comparator->compare($cutoff, [$cash + ['requiredControls' => $requiredControls]], $source, $destination));
}
$ambiguousPlan = $plan;
$ambiguousPlan[1]['destinationWorkspaceId'] = 'ledger-a';
reject('a destination scope cannot satisfy two source scopes', 'duplicate_required_control_scope',
    fn() => $comparator->compare($cutoff, $ambiguousPlan, $source, $destination));

foreach ([0, 0.25, '-0', '01', '+1', '1.0', '1e3', str_repeat('9', 39)] as $minor) {
    $invalid = $destination;
    $invalid['scopes'][3]['controls'][0]['minor'] = $minor;
    reject('invalid minor units ' . var_export($minor, true), 'invalid_control_minor_units',
        fn() => $compare($source, $invalid));
}
$invalid = array_replace($destination, ['cutoff' => '2025-02-30T00:00:00Z']);
reject('invalid calendar cutoff', 'invalid_control_cutoff', fn() => $compare($source, $invalid));
$oversized = array_replace($destination, ['scopes' => array_fill(0, 257, $destination['scopes'][0])]);
reject('scope count limit', 'invalid_control_comparison_list', fn() => $compare($source, $oversized));
$oversized = $destination;
$oversized['scopes'][0]['controls'] = array_fill(0, 33, control($closing, '1'));
reject('control count limit', 'invalid_control_comparison_list', fn() => $compare($source, $oversized));

echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
