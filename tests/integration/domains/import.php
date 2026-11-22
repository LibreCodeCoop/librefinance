<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$app = 'librefinance';
$tag = bin2hex(random_bytes(5));
$workspace = $t->check('create isolated migration workspace', $a, 'POST', '/spaces', [
    'name' => 'Synthetic migration evaluation', 'kind' => 'organization', 'currency' => 'BRL',
], 201);
$spaceId = (int)$workspace['id'];
$base = '/spaces/' . $spaceId;

$t->check('discover core migration engine for a manager', $a, 'GET',
    $base . '/modules', null, 200, static fn(array $v): bool =>
        count(array_filter($v['modules'] ?? [], static fn(array $m): bool =>
            $m['id'] === 'librefinance_import')) === 1);

$fixture = [
    'format' => 'librefinance.normalized.v1',
    'source' => ['system' => 'generic', 'companyId' => 'synthetic-' . $tag],
    'contacts' => [
        ['sourceId' => 'contact-13', 'name' => 'Synthetic migration customer', 'kind' => 'customer',
            'email' => 'example-' . $tag . '@example.invalid', 'taxId' => null],
    ],
    'categories' => [
        // Reversed order proves topological resolution of a child before its parent.
        ['sourceId' => 'category-22', 'code' => 'CHILD_' . $tag,
            'name' => 'Synthetic child', 'kind' => 'income',
            'parentSourceId' => 'category-21', 'accountCode' => null],
        ['sourceId' => 'category-21', 'code' => 'PARENT_' . $tag,
            'name' => 'Synthetic parent', 'kind' => 'income',
            'parentSourceId' => null, 'accountCode' => '4000'],
    ],
];
$json = json_encode($fixture, JSON_THROW_ON_ERROR);
$request = ['exportJson' => $json];
$t->checkModule('another user cannot preview migration', $b, $app, 'POST',
    $base . '/preview', $request, 404);
$preview = $t->checkModule('read-only preview with independent source identity',
    $a, $app, 'POST', $base . '/preview', $request, 200, static fn(array $v): bool =>
        ($v['ready'] ?? false) === true
        && ($v['counts']['new'] ?? null) === 3
        && ($v['counts']['conflict'] ?? null) === 0
        && !array_key_exists('_account_map', $v));
$t->check('preview left contacts unmodified', $a, 'GET', $base . '/contacts',
    null, 200, static fn(array $v): bool => $v['contacts'] === []);
$t->check('preview left categories unmodified', $a, 'GET', $base . '/categories',
    null, 200, static fn(array $v): bool => $v['categories'] === []);

$t->checkModule('cannot import without explicit confirmation',
    $a, $app, 'POST', $base . '/import',
    $request + ['previewHash' => $preview['previewHash'], 'confirmed' => false], 422);
$t->checkModule('changed preview hash blocks import',
    $a, $app, 'POST', $base . '/import',
    $request + ['previewHash' => str_repeat('0', 64), 'confirmed' => true], 409);
$import = $t->checkModule('atomic import of contact and parent-child categories',
    $a, $app, 'POST', $base . '/import',
    $request + ['previewHash' => $preview['previewHash'], 'confirmed' => true], 200,
    static fn(array $v): bool =>
        ($v['created'] ?? null) === 3 && ($v['skipped'] ?? null) === 0
        && ($v['complete'] ?? false) === true);
$cats = $t->check('imported category hierarchy and inherited account',
    $a, 'GET', $base . '/categories', null, 200, static function(array $v): bool {
        $categories = $v['categories'] ?? [];
        if (count($categories) !== 2) { return false; }
        $byCode = [];
        foreach ($categories as $cat) { $byCode[$cat['code']] = $cat; }
        if (count($byCode) !== 2) { return false; }
        $parent = current(array_filter($categories,
            static fn(array $cat): bool => $cat['parent_id'] === null));
        $child = current(array_filter($categories,
            static fn(array $cat): bool => $cat['parent_id'] !== null));
        return is_array($parent) && is_array($child)
            && $child['parent_id'] === $parent['id']
            && $child['effective_account_id'] === $parent['effective_account_id'];
    });
$t->check('exactly one contact imported',
    $a, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => count($v['contacts'] ?? []) === 1);

$t->checkModule('repeated identical batch never duplicates target records',
    $a, $app, 'POST', $base . '/import',
    $request + ['previewHash' => $preview['previewHash'], 'confirmed' => true], 200,
    static fn(array $v): bool => ($v['created'] ?? null) === 0
        && ($v['skipped'] ?? null) === 3);
$t->checkModule('replaying same source with changed fields is a visible conflict',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode(array_replace($fixture,
        ['contacts' => [array_replace($fixture['contacts'][0],
            ['name' => 'Changed after import'])]]), JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && ($v['counts']['conflict'] ?? null) === 1);

$wrongAccount = $fixture;
$wrongAccount['categories'][1]['accountCode'] = '5000';
$wrongJson = json_encode($wrongAccount, JSON_THROW_ON_ERROR);
$t->checkModule('preview rejects incompatible account class',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => $wrongJson], 200,
    static fn(array $v): bool => $v['ready'] === false
        && count($v['issues'] ?? []) >= 1);
$cycle = $fixture;
$cycle['source']['companyId'] = 'cycle-' . $tag;
$cycle['categories'][1]['parentSourceId'] = 'category-22';
$cycleJson = json_encode($cycle, JSON_THROW_ON_ERROR);
$cyclePreview = $t->checkModule('preview detects cyclic category inheritance',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => $cycleJson], 200,
    static fn(array $v): bool => $v['ready'] === false
        && count(array_filter($v['issues'] ?? [], static fn(array $i): bool =>
            $i['reason'] === 'parent_cycle')) >= 1);
$t->checkModule('cyclic batch cannot write any contact or category',
    $a, $app, 'POST', $base . '/import',
    ['exportJson' => $cycleJson, 'previewHash' => $cyclePreview['previewHash'],
        'confirmed' => true], 409);

$dup = $fixture;
$dup['contacts'][] = $dup['contacts'][0];
$t->checkModule('duplicated source record ID rejected',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($dup, JSON_THROW_ON_ERROR)], 422);
$t->checkModule('invalid JSON denied',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => '{not json}'], 422);
$t->checkModule('oversized import blocked',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => str_repeat('x', 131073)], 422);

$otherSource = $fixture;
$otherSource['source']['companyId'] = 'another-company-' . $tag;
$otherSource['categories'] = [];
$otherJson = json_encode($otherSource, JSON_THROW_ON_ERROR);
$otherPreview = $t->checkModule('same source record ID in different company is independent',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => $otherJson], 200,
    static fn(array $v): bool => $v['ready'] && $v['counts']['new'] === 1);
$t->checkModule('second source company contact imported separately',
    $a, $app, 'POST', $base . '/import',
    ['exportJson' => $otherJson, 'previewHash' => $otherPreview['previewHash'],
        'confirmed' => true], 200,
    static fn(array $v): bool => $v['created'] === 1);

$foreignCategory = $fixture;
$foreignCategory['source']['companyId'] = 'clash-' . $tag;
$foreignJson = json_encode($foreignCategory, JSON_THROW_ON_ERROR);
$foreignPreview = $t->checkModule('preview flags code already used by another source',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => $foreignJson], 200,
    static fn(array $v): bool => $v['ready'] === false
        && count(array_filter($v['issues'], static fn(array $i): bool =>
            $i['reason'] === 'category_code_in_use')) >= 1);
