<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCA\LibreFinance\Service\CustomFieldService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class CustomFieldController extends ApiController {
    public function __construct(string $appName, IRequest $request, private CustomFieldService $fields) {
        parent::__construct($appName, $request);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/company-fields')]
    public function index(int $spaceId): JSONResponse {
        return $this->respond(fn() => $this->fields->list($spaceId));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/company-fields')]
    public function create(int $spaceId, string $key, string $label, string $type,
        bool $required = false, array $options = [], bool $visible = true,
        ?string $help = null, int $sortOrder = 0, int $columnSpan = 12,
        ?bool $apiVisible = null): JSONResponse {
        return $this->respond(fn() => $this->fields->create($spaceId, $key, $label,
            $type, $required, $options, 'company', $visible, $help, $sortOrder, $columnSpan, $apiVisible), 201);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/company-fields/{fieldId}')]
    public function update(int $spaceId, int $fieldId, string $label,
        bool $required = false, bool $active = true, ?bool $visible = null,
        ?string $help = null, ?int $sortOrder = null, ?int $columnSpan = null,
        ?bool $apiVisible = null): JSONResponse {
        return $this->respond(fn() => $this->fields->update($spaceId, $fieldId,
            $label, $required, $active, 'company', $visible, $help, $sortOrder, $columnSpan, $apiVisible));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/custom-fields/{entityKind}')]
    public function indexForEntity(int $spaceId, string $entityKind): JSONResponse {
        return $this->respond(fn() => $this->fields->list($spaceId, $entityKind));
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/custom-fields/{entityKind}')]
    public function createForEntity(int $spaceId, string $entityKind,
        string $key, string $label, string $type, bool $required = false,
        array $options = [], bool $visible = true, ?string $help = null,
        int $sortOrder = 0, int $columnSpan = 12,
        ?bool $apiVisible = null): JSONResponse {
        return $this->respond(fn() => $this->fields->create($spaceId, $key, $label,
            $type, $required, $options, $entityKind, $visible, $help, $sortOrder, $columnSpan, $apiVisible), 201);
    }
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/custom-fields/{entityKind}/{fieldId}')]
    public function updateForEntity(int $spaceId, string $entityKind, int $fieldId,
        string $label, bool $required = false, bool $active = true,
        ?bool $visible = null, ?string $help = null,
        ?int $sortOrder = null, ?int $columnSpan = null,
        ?bool $apiVisible = null): JSONResponse {
        return $this->respond(fn() => $this->fields->update($spaceId, $fieldId,
            $label, $required, $active, $entityKind, $visible, $help, $sortOrder, $columnSpan, $apiVisible));
    }
}
