<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\ModuleService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class ModuleController extends ApiController {
    public function __construct(string $appName, IRequest $request, private ModuleService $modules) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $spaceId): JSONResponse {
        return $this->respond(fn(): array => $this->modules->listForSpace($spaceId));
    }

    #[NoAdminRequired]
    public function toggle(int $spaceId, string $moduleId, bool $enabled): JSONResponse {
        return $this->respond(fn(): array => $this->modules->toggle($spaceId, $moduleId, $enabled));
    }
}