$t->checkModule('conflicting category source cannot write contact first',
    $a, $app, 'POST', $base . '/import',
    ['exportJson' => $foreignJson, 'previewHash' => $foreignPreview['previewHash'],
        'confirmed' => true], 409);
$t->check('refused import left source company contacts untouched',
    $a, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => count($v['contacts'] ?? []) === 2);

$companyFixture = [
    'format' => 'librefinance.normalized.v1',
    'source' => ['system' => 'generic', 'companyId' => 'org-' . $tag],
    'company' => ['sourceId' => 'org-' . $tag,
        'legalName' => 'Synthetic Imported Cooperative', 'country' => 'BR',
        'taxId' => '12345678000190', 'tradeName' => 'Synthetic Trading',
        'municipalRegistration' => 'SYN-1', 'stateRegistration' => null],
    'contacts' => [], 'categories' => [],
];
$companyJson = json_encode($companyFixture, JSON_THROW_ON_ERROR);
$coveragePath = $base . '/coverage?sourceCompanyId=org-' . $tag;
$t->checkModule('coverage does not claim source completeness before import',
    $a, $app, 'GET', $coveragePath, null, 200,
    static fn(array $v): bool => ($v['kinds']['company']['mapped'] ?? null) === 0
        && ($v['completeSourceMigration'] ?? true) === false
        && ($v['sourceInventoryVerified'] ?? true) === false);
$t->checkModule('other user denied company coverage', $b, $app, 'GET',
    $coveragePath, null, 404);
$companyPreview = $t->checkModule('preview legal entity before any mutation',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => $companyJson], 200,
    static fn(array $v): bool => $v['ready'] === true
        && $v['counts']['new'] === 1 && $v['total'] === 1);
$t->check('company preview leaves destination unchanged', $a,
    'GET', $base . '/companies', null, 200,
    static fn(array $v): bool => $v['companies'] === []);
$companyImport = ['exportJson' => $companyJson,
    'previewHash' => $companyPreview['previewHash'], 'confirmed' => true];
$t->checkModule('atomically import legal entity and durable source mapping',
    $a, $app, 'POST', $base . '/import', $companyImport, 200,
    static fn(array $v): bool => $v['created'] === 1 && $v['total'] === 1);
$t->check('legal entity actually exists in destination', $a,
    'GET', $base . '/companies', null, 200,
    static fn(array $v): bool => count($v['companies'] ?? []) === 1
        && $v['companies'][0]['legal_name'] === 'Synthetic Imported Cooperative');
$t->checkModule('coverage counts actual mapped and present legal entities',
    $a, $app, 'GET', $coveragePath, null, 200,
    static fn(array $v): bool => $v['kinds']['company']['mapped'] === 1
        && $v['kinds']['company']['targetPresent'] === 1
        && $v['kinds']['company']['missingTarget'] === 0
        && $v['kinds']['contact']['mapped'] === 0
        && $v['completeSourceMigration'] === false);
$t->checkModule('identical company import replay does not duplicate',
    $a, $app, 'POST', $base . '/import', $companyImport, 200,
    static fn(array $v): bool => $v['created'] === 0 && $v['skipped'] === 1);
$t->checkModule('changed legal company source is a visible conflict',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode(array_replace($companyFixture,
        ['company' => array_replace($companyFixture['company'],
            ['legalName' => 'Changed Name'])]), JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && ($v['counts']['conflict'] ?? null) === 1);
$t->checkModule('source-company mismatch rejected before import',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode(array_replace($companyFixture,
        ['company' => array_replace($companyFixture['company'],
            ['sourceId' => 'another-company'])]), JSON_THROW_ON_ERROR)], 422);
$t->checkModule('invalid legal identifier rejected before import',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode(array_replace($companyFixture,
        ['company' => array_replace($companyFixture['company'],
            ['taxId' => 'INVALID'])]), JSON_THROW_ON_ERROR)], 422);
$otherCompanyFixture = array_replace($companyFixture, [
    'source' => ['system' => 'generic', 'companyId' => 'other-' . $tag],
    'company' => array_replace($companyFixture['company'],
        ['sourceId' => 'other-' . $tag]),
]);
$t->checkModule('preview rejects tax ID belonging to a different source',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($otherCompanyFixture, JSON_THROW_ON_ERROR)],
    200, static fn(array $v): bool => $v['ready'] === false
        && count(array_filter($v['issues'],
            static fn(array $issue): bool => $issue['reason'] === 'company_tax_id_in_use')) === 1);
$t->checkModule('coverage invalid company identity refused', $a, $app,
    'GET', $base . '/coverage?sourceCompanyId=invalid%20id', null, 422);
$bankSource = 'bank-' . $tag;
$bankFixture = [
    'format' => 'librefinance.normalized.v1',
    'source' => ['system' => 'generic', 'companyId' => $bankSource],
    'contacts' => [], 'categories' => [],
    'accounts' => [
        ['sourceId' => 'bank-101', 'code' => 'BANK_' . $tag,
            'name' => 'Synthetic checking', 'kind' => 'bank', 'currency' => 'BRL',
            'openingBalanceMinor' => 12345, 'currentBalanceMinor' => 23456,
            'balanceAsOf' => '2026-11-20', 'bankName' => 'Synthetic Bank',
            'number' => '00001234-56', 'enabled' => true],
        ['sourceId' => 'cash-102', 'code' => 'CASH_' . $tag,
            'name' => 'Synthetic disabled cash', 'kind' => 'cash',
            'currency' => 'BRL', 'openingBalanceMinor' => 0,
            'currentBalanceMinor' => -150, 'balanceAsOf' => '2026-11-20',
            'bankName' => null, 'number' => null, 'enabled' => false],
    ],
];
$bankJson = json_encode($bankFixture, JSON_THROW_ON_ERROR);
$bankPath = $base . '/source-accounts?sourceCompanyId=' . $bankSource;
$bankCoveragePath = $base . '/coverage?sourceCompanyId=' . $bankSource;
$t->checkModule('no bank mappings before bank import', $a, $app, 'GET',
    $bankCoveragePath, null, 200,
    static fn(array $v): bool =>
        $v['kinds']['account']['mapped'] === 0
        && $v['kinds']['account']['snapshotsPresent'] === 0);
$t->checkModule('no source bank snapshots before import', $a, $app, 'GET',
    $bankPath, null, 200,
    static fn(array $v): bool => $v['accounts'] === []
        && $v['nextAfterId'] === null);
$t->checkModule('unrelated user cannot discover source bank balances',
    $b, $app, 'GET', $bankPath, null, 404);
$bankPreview = $t->checkModule('bank and cash import preview never books a balance',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => $bankJson], 200,
    static fn(array $v): bool => $v['ready'] === true
        && $v['counts']['new'] === 2
        && $v['total'] === 2);
$t->check('bank preview leaves destination chart unchanged', $a,
    'GET', $base . '/accounts', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 4);
$bankImport = ['exportJson' => $bankJson,
    'previewHash' => $bankPreview['previewHash'], 'confirmed' => true];
$t->checkModule('bank and cash metadata imported without ledger posting',
    $a, $app, 'POST', $base . '/import', $bankImport, 200,
    static fn(array $v): bool => $v['created'] === 2
        && $v['total'] === 2 && $v['skipped'] === 0);
