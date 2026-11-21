<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\IUserSession;

/**
 * Stateless, read-only MCP JSON-RPC tools. Authentication is the normal
 * Nextcloud session/app-password authentication; there are NO bearer tokens
 * of our own, no delegated escalation and no writable tools.
 *
 * The endpoint deliberately does not implement remote tool writes. A future
 * write transport must require human approval, not a prompt from an agent.
 */
final class McpService {
    public const PROTOCOL = '2025-06-18';

    public function __construct(
        private SpaceService $spaces,
        private LedgerService $ledger,
        private FinanceApi $finance,
        private SearchService $search,
        private IUserSession $session,
    ) {}

    /** @return array<string,mixed> */
    public function dispatch(array $message): array {
        if ($this->session->getUser() === null) {
            return self::error($message['id'] ?? null, -32001, 'authentication_required');
        }
        if (($message['jsonrpc'] ?? null) !== '2.0'
            || !isset($message['method']) || !is_string($message['method'])
            || (isset($message['id']) && !is_int($message['id']) && !is_string($message['id']))) {
            return self::error(null, -32600, 'Invalid Request');
        }
        $id = $message['id'] ?? null;
        $method = $message['method'];
        if ($method === 'notifications/initialized') {
            return ['jsonrpc' => '2.0', 'result' => null]; // Controller returns HTTP 202.
        }
        if ($id === null) {
            return self::error(null, -32600, 'Invalid Request');
        }
        return match ($method) {
            'initialize' => self::success($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'librefinance-nextcloud', 'version' => '0.1.0'],
            ]),
            'ping' => self::success($id, new \stdClass()),
            'tools/list' => self::success($id, [
                'tools' => self::tools(),
            ]),
            'tools/call' => $this->callTool($id, $message['params'] ?? null),
            default => self::error($id, -32601, 'Method not found'),
        };
    }

    /** @return list<array<string,mixed>> */
    private static function tools(): array {
        return [
            [
                'name' => 'librefinance_list_spaces',
                'title' => 'List authorized finance spaces',
                'description' => 'List only spaces visible to the authenticated Nextcloud user. Read only.',
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false,
                    'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(),
                    'additionalProperties' => false],
            ],
            [
                'name' => 'librefinance_list_accounts',
                'title' => 'List finance accounts',
                'description' => 'Bank/ledger account list and derived balances for a permitted space. Read only.',
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false,
                    'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => self::spaceSchema(),
            ],
            [
                'name' => 'librefinance_list_journals',
                'title' => 'List posted journals',
                'description' => 'Paginated accounting entries for a permitted space. Read only.',
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false,
                    'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spaceId' => ['type' => 'integer', 'minimum' => 1],
                        'beforeId' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'required' => ['spaceId'], 'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'librefinance_search',
                'title' => 'Search finance records',
                'description' => 'Read-only literal search for active contacts, categories and items within an authorized space.',
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false,
                    'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spaceId' => ['type' => 'integer', 'minimum' => 1],
                        'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                        'type' => ['type' => 'string', 'enum' => ['all', 'contacts', 'categories', 'items']],
                    ],
                    'required' => ['spaceId', 'query'], 'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'librefinance_profit_loss',
                'title' => 'Revenue and expenses',
                'description' => 'Derived period totals through the optional Reports app; read only.',
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false,
                    'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'spaceId' => ['type' => 'integer', 'minimum' => 1],
                        'from' => ['type' => 'string', 'format' => 'date'],
                        'through' => ['type' => 'string', 'format' => 'date'],
                    ],
                    'required' => ['spaceId', 'from', 'through'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private static function spaceSchema(): array {
        return ['type' => 'object',
            'properties' => ['spaceId' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['spaceId'], 'additionalProperties' => false];
    }

    /** @return array<string,mixed> */
    private function callTool(int|string $id, mixed $params): array {
        if (!is_array($params) || array_is_list($params)
            || !is_string($params['name'] ?? null)
            || !is_array($params['arguments'] ?? null)) {
            return self::error($id, -32602, 'Invalid params');
        }
        $name = $params['name'];
        $args = $params['arguments'];
        $allowed = match ($name) {
            'librefinance_list_spaces' => [],
            'librefinance_list_accounts' => ['spaceId'],
            'librefinance_list_journals' => ['spaceId', 'beforeId'],
            'librefinance_search' => ['spaceId', 'query', 'type'],
            'librefinance_profit_loss' => ['spaceId', 'from', 'through'],
            default => null,
        };
        if ($allowed === null) {
            return self::error($id, -32602, 'Unknown or prohibited tool');
        }
        if (array_diff(array_keys($args), $allowed) !== []) {
            return self::error($id, -32602, 'Invalid params');
        }
        if ($name !== 'librefinance_list_spaces'
            && (!is_int($args['spaceId'] ?? null) || $args['spaceId'] < 1)) {
            return self::error($id, -32602, 'Invalid spaceId');
        }
        if ($name === 'librefinance_list_journals'
            && (!is_int($args['beforeId'] ?? 0) || ($args['beforeId'] ?? 0) < 0)) {
            return self::error($id, -32602, 'Invalid beforeId');
        }
        if ($name === 'librefinance_search'
            && (!is_string($args['query'] ?? null)
                || (isset($args['type']) && !is_string($args['type'])))) {
            return self::error($id, -32602, 'Invalid search');
        }
        if ($name === 'librefinance_profit_loss'
            && (!is_string($args['from'] ?? null)
                || !is_string($args['through'] ?? null))) {
            return self::error($id, -32602, 'Invalid period');
        }
        try {
            $data = match ($name) {
                'librefinance_list_spaces' => ['spaces' => $this->spaces->list()],
                'librefinance_search' => $this->search->search($args['spaceId'], $args['query'], $args['type'] ?? 'all'),
                'librefinance_list_accounts' => ['accounts' => $this->ledger->accounts($args['spaceId'])],
                'librefinance_list_journals' => $this->ledger->journals(
                    $args['spaceId'], $args['beforeId'] ?? 0),
                'librefinance_profit_loss' => $this->finance->profitLoss(
                    $args['spaceId'], 'librefinance_reports', $args['from'], $args['through']),
            };
            return self::success($id, [
                'content' => [['type' => 'text', 'text' =>
                    json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (DomainException $e) {
            $reason = $e->getMessage();
            if (in_array($reason, ['space_not_found', 'permission_denied',
                'module_disabled', 'module_not_found'], true)) {
                $reason = 'access_denied';
            }
            return self::success($id, [
                'content' => [['type' => 'text', 'text' => $reason]],
                'isError' => true,
            ]);
        }
    }

    private static function success(int|string $id, mixed $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(int|string|null $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id,
            'error' => ['code' => $code, 'message' => $message]];
    }
}
