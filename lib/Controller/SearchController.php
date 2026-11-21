<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\SearchService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SearchController extends ApiController {
    public function __construct(string $appName, IRequest $request, private SearchService $search) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $spaceId, string $query, string $type = 'all'): JSONResponse {
        return $this->respond(fn(): array => $this->search->search($spaceId, $query, $type));
    }
}
