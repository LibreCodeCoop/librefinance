<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\Domain\Obligations\ObligationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class ObligationController extends Controller {
    public function __construct(string $appName, IRequest $request, private ObligationService $service) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/obligations')]
    public function index(int $spaceId, int $afterId = 0, string $status = 'all',
        string $kind = 'all', string $dueRange = 'all', string $search = '',
        string $sortBy = 'id', string $sortDirection = 'asc',
        string $referenceDate = ''): JSONResponse {
        return $this->respond(fn() => $this->service->list(
            $spaceId, $afterId, $status, $kind, $dueRange, $search,
            $sortBy, $sortDirection, $referenceDate));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/obligations/{obligationId}')]
    public function show(int $spaceId, int $obligationId): JSONResponse {
        return $this->respond(fn() => $this->service->show($spaceId, $obligationId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/obligations')]
    public function create(int $spaceId, string $kind, string $description,
        int $amountMinor, string $issuedOn, string $dueOn, string $reference = '',
        ?int $contactId = null): JSONResponse {
        return $this->respond(fn() => $this->service->create($spaceId, $kind,
            $description, $reference, $amountMinor, $issuedOn, $dueOn, $contactId), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/obligations/{obligationId}/settlements')]
    public function settle(int $spaceId, int $obligationId, int $amountMinor,
        string $paidOn, string $operationKey, ?string $note = null): JSONResponse {
        return $this->respond(fn() => $this->service->settle($spaceId, $obligationId,
            $amountMinor, $paidOn, $operationKey, $note), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/obligations/{obligationId}/cancel')]
    public function cancel(int $spaceId, int $obligationId): JSONResponse {
        return $this->respond(fn() => $this->service->cancel($spaceId, $obligationId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/obligations/{obligationId}/settlements')]
    public function settlements(int $spaceId, int $obligationId, int $afterId = 0): JSONResponse {
        return $this->respond(fn() => $this->service->settlements($spaceId, $obligationId, $afterId));
    }

    private function respond(callable $fn, int $status = 200): JSONResponse {
        try {
            $result = $fn();
            if (is_array($result) && ($result['replayed'] ?? false) === true) {
                $status = 200;
            }
            return new JSONResponse($result, $status);
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            return new JSONResponse(['error' => $code], match ($code) {
                'space_not_found', 'module_not_found', 'obligation_not_found', 'contact_not_found' => 404,
                'permission_denied', 'module_disabled' => 403,
                'reference_conflict', 'idempotency_conflict', 'concurrent_change', 'obligation_closed', 'amount_exceeds_balance',
                'invoice_payment_via_allocation' => 409,
                'authentication_required' => 401,
                default => 422,
            });
        }
    }
}
