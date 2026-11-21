<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\PublicAPI;

use LogicException;
use OCP\EventDispatcher\Event;

/** Dispatched by core when looking for installed extension modules. */
final class RegisterModulesEvent extends Event {
    /** @var array<string, ModuleDescriptor> */
    private array $modules = [];

    public function register(ModuleDescriptor $descriptor): void {
        if (isset($this->modules[$descriptor->appId])) {
            throw new LogicException('Module already registered: ' . $descriptor->appId);
        }
        $this->modules[$descriptor->appId] = $descriptor;
    }

    /** @return array<string, ModuleDescriptor> */
    public function modules(): array {
        ksort($this->modules);
        return $this->modules;
    }
}
