<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$space = $t->check('create isolated MCP finance space', $a, 'POST',
    '/spaces', ['name' => 'MCP Synthetic', 'kind' => 'personal', 'currency' => 'BRL'], 201);
$id = (int)$space['id'];
$base = '/spaces/' . $id;
$rpc = static fn(string $method, mixed $params = null, int $rpcId = 19): array =>
    array_filter(['jsonrpc' => '2.0', 'id' => $rpcId, 'method' => $method,
        'params' => $params], static fn($value): bool => $value !== null);
$valid = $t->check('MCP initialize over authenticated Nextcloud',
    $a, 'POST', '/mcp', $rpc('initialize', ['protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'synthetic', 'version' => '1.0']]), 200,
    static fn(array $v): bool => ($v['result']['protocolVersion'] ?? null) === '2025-06-18'
        && $v['result']['capabilities']['tools'] !== null);
$t->check('MCP ping', $a, 'POST', '/mcp', $rpc('ping'), 200,
    static fn(array $v): bool => isset($v['result']));
$tools = $t->check('MCP tools/list read-only capabilities',
    $a, 'POST', '/mcp', $rpc('tools/list'), 200,
    static fn(array $v): bool => count($v['result']['tools'] ?? []) === 5
        && count(array_filter($v['result']['tools'] ?? [],
            static fn(array $tool): bool =>
                ($tool['annotations']['readOnlyHint'] ?? false) === true)) === 5);
$contact = $t->check('create searchable synthetic contact', $a, 'POST',
    $base . '/contacts', ['name' => 'Searchable Synthetic Customer',
        'kind' => 'customer'], 201);
$t->check('HTTP search finds an authorized contact', $a, 'GET',
    $base . '/search?query=Searchable&type=contacts', null, 200,
    static fn(array $v): bool => count($v['results']['contacts'] ?? []) === 1
        && ($v['results']['contacts'][0]['name'] ?? '') === 'Searchable Synthetic Customer');
$t->check('MCP search returns authorized matching contact', $a, 'POST',
    '/mcp', $rpc('tools/call', ['name' => 'librefinance_search',
        'arguments' => ['spaceId' => $id, 'query' => 'Searchable', 'type' => 'contacts']]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? true) === false
        && ($v['result']['structuredContent']['results']['contacts'][0]['name'] ?? '') === 'Searchable Synthetic Customer');
$t->check('search returns empty scoped results', $a, 'GET',
    $base . '/search?query=MissingSynthetic&type=all', null, 200,
    static fn(array $v): bool => ($v['results']['contacts'] ?? null) === []
        && ($v['results']['categories'] ?? null) === []
        && ($v['results']['items'] ?? null) === []);
$t->check('search does not reveal records to another space', $b, 'GET',
    $base . '/search?query=MissingSynthetic', null, 404);
$t->check('search rejects invalid type', $a, 'GET',
    $base . '/search?query=SomeName&type=secrets', null, 422);
$t->check('MCP search is restricted to authorized space', $b, 'POST',
    '/mcp', $rpc('tools/call', ['name' => 'librefinance_search',
        'arguments' => ['spaceId' => $id, 'query' => 'Synthetic']]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? false) === true
        && ($v['result']['content'][0]['text'] ?? null) === 'access_denied');
$t->check('MCP rejects financial write tool unknown',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_create_journal',
        'arguments' => ['spaceId' => $id, 'amountMinor' => 100000]]), 200,
    static fn(array $v): bool => ($v['error']['code'] ?? null) === -32602);
$t->check('MCP list_spaces honors Nextcloud membership',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_list_spaces',
        'arguments' => []]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? true) === false
        && count(array_filter($v['result']['structuredContent']['spaces'] ?? [],
            static fn(array $sp): bool => (int)$sp['id'] === $id)) === 1);
