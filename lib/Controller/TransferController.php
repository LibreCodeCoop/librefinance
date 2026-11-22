<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\Service\TransferImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Source transfer staging is separate from import of bank statement entries.
 * Reconciliation and posting require later explicit verified linkage.
 */
final class TransferController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private TransferImportService $service) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/transfer-preview')]
    public function preview(int $spaceId, string $exportJson): JSONResponse {
        return $this->respond(fn(): array => $this->service->preview($spaceId, $exportJson));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/transfer-import')]
    public function apply(int $spaceId, string $exportJson, string $previewHash,
        bool $confirmed = false): JSONResponse {
        return $this->respond(fn(): array =>
            $this->service->apply($spaceId, $exportJson, $previewHash, $confirmed));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/transfer-coverage')]
    public function coverage(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic'): JSONResponse {
        return $this->respond(fn(): array =>
            $this->service->coverage($spaceId, $sourceCompanyId, $sourceSystem));
    }

    private function respond(callable $work): JSONResponse {
        try {
            return new JSONResponse($work(), 200);
        } catch (DomainException $e) {
            $code = $e->getMessage();
            return new JSONResponse(['error' => $code], match ($code) {
                'space_not_found', 'module_not_found', 'bank_account_not_found' => 404,
                'module_disabled', 'permission_denied' => 403,
                'authentication_required' => 401,
                'import_conflict', 'import_preview_changed',
                    'account_reference_busy', 'import_concurrent_change', 'transfer_import_collision' => 409,
                default => 422,
            });
        }
    }
}
