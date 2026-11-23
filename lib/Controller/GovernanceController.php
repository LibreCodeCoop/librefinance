<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\GovernanceService;
use OCA\LibreFinance\Service\SpaceService;
use OCA\LibreFinance\Service\AccessService;
use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

/**
 * Explicit JSON administration surface. The service checks instance admin
 * for every operation; Nextcloud delegated group admins cannot call these.
 */
final class GovernanceController extends ApiController {
    public function __construct(
        string $appName, IRequest $request,
        private GovernanceService $governance,
        private SpaceService $spaces,
        private AccessService $access,
    ) { parent::__construct($appName, $request); }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/governance/capabilities')]
    public function capabilities(): JSONResponse {
        return $this->respond(fn() => [
            'managed' => $this->governance->enabled(),
            'canCreate' => !$this->governance->enabled()
                || $this->governance->isInstanceAdmin(),
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/spaces/{spaceId}/governance')]
    public function scoped(int $spaceId): JSONResponse {
        return $this->respond(function () use ($spaceId): array {
            $this->access->requireRole($spaceId, 'viewer');
            $group = $this->governance->groupFor($spaceId);
            return [
                'governanceGroup' => $group,
                'groupGoverned' => $group !== null,
                'canChangeGroupGrants' => $group === null
                    || $this->governance->isInstanceAdmin(),
                'canGrantManager' => $group === null
                    || $this->governance->isInstanceAdmin(),
            ];
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/admin/governance')]
    public function show(): JSONResponse {
        return $this->respond(fn() => $this->governance->settings());
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/admin/governance')]
    public function configure(bool $managed): JSONResponse {
        return $this->respond(fn() => $this->governance->configure($managed));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/admin/groups')]
    public function groups(string $search = '', int $offset = 0): JSONResponse {
        return $this->respond(fn() => $this->governance->searchGroups($search, $offset));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/admin/groups/{gid}/delegates')]
    public function delegates(string $gid): JSONResponse {
        return $this->respond(fn() => ['delegates' => $this->governance->delegates($gid)]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/admin/groups/{gid}/delegates')]
    public function grantDelegate(string $gid, string $uid): JSONResponse {
        return $this->respond(fn() => $this->governance->grantDelegate($gid, $uid), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/admin/groups/{gid}/delegates/{uid}')]
    public function revokeDelegate(string $gid, string $uid): JSONResponse {
        return $this->respond(fn() => $this->governance->revokeDelegate($gid, $uid));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/admin/contexts')]
    public function contexts(): JSONResponse {
        return $this->respond(fn() => ['contexts' => $this->governance->contexts()]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/admin/contexts')]
    public function provision(string $name, string $kind, string $gid, string $currency = 'BRL'): JSONResponse {
        return $this->respond(function () use ($name, $kind, $gid, $currency) {
            $this->governance->requireInstanceAdmin();
            if (!$this->governance->enabled()) throw new \DomainException('governance_disabled');
            return $this->spaces->create($name, $kind, $currency, '#64748B', $gid);
        }, 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/admin/contexts/{spaceId}/archive')]
    public function archive(int $spaceId): JSONResponse {
        return $this->respond(function () use ($spaceId) {
            $this->governance->requireInstanceAdmin();
            return $this->spaces->archive($spaceId);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/admin/contexts/{spaceId}/restore')]
    public function restore(int $spaceId): JSONResponse {
        return $this->respond(function () use ($spaceId) {
            $this->governance->requireInstanceAdmin();
            return $this->spaces->restore($spaceId);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/admin/contexts/{spaceId}/group')]
    public function bind(int $spaceId, string $gid): JSONResponse {
        return $this->respond(fn() => $this->governance->bind($spaceId, $gid));
    }
}
