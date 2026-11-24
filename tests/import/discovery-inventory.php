<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

foreach (['SourceSystem', 'DiscoverySource', 'DiscoveryPage', 'DiscoveryInventory'] as $class) {
    require_once dirname(__DIR__, 2) . '/lib/Import/Source/' . $class . '.php';
}
use OCA\LibreFinance\Import\Source\DiscoveryInventory;
use OCA\LibreFinance\Import\Source\DiscoveryPage;
use OCA\LibreFinance\Import\Source\DiscoverySource;

final class FixtureDiscoverySource implements DiscoverySource {
    public int $requests = 0;
    public array $metadata;
    public array $collections = [];
    public array $overrides = [];
    public function __construct() {
        $resources = ['companies', 'currencies', 'taxes', 'accounts', 'bank_accounts', 'categories', 'items', 'contacts',
            'invoices', 'invoice_lines', 'bills', 'bill_lines', 'settlements', 'transactions',
            'transfers', 'reconciliations', 'payments', 'journal_entries', 'journal_lines',
            'employees', 'departments', 'funding_sources', 'budget_lines', 'budget_revisions',
        'dimensions', 'commitments', 'funding_allocations', 'reports',
            'dashboard_settings', 'custom_fields', 'attachments', 'fiscal_artifacts', 'extension.example'];
        $this->metadata = ['sourceSystem' => 'synthetic', 'sourceVersion' => '1.2.3',
            'cutoff' => '2026-11-24T00:00:00Z', 'snapshotId' => 'private-snapshot-token',
            'resources' => array_fill_keys($resources, 'supported'),
            'features' => ['extension.example' => true, 'optional.feature' => false]];
        $this->collections['workspaces'] = [['id' => 'A'], ['id' => 'B'], ['id' => 'C']];
        $this->collections['A/invoices'] = [['id' => 'i1'], ['id' => 'i2'], ['id' => 'i3']];
        $this->collections['A/invoice_lines'] = [
            ['id' => 'l1', 'references' => [['resource' => 'invoices', 'id' => 'i1']]],
            ['id' => 'l2', 'references' => [['resource' => 'invoices', 'id' => 'i2']]],
            ['id' => 'l3', 'references' => [['resource' => 'invoices', 'id' => 'i3']]],
        ];
        $this->collections['B/invoices'] = [['id' => 'i1']];
        $this->collections['B/fiscal_artifacts'] = [
            ['id' => 'f1', 'artifactSha256' => hash('sha256', 'synthetic artifact')],
        ];
        $this->collections['C/extension.example'] = [['id' => 'x1']];
    }
    public function describe(): array {
        return $this->metadata;
    }
    public function workspaces(string $snapshotId, ?string $cursor, int $limit): DiscoveryPage {
        return $this->page('workspaces', $snapshotId, $cursor, $limit);
    }
    public function records(string $snapshotId, string $workspaceId, string $resource,
        ?string $cursor, int $limit): DiscoveryPage {
        return $this->page($workspaceId . '/' . $resource, $snapshotId, $cursor, $limit);
    }
    private function page(string $key, string $snapshotId, ?string $cursor, int $limit): DiscoveryPage {
        ++$this->requests;
        if ($snapshotId !== $this->metadata['snapshotId'] || $limit < 1 || $limit > 250) {
            throw new RuntimeException('Unexpected fixture contract');
        }
        if (isset($this->overrides[$key])) {
            return ($this->overrides[$key])($snapshotId, $cursor, $limit);
        }
        $records = $this->collections[$key] ?? [];
        $offset = $cursor === null ? 0 : (int)$cursor;
        $page = array_slice($records, $offset, $limit);
        $next = $offset + count($page);
        return new DiscoveryPage('ok', $snapshotId, $page, $next < count($records) ? (string)$next : null,
            count($records));
    }
}

$checks = 0;
function check(string $description, bool $ok): void {
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    ++$checks;
    echo 'PASS: ', $description, PHP_EOL;
}
function inventory(FixtureDiscoverySource $source, int $size = 2): array {
    return (new DiscoveryInventory($size))->collect($source);
}
function result(array $manifest, string $workspace, string $resource): array {
    return array_column($manifest['inventory'], 'resources', 'workspaceId')[$workspace][$resource];
}
$source = new FixtureDiscoverySource();
$manifest = inventory($source);
check('three accessible companies, independently paginated', $manifest['complete']
    && $manifest['workspaces']['count'] === 3 && $source->requests > 3 * count($source->metadata['resources']));
