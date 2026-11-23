<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\Domain\Banking\BankingService;
use OCA\LibreFinance\PublicAPI\BankTransferApi;
use OCA\LibreFinance\Domain\Banking\Providers\BankProviderRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class BankingController extends Controller {
    public function __construct(string $appName, IRequest $request, private BankingService $service,
        private BankProviderRegistry $providers,
        private BankTransferApi $transfers) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/providers')]
    public function providers(int $spaceId): JSONResponse {
        return $this->respond(fn() => $this->providers->list($spaceId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/transactions')]
    public function index(int $spaceId, int $bankAccountId = 0, string $status = 'all',
        int $afterId = 0): JSONResponse {
        return $this->respond(fn() => $this->service->list($spaceId, $bankAccountId, $status, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/transfers')]
    public function transfers(int $spaceId, int $afterId = 0): JSONResponse {
        return $this->respond(fn() => $this->transfers->listForSpace($spaceId, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/imports')]
    public function import(int $spaceId, int $bankAccountId, string $csv = '',
        string $format = 'csv', string $ofx = ''): JSONResponse {
        return $this->respond(
            fn() => $this->service->import($spaceId, $bankAccountId, $csv, $format, $ofx), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/transactions/{transactionId}/match')]
    public function match(int $spaceId, int $transactionId, int $journalId): JSONResponse {
        return $this->respond(fn() => $this->service->match($spaceId, $transactionId, $journalId));
    }

    private function respond(callable $operation, int $status = 200): JSONResponse {
        try {
            return new JSONResponse($operation(), $status);
        } catch (DomainException $exception) {
            $code = $exception->getMessage();
            return new JSONResponse(['error' => $code], match ($code) {
                'space_not_found', 'module_not_found', 'bank_account_not_found',
                'bank_transaction_not_found', 'journal_posting_not_found' => 404,
                'permission_denied', 'module_disabled' => 403,
                'bank_import_conflict', 'journal_already_matched', 'bank_transaction_matched',
                'journal_already_claimed', 'journal_not_available',
                'account_reference_busy' => 409,
                'authentication_required' => 401,
                default => 422,
            });
        }
    }
}
