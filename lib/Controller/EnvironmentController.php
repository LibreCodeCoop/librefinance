<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\EnvironmentService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Environment discovery and administration, not financial-workspace access.
 * All authorization is server-owned in EnvironmentService.
 */
final class EnvironmentController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private EnvironmentService $environments) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/environments')]
    public function index(): JSONResponse {
        return $this->respond(fn(): array => [
            'contractVersion' => 1,
            'environments' => $this->environments->discover(),
            'nextCursor' => null,
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/admin/groups')]
    public function adminGroups(string $search = '', int $offset = 0): JSONResponse {
        return $this->respond(fn(): array =>
            $this->environments->listAdminGroups($search, $offset));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/saas/v1/admin/groups/{gid}/enablement')]
    public function enable(string $gid, bool $enabled): JSONResponse {
        return $this->respond(fn(): array => [
            'contractVersion' => 1,
            'environment' => $enabled
                ? $this->environments->enableGroup($gid)
                : $this->environments->suspendGroup($gid),
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/saas/v1/admin/groups/{gid}/recover')]
    public function recoverGroup(string $gid, string $reasonCode): JSONResponse {
        return $this->respond(fn(): array => [
            'contractVersion' => 1,
            'environment' => $this->environments->recoverGroup($gid, $reasonCode),
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/saas/v1/environments/{environmentId}/provision')]
    public function provision(string $environmentId): JSONResponse {
        return $this->respond(fn(): array => [
            'contractVersion' => 1,
            'environment' => $this->environments->activate($environmentId),
        ]);
    }
}
