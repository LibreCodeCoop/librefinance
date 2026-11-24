<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\RateHistoryService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class RateHistoryController extends ApiController {
    public function __construct(string $appName, IRequest $request, private RateHistoryService $rates) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/exchange-rates')]
    public function index(int $spaceId, int $afterId = 0): JSONResponse {
        return $this->respond(fn() => $this->rates->list($spaceId, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/exchange-rates/{rateId}/preview')]
    public function preview(int $spaceId, int $rateId, mixed $sourceMinor = null): JSONResponse {
        return $this->respond(fn() => $this->rates->preview($spaceId, $rateId, $sourceMinor));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/exchange-rates')]
    public function record(int $spaceId, string $sourceCurrency, string $targetCurrency,
        mixed $rate, string $effectiveOn, string $sourceType, string $sourceRef): JSONResponse {
        return $this->respond(fn() => $this->rates->record($spaceId, $sourceCurrency,
            $targetCurrency, $rate, $effectiveOn, $sourceType, $sourceRef));
    }
}
