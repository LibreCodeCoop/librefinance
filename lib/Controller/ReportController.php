<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\PublicAPI\FinanceApi;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class ReportController extends Controller {
    public function __construct(string $appName, IRequest $request, private FinanceApi $finance) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/profit-loss')]
    public function profitLoss(int $spaceId, string $from, string $through): JSONResponse {
        return $this->respond(fn() => $this->finance->profitLoss($spaceId, 'librefinance_reports', $from, $through));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/trial-balance')]
    public function trialBalance(int $spaceId, string $through, int $afterId = 0,
        ?string $accountClass = null): JSONResponse {
        return $this->respond(fn() => $this->finance->trialBalance(
            $spaceId, 'librefinance_reports', $through, $afterId, $accountClass));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/cash-flow')]
    public function cashFlow(int $spaceId, string $from, string $through, int $bankAccountId = 0): JSONResponse {
        return $this->respond(fn() =>
            $this->finance->cashFlow($spaceId, 'librefinance_reports', $from, $through, $bankAccountId));
    }

    private function respond(callable $operation): JSONResponse {
        try {
            return new JSONResponse($operation());
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            return new JSONResponse(['error' => $code], match ($code) {
                'module_not_found', 'space_not_found', 'bank_account_not_found' => 404,
                'module_disabled', 'permission_denied' => 403,
                default => 422,
            });
        }
    }
}
