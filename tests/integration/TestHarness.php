<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreFinance\Tests\Integration;

/**
 * Pure PHP integration client. No Nextcloud internals or separate language runtime.
 * Tests accept only loopback endpoints and synthetic identities.
 */
final class TestHarness {
    private int $checks = 0;
    private string $baseUrl;
    private string $password;

    public function __construct() {
        $this->baseUrl = rtrim((string)getenv('LF_BASE_URL'), '/');
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || !in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true)
            || !str_ends_with((string)($parts['path'] ?? ''), '/apps/librefinance/api')) {
            throw new \RuntimeException('Integration test URL must use a loopback Nextcloud API endpoint');
        }
        $this->password = (string)getenv('LF_TEST_USER_PASS');
        if ($this->password === '') {
            throw new \RuntimeException('LF_TEST_USER_PASS must be set');
        }
    }

    /**
     * @return array{code:int,body:array<string,mixed>}
     */
    public function request(string $user, string $method, string $path, ?array $data = null): array {
        if (!in_array($user, ['lfmaintestA', 'lfmaintestB'], true)) {
            throw new \InvalidArgumentException('Only synthetic test identities are allowed');
        }
        $handle = $this->prepareCurl($user, $method, $path, $data);
        return $this->decode($handle);
    }

    /** @return array{code:int,body:string,contentType:string,cacheControl:list<string>} */
    public function requestImage(string $user, string $path): array {
        if (!in_array($user, ['lfmaintestA', 'lfmaintestB'], true)) {
            throw new \InvalidArgumentException('Only synthetic test identities are allowed');
        }
        $handle = $this->prepareCurl($user, 'GET', $path, accept: 'image/png, application/json');
        $cacheControl = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function (\CurlHandle $handle, string $line)
            use (&$cacheControl): int {
            if (str_starts_with($line, 'HTTP/')) {
                $cacheControl = [];
            } elseif (stripos($line, 'Cache-Control:') === 0) {
                $cacheControl[] = trim(substr($line, strlen('Cache-Control:')));
            }
            return strlen($line);
        });
        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new \RuntimeException('Test image request failed: ' . curl_error($handle));
        }
        return [
            'code' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => $body,
            'contentType' => (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
            'cacheControl' => $cacheControl,
        ];
    }

    /** @return array{code:int,body:array<string,mixed>} */
    public function requestModule(string $user, string $appId, string $method, string $path, ?array $data = null): array {
        if (!in_array($user, ['lfmaintestA', 'lfmaintestB'], true)) {
            throw new \InvalidArgumentException('Only synthetic test identities');
        }
        $handle = $this->prepareCurl($user, $method, $path, $data, $appId);
        return $this->decode($handle);
    }

    /** @return array<string,mixed> */
    public function checkModule(string $name, string $user, string $appId, string $method, string $route,
        ?array $payload, int $status, ?callable $predicate = null): array {
        $r = $this->requestModule($user, $appId, $method, $route, $payload);
        $valid = $r['code'] === $status && ($predicate === null || $predicate($r['body']));
        $this->record($name, $valid, 'HTTP ' . $r['code'], $r['body']);
        return $r['body'];
    }

    /** @return \CurlHandle */
    private function prepareCurl(string $user, string $method, string $path, ?array $data = null,
        ?string $appId = null, string $accept = 'application/json'): \CurlHandle {
        if (!str_starts_with($path, '/') || str_contains($path, '//') || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid test route');
        }
        $url = $this->baseUrl . $path;
        if ($appId !== null) {
            if ($appId !== 'librefinance' && !preg_match('/^librefinance_[a-z][a-z0-9_]+$/', $appId)) {
                throw new \InvalidArgumentException('Invalid synthetic test module');
            }
            $internal = $appId === 'librefinance' || in_array($appId, [
                'librefinance_reports', 'librefinance_banking',
                'librefinance_invoicing', 'librefinance_obligations',
            ], true);
            if (!$internal) {
                $url = str_replace('/apps/librefinance/api', '/apps/' . $appId . '/api', $url);
            }
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headers = ['OCS-APIREQUEST: true', 'Accept: ' . $accept];
        if ($path === '/mcp') {
            $headers[] = 'MCP-Protocol-Version: 2025-06-18';
            $headers[1] = 'Accept: application/json, text/event-stream';
        }
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $user . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        return $handle;
    }

    /** @return array{code:int,body:array<string,mixed>} */
    private function decode(\CurlHandle $handle, ?string $response = null): array {
        if ($response === null) {
            $response = curl_exec($handle);
        }
        if ($response === false) {
            throw new \RuntimeException('Test network error: ' . curl_error($handle));
        }
        $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $decoded = ['unexpected_non_json' => mb_substr((string)$response, 0, 150)];
        }
        return ['code' => $code, 'body' => $decoded];
    }

    /**
     * Execute two actual parallel HTTP calls; returns two results in request order.
     *
     * @param array{user:string,method:string,path:string,data:array<string,mixed>} $a
     * @param array{user:string,method:string,path:string,data:array<string,mixed>} $b
     * @return array<int,array{code:int,body:array<string,mixed>}>
     */
    public function parallel(array $a, array $b): array {
        $handles = [
            $this->prepareCurl($a['user'], $a['method'], $a['path'], $a['data'], $a['module'] ?? null),
            $this->prepareCurl($b['user'], $b['method'], $b['path'], $b['data'], $b['module'] ?? null),
        ];
        $multi = curl_multi_init();
        try {
            foreach ($handles as $handle) {
                curl_multi_add_handle($multi, $handle);
            }
            do {
                $result = curl_multi_exec($multi, $running);
                if ($result !== CURLM_OK) {
                    throw new \RuntimeException('curl_multi_exec failed');
                }
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);
            return array_map(fn(\CurlHandle $handle): array => $this->decode($handle, curl_multi_getcontent($handle)), $handles);
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
        }
    }

    /**
     * Assert HTTP code and optional response contract; stop immediately on failure.
     *
     * @param callable(array<string,mixed>):bool|null $predicate
     * @return array<string,mixed>
     */
    public function check(
        string $name, string $user, string $method, string $route, ?array $payload,
        int $status, ?callable $predicate = null
    ): array {
        $r = $this->request($user, $method, $route, $payload);
        $valid = $r['code'] === $status && ($predicate === null || $predicate($r['body']));
        $this->record($name, $valid, 'HTTP ' . $r['code'], $r['body']);
        return $r['body'];
    }

    /** @param array<string,mixed> $details */
    public function record(string $name, bool $pass, string $summary, array $details = []): void {
        echo ($pass ? 'PASS ' : 'FAIL ') . $name . ' -> ' . $summary;
        if (!$pass) {
            echo ' ' . substr(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 220);
        }
        echo PHP_EOL;
        if (!$pass) {
            throw new \RuntimeException('Test failed: ' . $name);
        }
        ++$this->checks;
    }

    public function finish(): void {
        echo 'ALL CHECKS PASSED: ' . $this->checks . PHP_EOL;
    }
}
