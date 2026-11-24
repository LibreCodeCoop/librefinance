<?php

/*
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/Domain/Currency/ExactConversion.php';

use OCA\LibreFinance\Domain\Currency\ExactConversion;

$valid = [
    'unchanged BRL' => [12345, 2, 2, '1', 12345],
    'zero precision to two' => [100, 0, 2, '0.025', 250],
    'two to three' => [123, 2, 3, '1', 1230],
    'three to zero below half' => [499, 3, 0, '1', 0],
    'three to zero half' => [500, 3, 0, '1', 1],
    'negative half' => [-500, 3, 0, '1', -1],
    'negative below half' => [-499, 3, 0, '1', 0],
    'round carry' => [999, 3, 2, '1', 100],
    'half with fractional rate' => [1, 2, 2, '0.5', 1],
    'negative half rate' => [-1, 2, 2, '0.5', -1],
    'zero with valid rate' => [0, 2, 3, '0.000000000001', 0],
    'large intermediate product' => [9000000000000, 2, 2, '0.999999999999', 8999999999991],
    'exact maximum' => [9000000000000, 2, 2, '1.000000000000', 9000000000000],
    'tiny rate rounded' => [9000000000000, 0, 0, '0.000000000001', 9],
    'negative exact maximum' => [-9000000000000, 3, 3, '1', -9000000000000],
];
foreach ($valid as $name => [$amount, $from, $to, $rate, $expected]) {
    $actual = ExactConversion::convert($amount, $from, $to, $rate);
    if ($actual !== $expected) {
        throw new RuntimeException($name . ': expected ' . $expected . ', got ' . $actual);
    }
    echo "PASS: $name\n";
}
$invalid = [
    'source above bound' => [9000000000001, 2, 2, '1', 'invalid_currency_amount'],
    'minimum PHP integer' => [PHP_INT_MIN, 2, 2, '1', 'invalid_currency_amount'],
    'negative precision' => [1, -1, 2, '1', 'invalid_currency_amount'],
    'unsupported precision' => [1, 2, 4, '1', 'invalid_currency_amount'],
    'zero rate' => [0, 2, 2, '0.000', 'invalid_exchange_rate'],
    'negative rate' => [1, 2, 2, '-1', 'invalid_exchange_rate'],
    'scientific rate' => [1, 2, 2, '1e2', 'invalid_exchange_rate'],
    'locale comma' => [1, 2, 2, '1,2', 'invalid_exchange_rate'],
    'leading zero' => [1, 2, 2, '01', 'invalid_exchange_rate'],
    'whitespace' => [1, 2, 2, '1 ', 'invalid_exchange_rate'],
    'too many decimals' => [1, 2, 2, '1.0000000000001', 'invalid_exchange_rate'],
    'too many whole digits' => [1, 2, 2, '1000000000000', 'invalid_exchange_rate'],
    'positive overflow' => [9000000000000, 2, 2, '1.000000000001', 'currency_amount_overflow'],
    'negative overflow' => [-9000000000000, 2, 2, '2', 'currency_amount_overflow'],
    'precision overflow' => [9000000000000, 0, 3, '1', 'currency_amount_overflow'],
];
foreach ($invalid as $name => [$amount, $from, $to, $rate, $expected]) {
    try {
        ExactConversion::convert($amount, $from, $to, $rate);
        throw new RuntimeException($name . ': expected an exception');
    } catch (DomainException $e) {
        if ($e->getMessage() !== $expected) {
            throw new RuntimeException($name . ': incorrect error ' . $e->getMessage());
        }
    }
    echo "PASS: $name\n";
}
echo 'ALL CHECKS PASSED: ' . (count($valid) + count($invalid)) . "\n";
