<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\LedgerService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class JournalController extends ApiController {
    public function __construct(string $appName, IRequest $request, private LedgerService $ledger) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    public function index(int $spaceId, int $beforeId = 0): JSONResponse {
        return $this->respond(fn() => $this->ledger->journals($spaceId, $beforeId));
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    public function create(
        int $spaceId,
        int $debitId,
        int $creditId,
        int $amountMinor,
        string $date,
        string $memo,
        string $externalKey,
        ?int $categoryId = null,
        array $customFields = [],
        ?string $notesMarkdown = null,
        ?int $paymentMethodId = null,
    ): JSONResponse {
        return $this->respond(
            fn() => $this->ledger->createJournal($spaceId, $debitId, $creditId, $amountMinor, $date, $memo, $externalKey, $categoryId, $customFields, $notesMarkdown, $paymentMethodId),
            201,
        );
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    public function reverse(int $spaceId, int $journalId): JSONResponse {
        return $this->respond(fn() => $this->ledger->reverse($spaceId, $journalId));
    }
}
