<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Service;

use DomainException;
use OCA\LibreFinance\PublicAPI\RegisterModulesEvent;
use OCA\LibreFinance\PublicAPI\ModuleDescriptor;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IDBConnection;

final class ModuleService {
    public function __construct(
        private AccessService $access,
        private IEventDispatcher $events,
        private IAppManager $apps,
        private IUserSession $session,
        private IURLGenerator $urls,
        private IDBConnection $db,
        private WriteService $write,
    ) {}

    /** @return array<string,ModuleDescriptor> */
    private function discover(): array {
        $event = new RegisterModulesEvent();
        $this->events->dispatchTyped($event);
        $user = $this->session->getUser();
        $enabled = $user !== null ? $this->apps->getEnabledAppsForUser($user) : [];
        $internal = [
            'librefinance_banking' => new ModuleDescriptor(
                'librefinance_banking', 'Banking and reconciliation',
                'Bank statements, transfers and reconciliation',
                'librefinance.page.index'),
            'librefinance_obligations' => new ModuleDescriptor(
                'librefinance_obligations', 'Payables and receivables',
                'Bills, obligations, due dates and settlements',
                'librefinance.page.index'),
            'librefinance_invoicing' => new ModuleDescriptor(
                'librefinance_invoicing', 'Sales, purchases and taxes',
                'Commercial invoices and jurisdiction-specific tax rules',
                'librefinance.page.index'),
            'librefinance_reports' => new ModuleDescriptor(
                'librefinance_reports', 'Financial reports',
                'Profit and loss, cash flow, trial balance and saved reports',
                'librefinance.page.index'),
            'librefinance_import' => new ModuleDescriptor(
                'librefinance_import', 'Data import and migration',
                'Approved imports, source identity mapping and reconciliation',
                'librefinance.page.index', 'manager'),
        ];
        // A legacy optional app might still be enabled during a developer
        // upgrade. It must never shadow a built-in domain or its permissions.
        $external = array_filter($event->modules(), static fn(ModuleDescriptor $d): bool =>
            in_array($d->appId, $enabled, true) && !isset($internal[$d->appId]));
        return $internal + $external;
    }

    /** @return array<string,mixed> */
    public function listForSpace(int $spaceId): array {
        $role = $this->access->requireRole($spaceId, 'viewer');
        $modules = [];
        foreach ($this->discover() as $id => $module) {
            if (AccessService::rank($role) < AccessService::rank($module->requiredRole)) {
                continue;
            }
            $modules[] = [
                'id' => $id,
                'name' => $module->name,
                'description' => $module->description,
                'requiredRole' => $module->requiredRole,
                'enabled' => $this->enabledInSpace($spaceId, $id),
                'url' => $this->urls->linkToRoute($module->routeName, ['spaceId' => $spaceId]),
            ];
        }
        return ['modules' => $modules];
    }

    public function requireEnabled(int $spaceId, string $moduleId, string $role = 'viewer'): void {
        // Enforce both the endpoint role and the module's registered minimum.
        // Hiding a module from the UI is never a substitute for PHP authorization.
        $actualRole = $this->access->requireRole($spaceId, $role);
        $module = $this->discover()[$moduleId] ?? null;
        if ($module === null) {
            throw new DomainException('module_not_found');
        }
        if (AccessService::rank($actualRole) < AccessService::rank($module->requiredRole)) {
            throw new DomainException('permission_denied');
        }
        if (!$this->enabledInSpace($spaceId, $moduleId)) {
            throw new DomainException('module_disabled');
        }
    }

    public function toggle(int $spaceId, string $id, bool $enabled): array {
        $this->access->requireRole($spaceId, 'manager');
        if (!isset($this->discover()[$id])) {
            throw new DomainException('module_not_found');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('lf_module_flags')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('module_id', $q->createNamedParameter($id)));
        $existing = $q->executeQuery()->fetchOne();
        if ($existing === false) {
            $this->write->insert('lf_module_flags', [
                'space_id' => $spaceId, 'module_id' => $id, 'enabled' => $enabled ? 1 : 0,
            ]);
        } else {
            $this->write->update('lf_module_flags',
                ['enabled' => $enabled ? 1 : 0], ['space_id' => $spaceId, 'module_id' => $id]);
        }
        return ['id' => $id, 'enabled' => $enabled];
    }

    private function enabledInSpace(int $spaceId, string $id): bool {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'enabled')->from('lf_module_flags')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('module_id', $q->createNamedParameter($id)));
        $found = $q->executeQuery()->fetchAssociative();
        return $found === false || (bool)$found['enabled'];
    }
}
