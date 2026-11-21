<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Controller;
use OCA\LibreFinance\Service\DashboardLayoutService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class DashboardLayoutController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private DashboardLayoutService $layout) { parent::__construct($appName, $request); }
    #[NoAdminRequired]
    public function index(int $spaceId): JSONResponse {
        return $this->respond(fn() => $this->layout->get($spaceId));
    }
    #[NoAdminRequired]
    public function save(int $spaceId, array $widgets): JSONResponse {
        return $this->respond(fn() => $this->layout->save($spaceId, $widgets));
    }
    #[NoAdminRequired]
    public function reset(int $spaceId): JSONResponse {
        return $this->respond(fn() => $this->layout->reset($spaceId));
    }
}