check('consistent source metadata and cutoff', $manifest['sourceVersion'] === '1.2.3'
    && $manifest['cutoff'] === '2026-11-24T00:00:00Z' && !$manifest['sourceAttested']
    && $manifest['scope'] === 'accessible_workspaces_only');
check('workspace totals do not combine', result($manifest, 'A', 'invoices')['count'] === 3
    && result($manifest, 'B', 'invoices')['count'] === 1);
check('child references verified within their workspace', result($manifest, 'A', 'invoice_lines')['referenceCount'] === 3
    && result($manifest, 'A', 'invoice_lines')['unresolvedReferences'] === 0);
check('successful empty collection is explicit', result($manifest, 'C', 'invoices')['status'] === 'empty'
    && result($manifest, 'C', 'invoices')['count'] === 0);
check('extension explicitly retained for mapping', result($manifest, 'C', 'extension.example')['mappingRequired']
    && result($manifest, 'C', 'extension.example')['count'] === 1 && $manifest['features']['extension.example']);
check('deterministic digest across page boundaries', inventory(new FixtureDiscoverySource(), 1) === $manifest
    && inventory(new FixtureDiscoverySource(), 250) === $manifest);
check('snapshot token and raw child identifiers not disclosed',
    !str_contains(json_encode($manifest, JSON_THROW_ON_ERROR), 'private-snapshot-token')
    && !str_contains(json_encode($manifest, JSON_THROW_ON_ERROR), '"l1"'));
$changed = new FixtureDiscoverySource();
$changed->collections['B/fiscal_artifacts'][0]['artifactSha256'] = hash('sha256', 'different synthetic artifact');
check('artifact digest coverage is counted without reading artifacts',
    result($manifest, 'B', 'fiscal_artifacts')['artifactDigestCount'] === 1);
check('artifact digest changes integrity evidence',
    result(inventory($changed), 'B', 'fiscal_artifacts')['sha256'] !== result($manifest, 'B', 'fiscal_artifacts')['sha256']);
$changed = new FixtureDiscoverySource();
$changed->collections['A/invoice_lines'][0]['references'][0]['id'] = 'missing';
$m = inventory($changed);
check('missing target in complete collection is unresolved', !$m['complete']
    && result($m, 'A', 'invoice_lines')['unresolvedReferences'] === 1);

$partial = new FixtureDiscoverySource();
unset($partial->metadata['resources']['taxes']);
$partial->metadata['resources']['employees'] = 'unsupported';
$partial->overrides['A/invoices'] = static fn($snapshot) => new DiscoveryPage('access_denied', $snapshot);
$partial->overrides['B/items'] = static fn($snapshot) => new DiscoveryPage('timeout', $snapshot);
$partial->overrides['C/contacts'] = static fn($snapshot) => new DiscoveryPage('unsupported', $snapshot);
$partial->overrides['C/accounts'] = static fn($snapshot) => new DiscoveryPage('ok', $snapshot, [['id' => 'acct']], null, 5);
$m = inventory($partial);
foreach ([
    ['A', 'taxes', 'missing_capability'], ['A', 'employees', 'unsupported'],
    ['A', 'invoices', 'access_denied'], ['B', 'items', 'timeout'],
    ['C', 'contacts', 'unsupported'], ['C', 'accounts', 'truncated'],
] as [$workspace, $resource, $status]) {
    $r = result($m, $workspace, $resource);
    check($status . ' is not successful empty', $r['status'] === $status
        && $r['count'] === null && $r['sha256'] === null && !$m['complete']);
}
check('partial reference target remains unverified, not missing',
    result($m, 'A', 'invoice_lines')['unverifiedReferences'] === 3
    && result($m, 'A', 'invoice_lines')['unresolvedReferences'] === 0);
$excluded = new FixtureDiscoverySource();
$excluded->metadata['resources']['employees'] = 'excluded';
foreach (['A','B','C'] as $workspace) {
    $excluded->overrides[$workspace . '/employees'] = static fn() =>
        throw new RuntimeException('Excluded collection must not be enumerated');
}
$excludedManifest = inventory($excluded);
check('scope exclusion is neither an empty nor a retrieved collection',
    !$excludedManifest['complete'] && result($excludedManifest, 'A', 'employees')['status'] === 'excluded'
    && result($excludedManifest, 'A', 'employees')['count'] === null
    && result($excludedManifest, 'A', 'employees')['sha256'] === null
    && $excluded->requests === $source->requests - 3);
$loop = new FixtureDiscoverySource();
$loop->overrides['A/accounts'] = static fn($snapshot, $cursor) =>
    new DiscoveryPage('ok', $snapshot, [['id' => $cursor === null ? 'one' : 'two']], 'same');
