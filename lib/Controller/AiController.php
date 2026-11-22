<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use OCA\LibreFinance\Service\AiService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class AiController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private AiService $ai) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/ai/capabilities')]
    public function capabilities(int $spaceId): JSONResponse {
        return $this->respond(fn(): array => $this->ai->capabilities($spaceId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/ai/preview')]
    public function preview(int $spaceId, string $from, string $through): JSONResponse {
        return $this->respond(fn(): array => $this->ai->preview($spaceId, $from, $through));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/ai/analyze')]
    public function analyze(int $spaceId, string $from, string $through,
        bool $consent = false, string $previewHash = ''): JSONResponse {
        return $this->respond(fn(): array =>
            $this->ai->analyze($spaceId, $from, $through, $consent, $previewHash), 202);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/ai/tasks/{taskId}')]
    public function status(int $spaceId, int $taskId): JSONResponse {
        return $this->respond(fn(): array => $this->ai->status($spaceId, $taskId));
    }
}
