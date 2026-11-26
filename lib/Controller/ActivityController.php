<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use DomainException;
use OCA\LibreFinance\Service\ActivityFeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Cash movements and expected commitments, with bank evidence kept distinct. */
final class ActivityController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private ActivityFeedService $feed) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/activity')]
    public function index(int $spaceId, string $cursor = '',
        string $direction = 'all', string $state = 'all',
        int $accountId = 0, string $search = '', string $from = '', string $through = ''): JSONResponse {
        try {
            return new JSONResponse($this->feed->list($spaceId, $cursor,
                $direction, $state, $accountId, $search, $from, $through));
        } catch (DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], match ($e->getMessage()) {
                'authentication_required' => 401,
                'permission_denied', 'module_disabled' => 403,
                'space_not_found' => 404,
                default => 422,
            });
        }
    }
}
