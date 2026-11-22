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

final class ContactController extends ApiController {
    public function __construct(string $appName, IRequest $request, private CatalogService $catalog) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/contacts')]
    public function index(int $spaceId, int $afterId = 0, string $search = ''): JSONResponse {
        return $this->respond(fn(): array => $this->catalog->contacts($spaceId, $afterId, $search));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/contacts')]
    public function create(int $spaceId, string $name, string $kind = 'customer',
        ?string $email = null, ?string $taxId = null, array $customFields = []): JSONResponse {
        return $this->respond(fn(): array => $this->catalog->createContact(
            $spaceId, $name, $kind, $email, $taxId, $customFields,
        ), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/contacts/{contactId}')]
    public function update(int $spaceId, int $contactId, string $name, string $kind,
        ?string $email = null, ?string $taxId = null, bool $active = true, array $customFields = []): JSONResponse {
        return $this->respond(fn(): array => $this->catalog->updateContact(
            $spaceId, $contactId, $name, $kind, $email, $taxId, $active, $customFields,
        ));
    }
}
