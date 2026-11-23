<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use DomainException;
use OCA\LibreFinance\Service\SaasPolicyService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Phase-one instance policy configuration. Never opens financial workspaces.
 * Per-environment and per-user APIs will require authenticated tenant scopes.
 */
final class SaasPolicyController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private SaasPolicyService $policies) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/environments/{environmentId}/policies/effective')]
    public function effective(string $environmentId): JSONResponse {
        return $this->respond(fn(): array => $this->policies->listEnvironment($environmentId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/environments/{environmentId}/policies/{policyKey}')]
    public function readEnvironment(string $environmentId, string $policyKey): JSONResponse {
        return $this->respond(fn(): array => $this->policies->getEnvironment($environmentId, $policyKey));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/saas/v1/environments/{environmentId}/policies/{policyKey}')]
    public function writeEnvironment(string $environmentId, string $policyKey,
        string $valueJson, int $expectedRevision): JSONResponse {
        return $this->respond(function () use ($environmentId, $policyKey, $valueJson, $expectedRevision): array {
            if (strlen($valueJson) > 16384) {
                throw new DomainException('invalid_policy_value');
            }
            try {
                $value = json_decode($valueJson, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DomainException('invalid_policy_value');
            }
            return $this->policies->writeEnvironment($environmentId, $policyKey,
                $value, $expectedRevision);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/saas/v1/environments/{environmentId}/policies/{policyKey}')]
    public function clearEnvironment(string $environmentId, string $policyKey,
        int $expectedRevision): JSONResponse {
        return $this->respond(fn(): array => $this->policies->clearEnvironment(
            $environmentId, $policyKey, $expectedRevision));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/admin/policies')]
    public function index(): JSONResponse {
        return $this->respond(fn(): array => $this->policies->listInstance());
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/admin/policies/{policyKey}')]
    public function read(string $policyKey): JSONResponse {
        return $this->respond(fn(): array => $this->policies->getInstance($policyKey));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/saas/v1/admin/policies/{policyKey}')]
    public function write(string $policyKey, string $valueJson, int $expectedRevision): JSONResponse {
        return $this->respond(function () use ($policyKey, $valueJson, $expectedRevision): array {
            if (strlen($valueJson) > 16384) {
                throw new DomainException('invalid_policy_value');
            }
            try {
                $value = json_decode($valueJson, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DomainException('invalid_policy_value');
            }
            return $this->policies->writeInstance($policyKey, $value, $expectedRevision);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/saas/v1/admin/policies/{policyKey}')]
    public function clear(string $policyKey, int $expectedRevision): JSONResponse {
        return $this->respond(fn(): array =>
            $this->policies->clearInstance($policyKey, $expectedRevision));
    }
}