$t->check('MCP accounts are available for authorized user',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_list_accounts',
        'arguments' => ['spaceId' => $id]]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? true) === false
        && count($v['result']['structuredContent']['accounts'] ?? []) === 4);
$t->check('MCP rejects cross-space access without revealing accounting data',
    $b, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_list_accounts',
        'arguments' => ['spaceId' => $id]]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? false) === true
        && ($v['result']['content'][0]['text'] ?? null) === 'access_denied'
        && !isset($v['result']['structuredContent']));
$t->check('MCP rejects arbitrary tool parameters',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_list_accounts',
        'arguments' => ['spaceId' => $id, 'includeAllCompanies' => true]]), 200,
    static fn(array $v): bool => ($v['error']['code'] ?? null) === -32602);
$t->check('MCP cannot enumerate negative space IDs',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_list_accounts',
        'arguments' => ['spaceId' => -1]]), 200,
    static fn(array $v): bool => ($v['error']['code'] ?? null) === -32602);
$t->check('MCP rejects unknown JSON RPC method',
    $a, 'POST', '/mcp', $rpc('remote/execute'), 404,
    static fn(array $v): bool => ($v['error']['code'] ?? null) === -32601);
$t->check('MCP returns paginated journals without side effects',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_list_journals',
        'arguments' => ['spaceId' => $id]]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? true) === false
        && ($v['result']['structuredContent']['journals'] ?? null) === []);
$t->check('MCP reports require optional reports extension',
    $a, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_profit_loss',
        'arguments' => ['spaceId' => $id, 'from' => '2026-01-01',
            'through' => '2026-12-31']]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? true) === false
        && ($v['result']['structuredContent']['revenueMinor'] ?? null) === 0);
$t->check('MCP profit and loss denies another company user',
    $b, 'POST', '/mcp', $rpc('tools/call', ['name' => 'librefinance_profit_loss',
        'arguments' => ['spaceId' => $id, 'from' => '2026-01-01',
            'through' => '2026-12-31']]), 200,
    static fn(array $v): bool => ($v['result']['isError'] ?? false) === true);

$ai = $t->check('AI capability discovery enforces manager access',
    $a, 'GET', $base . '/ai/capabilities', null, 200,
    static fn(array $v): bool => ($v['readOnly'] ?? false) === true
        && ($v['explicitConsentRequired'] ?? false) === true
        && ($v['automaticFinancialWrites'] ?? true) === false);
$chart = $t->check('read synthetic chart for AI aggregate integrity',
    $a, 'GET', $base . '/accounts', null, 200);
$byCode = [];
foreach ($chart['accounts'] as $account) {
    $byCode[$account['code']] = (int)$account['id'];
}
$t->check('post synthetic revenue to verify AI payload', $a, 'POST',
    $base . '/journals',
    ['debitId' => $byCode['1000'], 'creditId' => $byCode['4000'],
        'amountMinor' => 15000, 'date' => '2026-01-15',
        'memo' => 'Synthetic AI data integrity revenue',
        'externalKey' => 'mcpai:revenue:' . bin2hex(random_bytes(7))], 201);
$t->check('post synthetic expense to verify AI payload', $a, 'POST',
    $base . '/journals',
    ['debitId' => $byCode['5000'], 'creditId' => $byCode['1000'],
        'amountMinor' => 2800, 'date' => '2026-01-16',
        'memo' => 'Synthetic AI data integrity expense',
        'externalKey' => 'mcpai:expense:' . bin2hex(random_bytes(7))], 201);
$approvedPreview = $t->check('AI preview uses correct financial result, without sending task or contacts',
    $a, 'GET',
    $base . '/ai/preview?from=2026-01-01&through=2026-01-31', null, 200,
    static fn(array $v): bool =>
        ($v['revenueMinor'] ?? null) === 15000
        && ($v['expenseMinor'] ?? null) === 2800
        && ($v['resultMinor'] ?? null) === 12200
        && ($v['currency'] ?? null) === 'BRL'
        && is_string($v['previewHash'] ?? null)
        && preg_match('/^[0-9a-f]{64}$/', $v['previewHash']) === 1
        && !array_key_exists('contacts', $v)
        && !array_key_exists('bankAccounts', $v));
