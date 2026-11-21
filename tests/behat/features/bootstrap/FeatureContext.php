<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace LibreFinance\Behat;

use Behat\Behat\Context\Context;
use RuntimeException;

/**
 * Behat runs inside the existing disposable Nextcloud PHP container.
 * No mocks, no production URLs and no credentials in fixture files.
 */
final class FeatureContext implements Context {
    private string $url;
    private string $password;
    private string $actor = 'lfmaintestA';
    private ?int $spaceId = null;
    private array $last = [];
    private ?array $lastPosting = null;

    public function __construct() {
        $this->url = rtrim((string)getenv('LF_BASE_URL'), '/');
        $url = parse_url($this->url);
        if (!is_array($url)
            || !in_array($url['host'] ?? null, ['localhost', '127.0.0.1'], true)
            || !str_ends_with((string)($url['path'] ?? ''), '/apps/librefinance/api')) {
            throw new RuntimeException('Behat is restricted to the loopback Nextcloud laboratory');
        }
        $this->password = (string)getenv('LF_TEST_USER_PASS');
        if ($this->password === '') {
            throw new RuntimeException('Missing synthetic Behat user password');
        }
    }

    /**
     * @BeforeScenario
     */
    public function beforeScenario(): void {
        $this->actor = 'lfmaintestA';
        $this->spaceId = null;
        $this->last = [];
        $this->lastPosting = null;
    }

    /**
     * @Given I am finance test user :user
     * @When I switch to finance test user :user
     */
    public function setUser(string $user): void {
        if (!in_array($user, ['lfmaintestA', 'lfmaintestB'], true)) {
            throw new RuntimeException('Only synthetic fixture users are allowed');
        }
        $this->actor = $user;
    }

    /**
     * @Given I create an isolated finance space
     */
    public function createSpace(): void {
        $name = 'Behat synthetic ' . bin2hex(random_bytes(6));
        $result = $this->send('POST', '/spaces',
            ['name' => $name, 'kind' => 'organization', 'currency' => 'BRL']);
        if ($result['code'] !== 201 || !is_int($result['body']['id'] ?? null)) {
            throw new RuntimeException('Cannot prepare isolated finance fixture (HTTP '
                . $result['code'] . ')');
        }
        $this->spaceId = $result['body']['id'];
    }

    /**
     * @Given I grant :user the :role role for this finance space
     */
    public function grant(string $user, string $role): void {
        $result = $this->send('POST', $this->base() . '/members',
            ['uid' => $user, 'role' => $role]);
        if ($result['code'] !== 200) {
            throw new RuntimeException('Cannot establish scenario finance membership');
        }
    }

    /**
     * @When I list accounts for the current finance space
     */
    public function accounts(): void {
        $this->send('GET', $this->base() . '/accounts');
    }

    /**
     * @When I list journals for the current finance space
     */
    public function journals(): void {
        $this->send('GET', $this->base() . '/journals');
    }

    /**
     * @When I post a synthetic expense of :amount minor units
     */
    public function expense(int $amount): void {
        $accounts = $this->send('GET', $this->base() . '/accounts');
        if ($accounts['code'] !== 200) {
            // A viewer has no write permission; the account GET succeeds.
            throw new RuntimeException('Unable to load synthetic ledger for posting test');
        }
        $mapped = [];
        foreach ($accounts['body']['accounts'] as $account) {
            $mapped[$account['code']] = (int)$account['id'];
        }
        $data = [
            'debitId' => $mapped['5000'], 'creditId' => $mapped['1000'],
            'amountMinor' => $amount, 'date' => '2026-11-20',
            'memo' => 'Behat synthetic regression', 'externalKey' =>
                'behat:' . bin2hex(random_bytes(9)),
        ];
        $this->lastPosting = $data;
        $this->send('POST', $this->base() . '/journals', $data);
    }

    /**
     * @When I repeat the previous synthetic posting
     */
    public function replay(): void {
        if ($this->lastPosting === null) {
            throw new RuntimeException('No posting to retry in this scenario');
        }
        $this->send('POST', $this->base() . '/journals', $this->lastPosting);
    }

    /**
     * @When I request the MCP financial tool list
     */
    public function mcpTools(): void {
        $this->send('POST', '/mcp', $this->rpc('tools/list'), true);
    }

    /**
     * @When I request MCP accounts for the current finance space
     */
    public function mcpAccounts(): void {
        $this->send('POST', '/mcp',
            $this->rpc('tools/call', ['name' => 'librefinance_list_accounts',
                'arguments' => ['spaceId' => $this->requiredSpace()]]), true);
    }

