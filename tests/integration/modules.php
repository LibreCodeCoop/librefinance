<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/base.php';
require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$report = 'librefinance_reports';
$space = $t->check('create finance module space', $a, 'POST', '/spaces',
    ['name' => 'Synthetic plugin integration', 'kind' => 'collective', 'currency' => 'BRL'], 201);
$id = (int)$space['id'];
$path = '/spaces/' . $id;
$assertReport = static fn(array $v): bool => count(array_filter($v['modules'] ?? [],
    static fn(array $m): bool => $m['id'] === $report && $m['enabled'] === true)) === 1;
$t->check('built-in reports discovered by core', $a, 'GET', $path . '/modules', null, 200, $assertReport);
$t->check('unrelated user cannot list modules', $b, 'GET', $path . '/modules', null, 404);

// Legacy module page URLs must redirect to the ONE canonical LibreFinance UI.
// No extension renders a second app header, even when embed=1 is requested.
// Redirect is after backend space and module authorization.
$fetchPage = static function(string $appId, string $user) use ($id): array {
    $url = 'http://127.0.0.1/index.php/apps/' . $appId . '/spaces/' . $id . '?embed=1';
    $h = curl_init($url);
    $location = '';
    curl_setopt_array($h, [
        CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, strlen('Location:')));
            }
            return strlen($line);
        },
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['OCS-APIREQUEST: true'],
        CURLOPT_USERPWD => $user . ':' . getenv('LF_TEST_USER_PASS'),
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
    ]);
    $html = curl_exec($h);
    $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    return [
        'code' => $code,
        'location' => $location,
        'html' => is_string($html) ? $html : '',
    ];
};
$appsWithPages = [
    'librefinance_nfse' => 'nfse',
    'librefinance_import' => 'import',
];
foreach ($appsWithPages as $appId => $view) {
    $permitted = $fetchPage($appId, $a);
    $t->record($appId . ' redirects to canonical authorized finance workspace',
        $permitted['code'] === 303
            && str_contains($permitted['location'], '/apps/librefinance/')
            && str_contains($permitted['location'], 'spaceId=' . $id)
            && str_contains($permitted['location'], 'view=' . $view)
            && !str_contains($permitted['html'], 'id="header"'),
        'HTTP ' . $permitted['code']);
    $denied = $fetchPage($appId, $b);
    $t->record($appId . ' denies unrelated users without a PHP type error',
        $denied['code'] === 404,
        'HTTP ' . $denied['code']);
}

$t->checkModule('read empty profit and loss', $a, $report, 'GET',
    $path . '/profit-loss?from=2026-01-01&through=2026-12-31', null, 200,
    static fn(array $v): bool => ($v['revenueMinor'] ?? null) === 0 && ($v['expenseMinor'] ?? null) === 0);
$t->checkModule('B denied profit and loss of A', $b, $report, 'GET',
    $path . '/profit-loss?from=2026-01-01&through=2026-12-31', null, 404);
$t->check('B denied changing module configuration', $b, 'PUT',
    $path . '/modules/' . $report, ['enabled' => false], 404);
$t->check('owner disables module only in space', $a, 'PUT',
    $path . '/modules/' . $report, ['enabled' => false], 200,
    static fn(array $v): bool => ($v['enabled'] ?? null) === false);
$t->check('core reports module disabled', $a, 'GET', $path . '/modules', null, 200,
    static fn(array $v): bool => count(array_filter($v['modules'] ?? [],
        static fn(array $m): bool => $m['id'] === $report && $m['enabled'] === false)) === 1);
$t->checkModule('disabled module blocks data access', $a, $report, 'GET',
    $path . '/profit-loss?from=2026-01-01&through=2026-12-31', null, 403,
    static fn(array $v): bool => ($v['error'] ?? null) === 'module_disabled');
$t->check('owner re-enables installed module', $a, 'PUT',
    $path . '/modules/' . $report, ['enabled' => true], 200,
    static fn(array $v): bool => ($v['enabled'] ?? null) === true);
$t->checkModule('re-enabled extension is available', $a, $report, 'GET',
    $path . '/trial-balance?through=2026-12-31', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 4);
$t->checkModule('invalid date rejected', $a, $report, 'GET',
    $path . '/profit-loss?from=2026-02-30&through=2026-12-31', null, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_date');
$t->checkModule('inverted period rejected', $a, $report, 'GET',
    $path . '/profit-loss?from=2026-12-31&through=2026-01-01', null, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_period');
$t->check('grant viewer for reports', $a, 'POST', $path . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
// Regression: the descriptor role is a server-side floor, not only a menu filter.
// Exercise the public module service with the same synthetic viewer and the
// installed manager-only import module. Do not add a test-only HTTP endpoint.
\OC_App::loadApp('librefinance');
\OC_App::loadApp('librefinance_import');
$testUser = \OC::$server->get(\OCP\IUserManager::class)->get($b);
if ($testUser === null) {
    throw new \RuntimeException('Synthetic viewer account not found');
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($testUser);
$moduleService = \OC::$server->get(\OCA\LibreFinance\Service\ModuleService::class);
$deniedManagerModule = false;
try {
    $moduleService->requireEnabled($id, 'librefinance_import', 'viewer');
} catch (\DomainException $e) {
    $deniedManagerModule = $e->getMessage() === 'permission_denied';
}
$t->record('viewer cannot use manager-only module through generic API gate',
    $deniedManagerModule, $deniedManagerModule ? 'permission_denied' : 'not denied');
$moduleService->requireEnabled($id, $report, 'viewer');
$t->record('viewer retains access to viewer-level module', true, 'authorized');
$deniedElevatedOperation = false;
try {
    $moduleService->requireEnabled($id, $report, 'manager');
} catch (\DomainException $e) {
    $deniedElevatedOperation = $e->getMessage() === 'permission_denied';
}
$t->record('viewer cannot use manager operation of viewer-level module',
    $deniedElevatedOperation, $deniedElevatedOperation ? 'permission_denied' : 'not denied');
$ownerUser = \OC::$server->get(\OCP\IUserManager::class)->get($a);
if ($ownerUser === null) {
    throw new \RuntimeException('Synthetic owner account not found');
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($ownerUser);
$moduleService->requireEnabled($id, 'librefinance_import', 'viewer');
$t->record('owner retains access to manager-only module', true, 'authorized');
$t->checkModule('viewer can inspect reports', $b, $report, 'GET',
    $path . '/trial-balance?through=2026-12-31', null, 200);
$t->check('viewer cannot turn module off', $b, 'PUT',
    $path . '/modules/' . $report, ['enabled' => false], 403,
    static fn(array $v): bool => ($v['error'] ?? null) === 'permission_denied');
$t->check('revoke viewer', $a, 'DELETE', $path . '/members/' . $b, null, 200);
$t->checkModule('revoked viewer denied reports', $b, $report, 'GET',
    $path . '/trial-balance?through=2026-12-31', null, 404);
$t->finish();
