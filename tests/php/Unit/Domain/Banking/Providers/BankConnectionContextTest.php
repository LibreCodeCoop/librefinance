<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Domain\Banking\Providers;

use InvalidArgumentException;
use OCA\LibreFinance\Domain\Banking\Providers\BankConnectionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankConnectionContextTest extends TestCase {
    public static function validProviderProvider(): array {
        return [
            'min length 2' => ['ab', 'USD', 1, 1, 'ab/space-1/account-1'],
            'max length 41' => [str_repeat('a', 41), 'EUR', 5, 12, str_repeat('a', 41) . '/space-5/account-12'],
            'with digits and underscore' => ['bank_123', 'GBP', 10, 42, 'bank_123/space-10/account-42'],
        ];
    }

    #[DataProvider('validProviderProvider')]
    public function testValidConstruct(string $providerId, string $currency, int $spaceId, int $bankAccountId, string $credentialReference): void {
        $context = new BankConnectionContext($spaceId, $bankAccountId, $providerId, $currency, $credentialReference);
        $this->assertSame($spaceId, $context->spaceId);
        $this->assertSame($bankAccountId, $context->bankAccountId);
        $this->assertSame($providerId, $context->providerId);
        $this->assertSame($currency, $context->currency);
        $this->assertSame($credentialReference, $context->credentialReference);
    }

    public static function invalidProviderProvider(): array {
        return [
            'space id zero' => [0, 1, 'mock', 'USD', 'mock/space-0/account-1', '#^invalid_bank_connection_context$#'],
            'space id negative' => [-1, 1, 'mock', 'USD', 'mock/space--1/account-1', '#^invalid_bank_connection_context$#'],
            'bank account id zero' => [1, 0, 'mock', 'USD', 'mock/space-1/account-0', '#^invalid_bank_connection_context$#'],
            'bank account id negative' => [1, -5, 'mock', 'USD', 'mock/space-1/account--5', '#^invalid_bank_connection_context$#'],
            'provider starts with uppercase' => [1, 1, 'Mock', 'USD', 'Mock/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'provider too short 1 char' => [1, 1, 'a', 'USD', 'a/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'provider too long 42 chars' => [1, 1, str_repeat('a', 42), 'USD', str_repeat('a', 42) . '/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'provider with terminal newline' => [1, 1, "mock\n", 'USD', "mock\n/space-1/account-1", '#^invalid_bank_connection_context$#'],
            'currency lowercase' => [1, 1, 'mock', 'usd', 'mock/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'currency too short' => [1, 1, 'mock', 'US', 'mock/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'currency too long' => [1, 1, 'mock', 'USDT', 'mock/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'currency with terminal newline' => [1, 1, 'mock', "USD\n", 'mock/space-1/account-1', '#^invalid_bank_connection_context$#'],
            'credential reference mismatch' => [1, 1, 'mock', 'USD', 'mock/space-1/account-99', '#^invalid_bank_connection_context$#'],
        ];
    }

    #[DataProvider('invalidProviderProvider')]
    public function testInvalidConstruct(int $spaceId, int $bankAccountId, string $providerId, string $currency, string $credentialReference, string $exceptionMessagePattern): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($exceptionMessagePattern);
        new BankConnectionContext($spaceId, $bankAccountId, $providerId, $currency, $credentialReference);
    }
}
