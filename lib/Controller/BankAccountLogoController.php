<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use DomainException;
use OCA\LibreFinance\Service\BankAccountLogoService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

final class BankAccountLogoController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private BankAccountLogoService $logos) { parent::__construct($appName, $request); }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/accounts/{accountId}/logo')]
    public function upload(int $spaceId, int $accountId, string $imageBase64): JSONResponse {
        return $this->respond(fn() => $this->logos->upload($spaceId, $accountId, $imageBase64));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/spaces/{spaceId}/accounts/{accountId}/logo')]
    public function delete(int $spaceId, int $accountId): JSONResponse {
        return $this->respond(fn() => $this->logos->remove($spaceId, $accountId));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/accounts/{accountId}/logo')]
    public function image(int $spaceId, int $accountId): Response {
        try {
            $result = $this->logos->read($spaceId, $accountId);
            return new DataDisplayResponse($result['content'], 200,
                ['Content-Type' => $result['mime'], 'Cache-Control' => 'private, no-store',
                 'X-Content-Type-Options' => 'nosniff']);
        } catch (DomainException $e) {
            $status = match ($e->getMessage()) {
                'account_not_found', 'space_not_found', 'account_logo_not_found' => 404,
                'permission_denied' => 403,
                'authentication_required' => 401,
                default => 422,
            };
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }
}