$t->check('bank and disabled cash accounts exist with zero posted balance',
    $a, 'GET', $base . '/accounts', null, 200,
    static function(array $v) use ($tag): bool {
        $accounts = [];
        foreach ($v['accounts'] ?? [] as $row) { $accounts[$row['code']] = $row; }
        $bank = $accounts['BANK_' . strtoupper($tag)] ?? null;
        $cash = $accounts['CASH_' . strtoupper($tag)] ?? null;
        return count($accounts) === 6
            && ($bank['is_bank'] ?? false) === true
            && ($bank['active'] ?? false) === true
            && ($bank['balance_minor'] ?? null) === 0
            && ($cash['is_bank'] ?? false) === true
            && ($cash['active'] ?? true) === false
            && ($cash['balance_minor'] ?? null) === 0;
    });
$t->checkModule('coverage counts bank mapping plus immutable snapshots',
    $a, $app, 'GET', $bankCoveragePath, null, 200,
    static fn(array $v): bool =>
        $v['kinds']['account']['mapped'] === 2
        && $v['kinds']['account']['targetPresent'] === 2
        && $v['kinds']['account']['snapshotsPresent'] === 2
        && $v['kinds']['account']['missingSnapshots'] === 0
        && $v['kinds']['account']['balanceReconciled'] === false
        && $v['completeSourceMigration'] === false);
$bankSnapshots = $t->checkModule('read bank snapshots with masked account number',
    $a, $app, 'GET', $bankPath, null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 2
        && $v['accounts'][0]['numberMasked'] === '****3456'
        && $v['accounts'][0]['openingBalanceMinor'] === 12345
        && $v['accounts'][0]['sourceCurrentBalanceMinor'] === 23456
        && $v['accounts'][0]['ledgerBalanceMinor'] === 0
        && $v['accounts'][0]['unreconciledDifferenceMinor'] === 23456
        && $v['accounts'][0]['balanceAsOf'] === '2026-11-20'
        && $v['accounts'][0]['balanceReconciled'] === false
        && $v['accounts'][1]['active'] === false
        && $v['accounts'][1]['sourceCurrentBalanceMinor'] === -150
        && $v['nextAfterId'] === null);
$t->record('account number is not disclosed by any snapshot JSON property',
    !str_contains(json_encode($bankSnapshots, JSON_THROW_ON_ERROR), '00001234-56'),
    'only masked account suffix returned');
$t->checkModule('bank cursor does not repeat earlier source account',
    $a, $app, 'GET', $bankPath . '&afterId=99999999', null, 200,
    static fn(array $v): bool => $v['accounts'] === []);
$t->checkModule('replay skips both bank accounts and does not repost money',
    $a, $app, 'POST', $base . '/import', $bankImport, 200,
    static fn(array $v): bool => $v['created'] === 0 && $v['skipped'] === 2);