check('repeated cursor is detected', result(inventory($loop), 'A', 'accounts')['status'] === 'truncated');
$drift = new FixtureDiscoverySource();
$drift->overrides['A/accounts'] = static fn() => new DiscoveryPage('ok', 'changed-snapshot', []);
check('snapshot change cannot complete', result(inventory($drift), 'A', 'accounts')['status'] === 'snapshot_changed');
$bad = new FixtureDiscoverySource();
$bad->overrides['A/accounts'] = static fn($snapshot) => new DiscoveryPage('ok', $snapshot,
    [['id' => 'one'], ['id' => 'one']], null, 2);
check('duplicate source ID is invalid', result(inventory($bad), 'A', 'accounts')['status'] === 'invalid_response');
$leak = new FixtureDiscoverySource();
$leak->overrides['A/accounts'] = static function () {
    throw new RuntimeException('private-credential-fixture');
};
$m = inventory($leak);
check('transport exception is sanitized', result($m, 'A', 'accounts')['status'] === 'unavailable'
    && !str_contains(json_encode($m, JSON_THROW_ON_ERROR), 'private-credential-fixture'));
$oversize = new FixtureDiscoverySource();
$oversize->overrides['A/accounts'] = static fn($snapshot) => new DiscoveryPage('ok', $snapshot,
    [['id' => 'one'], ['id' => 'two'], ['id' => 'three']]);
check('adapter cannot exceed requested page size',
    result(inventory($oversize), 'A', 'accounts')['status'] === 'invalid_response');
$payload = new FixtureDiscoverySource();
$payload->overrides['A/accounts'] = static fn($snapshot) => new DiscoveryPage('ok', $snapshot,
    [['id' => 'one', 'secret' => 'never transport raw record bodies']]);
check('record body fields are rejected', result(inventory($payload), 'A', 'accounts')['status'] === 'invalid_response');
$bounded = new FixtureDiscoverySource();
$m = (new DiscoveryInventory(1, 2, 2))->collect($bounded);
check('global request and record caps never imply complete inventory',
    $bounded->requests === 2 && !$m['complete'] && $m['workspaces']['status'] === 'truncated');
$large = new FixtureDiscoverySource();
foreach (['A', 'B', 'C'] as $workspace) {
    foreach ($large->metadata['resources'] as $resource => $capability) {
        $large->collections[$workspace . '/' . $resource] = array_map(
            static fn(int $i): array => ['id' => 'r' . $i], range(1, 101));
    }
}
$m = inventory($large, 100);
check('every declared resource across three workspaces crosses a page boundary',
    $m['complete'] && $large->requests === 1 + 6 * count($large->metadata['resources'])
    && result($m, 'C', 'extension.example')['count'] === 101);
$inconsistent = new FixtureDiscoverySource();
$inconsistent->overrides['A/accounts'] = static fn($snapshot, $cursor) => $cursor === null
    ? new DiscoveryPage('ok', $snapshot, [['id' => 'one']], 'next', 2)
    : new DiscoveryPage('ok', $snapshot, [['id' => 'two']], null, 3);
check('inconsistent source total cannot attest completeness',
    result(inventory($inconsistent), 'A', 'accounts')['status'] === 'invalid_response');
$inconsistent = new FixtureDiscoverySource();
$inconsistent->overrides['A/accounts'] = static fn($snapshot) =>
    new DiscoveryPage('ok', $snapshot, [], 'next');
check('empty page with a continuation is not an empty collection',
    result(inventory($inconsistent), 'A', 'accounts')['status'] === 'truncated');
$bad = new FixtureDiscoverySource();
$bad->metadata['cutoff'] = '2026-02-30T00:00:00Z';
try {
    inventory($bad);
    check('invalid cutoff rejected', false);
} catch (RuntimeException $e) {
    check('invalid cutoff rejected before enumeration',
        $e->getMessage() === 'Invalid source discovery metadata' && $bad->requests === 0);
}
$denied = new FixtureDiscoverySource();
$denied->overrides['workspaces'] = static fn($snapshot) => new DiscoveryPage('access_denied', $snapshot);
$m = inventory($denied);
check('denied workspace listing never enumerates children', $denied->requests === 1
    && !$m['complete'] && $m['workspaces']['status'] === 'access_denied' && $m['inventory'] === []);
$empty = new FixtureDiscoverySource();
$empty->collections['workspaces'] = [];
$m = inventory($empty);
check('empty accessible workspace scope is explicit, never source attestation', $m['complete']
    && $m['workspaces']['status'] === 'empty' && !$m['sourceAttested']);
echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
