<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\PublicAPI;

use InvalidArgumentException;

/** Contract v1: installable Nextcloud app, one link per module, no injected JS. */
final readonly class ModuleDescriptor {
    public function __construct(
        public string $appId,
        public string $name,
        public string $description,
        public string $routeName,
        public string $requiredRole = 'viewer',
    ) {
        if (!preg_match('/^librefinance_[a-z][a-z0-9_]{2,40}$/', $appId)) {
            throw new InvalidArgumentException('Invalid module ID');
        }
        if (trim($name) === '' || mb_strlen($name) > 120 || mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Invalid module label');
        }
        if (!str_starts_with($routeName, $appId . '.') || !preg_match('/^[a-z0-9_.]+$/', $routeName)) {
            throw new InvalidArgumentException('Module route must belong to its app');
        }
        if (!in_array($requiredRole, ['viewer', 'auditor', 'operator', 'manager'], true)) {
            throw new InvalidArgumentException('Invalid module permission');
        }
    }
}
