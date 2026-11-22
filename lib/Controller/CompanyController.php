<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use OCA\LibreFinance\Service\CompanyService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class CompanyController extends ApiController {
    public function __construct(string $appName, IRequest $request, private CompanyService $companies) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/companies')]
    public function index(int $spaceId, int $afterId = 0, string $search = ''): JSONResponse {
        return $this->respond(fn(): array => $this->companies->list($spaceId, $afterId, $search));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/companies')]
    public function create(int $spaceId, string $legalName, string $country = 'BR', ?string $taxId = null,
        ?string $tradeName = null, ?string $municipalRegistration = null,
        ?string $stateRegistration = null, array $customFields = []): JSONResponse {
        return $this->respond(fn(): array => $this->companies->create($spaceId,
            $legalName, $country, $taxId, $tradeName,
            $municipalRegistration, $stateRegistration, $customFields), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/companies/{companyId}')]
    public function update(int $spaceId, int $companyId, string $legalName,
        string $country = 'BR', ?string $taxId = null, ?string $tradeName = null,
        ?string $municipalRegistration = null, ?string $stateRegistration = null,
        bool $active = true, array $customFields = []): JSONResponse {
        return $this->respond(fn(): array => $this->companies->update($spaceId,
            $companyId, $legalName, $country, $taxId, $tradeName,
            $municipalRegistration, $stateRegistration, $active, $customFields));
    }
}
