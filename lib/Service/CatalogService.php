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

final class CatalogService {
    public function __construct(
        private IDBConnection $db,
        private WriteService $writer,
        private AccessService $access,
        private CustomFieldService $customFields,
    ) {}

    /** @return array<string,mixed> */
    public function contacts(int $spaceId, int $afterId = 0, string $search = ''): array {
        $this->access->requireFinancialCapability($spaceId, 'contacts.read');
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'kind', 'email', 'tax_id', 'active', 'created_at')
            ->addSelect(...ContactProfile::columns())
            ->from('lf_contacts')
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(51);
        if (mb_strlen($search) > 120) throw new DomainException('invalid_search');
        $needle = trim($search);
        if ($needle !== '') {
            $qb->andWhere($qb->expr()->iLike('name',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($needle) . '%')));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (bool)$row['active'];
            $row['profile'] = ContactProfile::fromRow($row);
            foreach (ContactProfile::columns() as $column) {
                unset($row[$column]);
            }
        }
        unset($row);
        $values = $this->customFields->valuesForEntities($spaceId, 'contact', array_column($rows, 'id'));
        foreach ($rows as &$row) { $row['custom_fields'] = $values[$row['id']] ?? []; }
        unset($row);
        return ['contacts' => $rows, 'nextAfterId' => $more ? (int)end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    public function createContact(int $spaceId, string $name, string $kind, ?string $email, ?string $taxId,
        array $customFields = [], array $profile = []): array {
        $this->access->requireFinancialCapability($spaceId, 'contacts.manage');
        $name = $this->requiredName($name);
        if (!in_array($kind, ['customer', 'vendor', 'both'], true)) {
            throw new DomainException('invalid_contact_kind');
        }
        $email = $this->normalizeEmail($email);
        $taxId = $this->normalizeTaxId($taxId);
        $profileColumns = ContactProfile::patch($profile);
        $uid = $this->access->uid();
        $custom = $this->customFields->validateValues($spaceId, $customFields, true, 0, 'contact');
        $this->db->beginTransaction();
        try {
            $this->writer->insert('lf_contacts', [
                'space_id' => $spaceId, 'name' => $name, 'kind' => $kind,
                'email' => $email, 'tax_id' => $taxId, 'active' => 1,
                ...$profileColumns,
                'created_by' => $uid, 'created_at' => time(),
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lf_contacts');
            $this->customFields->saveValues($spaceId, $id, $custom, 'contact');
            $this->audit($spaceId, 'contact.created', 'contact', $id);
            $this->db->commit();
            return ['id' => $id, 'name' => $name, 'kind' => $kind,
                'email' => $email, 'tax_id' => $taxId, 'active' => true,
                'profile' => ContactProfile::fromRow($profileColumns),
                'custom_fields' => $this->customFields->valuesForEntities($spaceId, 'contact', [$id])[$id] ?? []];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function updateContact(int $spaceId, int $contactId, string $name, string $kind, ?string $email, ?string $taxId,
        bool $active, array $customFields = [], array $profile = []): array {
        $this->access->requireFinancialCapability($spaceId, 'contacts.manage');
        $name = $this->requiredName($name);
        if (!in_array($kind, ['customer', 'vendor', 'both'], true)) {
            throw new DomainException('invalid_contact_kind');
        }
        $email = $this->normalizeEmail($email);
        $taxId = $this->normalizeTaxId($taxId);
        $profileColumns = ContactProfile::patch($profile);
        $this->db->beginTransaction();
        try {
            $existing = $this->find($spaceId, 'lf_contacts', $contactId);
            if ($existing === null) {
                throw new DomainException('contact_not_found');
            }
            $custom = $this->customFields->validateValues($spaceId, $customFields, false, $contactId, 'contact');
            $this->writer->update('lf_contacts', [
                'name' => $name, 'kind' => $kind, 'email' => $email,
                'tax_id' => $taxId, 'active' => $active ? 1 : 0,
                ...$profileColumns,
            ], ['id' => $contactId, 'space_id' => $spaceId]);
            $this->customFields->saveValues($spaceId, $contactId, $custom, 'contact');
            $this->audit($spaceId, 'contact.updated', 'contact', $contactId);
            $saved = $this->find($spaceId, 'lf_contacts', $contactId);
            $this->db->commit();
            return ['id' => $contactId, 'name' => $name, 'kind' => $kind,
                'email' => $email, 'tax_id' => $taxId, 'active' => $active,
                'profile' => ContactProfile::fromRow($saved ?? []),
                'custom_fields' => $this->customFields->valuesForEntities($spaceId, 'contact', [$contactId])[$contactId] ?? []];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function items(int $spaceId, int $afterId = 0, string $search = ''): array {
        $this->access->requireFinancialCapability($spaceId, 'items.read');
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'sku', 'name', 'kind', 'description', 'unit_price_minor', 'currency', 'active')
            ->from('lf_items')
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(51);
        if (mb_strlen($search) > 120) throw new DomainException('invalid_search');
        $needle = trim($search);
        if ($needle !== '') {
            $qb->andWhere($qb->expr()->iLike('name',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($needle) . '%')));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (bool)$row['active'];
            $row['unit_price_minor'] = (int)$row['unit_price_minor'];
        }
        unset($row);
        $values = $this->customFields->valuesForEntities($spaceId, 'item', array_column($rows, 'id'));
        foreach ($rows as &$row) { $row['custom_fields'] = $values[$row['id']] ?? []; }
        unset($row);
        return ['items' => $rows, 'nextAfterId' => $more ? (int)end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    public function createItem(
        int $spaceId, string $sku, string $name, string $kind,
        int $unitPriceMinor, ?string $description, array $customFields = [],
    ): array {
        $this->access->requireFinancialCapability($spaceId, 'items.manage');
        $sku = $this->sku($sku);
        $name = $this->requiredName($name);
        $kind = $this->itemKind($kind);
        $this->unitPrice($unitPriceMinor);
        $description = $this->description($description);
        $currency = $this->currency($spaceId);
        $custom = $this->customFields->validateValues($spaceId, $customFields, true, 0, 'item');
        $this->db->beginTransaction();
        try {
            $this->writer->insert('lf_items', [
                'space_id' => $spaceId, 'sku' => $sku, 'name' => $name, 'kind' => $kind,
                'description' => $description, 'unit_price_minor' => $unitPriceMinor,
                'currency' => $currency, 'active' => 1,
                'created_by' => $this->access->uid(), 'created_at' => time(),
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lf_items');
            $this->customFields->saveValues($spaceId, $id, $custom, 'item');
            $this->audit($spaceId, 'item.created', 'item', $id);
            $this->db->commit();
            return ['id' => $id, 'sku' => $sku, 'name' => $name, 'kind' => $kind,
                'description' => $description, 'unit_price_minor' => $unitPriceMinor,
                'currency' => $currency, 'active' => true,
                'custom_fields' => $this->customFields->valuesForEntities($spaceId, 'item', [$id])[$id] ?? []];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('sku_conflict', previous: $e);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function updateItem(
        int $spaceId, int $itemId, string $sku, string $name, string $kind,
        int $unitPriceMinor, ?string $description, bool $active,
        array $customFields = [],
    ): array {
        $this->access->requireFinancialCapability($spaceId, 'items.manage');
        $sku = $this->sku($sku);
        $name = $this->requiredName($name);
        $kind = $this->itemKind($kind);
        $this->unitPrice($unitPriceMinor);
        $description = $this->description($description);
        $this->db->beginTransaction();
        try {
            $existing = $this->find($spaceId, 'lf_items', $itemId);
            if ($existing === null) {
                throw new DomainException('item_not_found');
            }
            $custom = $this->customFields->validateValues($spaceId, $customFields, false, $itemId, 'item');
            $this->writer->update('lf_items', [
                'sku' => $sku, 'name' => $name, 'kind' => $kind,
                'description' => $description, 'unit_price_minor' => $unitPriceMinor,
                'active' => $active ? 1 : 0,
            ], ['id' => $itemId, 'space_id' => $spaceId]);
            $this->customFields->saveValues($spaceId, $itemId, $custom, 'item');
            $this->audit($spaceId, 'item.updated', 'item', $itemId);
            $this->db->commit();
            return ['id' => $itemId, 'sku' => $sku, 'name' => $name, 'kind' => $kind,
                'description' => $description, 'unit_price_minor' => $unitPriceMinor,
                'currency' => (string)$existing['currency'], 'active' => $active,
                'custom_fields' => $this->customFields->valuesForEntities($spaceId, 'item', [$itemId])[$itemId] ?? []];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('sku_conflict', previous: $e);
            }
            throw $e;
        }
    }

    private function requiredName(string $name): string {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainException('invalid_name');
        }
        return $name;
    }

    private function sku(string $value): string {
        $value = strtoupper(trim($value));
        if (!preg_match('/^[A-Z0-9._-]{1,48}$/', $value)) {
            throw new DomainException('invalid_sku');
        }
        return $value;
    }

    private function itemKind(string $kind): string {
        if (!in_array($kind, ['product', 'service'], true)) {
            throw new DomainException('invalid_item_kind');
        }
        return $kind;
    }

    private function unitPrice(int $price): void {
        if ($price < 0 || $price > 9000000000000) {
            throw new DomainException('invalid_unit_price');
        }
    }

    private function description(?string $text): ?string {
        $text = trim($text ?? '');
        if (mb_strlen($text) > 4000) {
            throw new DomainException('invalid_description');
        }
        return $text !== '' ? $text : null;
    }

    private function normalizeEmail(?string $email): ?string {
        $email = trim($email ?? '');
        if ($email === '') {
            return null;
        }
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('invalid_email');
        }
        return $email;
    }

    private function normalizeTaxId(?string $taxId): ?string {
        $taxId = trim($taxId ?? '');
        if ($taxId === '') {
            return null;
        }
        // Display-only identifier, never assume a CPF/CNPJ is fiscally valid.
        if (strlen($taxId) > 40 || !preg_match('/^[A-Za-z0-9.\/-]+$/', $taxId)) {
            throw new DomainException('invalid_tax_id');
        }
        return $taxId;
    }

    /** @return array<string,mixed>|null */
    private function find(int $spaceId, string $table, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)
            ->where($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $qb->executeQuery()->fetchAssociative() ?: null;
    }

    private function currency(int $spaceId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('currency')->from('lf_spaces')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)));
        $currency = $qb->executeQuery()->fetchOne();
        if ($currency === false) {
            throw new DomainException('space_not_found');
        }
        return (string)$currency;
    }

    private function audit(int $spaceId, string $action, string $entity, int $id): void {
        $this->writer->insert('lf_audit', [
            'space_id' => $spaceId, 'actor_uid' => $this->access->uid(),
            'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'occurred_at' => time(),
        ]);
    }
}
