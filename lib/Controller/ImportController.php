<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class ImportController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private ImportService $service) {
        parent::__construct($appName, $request);
    }

    /** Read-only source bank-account snapshots, numbers masked and no postings. */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/source-accounts')]
    public function accounts(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic', int $afterId = 0): JSONResponse {
        return $this->respond(fn(): array =>
            $this->service->accountSnapshots($spaceId, $sourceCompanyId,
                $sourceSystem, $afterId));
    }

    /** Read-only counts of durable source mappings, never source completeness. */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/coverage')]
    public function coverage(int $spaceId, string $sourceCompanyId,
        string $sourceSystem = 'generic'): JSONResponse {
        return $this->respond(fn(): array =>
            $this->service->coverage($spaceId, $sourceCompanyId, $sourceSystem));
    }

    /** Dry run: never mutate the database or retain uploaded data. */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/preview')]
    public function preview(int $spaceId, string $exportJson): JSONResponse {
        return $this->respond(function () use ($spaceId, $exportJson): array {
            $result = $this->service->preview($spaceId, $exportJson);
            unset($result['_account_map']);
            return $result;
        });
    }

    /** Requires a matching preview hash and deliberate confirmation. */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/import')]
    public function apply(int $spaceId, string $exportJson,
        string $previewHash, bool $confirmed = false): JSONResponse {
        return $this->respond(fn(): array =>
            $this->service->apply($spaceId, $exportJson, $previewHash, $confirmed));
    }

    private function respond(callable $work): JSONResponse {
        try {
            return new JSONResponse($work(), 200);
        } catch (DomainException $e) {
            $name = $e->getMessage();
            return new JSONResponse(['error' => $name], match ($name) {
                'space_not_found', 'module_not_found' => 404,
                'module_disabled', 'permission_denied' => 403,
                'authentication_required' => 401,
                'import_conflict', 'import_preview_changed',
                    'account_reference_busy', 'import_concurrent_change', 'category_code_conflict',
                    'company_tax_id_conflict' => 409,
                default => 422,
            });
        }
    }
}
