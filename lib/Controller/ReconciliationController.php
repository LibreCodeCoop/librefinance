<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use DomainException;
use OCA\LibreFinance\Domain\Banking\ReconciliationCandidateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class ReconciliationController extends Controller {
    public function __construct(string $appName, IRequest $request, private ReconciliationCandidateService $service) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/transactions/{transactionId}/reconciliation-candidates')]
    public function candidates(int $spaceId, int $transactionId, int $windowDays = 3, int $afterId = 0): JSONResponse {
        try {
            return new JSONResponse($this->service->candidates($spaceId, $transactionId, $windowDays, $afterId));
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            return new JSONResponse(['error' => $code], match ($code) {
                'space_not_found', 'module_not_found', 'bank_account_not_found', 'bank_transaction_not_found' => 404,
                'permission_denied', 'module_disabled' => 403,
                'bank_transaction_matched', 'reconciliation_evidence_changed' => 409,
                'authentication_required' => 401,
                default => 422,
            });
        }
    }
}
