<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/Domain/Saas/PolicyDefinition.php';
require_once dirname(__DIR__, 2) . '/lib/Domain/Saas/PolicyResolver.php';
require_once dirname(__DIR__, 2) . '/lib/Domain/Saas/PolicyRegistry.php';

use OCA\LibreFinance\Domain\Saas\PolicyDefinition;
use OCA\LibreFinance\Domain\Saas\PolicyRegistry;
use OCA\LibreFinance\Domain\Saas\PolicyResolver;

$checks = 0;
function result(string $case, mixed $observed, mixed $expected): void {
    global $checks;
    if ($observed !== $expected) {
        throw new RuntimeException("$case: expected " . var_export($expected, true)
            . ' got ' . var_export($observed, true));
    }
    ++$checks;
}
function denied(string $case, callable $operation, string $error): void {
    global $checks;
    try {
        $operation();
    } catch (DomainException $e) {
        result($case, $e->getMessage(), $error);
        return;
    }
    throw new RuntimeException("$case: unexpected permission or input acceptance");
}
function layer(string $scope, mixed $value): array {
    return ['scope' => $scope, 'value' => $value];
}

$registry = new PolicyRegistry();
$resolver = new PolicyResolver();
$external = $registry->get('externalSharing.enabled');
$enable = $registry->get('environments.enabled');

result('external default fail closed', $resolver->resolve($external, [])['effectiveValue'], false);
result('default is source', $resolver->resolve($external, [])['sourceScope'], 'default');
$result = $resolver->resolve($external, [
    layer('instance', true), layer('environment', false), layer('workspace', true),
]);
result('child cannot bypass parent false', $result['effectiveValue'], false);
result('child denial is attributed', $result['blockedBy'], 'environment');
result('effective bool denies all enable values', $result['allowedValues'], [false]);
result('UI is never authoritative for editability', $result['editableByCurrentActor'], false);

$result = $resolver->resolve($external, [layer('instance', true), layer('environment', true)]);
result('explicit parent allows only allowed child', $result['effectiveValue'], true);
result('instance origin retained', $result['sourceScope'], 'instance');
result('bool still can be disabled', $result['allowedValues'], [false, true]);

$result = $resolver->resolve($external, [layer('environment', true)]);
result('missing instance opt-in stays denied', $result['effectiveValue'], false);
result('missing instance cannot be circumvented', $result['blockedBy'], 'default');

denied('instance-only policy rejects environment', fn() => $resolver->resolve($enable, [
    layer('instance', true), layer('environment', true),
]), 'unsupported_scope');
denied('malformed flag rejected', fn() => $resolver->resolve($external, [
    layer('instance', 'false'),
]), 'invalid_policy_value');
denied('duplicate instance rejected', fn() => $resolver->resolve($external, [
    layer('instance', true), layer('instance', false),
]), 'invalid_policy_order');
denied('reverse order rejected', fn() => $resolver->resolve($external, [
    layer('workspace', false), layer('environment', true),
]), 'invalid_policy_order');
denied('two environment contexts cannot be combined', fn() => $resolver->resolve($external, [
    layer('environment', true), layer('environment', false),
]), 'invalid_policy_order');
denied('client may not invent policies', fn() => $registry->get('financial.allAdmin'), 'unknown_policy_key');

$ceiling = $registry->get('workspaces.maxCount');
$result = $resolver->resolve($ceiling, [
    layer('instance', 8), layer('environment', 3),
]);
result('child ceiling tightens', $result['effectiveValue'], 3);
result('child ceiling source', $result['sourceScope'], 'environment');
result('numeric max advertised', $result['maxAllowed'], 3);
$result = $resolver->resolve($ceiling, [
    layer('instance', 2), layer('environment', 10),
]);
result('child ceiling cannot widen', $result['effectiveValue'], 2);
result('parent ceiling blocks wider setting', $result['blockedBy'], 'instance');
$result = $resolver->resolve($ceiling, [layer('instance', 0)]);
result('zero ceiling denies', $result['blockedBy'], 'instance');
denied('negative quota rejected', fn() => $resolver->resolve($ceiling, [
    layer('instance', -1),
]), 'invalid_policy_value');
denied('noninteger quota rejected', fn() => $resolver->resolve($ceiling, [
    layer('instance', 1.1),
]), 'invalid_policy_value');
denied('unsafe JSON integer quota rejected', fn() => $resolver->resolve($ceiling, [
    layer('instance', 9007199254740992),
]), 'invalid_policy_value');

