<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/Import/Source/SourceSystem.php';

use OCA\LibreFinance\Import\Source\SourceSystem;

$accepted = ['akaunting', 'generic', 'gnucash', 'source_123', 'my-erp'];
$rejected = ['', 'a', 'ab', 'Akaunting', '../escape', 'my/erp',
    'unsafe.example.com', "foo\nX-Company: 1", str_repeat('x', 25), 'x', null,
    123, [], false];
$checks = 0;
foreach ($accepted as $id) {
    if (!SourceSystem::valid($id)) {
        throw new RuntimeException('Valid source ID rejected: ' . $id);
    }
    ++$checks;
}
foreach ($rejected as $id) {
    if (SourceSystem::valid($id)) {
        throw new RuntimeException('Invalid source ID accepted');
    }
    ++$checks;
}
if ($checks !== count($accepted) + count($rejected)) {
    throw new RuntimeException('Missing SourceSystem cases');
}
echo 'PASS: source IDs validate third-party namespaces without trusting origin attestation', PHP_EOL;
echo 'ALL CHECKS PASSED: ', $checks, PHP_EOL;
