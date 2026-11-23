<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Settings;

use OCP\Settings\IIconSection;
use OCP\IURLGenerator;

final class AdminSection implements IIconSection {
    public function __construct(private IURLGenerator $urls) {}

    public function getID(): string { return 'librefinance'; }
    public function getName(): string { return 'LibreFinance'; }
    public function getPriority(): int { return 70; }
    public function getIcon(): string { return $this->urls->imagePath('librefinance', 'app.svg'); }
}