$t->check('AI preview is not visible to unrelated users', $b, 'GET',
    $base . '/ai/preview?from=2026-01-01&through=2026-01-31', null, 404);
$t->check('AI preview rejects excessive date range', $a, 'GET',
    $base . '/ai/preview?from=2024-01-01&through=2026-12-31', null, 422);
$hash = $approvedPreview['previewHash'];
$t->check('AI rejects analysis without preview fingerprint', $a, 'POST',
    $base . '/ai/analyze',
    ['from' => '2026-01-01', 'through' => '2026-01-31',
        'consent' => true], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'ai_preview_changed');
$t->check('AI rejects arbitrary preview fingerprint', $a, 'POST',
    $base . '/ai/analyze',
    ['from' => '2026-01-01', 'through' => '2026-01-31',
        'consent' => true, 'previewHash' => str_repeat('0', 64)], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'ai_preview_changed');
$t->check('post an additional amount after AI preview to invalidate consent', $a,
    'POST', $base . '/journals',
    ['debitId' => $byCode['5000'], 'creditId' => $byCode['1000'],
        'amountMinor' => 100, 'date' => '2026-01-17',
        'memo' => 'Synthetic concurrent update after preview',
        'externalKey' => 'mcpai:changed:' . bin2hex(random_bytes(7))], 201);
$t->check('AI rejects stale preview after financial changes', $a, 'POST',
    $base . '/ai/analyze',
    ['from' => '2026-01-01', 'through' => '2026-01-31',
        'consent' => true, 'previewHash' => $hash], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'ai_preview_changed');
$updatedPreview = $t->check('AI returns new fingerprint and updated result after posting',
    $a, 'GET',
    $base . '/ai/preview?from=2026-01-01&through=2026-01-31', null, 200,
    static fn(array $v): bool => ($v['resultMinor'] ?? null) === 12100
        && is_string($v['previewHash'] ?? null));
if ($updatedPreview['previewHash'] === $hash) {
    throw new \RuntimeException('AI did not invalidate preview token on accounting change');
}
$t->check('AI capacity hidden from unrelated user', $b, 'GET',
    $base . '/ai/capabilities', null, 404);
$t->check('AI refuses analysis without explicit consent', $a, 'POST',
    $base . '/ai/analyze',
    ['from' => '2026-01-01', 'through' => '2026-01-31', 'consent' => false], 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'ai_consent_required');
$t->check('AI refuses invalid accounting date', $a, 'POST',
    $base . '/ai/analyze',
    ['from' => '2026-02-30', 'through' => '2026-03-31', 'consent' => true], 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_date');
$t->check('AI cannot disclose an unrelated task', $a, 'GET',
    $base . '/ai/tasks/2147483647', null, 404);
if (($ai['available'] ?? true) === false) {
    $t->check('AI without configured provider refuses forwarding totals', $a, 'POST',
        $base . '/ai/analyze',
        ['from' => '2026-01-01', 'through' => '2026-01-31', 'consent' => true,
            'previewHash' => $updatedPreview['previewHash']], 503,
        static fn(array $v): bool => ($v['error'] ?? null) === 'ai_provider_unavailable');
} else {
    $t->record('AI provider configured: skip external AI invocation in synthetic tests',
        true, 'provider present, no external data sent');
}
$t->check('grant financial viewer to B', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->check('finance viewer cannot discover AI capabilities', $b, 'GET',
    $base . '/ai/capabilities', null, 403);
$t->check('finance viewer cannot dispatch AI task', $b, 'POST',
    $base . '/ai/analyze',
    ['from' => '2026-01-01', 'through' => '2026-01-31', 'consent' => true], 403);
$t->finish();
