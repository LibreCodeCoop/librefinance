<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\LibreFinance\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\EventDispatcher\IEventDispatcher;

final class PageController extends Controller {
    public function __construct(string $appName, IRequest $request,
        private IEventDispatcher $events) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    #[NoCSRFRequired]
    #[FrontpageRoute(verb: 'GET', url: '/')]
    public function index(): TemplateResponse {
        // The workspace no longer hosts any iframe. Use Nextcloud's default
        // Content Security Policy without relaxing frame-src.
        // Nextcloud Text is optional. Dispatch its supported load event if installed;
        // the Vue field has a Markdown textarea fallback if unavailable.
        if (class_exists(\OCA\Text\Event\LoadEditor::class)) {
            $this->events->dispatchTyped(new \OCA\Text\Event\LoadEditor());
        }
        return new TemplateResponse($this->appName, 'index');
    }
}
