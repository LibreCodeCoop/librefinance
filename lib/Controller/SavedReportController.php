<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use DomainException;
use OCA\LibreFinance\Domain\Reports\SavedReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SavedReportController extends Controller {
    public function __construct(string $appName, IRequest $request, private SavedReportService $saved) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/saved-reports')]
    public function index(int $spaceId, int $afterId = 0): JSONResponse {
        return $this->respond(fn() => $this->saved->list($spaceId, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/saved-reports')]
    public function create(int $spaceId, string $name, string $kind,
        ?string $from, string $through, array $columns = [], ?string $accountClass = null): JSONResponse {
        return $this->respond(fn() => $this->saved->create($spaceId, $name, $kind,
            $from, $through, $columns, $accountClass), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}/saved-reports/{reportId}')]
    public function update(int $spaceId, int $reportId, string $name, string $kind,
        ?string $from, string $through, array $columns = [], ?string $accountClass = null): JSONResponse {
        return $this->respond(fn() => $this->saved->update($spaceId, $reportId,
            $name, $kind, $from, $through, $columns, $accountClass));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/spaces/{spaceId}/saved-reports/{reportId}')]
    public function archive(int $spaceId, int $reportId): JSONResponse {
        return $this->respond(fn() => $this->saved->archive($spaceId, $reportId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/spaces/{spaceId}/saved-reports/{reportId}/permanent')]
    public function deletePermanently(int $spaceId, int $reportId): JSONResponse {
        return $this->respond(fn() => $this->saved->deletePermanently($spaceId, $reportId));
    }

    private function respond(callable $work, int $code = 200): JSONResponse {
        try { return new JSONResponse($work(), $code); }
        catch (DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], match ($e->getMessage()) {
                'space_not_found', 'module_not_found', 'saved_report_not_found' => 404,
                'module_disabled', 'permission_denied' => 403,
                'authentication_required' => 401,
                default => 422,
            });
        }
    }
}
