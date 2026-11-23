<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\LibreFinance\Settings;

use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;

final class Admin implements ISettings {
    public function getForm(): TemplateResponse {
        Util::addScript('librefinance', 'admin');
        Util::addStyle('librefinance', 'admin');
        return new TemplateResponse('librefinance', 'admin');
    }

    public function getSection(): string { return 'librefinance'; }
    public function getPriority(): int { return 50; }
}
