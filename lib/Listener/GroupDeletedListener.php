<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Listener;

use OCA\LibreFinance\Service\EnvironmentService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/** @template-implements IEventListener<GroupDeletedEvent> */
final class GroupDeletedListener implements IEventListener {
    public function __construct(private EnvironmentService $environments) {}

    public function handle(Event $event): void {
        if (!$event instanceof GroupDeletedEvent) {
            return;
        }
        // A reused Nextcloud GID must never reactivate its old financial tenant.
        $this->environments->suspendDeletedGroup($event->getGroup()->getGID());
    }
}