$changedBank = $bankFixture;
$changedBank['accounts'][0]['currentBalanceMinor'] = 25000;
$t->checkModule('changing source balance requires reviewed new snapshot',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($changedBank, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && ($v['counts']['conflict'] ?? null) === 1
        && count(array_filter($v['issues'], static fn(array $issue): bool =>
            $issue['reason'] === 'source_record_changed')) === 1);
$bankWrongCurrency = $bankFixture;
$bankWrongCurrency['source']['companyId'] = 'fx-' . $tag;
$bankWrongCurrency['accounts'][0]['sourceId'] = 'foreign-103';
$bankWrongCurrency['accounts'][0]['code'] = 'FX_' . $tag;
$bankWrongCurrency['accounts'][0]['currency'] = 'USD';
$bankWrongCurrency['accounts'] = [$bankWrongCurrency['accounts'][0]];
$t->checkModule('foreign currency cannot silently turn into BRL bank balance',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($bankWrongCurrency, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['counts']['conflict'] === 1
        && $v['issues'][0]['reason'] === 'currency_mismatch');
$bankCollision = $bankFixture;
$bankCollision['source']['companyId'] = 'collision-' . $tag;
$bankCollision['accounts'] = [$bankCollision['accounts'][1]];
$t->checkModule('disabled bank-code collision is not silently reused',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($bankCollision, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'account_code_in_use');
$badAmount = $bankFixture;
$badAmount['accounts'][0]['currentBalanceMinor'] = 12.34;
$t->checkModule('fractional minor-unit amount rejected without rounding',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($badAmount, JSON_THROW_ON_ERROR)], 422);
$badAccountNumber = $bankFixture;
$badAccountNumber['accounts'][0]['number'] = "1234\nHeader: injected";
$t->checkModule('malformed source bank account identifier rejected',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($badAccountNumber, JSON_THROW_ON_ERROR)], 422);
$t->checkModule('bank snapshot invalid cursor cannot bypass authorization',
    $a, $app, 'GET', $bankPath . '&afterId=-1', null, 422);

$pageFixture = [
    'format' => 'librefinance.normalized.v1',
    'source' => ['system' => 'generic', 'companyId' => 'pages-' . $tag],
    'contacts' => [], 'categories' => [], 'accounts' => [],
];
for ($i = 1; $i <= 52; ++$i) {
    $pageFixture['accounts'][] = [
        'sourceId' => 'account-' . $i,
        'code' => 'P' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '_' . $tag,
        'name' => 'Synthetic paginated bank ' . $i,
        'kind' => 'bank', 'currency' => 'BRL',
        'openingBalanceMinor' => 0, 'currentBalanceMinor' => 0,
        'balanceAsOf' => '2026-11-20', 'bankName' => null,
        'number' => null, 'enabled' => true,
    ];
}
$pageJson = json_encode($pageFixture, JSON_THROW_ON_ERROR);
$pagePreview = $t->checkModule('52 bank source accounts preview without a silent 50-record cap',
    $a, $app, 'POST', $base . '/preview', ['exportJson' => $pageJson], 200,
    static fn(array $v): bool => $v['ready'] === true && $v['counts']['new'] === 52);
$t->checkModule('52 bank source accounts imported atomically',
    $a, $app, 'POST', $base . '/import', [
        'exportJson' => $pageJson,
        'previewHash' => $pagePreview['previewHash'], 'confirmed' => true,
    ], 200, static fn(array $v): bool => $v['created'] === 52);
$pagesUrl = $base . '/source-accounts?sourceCompanyId=pages-' . $tag
    . '&sourceSystem=generic';
$firstBankPage = $t->checkModule('bank snapshot first page exactly 50 with cursor',
    $a, $app, 'GET', $pagesUrl, null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 50
        && is_int($v['nextAfterId']) && $v['nextAfterId'] > 0);
$t->checkModule('bank snapshot second page returns last two without duplication',
    $a, $app, 'GET', $pagesUrl . '&afterId=' . $firstBankPage['nextAfterId'],
    null, 200, static fn(array $v): bool =>
        count($v['accounts'] ?? []) === 2
        && $v['accounts'][0]['sourceId'] === 'account-51'
        && $v['accounts'][1]['sourceId'] === 'account-52'
        && $v['nextAfterId'] === null);
$t->checkModule('coverage counts all 52, not just first page',
    $a, $app, 'GET',
    $base . '/coverage?sourceCompanyId=pages-' . $tag . '&sourceSystem=generic',
    null, 200, static fn(array $v): bool =>
        $v['kinds']['account']['mapped'] === 52
        && $v['kinds']['account']['snapshotsPresent'] === 52
        && $v['completeSourceMigration'] === false);
$tooManyBanks = $pageFixture;
while (count($tooManyBanks['accounts']) <= 100) {
    $i = count($tooManyBanks['accounts']) + 1;
    $more = $pageFixture['accounts'][0];
    $more['sourceId'] = 'account-' . $i;
    $more['code'] = 'P' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '_' . $tag;
    $tooManyBanks['accounts'][] = $more;
}
$t->checkModule('101 bank records rejected instead of silently truncated',
    $a, $app, 'POST', $base . '/preview',
    ['exportJson' => json_encode($tooManyBanks, JSON_THROW_ON_ERROR)], 422);

$t->check('grant B viewer to migration space', $a, 'POST',
    $base . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('viewer cannot preview imports', $b, $app, 'POST',
    $base . '/preview', $request, 403);
$t->checkModule('viewer cannot submit imports', $b, $app, 'POST',
    $base . '/import',
    $request + ['previewHash' => $preview['previewHash'], 'confirmed' => true], 403);
$t->check('revoke viewer', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('disable import module', $a, 'PUT', $base . '/modules/librefinance_import',
    ['enabled' => false], 200);
$t->checkModule('disabled import module denies requests', $a, $app, 'POST',
    $base . '/preview', $request, 403);
$t->check('reactivate import module', $a, 'PUT', $base . '/modules/librefinance_import',
    ['enabled' => true], 200);

$parallelFixture = [
    'format' => 'librefinance.normalized.v1',
    'source' => ['system' => 'generic', 'companyId' => 'race-' . $tag],
    'contacts' => [['sourceId' => 'contact-1', 'name' => 'Parallel customer',
        'kind' => 'both', 'email' => null, 'taxId' => null]],
    'categories' => [],
];
$parallelJson = json_encode($parallelFixture, JSON_THROW_ON_ERROR);
$parallelPreview = $t->checkModule('parallel test receives preview', $a, $app,
    'POST', $base . '/preview', ['exportJson' => $parallelJson], 200);
$parallelBody = [
    'exportJson' => $parallelJson, 'previewHash' => $parallelPreview['previewHash'],
    'confirmed' => true,
];
[$first, $second] = $t->parallel(
    ['user' => $a, 'method' => 'POST', 'path' => $base . '/import',
        'data' => $parallelBody, 'module' => $app],
    ['user' => $a, 'method' => 'POST', 'path' => $base . '/import',
        'data' => $parallelBody, 'module' => $app],
);
$codes = [$first['code'], $second['code']];
sort($codes);
$t->record('concurrent replay never fails open or creates duplicate source map',
    in_array($codes, [[200, 200], [200, 409]], true),
    'codes ' . implode(',', $codes));
$t->check('concurrent import created one additional contact, not two',
    $a, 'GET', $base . '/contacts', null, 200,
    static fn(array $v): bool => count($v['contacts'] ?? []) === 3);

// Historical statement migration is an explicit, separate, source-scoped
// action. The already-imported bank-101 identity is used as an FK.
$movementSource = 'bank-' . $tag;
$movementUrl = $base . '/movement-preview';
$movementApply = $base . '/movement-import';
$movementCoverage = $base . '/movement-coverage?sourceCompanyId=' . $movementSource;
$movementFixture = [
    'format' => 'librefinance.transactions.v1',
    'source' => ['system' => 'generic', 'companyId' => $movementSource],
    'transactions' => [
        [
            'sourceId' => 'movement-301', 'accountSourceId' => 'bank-101',
            'type' => 'income', 'bookingDate' => '2026-11-19',
            'amountMinor' => 1800, 'currency' => 'BRL',
            'description' => 'Synthetic historical receipt',
        ],
        [
            'sourceId' => 'movement-302', 'accountSourceId' => 'bank-101',
            'type' => 'expense', 'bookingDate' => '2026-11-20',
            'amountMinor' => 400, 'currency' => 'BRL',
            'description' => 'Synthetic historical payment',
        ],
    ],
];
$movementJson = json_encode($movementFixture, JSON_THROW_ON_ERROR);
$bankAccountsAfter = $t->check('source bank chart remains available for migration',
    $a, 'GET', $base . '/accounts', null, 200);
$bankTargetId = 0;
foreach ($bankAccountsAfter['accounts'] as $acct) {
    if ($acct['code'] === 'BANK_' . strtoupper($tag)) {
        $bankTargetId = (int)$acct['id'];
    }
}
$t->record('mapped synthetic bank account ID is available',
    $bankTargetId > 0, 'mapped bank ID');
$statements = $base . '/transactions?bankAccountId=' . $bankTargetId;
$t->checkModule('movement coverage starts at zero', $a, $app, 'GET',
    $movementCoverage, null, 200,
    static fn(array $v): bool => $v['mapped'] === 0
        && $v['sourceInventoryVerified'] === false);
$t->checkModule('unauthorized user cannot preview historical movements',
    $b, $app, 'POST', $movementUrl, ['exportJson' => $movementJson], 404);
$movementPreview = $t->checkModule('preview maps both source movements without postings',
    $a, $app, 'POST', $movementUrl, ['exportJson' => $movementJson], 200,
    static fn(array $v): bool => $v['ready'] === true
        && $v['counts']['new'] === 2
        && $v['ledgerEntriesPosted'] === 0
        && $v['transfersImported'] === 0
        && $v['total'] === 2);
$t->checkModule('cannot apply historical movements without user confirmation',
    $a, $app, 'POST', $movementApply, [
        'exportJson' => $movementJson,
        'previewHash' => $movementPreview['previewHash'],
        'confirmed' => false,
    ], 422);
$t->checkModule('changed preview hash blocks historical movement import',
    $a, $app, 'POST', $movementApply, [
        'exportJson' => $movementJson,
        'previewHash' => str_repeat('0', 64),
        'confirmed' => true,
    ], 409);
$movementImport = ['exportJson' => $movementJson,
    'previewHash' => $movementPreview['previewHash'], 'confirmed' => true];
$t->checkModule('atomically import two pending native banking statements',
    $a, $app, 'POST', $movementApply, $movementImport, 200,
    static fn(array $v): bool => $v['created'] === 2
        && $v['skipped'] === 0 && $v['ledgerEntriesPosted'] === 0
        && $v['bankEntriesPending'] === true);
$importedLines = $t->checkModule('native bank module sees both signed imported movements',
    $a, 'librefinance_banking', 'GET', $statements, null, 200,
    static function(array $v): bool {
        $lines = $v['transactions'] ?? [];
        if (count($lines) !== 2) { return false; }
        $amounts = array_column($lines, 'amount_minor');
        sort($amounts);
        return $amounts === [-400, 1800]
            && count(array_filter($lines, static fn(array $line): bool =>
                $line['status'] === 'pending'
                    && $line['matched_journal_id'] === null)) === 2;
    });
$t->check('imported pending movement leaves authoritative bank ledger zero',
    $a, 'GET', $base . '/accounts', null, 200,
    static function(array $v) use ($bankTargetId): bool {
        foreach ($v['accounts'] as $account) {
            if ((int)$account['id'] === $bankTargetId) {
                return $account['balance_minor'] === 0;
            }
        }
        return false;
    });
$t->checkModule('movement coverage links both to real banking entries',
    $a, $app, 'GET', $movementCoverage, null, 200,
    static fn(array $v): bool => $v['mapped'] === 2
        && $v['targetPresent'] === 2 && $v['missingTarget'] === 0
        && $v['pending'] === 2 && $v['matched'] === 0
        && $v['completeSourceMigration'] === false
        && $v['balanceReconciled'] === false);
$reconciliationPath = $base . '/movement-reconciliation?sourceCompanyId=' . $movementSource;
$t->checkModule('reconstructed source balance exposes unexplained cents not a false reconciliation',
    $a, $app, 'GET', $reconciliationPath, null, 200,
    static function(array $v): bool {
        $accounts = $v['accounts'] ?? [];
        if (count($accounts) !== 2) { return false; }
        $bank = current(array_filter($accounts, static fn(array $r): bool =>
            $r['accountSourceId'] === 'bank-101'));
        return is_array($bank)
            && $bank['sourceOpeningBalanceMinor'] === 12345
            && $bank['importedMovementNetMinor'] === 1400
            && $bank['reconstructedFromImportedMinor'] === 13745
            && $bank['sourceCurrentBalanceMinor'] === 23456
            && $bank['unexplainedDifferenceMinor'] === 9711
            && $bank['importedMovementCount'] === 2
            && $bank['pendingMovementCount'] === 2
            && $bank['historyComplete'] === false
            && $bank['balanceReconciled'] === false
            && $v['nextAfterId'] === null
            && $v['sourceInventoryVerified'] === false;
    });
$t->checkModule('cross-space user cannot see migration balance diagnostics',
    $b, $app, 'GET', $reconciliationPath, null, 404);
$t->checkModule('negative diagnostic cursor is denied',
    $a, $app, 'GET', $reconciliationPath . '&afterId=-1', null, 422);
$t->checkModule('first 50 origin accounts returned without limiting full inventory',
    $a, $app, 'GET',
    $base . '/movement-reconciliation?sourceCompanyId=pages-' . $tag
        . '&sourceSystem=generic', null, 200,
    static fn(array $v): bool => count($v['accounts'] ?? []) === 50
        && is_int($v['nextAfterId'])
        && $v['accounts'][0]['unexplainedDifferenceMinor'] === 0
        && $v['accounts'][0]['historyComplete'] === false);
$t->checkModule('second page exposes two remaining origin accounts',
    $a, $app, 'GET',
    $base . '/movement-reconciliation?sourceCompanyId=pages-' . $tag
        . '&sourceSystem=generic&afterId='
        . $t->checkModule('read bank diagnostic cursor', $a, $app, 'GET',
            $base . '/movement-reconciliation?sourceCompanyId=pages-' . $tag
            . '&sourceSystem=generic', null, 200)['nextAfterId'],
    null, 200, static fn(array $v): bool => count($v['accounts'] ?? []) === 2
        && $v['nextAfterId'] === null);
$t->checkModule('identical movement replay never duplicates bank entries',
    $a, $app, 'POST', $movementApply, $movementImport, 200,
    static fn(array $v): bool => $v['created'] === 0
        && $v['skipped'] === 2);
$changedMovement = $movementFixture;
$changedMovement['transactions'][1]['amountMinor'] = 500;
$t->checkModule('source movement amount change reports an immutable conflict',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($changedMovement, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['counts']['conflict'] === 1
        && $v['issues'][0]['reason'] === 'source_record_changed');
$missingBank = $movementFixture;
$missingBank['transactions'][0]['accountSourceId'] = 'unknown-account';
$t->checkModule('unknown source bank cannot be imported across companies',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($missingBank, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'source_bank_account_not_imported');
$disabledBank = $movementFixture;
$disabledBank['transactions'][0]['accountSourceId'] = 'cash-102';
$t->checkModule('disabled source cash account blocks movement import',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($disabledBank, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'source_bank_account_inactive');
$fx = $movementFixture;
$fx['transactions'][0]['currency'] = 'USD';
$t->checkModule('foreign-currency movement is not converted silently',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($fx, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'currency_mismatch');
$badTransfer = $movementFixture;
$badTransfer['transactions'][0]['type'] = 'transfer';
$t->checkModule('transfer cannot be disguised as income or expense',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($badTransfer, JSON_THROW_ON_ERROR)], 422);
$badFraction = $movementFixture;
$badFraction['transactions'][0]['amountMinor'] = 1.5;
$t->checkModule('fractional minor units rejected without rounding',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($badFraction, JSON_THROW_ON_ERROR)], 422);
$badDate = $movementFixture;
$badDate['transactions'][0]['bookingDate'] = '2026-02-30';
$t->checkModule('invalid source booking date rejected',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($badDate, JSON_THROW_ON_ERROR)], 422);
$dupe = $movementFixture;
$dupe['transactions'][] = $dupe['transactions'][0];
$t->checkModule('duplicate source transaction ID in one lot rejected',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => json_encode($dupe, JSON_THROW_ON_ERROR)], 422);
$t->checkModule('movement importer blocks the wrong format',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => $bankJson], 422);
$t->check('disable native banking module for this finance space',
    $a, 'PUT', $base . '/modules/librefinance_banking',
    ['enabled' => false], 200);
$t->checkModule('disabled bank module blocks new historical imports',
    $a, $app, 'POST', $movementUrl,
    ['exportJson' => $movementJson], 403);
$t->checkModule('disabled bank module blocks coverage read',
    $a, $app, 'GET', $movementCoverage, null, 403);
$t->check('reactivate bank module after denial test',
    $a, 'PUT', $base . '/modules/librefinance_banking',
    ['enabled' => true], 200);
$t->checkModule('banking reactivation preserves original source mappings',
    $a, $app, 'GET', $movementCoverage, null, 200,
    static fn(array $v): bool => $v['mapped'] === 2);

// A full 105-record synthetic source transfer inventory must be imported in
// bounded independently validated batches. These are paired source events,
// NOT bank statement legs or double-entry journals.
$transferCompany = 'pages-' . $tag;
$transferPath = $base . '/transfer-preview';
$transferApplyPath = $base . '/transfer-import';
$transferCoveragePath = $base . '/transfer-coverage?sourceCompanyId='
    . $transferCompany . '&sourceSystem=generic';
$nativeTransfers = $base . '/transfers';
$transferRows = [];
for ($i = 1; $i <= 105; ++$i) {
    $transferRows[] = [
        'sourceId' => 'transfer-' . $i,
        'fromAccountSourceId' => 'account-1',
        'toAccountSourceId' => 'account-2',
        'bookingDate' => '2026-11-20',
        'amountMinor' => 100 + $i,
        'currency' => 'BRL',
        'description' => 'Synthetic paired transfer ' . $i,
    ];
}
$transferBatch = static function(array $rows) use ($transferCompany): string {
    return json_encode([
        'format' => 'librefinance.transfers.v1',
        'source' => ['system' => 'generic', 'companyId' => $transferCompany],
        'transfers' => $rows,
    ], JSON_THROW_ON_ERROR);
};
$transferSample = $transferBatch(array_slice($transferRows, 0, 2));
$t->checkModule('unrelated user cannot inspect transfer source coverage',
    $b, $app, 'GET', $transferCoveragePath, null, 404);
$t->checkModule('unrelated user cannot stage transfers',
    $b, $app, 'POST', $transferPath,
    ['exportJson' => $transferSample], 404);
$t->checkModule('native banking initially has no staged transfers',
    $a, 'librefinance_banking', 'GET', $nativeTransfers, null, 200,
    static fn(array $v): bool => $v['transfers'] === []
        && $v['nextAfterId'] === null);
$t->checkModule('transfer coverage starts at zero',
    $a, $app, 'GET', $transferCoveragePath, null, 200,
    static fn(array $v): bool => $v['mapped'] === 0
        && $v['transferLegsReconciled'] === false);
$samplePreview = $t->checkModule('paired transfer preview is read only and net zero',
    $a, $app, 'POST', $transferPath, ['exportJson' => $transferSample],
    200, static fn(array $v): bool => $v['ready'] === true
        && $v['counts']['new'] === 2
        && $v['statementLegsCreated'] === 0
        && $v['ledgerEntriesPosted'] === 0);
$t->checkModule('transfer preview alone never creates native banking rows',
    $a, 'librefinance_banking', 'GET', $nativeTransfers, null, 200,
    static fn(array $v): bool => $v['transfers'] === []);
$t->checkModule('transfer import requires user confirmation',
    $a, $app, 'POST', $transferApplyPath,
    ['exportJson' => $transferSample,
     'previewHash' => $samplePreview['previewHash'], 'confirmed' => false], 422);
$t->checkModule('transfer changed preview hash blocks commit',
    $a, $app, 'POST', $transferApplyPath,
    ['exportJson' => $transferSample,
     'previewHash' => str_repeat('0', 64), 'confirmed' => true], 409);
$sampleImport = ['exportJson' => $transferSample,
    'previewHash' => $samplePreview['previewHash'], 'confirmed' => true];
$t->checkModule('two source transfers atomically staged without statement legs',
    $a, $app, 'POST', $transferApplyPath, $sampleImport, 200,
    static fn(array $v): bool => $v['created'] === 2
        && $v['skipped'] === 0
        && $v['transfersPendingSourceMatch'] === true
        && $v['statementLegsCreated'] === 0
        && $v['ledgerEntriesPosted'] === 0);
$t->checkModule('native banking exposes two source transfer pairs',
    $a, 'librefinance_banking', 'GET', $nativeTransfers, null, 200,
    static fn(array $v): bool => count($v['transfers'] ?? []) === 2
        && $v['transfers'][0]['fromAccountId'] !== $v['transfers'][0]['toAccountId']
        && $v['transfers'][0]['amountMinor'] === 101
        && $v['transfers'][0]['currency'] === 'BRL'
        && $v['transfers'][0]['status'] === 'pending_source_match'
        && $v['transfers'][0]['statementLegsCreated'] === 0
        && $v['allTransfersReconciled'] === false);
$t->checkModule('same transfer replay cannot duplicate source mapping',
    $a, $app, 'POST', $transferApplyPath, $sampleImport, 200,
    static fn(array $v): bool => $v['created'] === 0
        && $v['skipped'] === 2);
$t->checkModule('transfer coverage checks native target rows',
    $a, $app, 'GET', $transferCoveragePath, null, 200,
    static fn(array $v): bool => $v['mapped'] === 2
        && $v['targetPresent'] === 2
        && $v['pendingSourceMatch'] === 2
        && $v['missingTarget'] === 0
        && $v['statementLegsCreated'] === 0
        && $v['completeSourceMigration'] === false);
$changedTransfer = $transferRows[0];
$changedTransfer['amountMinor'] = 999;
$t->checkModule('source transfer amount cannot change silently on replay',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$changedTransfer])], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['counts']['conflict'] === 1
        && $v['issues'][0]['reason'] === 'source_record_changed');
