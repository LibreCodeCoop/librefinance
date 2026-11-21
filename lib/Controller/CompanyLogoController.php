<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use DomainException;
use OCA\LibreFinance\Service\CompanyLogoService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

final class CompanyLogoController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private CompanyLogoService $logos) { parent::__construct($appName, $request); }

    #[NoAdminRequired]
    public function upload(int $spaceId, int $companyId, string $imageBase64): JSONResponse {
        return $this->respond(fn() => $this->logos->upload($spaceId, $companyId, $imageBase64));
    }

    #[NoAdminRequired]
    public function delete(int $spaceId, int $companyId): JSONResponse {
        return $this->respond(fn() => $this->logos->remove($spaceId, $companyId));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function image(int $spaceId, int $companyId): Response {
        try {
            $result = $this->logos->read($spaceId, $companyId);
            return new DataDisplayResponse($result['content'], 200,
                ['Content-Type' => $result['mime'], 'Cache-Control' => 'private, no-store',
                 'X-Content-Type-Options' => 'nosniff']);
        } catch (DomainException $e) {
            $status = match ($e->getMessage()) {
                'company_not_found', 'space_not_found', 'company_logo_not_found' => 404,
                'permission_denied' => 403,
                'authentication_required' => 401,
                default => 422,
            };
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }
}
