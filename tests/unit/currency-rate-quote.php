<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/Domain/Currency/ExactConversion.php';
require_once __DIR__ . '/../../lib/Domain/Currency/CurrencyMetadata.php';
require_once __DIR__ . '/../../lib/Domain/Currency/RateQuote.php';

use OCA\LibreFinance\Domain\Currency\RateQuote;

$checks = 0;
function verifyQuote(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException($name);
    ++$checks;
}

$args = [1, 'JPY', 'KWD', '0.002000', '2026-11-24', 'manual', 'synthetic-a'];
$q = new RateQuote(...$args);
verifyQuote($q->sourcePrecision === 0 && $q->targetPrecision === 3, 'Explicit currency scales');
verifyQuote($q->rate === '0.002' && $q->convert(1000) === 2000, 'Canonical exact conversion');
verifyQuote($q->convert(-1000) === -2000, 'Signed conversion');
$copy = new RateQuote(...array_replace($args, [3 => '0.002']));
verifyQuote($copy->fingerprint() === $q->fingerprint(), 'Equivalent decimal fingerprint');
foreach ([0 => 2, 3 => '0.003', 4 => '2026-11-25', 5 => 'imported', 6 => 'synthetic-b'] as $i => $v) {
    $other = new RateQuote(...array_replace($args, [$i => $v]));
    verifyQuote($other->fingerprint() !== $q->fingerprint(), 'Fingerprint distinguishes field ' . $i);
}
foreach ([
    [0, 0], [1, 'jpy'], [1, 'ZZZ'], [2, 'JPY'], [3, '0'], [3, '1e-3'],
    [4, '2026-02-30'], [4, '0000-01-01'], [5, 'online'], [6, ''],
    [6, 'https://example.invalid'], [6, str_repeat('x', 97)],
] as [$i, $v]) {
    try {
        new RateQuote(...array_replace($args, [$i => $v]));
        throw new RuntimeException('Invalid field accepted: ' . $i);
    } catch (DomainException $e) {
        verifyQuote(true, 'Invalid quote field rejected ' . $i);
    }
}
echo "Currency rate quote: $checks assertions passed\n";