$set = new PolicyDefinition('regions.allowed', 'allowed_set',
    ['instance', 'environment', 'workspace'], ['BR', 'PT', 'US'], true);
$result = $resolver->resolve($set, [
    layer('instance', ['BR', 'PT', 'US']),
    layer('environment', ['BR', 'PT', 'XX']),
    layer('workspace', ['PT', 'FR']),
]);
result('set only intersects', $result['effectiveValue'], ['PT']);
result('set narrowed at workspace', $result['sourceScope'], 'workspace');
$result = $resolver->resolve($set, [
    layer('instance', ['BR']), layer('environment', ['US']),
]);
result('empty intersection denies', $result['effectiveValue'], []);
result('empty set blocker', $result['blockedBy'], 'environment');
denied('set duplicate rejected', fn() => $resolver->resolve($set, [
    layer('instance', ['BR', 'BR']),
]), 'invalid_policy_value');
denied('set rejects nested values', fn() => $resolver->resolve($set, [
    layer('instance', [['BR']]),
]), 'invalid_policy_value');
denied('set rejects invalid scope', fn() => $resolver->resolve($set, [
    layer('instance', ['BR']), layer('user', ['BR']),
]), 'unsupported_scope');

denied('invalid scope definition', fn() => new PolicyDefinition('a.b', 'boolean',
    ['environment', 'instance'], false, true), 'invalid_policy_scopes');
denied('instance-only refuses child even if declared', fn() => $resolver->resolve(
    new PolicyDefinition('modules.enabled', 'boolean',
        ['instance', 'environment'], true, false),
    [layer('instance', true), layer('environment', false)]
), 'policy_override_forbidden');

$formatPolicy = $registry->get('banking.allowedImportFormats');
result('bank formats default preserves CSV and OFX',
    $resolver->resolve($formatPolicy, [])['effectiveValue'], ['csv', 'ofx']);
result('bank format policy is not a personal access grant',
    $formatPolicy->supports('user'), false);
$result = $resolver->resolve($formatPolicy, [
    layer('instance', ['csv', 'ofx']),
    layer('environment', ['ofx']),
    layer('workspace', ['csv', 'ofx']),
]);
result('bank format intersection never widens environment',
    $result['effectiveValue'], ['ofx']);
result('bank format intersection records narrowing source',
    $result['sourceScope'], 'environment');
$result = $resolver->resolve($formatPolicy, [
    layer('instance', ['ofx']), layer('environment', ['csv']),
]);
result('bank formats deny all on empty ancestor intersection',
    $result['effectiveValue'], []);
denied('bank format typo is not an automatically enabled format',
    fn() => $formatPolicy->assertValue(['csv', ['ofx']]), 'invalid_policy_value');

$dashboard = $registry->get('dashboard.kpis.enabled');
result('dashboard policy supports self-user scope',
    $dashboard->supports('user'), true);
result('dashboard default presents indicators',
    $resolver->resolve($dashboard, [])['effectiveValue'], true);
$result = $resolver->resolve($dashboard, [
    layer('instance', true), layer('environment', true),
    layer('workspace', true), layer('user', false),
]);
result('user may hide dashboard cards', $result['effectiveValue'], false);
result('user controls own dashboard source', $result['sourceScope'], 'user');
$result = $resolver->resolve($dashboard, [
    layer('instance', true), layer('environment', true),
    layer('workspace', false), layer('user', true),
]);
result('user cannot bypass workspace hide', $result['effectiveValue'], false);
result('workspace ancestor is the blocking source', $result['blockedBy'], 'workspace');
denied('user scope cannot change external sharing policy',
    fn() => $resolver->resolve($external, [
        layer('instance', true), layer('user', true),
    ]), 'unsupported_scope');
denied('user scope cannot change company count',
    fn() => $resolver->resolve($ceiling, [
        layer('instance', 2), layer('user', 99),
    ]), 'unsupported_scope');

echo "SaaS policy resolver: $checks named restrictive positive and negative assertions passed\n";