$missingTransfer = $transferRows[0];
$missingTransfer['toAccountSourceId'] = 'other-bank';
$t->checkModule('unmapped destination bank produces visible conflict',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$missingTransfer])], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'source_transfer_account_not_imported');
$sameBank = $transferRows[0];
$sameBank['toAccountSourceId'] = $sameBank['fromAccountSourceId'];
$t->checkModule('self-transfer from the same source bank refused',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$sameBank])], 422);
$fxTransfer = $transferRows[0];
$fxTransfer['currency'] = 'USD';
$t->checkModule('cross-currency transfer cannot silently become BRL',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$fxTransfer])], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'transfer_currency_mismatch');
$invalidTransfer = $transferRows[0];
$invalidTransfer['amountMinor'] = 1.25;
$t->checkModule('fractional cents refused for source transfers',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$invalidTransfer])], 422);
$invalidTransfer['amountMinor'] = 0;
$t->checkModule('zero-value transfer refused',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$invalidTransfer])], 422);
$invalidTransfer = $transferRows[0];
$invalidTransfer['bookingDate'] = '2026-02-30';
$t->checkModule('invalid source transfer date refused',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$invalidTransfer])], 422);
$t->checkModule('duplicate source transfer IDs in one batch refused',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch([$transferRows[0], $transferRows[0]])], 422);
$t->checkModule('transaction JSON cannot be imported as a transfer',
    $a, $app, 'POST', $transferPath, ['exportJson' => $movementJson], 422);
