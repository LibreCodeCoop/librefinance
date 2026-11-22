<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\Service\InventoryParityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Strictly read-only source inventory coverage audit. */
final class InventoryController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private InventoryParityService $service) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/inventory-preview')]
    public function preview(int $spaceId, string $inventoryJson): JSONResponse {
        try {
            return new JSONResponse($this->service->inspect($spaceId, $inventoryJson), 200);
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            return new JSONResponse(['error' => $code], match ($code) {
                'space_not_found', 'module_not_found' => 404,
                'module_disabled', 'permission_denied' => 403,
                'authentication_required' => 401,
                default => 422,
            });
        }
    }
}
