<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use OCA\LibreFinance\Service\SpaceService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SpaceController extends ApiController {
    public function __construct(string $appName, IRequest $request, private SpaceService $spaces) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces')]
    public function index(): JSONResponse {
        return $this->respond(fn() => ['spaces' => $this->spaces->list()]);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces')]
    public function create(string $name, string $kind = 'personal', string $currency = 'BRL',
        string $color = '#64748B'): JSONResponse {
        return $this->respond(fn() => $this->spaces->create($name, $kind, $currency, $color), 201);
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/archived')]
    public function archived(): JSONResponse {
        return $this->respond(fn() => ['spaces' => $this->spaces->archived()]);
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/spaces/{spaceId}')]
    public function rename(int $spaceId, string $name, ?string $color = null): JSONResponse {
        return $this->respond(fn() => $this->spaces->rename($spaceId, $name, $color));
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/archive')]
    public function archive(int $spaceId): JSONResponse {
        return $this->respond(fn() => $this->spaces->archive($spaceId));
    }

    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/restore')]
    public function restore(int $spaceId): JSONResponse {
        return $this->respond(fn() => $this->spaces->restore($spaceId));
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/members')]
    public function members(int $spaceId): JSONResponse {
        return $this->respond(fn() => ['members' => $this->spaces->members($spaceId)]);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/members')]
    public function share(int $spaceId, string $uid, string $role = 'viewer'): JSONResponse {
        return $this->respond(function () use ($spaceId, $uid, $role): array {
            $this->spaces->shareUser($spaceId, $uid, $role);
            return ['ok' => true];
        });
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/groups')]
    public function groups(int $spaceId): JSONResponse {
        return $this->respond(fn() => ['groups' => $this->spaces->groupGrants($spaceId)]);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/spaces/{spaceId}/groups')]
    public function shareGroup(int $spaceId, string $gid, string $role = 'viewer'): JSONResponse {
        return $this->respond(function () use ($spaceId, $gid, $role): array {
            $this->spaces->shareGroup($spaceId, $gid, $role);
            return ['ok' => true];
        });
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/spaces/{spaceId}/members/{uid}')]
    public function unshare(int $spaceId, string $uid): JSONResponse {
        return $this->respond(function () use ($spaceId, $uid): array {
            $this->spaces->revokeUser($spaceId, $uid);
            return ['ok' => true];
        });
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/spaces/{spaceId}/groups/{gid}')]
    public function unshareGroup(int $spaceId, string $gid): JSONResponse {
        return $this->respond(function () use ($spaceId, $gid): array {
            $this->spaces->revokeGroup($spaceId, $gid);
            return ['ok' => true];
        });
    }
}

