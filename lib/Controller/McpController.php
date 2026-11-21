<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\McpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IURLGenerator;
use OCP\IRequest;

final class McpController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private McpService $service,
        private IURLGenerator $urls) {
        parent::__construct($appName, $request);
    }

    /**
     * Authenticated JSON-RPC/MCP entry point. No public/shared-token route,
     * no CSRF opt-out, no tool mutation and no external HTTP dependencies.
     */
    #[NoAdminRequired]
    public function handle(): Response {
        // This is not a browser CORS API. Never accept an unrelated Origin.
        $origin = $this->request->getHeader('Origin');
        if (is_string($origin) && $origin !== '') {
            $expected = parse_url($this->urls->getAbsoluteURL('/'));
            $received = parse_url($origin);
            $normalized = static fn(array $p): string =>
                strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . ':'
                    . ($p['port'] ?? (strtolower($p['scheme'] ?? '') === 'https' ? 443 : 80)));
            if (!is_array($received) || !is_array($expected)
                || $normalized($received) !== $normalized($expected)) {
                return new JSONResponse(['jsonrpc' => '2.0', 'id' => null,
                    'error' => ['code' => -32000, 'message' => 'Origin not permitted']], 403);
            }
        }
        $version = $this->request->getHeader('MCP-Protocol-Version');
        if ($version !== McpService::PROTOCOL) {
            return new JSONResponse(['jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32600, 'message' => 'Unsupported protocol version']], 400);
        }
        // OCP IRequest exposes decoded parameters; getContent() is a
        // protected Nextcloud implementation detail, not a public API.
        $size = $this->request->getHeader('Content-Length');
        if (($size !== '' && ctype_digit($size) && (int)$size > 32768)) {
            return new JSONResponse(['jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32600, 'message' => 'Request too large']], 413);
        }
        $decoded = $this->request->getParams();
        $message = array_intersect_key($decoded,
            array_flip(['jsonrpc', 'id', 'method', 'params']));
        if ($message === [] || strlen(json_encode($message, JSON_THROW_ON_ERROR)) > 32768) {
            return new JSONResponse(['jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32600, 'message' => 'Invalid Request']], 400);
        }
        if (($message['method'] ?? null) === 'notifications/initialized') {
            $response = new Response();
            $response->setStatus(202);
            return $response;
        }
        $result = $this->service->dispatch($message);
        $status = ($result['error']['code'] ?? null) === -32601 ? 404 : 200;
        return new JSONResponse($result, $status);
    }
}
