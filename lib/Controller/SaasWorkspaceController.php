<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\SaasWorkspaceService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Environment administration never implies access to financial entries. */
final class SaasWorkspaceController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private SaasWorkspaceService $workspaces) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/environments/{environmentId}/workspaces')]
    public function index(string $environmentId, int $afterId = 0): JSONResponse {
        return $this->respond(fn(): array =>
            $this->workspaces->listForManager($environmentId, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/saas/v1/environments/{environmentId}/workspaces')]
    public function create(string $environmentId, string $name, string $ownerUid,
        string $idempotencyKey, string $kind = 'organization',
        string $currency = 'BRL', string $color = '#64748B',
        ?string $legalName = null, string $companyCountry = 'BR',
        ?string $companyTaxId = null): JSONResponse {
        return $this->respond(fn(): array =>
            $this->workspaces->create($environmentId, $name, $kind,
                $currency, $ownerUid, $idempotencyKey, $color,
                $legalName, $companyCountry, $companyTaxId), 201);
    }
}
