<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\LibreFinance\Service;

use DomainException;
use OCP\IConfig;
use OCP\IUserSession;

/** Native, per-user, per-finance-space layout with a closed widget registry. */
final class DashboardLayoutService {
    private const APP = 'librefinance';
    private const IDS = ['summary', 'cashflow', 'bankAccounts', 'recentActivity', 'quickActions'];

    public function __construct(private AccessService $access, private IConfig $config,
        private IUserSession $session) {}

    /** @return list<array{id:string,width:int,visible:bool}> */
    public static function defaults(): array {
        return [
            ['id' => 'summary', 'width' => 2, 'visible' => true],
            ['id' => 'cashflow', 'width' => 1, 'visible' => true],
            ['id' => 'bankAccounts', 'width' => 1, 'visible' => true],
            ['id' => 'recentActivity', 'width' => 1, 'visible' => true],
            ['id' => 'quickActions', 'width' => 1, 'visible' => true],
        ];
    }

    /** @return array<string,mixed> */
    public function get(int $spaceId): array {
        $this->access->requireRole($spaceId, 'viewer');
        $value = $this->config->getUserValue($this->uid(), self::APP, self::key($spaceId), '');
        if ($value === '') return ['widgets' => self::defaults()];
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            self::validate($decoded);
            return ['widgets' => $decoded];
        } catch (\Throwable) {
            // An incompatible persisted layout cannot cause a dashboard outage.
            return ['widgets' => self::defaults()];
        }
    }

    /** @param list<mixed> $widgets
     *  @return array<string,mixed>
     */
    public function save(int $spaceId, array $widgets): array {
        $this->access->requireRole($spaceId, 'viewer');
        self::validate($widgets);
        $this->config->setUserValue($this->uid(), self::APP, self::key($spaceId),
            json_encode($widgets, JSON_THROW_ON_ERROR));
        return ['widgets' => $widgets];
    }

    /** @return array<string,mixed> */
    public function reset(int $spaceId): array {
        $this->access->requireRole($spaceId, 'viewer');
        $this->config->deleteUserValue($this->uid(), self::APP, self::key($spaceId));
        return ['widgets' => self::defaults()];
    }

    private static function key(int $spaceId): string {
        return 'dashboard_layout_v1_' . $spaceId;
    }

    private function uid(): string {
        $uid = $this->session->getUser()?->getUID();
        if (!$uid) throw new DomainException('authentication_required');
        return $uid;
    }

    private static function validate(mixed $widgets): void {
        if (!is_array($widgets) || !array_is_list($widgets) || count($widgets) !== count(self::IDS)) {
            throw new DomainException('invalid_dashboard_layout');
        }
        $seen = [];
        $anyVisible = false;
        foreach ($widgets as $item) {
            if (!is_array($item) || count($item) !== 3
                || !isset($item['id'], $item['width'], $item['visible'])
                || !is_string($item['id']) || !in_array($item['id'], self::IDS, true)
                || isset($seen[$item['id']])
                || !is_int($item['width']) || !in_array($item['width'], [1,2], true)
                || !is_bool($item['visible'])) {
                throw new DomainException('invalid_dashboard_layout');
            }
            $seen[$item['id']] = true;
            $anyVisible = $anyVisible || $item['visible'];
        }
        if (!$anyVisible) throw new DomainException('invalid_dashboard_layout');
    }
}
