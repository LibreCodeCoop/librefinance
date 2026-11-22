<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\PaymentWorkflowService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class PaymentWorkflowController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private PaymentWorkflowService $payments) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/invoices/{invoiceId}/cash-payments')]
    public function invoice(int $spaceId, int $invoiceId, int $bankAccountId,
        int $counterAccountId, int $amountMinor, string $paidOn, string $operationKey): JSONResponse {
        return $this->respond(fn() => $this->payments->forInvoice($spaceId, $invoiceId,
            $bankAccountId, $counterAccountId, $amountMinor, $paidOn, $operationKey), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/obligations/{obligationId}/existing-journal-payments')]
    public function existing(int $spaceId, int $obligationId, int $bankAccountId,
        int $journalId, int $amountMinor, string $paidOn, string $operationKey): JSONResponse {
        return $this->respond(fn() => $this->payments->linkExistingObligation(
            $spaceId, $obligationId, $bankAccountId, $journalId,
            $amountMinor, $paidOn, $operationKey), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/obligations/{obligationId}/cash-payments')]
    public function obligation(int $spaceId, int $obligationId, int $bankAccountId,
        int $counterAccountId, int $amountMinor, string $paidOn, string $operationKey): JSONResponse {
        return $this->respond(fn() => $this->payments->forObligation($spaceId, $obligationId,
            $bankAccountId, $counterAccountId, $amountMinor, $paidOn, $operationKey), 201);
    }
}
