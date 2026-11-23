<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use OCA\LibreFinance\Service\LedgerService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class AccountController extends ApiController {
    public function __construct(string $appName, IRequest $request, private LedgerService $ledger) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/accounts')]
    public function index(int $spaceId): JSONResponse {
        return $this->respond(fn() => ['accounts' => $this->ledger->accounts($spaceId)]);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/accounts')]
    public function create(int $spaceId, string $code, string $name, string $class, bool $isBank = false, array $bankDetails = []): JSONResponse {
        return $this->respond(fn() => $this->ledger->createAccount($spaceId, $code, $name, $class, $isBank, true, $bankDetails), 201);
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/accounts/{accountId}')]
    public function update(int $spaceId, int $accountId, string $name, bool $active = true, array $bankDetails = []): JSONResponse {
        return $this->respond(fn() => $this->ledger->updateAccount($spaceId, $accountId, $name, $active, $bankDetails));
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/accounts/{accountId}/deletion')]
    public function deletionPreview(int $spaceId, int $accountId): JSONResponse {
        return $this->respond(fn() => $this->ledger->accountDeletionPreview($spaceId, $accountId));
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/spaces/{spaceId}/accounts/{accountId}')]
    public function delete(int $spaceId, int $accountId): JSONResponse {
        return $this->respond(fn() => $this->ledger->deleteAccount($spaceId, $accountId));
    }
}