$disabledSourceTransfer = $transferRows[0];
$disabledSourceTransfer['fromAccountSourceId'] = 'bank-101';
$disabledSourceTransfer['toAccountSourceId'] = 'cash-102';
$disabledJson = json_encode([
    'format' => 'librefinance.transfers.v1',
    'source' => ['system' => 'generic', 'companyId' => $bankSource],
    'transfers' => [$disabledSourceTransfer],
], JSON_THROW_ON_ERROR);
$t->checkModule('disabled imported source account blocks paired transfer',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $disabledJson], 200,
    static fn(array $v): bool => $v['ready'] === false
        && $v['issues'][0]['reason'] === 'source_transfer_account_inactive');
$t->checkModule('51 transfer records rejected instead of truncated',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferBatch(array_slice($transferRows, 0, 51))], 422);
$t->check('share migration space as viewer for authorization test',
    $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('viewer cannot preview transfer migration',
    $b, $app, 'POST', $transferPath,
    ['exportJson' => $transferSample], 403);
$t->checkModule('viewer may see native transfer list in an authorized space',
    $b, 'librefinance_banking', 'GET', $nativeTransfers, null, 200,
    static fn(array $v): bool => count($v['transfers']) === 2);
$t->check('revoke transfer viewer',
    $a, 'DELETE', $base . '/members/' . $b, null, 200);

// 105 synthetic source events, in independently validated pages of at most 50.
// The first two have already been imported and must remain skipped.
foreach ([
    array_slice($transferRows, 0, 50),
    array_slice($transferRows, 50, 50),
    array_slice($transferRows, 100, 5),
] as $part => $rows) {
    $json = $transferBatch($rows);
    $preview = $t->checkModule('transfer lot ' . ($part + 1) . ' previews',
        $a, $app, 'POST', $transferPath,
        ['exportJson' => $json], 200,
        static fn(array $v): bool => $v['ready'] === true
            && $v['total'] === count($rows));
    $expectedSkipped = $part === 0 ? 2 : 0;
    $t->checkModule('transfer lot ' . ($part + 1) . ' imports without truncation',
        $a, $app, 'POST', $transferApplyPath, [
            'exportJson' => $json, 'previewHash' => $preview['previewHash'],
            'confirmed' => true,
        ], 200, static fn(array $v): bool =>
            $v['created'] === count($rows) - $expectedSkipped
            && $v['skipped'] === $expectedSkipped
            && $v['ledgerEntriesPosted'] === 0);
}
$t->checkModule('all 105 source transfers durably mapped',
    $a, $app, 'GET', $transferCoveragePath, null, 200,
    static fn(array $v): bool => $v['mapped'] === 105
        && $v['targetPresent'] === 105
        && $v['pendingSourceMatch'] === 105
        && $v['statementLegsCreated'] === 0
        && $v['sourceInventoryVerified'] === false);
$firstTransferPage = $t->checkModule('native transfer first page has 50 and keyset cursor',
    $a, 'librefinance_banking', 'GET', $nativeTransfers, null, 200,
    static fn(array $v): bool => count($v['transfers']) === 50
        && is_int($v['nextAfterId']));
$secondTransferPage = $t->checkModule('native transfer second page has 50 without duplication',
    $a, 'librefinance_banking', 'GET',
    $nativeTransfers . '?afterId=' . $firstTransferPage['nextAfterId'],
    null, 200, static fn(array $v): bool => count($v['transfers']) === 50
        && is_int($v['nextAfterId'])
        && $v['transfers'][0]['id'] > $firstTransferPage['nextAfterId']);
$t->checkModule('native transfer final page includes remaining five',
    $a, 'librefinance_banking', 'GET',
    $nativeTransfers . '?afterId=' . $secondTransferPage['nextAfterId'],
    null, 200, static fn(array $v): bool =>
        count($v['transfers']) === 5 && $v['nextAfterId'] === null);
$t->checkModule('no balance doubled by 105 staged transfers',
    $a, $app, 'GET',
    $base . '/movement-reconciliation?sourceCompanyId=' . $transferCompany
        . '&sourceSystem=generic', null, 200,
    static fn(array $v): bool => count($v['accounts']) === 50
        && $v['accounts'][0]['importedMovementNetMinor'] === 0
        && $v['accounts'][1]['importedMovementNetMinor'] === 0
        && $v['accounts'][0]['balanceReconciled'] === false);
$t->check('disable native banking extension to deny transfer import',
    $a, 'PUT', $base . '/modules/librefinance_banking',
    ['enabled' => false], 200);
$t->checkModule('disabled banking module blocks transfer migration',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferSample], 403);
$t->check('reactivate banking extension',
    $a, 'PUT', $base . '/modules/librefinance_banking',
    ['enabled' => true], 200);
