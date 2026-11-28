<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;

/** Optional typed contact data, separate from fiscal identity and posted evidence. */
final class ContactProfile {
    private const FIELDS = [
        'contactPerson' => ['contact_person', 160],
        'phone' => ['phone', 60],
        'website' => ['website', 255],
    ];
    private const ADDRESS_FIELDS = [
        'line1' => ['line1', 255],
        'line2' => ['line2', 255],
        'city' => ['city', 120],
        'region' => ['region', 120],
        'postalCode' => ['postal_code', 32],
        'country' => ['country', 2],
    ];
    private const ADDRESSES = ['billingAddress' => 'billing_', 'shippingAddress' => 'shipping_'];

    /** @return list<string> */
    public static function columns(): array {
        $columns = array_column(self::FIELDS, 0);
        foreach (self::ADDRESSES as $prefix) {
            foreach (self::ADDRESS_FIELDS as [$name]) {
                $columns[] = $prefix . $name;
            }
        }
        return $columns;
    }

    /**
     * Omitted keys are not writes. Explicit null clears a field or all fields
     * of one address. No copying between billing and shipping, no inference.
     *
     * @return array<string,string|null>
     */
    public static function patch(array $profile): array {
        $columns = [];
        foreach ($profile as $key => $raw) {
            if (isset(self::FIELDS[$key])) {
                [$column, $limit] = self::FIELDS[$key];
                $value = self::text($raw, $limit);
                if ($key === 'website' && $value !== null
                    && (!filter_var($value, FILTER_VALIDATE_URL)
                        || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                    throw new DomainException('invalid_contact_profile');
                }
                $columns[$column] = $value;
            } elseif (isset(self::ADDRESSES[$key]) && ($raw === null || is_array($raw))) {
                $prefix = self::ADDRESSES[$key];
                $address = $raw ?? array_fill_keys(array_keys(self::ADDRESS_FIELDS), null);
                foreach ($address as $field => $input) {
                    if (!isset(self::ADDRESS_FIELDS[$field])) {
                        throw new DomainException('invalid_contact_profile');
                    }
                    [$column, $limit] = self::ADDRESS_FIELDS[$field];
                    $value = self::text($input, $limit);
                    if ($field === 'country' && $value !== null && !CompanyService::validCountry($value)) {
                        throw new DomainException('invalid_contact_profile');
                    }
                    $columns[$prefix . $column] = $value;
                }
            } else {
                throw new DomainException('invalid_contact_profile');
            }
        }
        return $columns;
    }

    /** Return the same shape for a legacy contact and a populated contact. */
    public static function fromRow(array $row): array {
        $profile = [];
        foreach (self::FIELDS as $key => [$column]) {
            $profile[$key] = $row[$column] ?? null;
        }
        foreach (self::ADDRESSES as $key => $prefix) {
            $address = [];
            foreach (self::ADDRESS_FIELDS as $field => [$column]) {
                $address[$field] = $row[$prefix . $column] ?? null;
            }
            $profile[$key] = $address;
        }
        return $profile;
    }

    private static function text(mixed $value, int $limit): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        // Retain original spelling, punctuation and significant spaces.
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > $limit || trim($value) === ''
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new DomainException('invalid_contact_profile');
        }
        return $value;
    }
}
