<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Controller;

use OCA\LibreFinance\Service\SaasExternalInvitationService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class SaasExternalInvitationController extends ApiController {
    public function __construct(string $appName, IRequest $request,
        private SaasExternalInvitationService $invitations) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/invitations')]
    public function inbox(int $afterId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->invitations->listForRecipient($afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/workspaces/{spaceId}/invitations')]
    public function index(int $spaceId, int $afterId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->invitations->listForOwner($spaceId, $afterId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/saas/v1/workspaces/{spaceId}/invitations')]
    public function create(int $spaceId, string $recipientUid, string $role,
        int $expiresAt, int $expectedRevision = 0): JSONResponse {
        return $this->respond(fn(): array => $this->invitations->create(
            $spaceId, $recipientUid, $role, $expiresAt, $expectedRevision), 201);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/saas/v1/workspaces/{spaceId}/invitations/{invitationId}/accept')]
    public function accept(int $spaceId, int $invitationId, int $expectedRevision): JSONResponse {
        return $this->respond(fn(): array => $this->invitations->accept(
            $spaceId, $invitationId, $expectedRevision));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/saas/v1/workspaces/{spaceId}/invitations/{invitationId}')]
    public function revoke(int $spaceId, int $invitationId, int $expectedRevision): JSONResponse {
        return $this->respond(fn(): array => $this->invitations->revoke(
            $spaceId, $invitationId, $expectedRevision));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/saas/v1/workspaces/{spaceId}/invitations/history')]
    public function history(int $spaceId, int $afterId = 0): JSONResponse {
        return $this->respond(fn(): array => $this->invitations->auditTrail($spaceId, $afterId));
    }
}
