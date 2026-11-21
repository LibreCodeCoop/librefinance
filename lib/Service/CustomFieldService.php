<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Finance Field Studio v2, inspired by LibreCode's AGPL-licensed Field Studio.
 * A bounded, typed metadata extension; never a dynamic SQL or code executor.
 * All field definitions and values are scoped to the authenticated finance space.
 */
final class CustomFieldService {
    private const ENTITIES = ['company', 'contact', 'item', 'journal'];
    private const TYPES = ['text', 'textarea', 'email', 'url', 'integer', 'number',
        'date', 'boolean', 'select'];

    public function __construct(private IDBConnection $db,
        private AccessService $access, private WriteService $write) {}

    public function list(int $spaceId, string $entityKind = 'company'): array {
        self::entity($entityKind);
        $role = $this->access->requireRole($spaceId, 'viewer');
        $fields = $this->definitions($spaceId, $entityKind);
        if (AccessService::rank($role) < AccessService::rank('manager')) {
            $fields = array_values(array_filter($fields, static fn(array $f): bool => $f['visible'] && $f['active']));
        }
        return ['fields' => $fields, 'entityKind' => $entityKind,
            'supportedEntities' => self::ENTITIES, 'supportedTypes' => self::TYPES];
    }

    /** @return list<array<string,mixed>> */
    private function definitions(int $spaceId, string $entityKind): array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('lf_custom_fields')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('entity_kind', $q->createNamedParameter($entityKind)))
            ->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->setMaxResults(31);
        $rows = $q->executeQuery()->fetchAllAssociative();
        if (count($rows) > 30) { throw new DomainException('custom_field_limit'); }
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'], 'entityKind' => (string)$r['entity_kind'],
            'key' => (string)$r['field_key'], 'label' => (string)$r['label'],
            'type' => (string)$r['field_type'], 'required' => (bool)$r['required'],
            'active' => (bool)$r['active'], 'visible' => (bool)$r['visible'],
            'help' => $r['help_text'] !== null ? (string)$r['help_text'] : null,
            'sortOrder' => (int)$r['sort_order'], 'columnSpan' => (int)$r['column_span'],
            'options' => json_decode($r['options_json'] ?? '[]', true) ?: [],
        ], $rows);
    }

    public function create(int $spaceId, string $key, string $label, string $type,
        bool $required = false, array $options = [], string $entityKind = 'company',
        bool $visible = true, ?string $help = null, int $sortOrder = 0,
        int $columnSpan = 12): array {
        $this->access->requireRole($spaceId, 'manager');
        self::entity($entityKind);
        $key = strtolower(trim($key));
        $label = trim($label);
        self::metadata($key, $label, $type, $required, $visible, $help, $sortOrder, $columnSpan);
        $options = self::validOptions($type, $options);
        if (count($this->definitions($spaceId, $entityKind)) >= 30) {
            throw new DomainException('custom_field_limit');
        }
        $now = time();
        try {
            $this->db->beginTransaction();
            $this->write->insert('lf_custom_fields', [
                'space_id' => $spaceId, 'entity_kind' => $entityKind,
                'field_key' => $key, 'label' => $label, 'field_type' => $type,
                'required' => $required, 'visible' => $visible, 'active' => true,
                'help_text' => $help !== null && trim($help) !== '' ? trim($help) : null,
                'sort_order' => $sortOrder, 'column_span' => $columnSpan,
                'options_json' => json_encode($options, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int)$this->db->lastInsertId('*PREFIX*lf_custom_fields');
            $this->audit($spaceId, $id, 'custom_field.created');
            $this->db->commit();
            return ['id' => $id, 'entityKind' => $entityKind, 'key' => $key,
                'label' => $label, 'type' => $type, 'required' => $required,
                'visible' => $visible, 'active' => true, 'help' => $help,
                'sortOrder' => $sortOrder, 'columnSpan' => $columnSpan,
                'options' => $options];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof \OCP\DB\Exception &&
                $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new DomainException('custom_field_key_conflict', previous: $e);
            }
            throw $e;
        }
    }

    public function update(int $spaceId, int $fieldId, string $label,
        bool $required = false, bool $active = true, string $entityKind = 'company',
        ?bool $visible = null, ?string $help = null, ?int $sortOrder = null,
        ?int $columnSpan = null): array {
        $this->access->requireRole($spaceId, 'manager');
        self::entity($entityKind);
        $old = $this->byId($spaceId, $entityKind, $fieldId);
        if ($old === null) { throw new DomainException('custom_field_not_found'); }
        $visible ??= $old['visible'];
        $help ??= $old['help'];
        $sortOrder ??= $old['sortOrder'];
        $columnSpan ??= $old['columnSpan'];
        $label = trim($label);
        self::metadata($old['key'], $label, $old['type'], $required, $visible,
            $help, $sortOrder, $columnSpan);
        $this->db->beginTransaction();
        try {
            $this->write->update('lf_custom_fields', [
                'label' => $label, 'required' => $required, 'active' => $active,
                'visible' => $visible, 'help_text' => $help !== '' ? $help : null,
                'sort_order' => $sortOrder, 'column_span' => $columnSpan,
                'updated_at' => time(),
            ], ['space_id' => $spaceId, 'entity_kind' => $entityKind, 'id' => $fieldId]);
            $this->audit($spaceId, $fieldId, 'custom_field.updated');
            $this->db->commit();
            return [...$old, 'label' => $label, 'required' => $required,
                'active' => $active, 'visible' => $visible, 'help' => $help,
                'sortOrder' => $sortOrder, 'columnSpan' => $columnSpan];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** @param array<string,mixed> $submitted
     * @return array<int,string>
     */
    public function validateValues(int $spaceId, array $submitted, bool $isNew,
        int $entityId = 0, string $entityKind = 'company'): array {
        self::entity($entityKind);
        $role = $this->access->requireRole($spaceId, 'operator');
        if (count($submitted) > 30) { throw new DomainException('custom_field_limit'); }
        $fields = $this->definitions($spaceId, $entityKind);
        $mapped = [];
        foreach ($fields as $field) { $mapped[$field['id']] = $field; }
        $result = [];
        foreach ($submitted as $key => $value) {
            if (!ctype_digit((string)$key) || !isset($mapped[(int)$key]) ||
                !$mapped[(int)$key]['active']) {
                throw new DomainException('custom_field_not_found');
            }
            $id = (int)$key;
            if (!$mapped[$id]['visible'] && AccessService::rank($role) < AccessService::rank('manager')) {
                throw new DomainException('permission_denied');
            }
            $result[$id] = json_encode(self::validateOne($mapped[$id], $value), JSON_THROW_ON_ERROR);
        }
        $existing = $isNew ? [] : ($this->valuesForEntities($spaceId, $entityKind, [$entityId], true)[$entityId] ?? []);
        foreach ($fields as $field) {
            if (!$field['active'] || !$field['required']) { continue; }
            $id = $field['id'];
            if (array_key_exists($id, $result)) {
                if (json_decode($result[$id], true) === null) { throw new DomainException('required_custom_field'); }
            } elseif (!array_key_exists((string)$id, $existing) || $existing[(string)$id] === null) {
                throw new DomainException('required_custom_field');
            }
        }
        return $result;
    }

    /** Called inside the entity's existing database transaction. */
    public function saveValues(int $spaceId, int $entityId, array $validated,
        string $entityKind = 'company'): void {
        self::entity($entityKind);
        foreach ($validated as $fieldId => $json) {
            $q = $this->db->getQueryBuilder();
            $q->select('id')->from('lf_custom_values')
                ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('entity_kind', $q->createNamedParameter($entityKind)))
                ->andWhere($q->expr()->eq('entity_id', $q->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('field_id', $q->createNamedParameter((int)$fieldId, IQueryBuilder::PARAM_INT)));
            $exists = $q->executeQuery()->fetchOne() !== false;
            $ident = ['space_id' => $spaceId, 'entity_kind' => $entityKind,
                'entity_id' => $entityId, 'field_id' => (int)$fieldId];
            if ($exists) {
                $this->write->update('lf_custom_values', ['value_json' => $json, 'updated_at' => time()], $ident);
            } else {
                $this->write->insert('lf_custom_values', $ident + ['value_json' => $json, 'updated_at' => time()]);
            }
            $this->audit($spaceId, $entityId, 'custom_field.value_saved');
        }
    }

    /** @param list<int> $entityIds
     * @return array<int,array<string,mixed>>
     */
    public function valuesForEntities(int $spaceId, string $entityKind,
        array $entityIds, bool $all = false): array {
        self::entity($entityKind);
        $role = $this->access->requireRole($spaceId, 'viewer');
        if ($entityIds === []) { return []; }
        $fields = $this->definitions($spaceId, $entityKind);
        $allowed = [];
        foreach ($fields as $field) {
            if ($all || (AccessService::rank($role) >= AccessService::rank('manager')
                || ($field['visible'] && $field['active']))) {
                $allowed[$field['id']] = true;
            }
        }
        if ($allowed === []) { return []; }
        $q = $this->db->getQueryBuilder();
        $q->select('entity_id', 'field_id', 'value_json')->from('lf_custom_values')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('entity_kind', $q->createNamedParameter($entityKind)))
            ->andWhere($q->expr()->in('entity_id', $q->createNamedParameter($entityIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($q->expr()->in('field_id', $q->createNamedParameter(array_keys($allowed), IQueryBuilder::PARAM_INT_ARRAY)));
        $result = [];
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $result[(int)$row['entity_id']][(string)$row['field_id']] = json_decode($row['value_json'], true);
        }
        return $result;
    }

    public function valuesForCompanies(int $spaceId, array $companyIds): array {
        return $this->valuesForEntities($spaceId, 'company', $companyIds);
    }

    private function byId(int $spaceId, string $entityKind, int $fieldId): ?array {
        foreach ($this->definitions($spaceId, $entityKind) as $field) {
            if ($field['id'] === $fieldId) return $field;
        }
        return null;
    }

    private static function entity(string $entityKind): void {
        if (!in_array($entityKind, self::ENTITIES, true)) {
            throw new DomainException('invalid_custom_field_entity');
        }
    }

    private static function metadata(string $key, string $label, string $type,
        bool $required, bool $visible, ?string $help, int $sortOrder, int $columnSpan): void {
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $key)
            || $label === '' || mb_strlen($label) > 100
            || !in_array($type, self::TYPES, true)
            || ($required && !$visible)
            || ($help !== null && mb_strlen(trim($help)) > 255)
            || $sortOrder < 0 || $sortOrder > 9999
            || !in_array($columnSpan, [6, 12], true)) {
            throw new DomainException('invalid_custom_field');
        }
    }

    private static function validOptions(string $type, array $options): array {
        if ($type !== 'select') {
            if ($options !== []) { throw new DomainException('invalid_custom_field'); }
            return [];
        }
        if ($options === [] || count($options) > 15) { throw new DomainException('invalid_custom_field'); }
        $clean = [];
        foreach ($options as $option) {
            if (!is_string($option) || trim($option) === '' || mb_strlen(trim($option)) > 80) {
                throw new DomainException('invalid_custom_field');
            }
            $clean[] = trim($option);
        }
        if (count(array_unique($clean)) !== count($clean)) {
            throw new DomainException('invalid_custom_field');
        }
        return $clean;
    }

    private static function validateOne(array $definition, mixed $value): mixed {
        if ($value === null || $value === '') { return null; }
        switch ($definition['type']) {
            case 'text':
                if (is_string($value) && mb_strlen(trim($value)) <= 500) return trim($value);
                break;
            case 'textarea':
                if (is_string($value) && mb_strlen(trim($value)) <= 2000) return trim($value);
                break;
            case 'email':
                if (is_string($value) && mb_strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL)) return $value;
                break;
            case 'url':
                if (is_string($value) && strlen($value) <= 400 && preg_match('~^https?://~i', $value)
                    && filter_var($value, FILTER_VALIDATE_URL)) return $value;
                break;
            case 'integer':
                if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,11})$/D', $value)) return $value;
                break;
            case 'number':
                if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,4})?$/D', $value)) return $value;
                break;
            case 'date':
                if (is_string($value) && preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/D', $value, $m)
                    && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $value;
                break;
            case 'boolean':
                if (is_bool($value)) return $value;
                break;
            case 'select':
                if (is_string($value) && in_array($value, $definition['options'], true)) return $value;
                break;
        }
        throw new DomainException('invalid_custom_field_value');
    }

    private function audit(int $spaceId, int $entityId, string $action): void {
        $this->write->insert('lf_audit', ['space_id' => $spaceId,
            'actor_uid' => $this->access->uid(), 'action' => $action,
            'entity' => 'custom_field', 'entity_id' => $entityId,
            'occurred_at' => time()]);
    }
}
