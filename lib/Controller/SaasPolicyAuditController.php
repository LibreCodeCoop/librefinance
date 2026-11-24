<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\SaasPolicyAuditService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SaasPolicyAuditController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private SaasPolicyAuditService $audit) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/policy-audit/instance')]
    public function instance(int $beforeId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->audit->instance($beforeId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/policy-audit/environments/{environmentId}')]
    public function environment(string $environmentId, int $beforeId = 0): JSONResponse {
        return $this->respond(fn(): array =>
            $this->audit->environment($environmentId, $beforeId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/policy-audit/workspaces/{spaceId}')]
    public function workspace(int $spaceId, int $beforeId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->audit->workspace($spaceId, $beforeId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/policy-audit/workspaces/{spaceId}/me')]
    public function user(int $spaceId, int $beforeId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->audit->user($spaceId, $beforeId));
    }
}
