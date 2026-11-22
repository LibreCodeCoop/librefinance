<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Legal entity metadata belongs to an authorized finance space.
 * Registration does not constitute fiscal registration or verification.
 */
final class CompanyService {
    public function __construct(
        private IDBConnection $db,
        private AccessService $access,
        private WriteService $writer,
        private CustomFieldService $customFields,
    ) {}

    /** @return array<string,mixed> */
    public function list(int $spaceId, int $afterId = 0, string $search = ''): array {
        $this->access->requireRole($spaceId, 'viewer');
        if ($afterId < 0) { throw new DomainException('invalid_cursor'); }
        $q = $this->db->getQueryBuilder();
        $q->select('id','legal_name','trade_name','country','tax_id',
            'municipal_registration','state_registration','logo_version','active','created_at','updated_at',
            'contact_email','contact_phone','website','address_line1','address_line2','city','region','postal_code','brand_color')
            ->from('lf_companies')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('id', $q->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(51);
        if (mb_strlen($search) > 120) throw new DomainException('invalid_search');
        $needle = trim($search);
        if ($needle !== '') {
            $q->andWhere($q->expr()->iLike('legal_name',
                $q->createNamedParameter('%' . $this->db->escapeLikeParameter($needle) . '%')));
        }
        $rows = $q->executeQuery()->fetchAllAssociative();
        $hasMore = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) { $row = self::cast($row); }
        unset($row);
        $values = $this->customFields->valuesForCompanies($spaceId, array_column($rows, 'id'));
        foreach ($rows as &$row) { $row['custom_fields'] = $values[$row['id']] ?? []; }
        unset($row);
        return ['companies' => $rows, 'nextAfterId' => $hasMore ? (int)end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    public function create(int $spaceId, string $legalName, string $country,
        ?string $taxId, ?string $tradeName = null, ?string $municipalRegistration = null,
        ?string $stateRegistration = null, array $customFields = [], array $profile = []): array {
        $this->access->requireRole($spaceId, 'manager');
        $fields = self::fields($legalName, $country, $taxId, $tradeName,
            $municipalRegistration, $stateRegistration);
        $fields = [...$fields, ...self::profileFields($profile)];
        $fields['brand_color'] ??= '#64748B';
        $custom = $this->customFields->validateValues($spaceId, $customFields, true);
        $now = time();
        $this->db->beginTransaction();
        try {
            $this->writer->insert('lf_companies', [
                'space_id' => $spaceId, ...$fields, 'active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lf_companies');
            $this->customFields->saveValues($spaceId, $id, $custom);
            $this->audit($spaceId, $id, 'company.created');
            $this->db->commit();
            return ['id' => $id, ...$fields, 'active' => true,
                'custom_fields' => $this->customFields->valuesForCompanies($spaceId, [$id])[$id] ?? [],
                'logo_version' => null, 'created_at' => $now, 'updated_at' => $now];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw self::convertConflict($e);
        }
    }

    /** @return array<string,mixed> */
    public function update(int $spaceId, int $companyId, string $legalName, string $country,
        ?string $taxId, ?string $tradeName = null, ?string $municipalRegistration = null,
        ?string $stateRegistration = null, bool $active = true, array $customFields = [], array $profile = []): array {
        $this->access->requireRole($spaceId, 'manager');
        $fields = self::fields($legalName, $country, $taxId, $tradeName,
            $municipalRegistration, $stateRegistration);
        $fields = [...$fields, ...self::profileFields($profile)];
        $now = time();
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('id')->from('lf_companies')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('id', $q->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
            if ($q->executeQuery()->fetchOne() === false) {
                throw new DomainException('company_not_found');
            }
            $custom = $this->customFields->validateValues($spaceId, $customFields, false, $companyId);
            $this->writer->update('lf_companies', [
                ...$fields, 'active' => $active ? 1 : 0, 'updated_at' => $now,
            ], ['space_id' => $spaceId, 'id' => $companyId]);
            $this->customFields->saveValues($spaceId, $companyId, $custom);
            $this->audit($spaceId, $companyId, 'company.updated');
            $this->db->commit();
            return ['id' => $companyId, ...$fields, 'active' => $active,
                'custom_fields' => $this->customFields->valuesForCompanies($spaceId, [$companyId])[$companyId] ?? [],
                'updated_at' => $now];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw self::convertConflict($e);
        }
    }

    /** ISO 3166-1 alpha-2 country codes from /usr/share/zoneinfo/iso3166.tab (2026 reference). */
    private const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

    /** Reuse the authoritative ISO country allowlist in import previews. */
    public static function validCountry(string $country): bool {
        return preg_match('/^[A-Z]{2}$/D', $country) === 1
            && in_array($country, explode(' ', self::COUNTRIES), true);
    }

    /** @return array<string,string|null> */
    private static function fields(string $legalName, string $country, ?string $taxId,
        ?string $tradeName, ?string $municipalRegistration,
        ?string $stateRegistration): array {
        $legalName = trim($legalName);
        $country = strtoupper(trim($country));
        $taxId = trim($taxId ?? '');
        if ($country === 'BR') { $taxId = preg_replace('/[^A-Z0-9]/', '', strtoupper($taxId)); }
        if ($legalName === '' || mb_strlen($legalName) > 180) {
            throw new DomainException('invalid_company_name');
        }
        // ISO 3166-1 alpha-2 format. No country is treated as a special tax
        // jurisdiction; official country-specific validation is a separate module.
        if (!self::validCountry($country)) {
            throw new DomainException('invalid_company_country');
        }
        // Retain historic Brazilian 14-character identifiers; non-Brazilian
        // values are opaque fiscal references, never claimed to be verified.
        if ($taxId !== '') {
            if (mb_strlen($taxId) > 40 || !preg_match('/^[\p{L}\p{N} .\/-]+$/uD', $taxId)) {
                throw new DomainException('invalid_company_tax_id');
            }
            if ($country === 'BR' && !preg_match('/^[0-9A-Z]{14}$/D', $taxId)) {
                throw new DomainException('invalid_company_tax_id');
            }
        }
        foreach (['trade_name' => $tradeName,
            'municipal_registration' => $municipalRegistration,
            'state_registration' => $stateRegistration] as $field => $value) {
            if ($value !== null && (mb_strlen(trim($value)) > ($field === 'trade_name' ? 180 : 40))) {
                throw new DomainException('invalid_company_attribute');
            }
        }
        return ['legal_name' => $legalName, 'trade_name' => self::optional($tradeName),
            'country' => $country, 'tax_id' => $taxId === '' ? null : $taxId,
            'municipal_registration' => self::optional($municipalRegistration),
            'state_registration' => self::optional($stateRegistration)];
    }

    /**
     * Only known, bounded identity fields enter the company record. Missing keys
     * never erase existing values in update requests from older clients.
     *
     * @param array<string,mixed> $profile
     * @return array<string,string|null>
     */
    private static function profileFields(array $profile): array {
        $columns = [
            'contactEmail' => ['contact_email', 190],
            'contactPhone' => ['contact_phone', 60],
            'website' => ['website', 255],
            'addressLine1' => ['address_line1', 255],
            'addressLine2' => ['address_line2', 255],
            'city' => ['city', 120],
            'region' => ['region', 120],
            'postalCode' => ['postal_code', 32],
            'brandColor' => ['brand_color', 7],
        ];
        $fields = [];
        foreach ($profile as $key => $raw) {
            if (!is_string($key) || !isset($columns[$key]) || ($raw !== null && !is_string($raw))) {
                throw new DomainException('invalid_company_attribute');
            }
            [$column, $limit] = $columns[$key];
            $value = self::optional($raw);
            if ($value !== null && mb_strlen($value) > $limit) {
                throw new DomainException('invalid_company_attribute');
            }
            if ($key === 'brandColor' && ($value === null
                || preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) !== 1)) {
                throw new DomainException('invalid_company_attribute');
            }
            if ($key === 'contactEmail' && $value !== null
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('invalid_company_attribute');
            }
            if ($key === 'website' && $value !== null
                && (!filter_var($value, FILTER_VALIDATE_URL)
                    || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)),
                        ['https', 'http'], true))) {
                throw new DomainException('invalid_company_attribute');
            }
            $fields[$column] = $value;
        }
        return $fields;
    }

    private static function optional(?string $value): ?string {
        $value = trim($value ?? '');
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array {
        $row['logo_version'] = $row['logo_version'] === null ? null : (int)$row['logo_version'];
        foreach (['id','created_at','updated_at'] as $key) { $row[$key] = (int)$row[$key]; }
        $row['active'] = (bool)$row['active'];
        return $row;
    }

    private function audit(int $spaceId, int $companyId, string $action): void {
        $this->writer->insert('lf_audit', [
            'space_id' => $spaceId, 'actor_uid' => $this->access->uid(),
            'action' => $action, 'entity' => 'company', 'entity_id' => $companyId,
            'occurred_at' => time(),
        ]);
    }

    private static function convertConflict(\Throwable $e): \Throwable {
        if ($e instanceof \OCP\DB\Exception &&
            $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
            return new DomainException('company_tax_id_conflict', previous: $e);
        }
        return $e;
    }
}