$t->checkModule('source transfer mapping remains after module reactivation',
    $a, $app, 'GET', $transferCoveragePath, null, 200,
    static fn(array $v): bool => $v['mapped'] === 105);

// A real simultaneous replay of one NEW source transfer must not create two
// native transfers or two source mappings.
$transferRace = $transferRows[0];
$transferRace['sourceId'] = 'transfer-106';
$transferRace['description'] = 'Synthetic concurrent transfer';
$transferRaceJson = $transferBatch([$transferRace]);
$transferRacePreview = $t->checkModule(
    'parallel transfer test receives independent preview',
    $a, $app, 'POST', $transferPath,
    ['exportJson' => $transferRaceJson], 200);
$transferRaceBody = [
    'exportJson' => $transferRaceJson,
    'previewHash' => $transferRacePreview['previewHash'], 'confirmed' => true,
];
[$raceA, $raceB] = $t->parallel(
    ['user' => $a, 'method' => 'POST', 'path' => $transferApplyPath,
        'data' => $transferRaceBody, 'module' => $app],
    ['user' => $a, 'method' => 'POST', 'path' => $transferApplyPath,
        'data' => $transferRaceBody, 'module' => $app],
);
$raceCodes = [$raceA['code'], $raceB['code']];
sort($raceCodes);
$t->record('parallel import never fails open or duplicates the transfer',
    in_array($raceCodes, [[200, 200], [200, 409]], true),
    'codes ' . implode(',', $raceCodes), ['first' => $raceA['body'], 'second' => $raceB['body']]);
$t->checkModule('parallel transfer inserts exactly one additional source record',
    $a, $app, 'GET', $transferCoveragePath, null, 200,
    static fn(array $v): bool => $v['mapped'] === 106
        && $v['targetPresent'] === 106
        && $v['pendingSourceMatch'] === 106);
$t->checkModule('parallel transfer idempotent replay skips one recorded event',
    $a, $app, 'POST', $transferApplyPath, $transferRaceBody, 200,
    static fn(array $v): bool => $v['created'] === 0 && $v['skipped'] === 1);

// Read-only source inventory audit: count matching is intentionally weaker
// than verified identity, historical value and business workflow parity.
$inventoryPath = $base . '/inventory-preview';
$sourceInventory = [
    'format' => 'librefinance.inventory.v1',
    'source' => [
        'system' => 'generic', 'companyId' => $bankSource,
        'asOf' => '2026-11-21',
    ],
    'counts' => [
        'accounts' => 2, 'transactions' => 2, 'transfers' => 0,
        'documents' => 6, 'nfse_receipts' => 3,
    ],
];
$inventoryJson = json_encode($sourceInventory, JSON_THROW_ON_ERROR);
$t->checkModule('unrelated user cannot inventory another finance space',
    $b, $app, 'POST', $inventoryPath,
    ['inventoryJson' => $inventoryJson], 404);
$inventoryResponse = $t->checkModule(
    'counts-only inventory exposes unimplemented fiscal and document domains',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => $inventoryJson], 200,
    static function(array $v): bool {
        $records = array_column($v['resources'], null, 'resource');
        return count($records) === 5
            && $records['accounts']['targetPresent'] === 2
            && $records['accounts']['mapped'] === 2
            && $records['accounts']['status'] === 'counts_match'
            && $records['transactions']['targetPresent'] === 2
            && $records['transactions']['status'] === 'counts_match'
            && $records['transfers']['status'] === 'counts_match'
            && $records['documents']['missing'] === 6
            && $records['documents']['status'] === 'not_implemented'
            && $records['nfse_receipts']['missing'] === 3
            && $v['summary']['sourceRecordsDeclared'] === 13
            && $v['summary']['destinationRecordsPresent'] === 4
            && $v['summary']['resourcesWithUnimplementedImports'] === 2
            && $v['functionalParityCertified'] === false
            && $v['sourceInventoryIndependentlyVerified'] === false
            && $v['readOnly'] === true;
    });
$t->checkModule('repeating the same manifest yields an unchanged diagnostic hash',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => $inventoryJson], 200,
    static fn(array $v): bool => $v['manifestHash'] === $inventoryResponse['manifestHash']
        && $v['summary']['destinationRecordsPresent'] === 4);
$t->checkModule('inventory preview never changes prior source mapping coverage',
    $a, $app, 'GET', $movementCoverage, null, 200,
    static fn(array $v): bool => $v['mapped'] === 2
        && $v['targetPresent'] === 2);
$fullPagesInventory = [
    'format' => 'librefinance.inventory.v1',
    'source' => [
        'system' => 'generic', 'companyId' => $transferCompany,
        'asOf' => '2026-11-21',
    ],
    'counts' => ['accounts' => 52, 'transfers' => 106],
];
$t->checkModule('all synthetic source counts match but never certify parity',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($fullPagesInventory, JSON_THROW_ON_ERROR)], 200,
    static function(array $v): bool {
        $rows = array_column($v['resources'], null, 'resource');
        return $rows['accounts']['mapped'] === 52
            && $rows['accounts']['targetPresent'] === 52
            && $rows['transfers']['mapped'] === 106
            && $rows['transfers']['targetPresent'] === 106
            && $rows['transfers']['missing'] === 0
            && $v['summary']['resourcesWithCountDifferences'] === 0
            && $v['functionalParityCertified'] === false
            && $v['financialBalancesReconciled'] === false;
    });
