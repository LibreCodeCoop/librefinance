<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use OCA\LibreFinance\Service\CatalogService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class ItemController extends ApiController {
    public function __construct(string $appName, IRequest $request, private CatalogService $catalog) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/items')]
    public function index(int $spaceId, int $afterId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->catalog->items($spaceId, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/items')]
    public function create(int $spaceId, string $sku, string $name, string $kind,
        int $unitPriceMinor, ?string $description = null, array $customFields = []): JSONResponse {
        return $this->respond(fn(): array => $this->catalog->createItem(
            $spaceId, $sku, $name, $kind, $unitPriceMinor, $description, $customFields,
        ), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/items/{itemId}')]
    public function update(int $spaceId, int $itemId, string $sku, string $name, string $kind,
        int $unitPriceMinor, ?string $description = null, bool $active = true, array $customFields = []): JSONResponse {
        return $this->respond(fn(): array => $this->catalog->updateItem(
            $spaceId, $itemId, $sku, $name, $kind, $unitPriceMinor, $description, $active, $customFields,
        ));
    }
}
