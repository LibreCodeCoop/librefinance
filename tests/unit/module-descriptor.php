<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/PublicAPI/ModuleDescriptor.php';

use OCA\LibreFinance\PublicAPI\ModuleDescriptor;

$checks = 0;
function verify(bool $condition, string $name): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    ++$checks;
}
function rejected(callable $create, string $name): void {
    try {
        $create();
    } catch (InvalidArgumentException $e) {
        verify(true, $name);
        return;
    }
    throw new RuntimeException($name . ': accepted invalid descriptor');
}

$legacy = new ModuleDescriptor('librefinance_example', 'Example', 'Synthetic module', 'librefinance_example.page.index');
verify($legacy->contractVersion === 1 && $legacy->isCompatible(), 'Legacy constructor is compatible v1');
$explicit = new ModuleDescriptor('librefinance_example', 'Example', 'Synthetic module', 'librefinance_example.page.index', 'manager', 1);
verify($explicit->isCompatible() && $explicit->requiredRole === 'manager', 'Explicit v1 preserves permission floor');
$future = new ModuleDescriptor('librefinance_example', 'Example', 'Synthetic module', 'librefinance_example.page.index', 'viewer', 2);
verify($future->contractVersion === 2 && !$future->isCompatible(), 'Future valid descriptor remains incompatible');
$internal = new ModuleDescriptor('librefinance_reports', 'Reports', 'Synthetic reports', 'librefinance.page.index');
verify($internal->isCompatible(), 'Built-in route and default version preserved');
foreach ([0, -1, 65536] as $version) {
    rejected(fn() => new ModuleDescriptor('librefinance_example', 'Example', 'Description', 'librefinance_example.page.index', 'viewer', $version), 'Invalid contract version ' . $version);
}
rejected(fn() => new ModuleDescriptor('unrelated_app', 'Example', 'Description', 'unrelated_app.page.index'), 'Foreign app ID');
rejected(fn() => new ModuleDescriptor('librefinance_example', 'Example', 'Description', 'librefinance_other.page.index'), 'Foreign app route');
rejected(fn() => new ModuleDescriptor('librefinance_example', 'Example', 'Description', 'librefinance_example.page.index', 'owner'), 'Invalid permission floor');
rejected(fn() => new ModuleDescriptor('librefinance_example', '', 'Description', 'librefinance_example.page.index'), 'Missing label');
echo "Module descriptor: $checks assertions passed\n";