$withExtra = $fullPagesInventory;
$withExtra['counts']['transfers'] = 105;
$t->checkModule('source inventory lower than target detects extra mappings',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($withExtra, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['resources'][1]['extra'] === 1
        && $v['resources'][1]['status'] === 'gap');
$negativeCount = $sourceInventory;
$negativeCount['counts']['documents'] = -1;
$t->checkModule('negative source count refused',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($negativeCount, JSON_THROW_ON_ERROR)], 422);
$floatCount = $sourceInventory;
$floatCount['counts']['accounts'] = 2.5;
$t->checkModule('fractional resource counts refused',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($floatCount, JSON_THROW_ON_ERROR)], 422);
$malformedDate = $sourceInventory;
$malformedDate['source']['asOf'] = '2026-02-30';
$t->checkModule('invalid inventory date refused',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($malformedDate, JSON_THROW_ON_ERROR)], 422);
$malformed = $sourceInventory;
$malformed['counts']['secret/key'] = 1;
$t->checkModule('unknown invalid resource key cannot become SQL identifier',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($malformed, JSON_THROW_ON_ERROR)], 422);
$untrusted = $sourceInventory;
$untrusted['source']['companyId'] = 'another-company';
$t->checkModule('other declared source company cannot reuse this company mappings',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => json_encode($untrusted, JSON_THROW_ON_ERROR)], 200,
    static fn(array $v): bool => $v['resources'][0]['targetPresent'] === 0
        && $v['resources'][0]['missing'] === 2
        && $v['functionalParityCertified'] === false);
$t->check('share space as viewer for inventory denial',
    $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('financial viewer cannot inventory source data',
    $b, $app, 'POST', $inventoryPath,
    ['inventoryJson' => $inventoryJson], 403);
$t->check('revoke inventory viewer',
    $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->check('disable banking extension during inventory report',
    $a, 'PUT', $base . '/modules/librefinance_banking',
    ['enabled' => false], 200);
$t->checkModule('disabled bank extension is an explicit unsupported coverage gap',
    $a, $app, 'POST', $inventoryPath,
    ['inventoryJson' => $inventoryJson], 200,
    static function(array $v): bool {
        $rows = array_column($v['resources'], null, 'resource');
        return $rows['accounts']['targetPresent'] === 2
            && $rows['transactions']['targetPresent'] === 0
            && $rows['transactions']['status'] === 'not_implemented'
            && $v['functionalParityCertified'] === false;
    });
$t->check('restore banking extension',
    $a, 'PUT', $base . '/modules/librefinance_banking',
    ['enabled' => true], 200);


// A registered third-party connector may declare a stable source namespace
// not hardcoded by the core; a self-reported name is never source attestation.
$additional = [
    'format' => 'librefinance.normalized.v1',
    'source' => ['system'=>'gnucash', 'companyId'=>'external-'.$tag],
    'contacts' => [[
        'sourceId'=>'c-thirdparty', 'name'=>'Generic connector customer',
        'kind'=>'customer', 'email'=>null, 'taxId'=>null,
    ]],
    'categories' => [],
];
$additionalJson = json_encode($additional, JSON_THROW_ON_ERROR);
$additionalPreview = $t->checkModule('third-party source namespace previewed without special PHP subclass',
    $a, $app, 'POST', $base.'/preview',
    ['exportJson'=>$additionalJson], 200,
    static fn(array $v): bool => $v['ready'] === true && $v['counts']['new'] === 1);
$t->checkModule('confirmed third-party source writes with core identity and audit',
    $a, $app, 'POST', $base.'/import',
    ['exportJson'=>$additionalJson, 'previewHash'=>$additionalPreview['previewHash'], 'confirmed'=>true],
    200, static fn(array $v): bool => $v['created'] === 1 && $v['skipped'] === 0);
$t->checkModule('third-party source replay is idempotent',
    $a, $app, 'POST', $base.'/import',
    ['exportJson'=>$additionalJson, 'previewHash'=>$additionalPreview['previewHash'], 'confirmed'=>true],
    200, static fn(array $v): bool => $v['created'] === 0 && $v['skipped'] === 1);
$t->checkModule('provenance coverage keeps origin ID and unverified status',
    $a, $app, 'GET', $base.'/coverage?sourceCompanyId=external-'.$tag.'&sourceSystem=gnucash',
    null, 200, static fn(array $v): bool =>
        $v['sourceSystem'] === 'gnucash'
        && $v['kinds']['contact']['mapped'] === 1
        && $v['sourceInventoryVerified'] === false);
$invalidOrigin = $additional;
$invalidOrigin['source']['system'] = 'gnucash/../../invalid';
$t->checkModule('path-like source system identifier rejected',
    $a, $app, 'POST', $base.'/preview',
    ['exportJson'=>json_encode($invalidOrigin, JSON_THROW_ON_ERROR)], 422);


// Source legal identities are global, not restricted to Brazilian CNPJ.
$ptCompany = [
    'format'=>'librefinance.normalized.v1',
    'source'=>['system'=>'gnucash','companyId'=>'pt-'.$tag],
    'company'=>[
        'sourceId'=>'pt-'.$tag, 'legalName'=>'Test Portugal Ltd',
        'country'=>'PT', 'taxId'=>'PT 123-45/67',
        'tradeName'=>null, 'municipalRegistration'=>null,
        'stateRegistration'=>null,
    ],
    'contacts'=>[], 'categories'=>[],
];
$ptJson = json_encode($ptCompany, JSON_THROW_ON_ERROR);
$ptPreview = $t->checkModule('international company ISO PT previewed without BR tax rules',
    $a, $app, 'POST', $base.'/preview', ['exportJson'=>$ptJson], 200,
    static fn(array $v): bool =>
        ($v['ready'] ?? null) === true && ($v['counts']['new'] ?? null) === 1);
$t->checkModule('international legal company committed under its source identity',
    $a, $app, 'POST', $base.'/import',
    ['exportJson'=>$ptJson, 'previewHash'=>$ptPreview['previewHash'], 'confirmed'=>true],
    200, static fn(array $v): bool =>
        ($v['created'] ?? null) === 1 && ($v['skipped'] ?? null) === 0);
$t->checkModule('international legal company replay remains idempotent',
    $a, $app, 'POST', $base.'/import',
    ['exportJson'=>$ptJson, 'previewHash'=>$ptPreview['previewHash'], 'confirmed'=>true],
    200, static fn(array $v): bool =>
        ($v['created'] ?? null) === 0 && ($v['skipped'] ?? null) === 1);
$usCompany = $ptCompany;
$usCompany['source']['companyId'] = 'us-'.$tag;
$usCompany['company'] = array_replace($ptCompany['company'], [
    'sourceId'=>'us-'.$tag, 'legalName'=>'Test United States LLC',
    'country'=>'US', 'taxId'=>null,
]);
$usJson = json_encode($usCompany, JSON_THROW_ON_ERROR);
$usPreview = $t->checkModule('US company without tax ID is previewed without false identity collision',
    $a, $app, 'POST', $base.'/preview', ['exportJson'=>$usJson], 200,
    static fn(array $v): bool => ($v['ready'] ?? null) === true);
$t->checkModule('US company without tax ID imports under core company rules',
    $a, $app, 'POST', $base.'/import',
    ['exportJson'=>$usJson, 'previewHash'=>$usPreview['previewHash'], 'confirmed'=>true],
    200, static fn(array $v): bool => ($v['created'] ?? null) === 1);
$badCountry = $ptCompany;
$badCountry['company']['country'] = 'ZZ';
$t->checkModule('nonexistent ISO country is rejected before financial write',
    $a, $app, 'POST', $base.'/preview',
    ['exportJson'=>json_encode($badCountry, JSON_THROW_ON_ERROR)], 422);
$badForeignTax = $ptCompany;
$badForeignTax['company']['taxId'] = 'PT <script>';
$t->checkModule('untrusted non-BR tax identifier cannot carry markup',
    $a, $app, 'POST', $base.'/preview',
    ['exportJson'=>json_encode($badForeignTax, JSON_THROW_ON_ERROR)], 422);

$t->finish();
