<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Tests\Unit\Service;

use DomainException;
use OCA\LibreFinance\Service\ContactProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactProfileTest extends TestCase {
    public function testLegacyAndPartialUpdates(): void {
        self::assertSame([], ContactProfile::patch([]));
        $legacy = ContactProfile::fromRow([]);
        self::assertNull($legacy['phone']);
        self::assertNull($legacy['billingAddress']['country']);
        self::assertNull($legacy['shippingAddress']['line1']);
        self::assertSame(['billing_city' => 'Rio de Janeiro'], ContactProfile::patch([
            'billingAddress' => ['city' => 'Rio de Janeiro'],
        ]));
        self::assertSame(['phone' => null], ContactProfile::patch(['phone' => '']));
        self::assertSame(['billing_line1' => null, 'billing_line2' => null, 'billing_city' => null,
            'billing_region' => null, 'billing_postal_code' => null, 'billing_country' => null],
            ContactProfile::patch(['billingAddress' => null]));
    }

    public function testDistinctAddressAndExactTextRoundTrip(): void {
        $input = ['contactPerson' => 'Pessoa Sintética', 'phone' => '+55 (21) 0000-0000',
            'website' => 'https://example.invalid/contact?q=a',
            'billingAddress' => ['line1' => 'Rua Exemplo, 12 A', 'line2' => '  Sala  3',
                'city' => 'São José', 'region' => 'RJ', 'postalCode' => '00123-000', 'country' => 'BR'],
            'shippingAddress' => ['line1' => 'Synthetic street', 'line2' => null,
                'city' => 'Lisboa', 'region' => null, 'postalCode' => '0000-000', 'country' => 'PT']];
        $row = ContactProfile::patch($input);
        self::assertSame($input, ContactProfile::fromRow($row));
        self::assertSame(ContactProfile::columns(), array_keys($row));
        self::assertSame('  Sala  3', $row['billing_line2']);
        self::assertSame('00123-000', $row['billing_postal_code']);
    }

    public static function invalidProfiles(): array {
        return [
            'unknown field' => [['taxId' => 'not identity']],
            'bad type' => [['phone' => 123]],
            'invalid address type' => [['billingAddress' => 'Rua']],
            'unknown address field' => [['shippingAddress' => ['regionCode' => 'R']]],
            'unknown country' => [['billingAddress' => ['country' => 'ZZ']]],
            'lowercase country' => [['billingAddress' => ['country' => 'br']]],
            'country name' => [['billingAddress' => ['country' => 'Brasil']]],
            'website scheme' => [['website' => 'javascript:alert(1)']],
            'long line' => [['billingAddress' => ['line1' => str_repeat('á', 256)]]],
            'control' => [['contactPerson' => "Name\0"]],
            'invalid utf8' => [['phone' => "\xff"]],
            'only whitespace' => [['phone' => '  ']],
        ];
    }

    #[DataProvider('invalidProfiles')]
    public function testInvalidProfile(array $profile): void {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('invalid_contact_profile');
        ContactProfile::patch($profile);
    }
}