    /**
     * @When I call an unregistered MCP write tool
     */
    public function mcpWrite(): void {
        $this->send('POST', '/mcp',
            $this->rpc('tools/call', ['name' => 'librefinance_create_payment',
                'arguments' => ['spaceId' => $this->requiredSpace()]]), true);
    }

    /**
     * @When I inspect finance AI capabilities
     */
    public function aiCapabilities(): void {
        $this->send('GET', $this->base() . '/ai/capabilities');
    }

    /**
     * @When I request finance AI analysis without consent
     */
    public function aiWithoutConsent(): void {
        $this->send('POST', $this->base() . '/ai/analyze',
            ['from' => '2026-01-01', 'through' => '2026-01-31', 'consent' => false]);
    }

    /**
     * @Then the HTTP status must be :expected
     */
    public function status(int $expected): void {
        if (($this->last['code'] ?? 0) !== $expected) {
            throw new RuntimeException("Expected HTTP {$expected}, got "
                . ($this->last['code'] ?? 0) . ': '
                . json_encode($this->last['body'] ?? null));
        }
    }

    /**
     * @Then the account list must contain :count accounts
     */
    public function accountCount(int $count): void {
        $this->count('accounts', $count);
    }

    /**
     * @Then the journal list must contain :count entries
     */
    public function journalCount(int $count): void {
        $this->count('journals', $count);
    }

    /**
     * @Then every MCP tool must be declared read-only
     */
    public function readOnlyTools(): void {
        $tools = $this->last['body']['result']['tools'] ?? [];
        if (count($tools) < 3) {
            throw new RuntimeException('Expected readable MCP financial tools');
        }
        foreach ($tools as $tool) {
            if (($tool['annotations']['readOnlyHint'] ?? false) !== true) {
                throw new RuntimeException('Found an MCP tool without readOnlyHint');
            }
        }
    }

    /**
     * @Then the MCP tool must deny access
     */
    public function mcpDenied(): void {
        $result = $this->last['body']['result'] ?? null;
        if ($this->last['code'] !== 200
            || ($result['isError'] ?? false) !== true
            || ($result['content'][0]['text'] ?? null) !== 'access_denied') {
            throw new RuntimeException('MCP cross-space access was not rejected');
        }
    }

    /**
     * @Then the MCP request must be rejected as invalid parameters
     */
    public function mcpInvalidParams(): void {
        if (($this->last['body']['error']['code'] ?? null) !== -32602) {
            throw new RuntimeException('MCP accepted an unregistered financial write tool');
        }
    }

    /**
     * @Then the AI integration must require consent and block automated writes
     */
    public function aiSafe(): void {
        $body = $this->last['body'];
        if (($body['readOnly'] ?? false) !== true
            || ($body['explicitConsentRequired'] ?? false) !== true
            || ($body['automaticFinancialWrites'] ?? true) !== false) {
            throw new RuntimeException('AI safety contract mismatch');
        }
    }

    /**
     * @Then the API error must equal :error
     */
    public function apiError(string $error): void {
        if (($this->last['body']['error'] ?? null) !== $error) {
            throw new RuntimeException('Unexpected API error');
        }
    }

    /** @return array<string,mixed> */
    private function rpc(string $method, ?array $params = null): array {
        $request = ['jsonrpc' => '2.0', 'method' => $method, 'id' => 17];
        if ($params !== null) { $request['params'] = $params; }
        return $request;
    }

    private function count(string $key, int $expected): void {
        $items = $this->last['body'][$key] ?? [];
        if (!is_array($items) || count($items) !== $expected) {
            throw new RuntimeException('Expected ' . $expected . ' ' . $key);
        }
    }

    private function requiredSpace(): int {
        if ($this->spaceId === null) {
            throw new RuntimeException('No space fixture established');
        }
        return $this->spaceId;
    }

    private function base(): string {
        return '/spaces/' . $this->requiredSpace();
    }

    /** @return array{code:int,body:array<string,mixed>} */
    private function send(string $method, string $path, ?array $data = null,
        bool $mcp = false): array {
        $h = curl_init($this->url . $path);
        $headers = ['OCS-APIREQUEST: true', 'Accept: application/json'];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        if ($mcp) {
            $headers[] = 'MCP-Protocol-Version: 2025-06-18';
            $headers[1] = 'Accept: application/json, text/event-stream';
        }
        curl_setopt_array($h, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->actor . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $content = curl_exec($h);
        if ($content === false) {
            throw new RuntimeException('Test HTTP connection failed: ' . curl_error($h));
        }
        $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $body = json_decode($content, true);
        $this->last = ['code' => $code,
            'body' => is_array($body) ? $body : ['unparsed' => mb_substr($content, 0, 80)]];
        return $this->last;
    }
}
