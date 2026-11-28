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
$app = 'librefinance_banking';
$tag = bin2hex(random_bytes(6));
$date = '2026-11-20';
$space = $t->check('bank create space', $a, 'POST', '/spaces',
    ['name' => 'Synthetic import bank', 'kind' => 'personal', 'currency' => 'BRL'], 201);
$spaceId = (int)$space['id'];
$base = '/spaces/' . $spaceId;
$t->check('discover bank module', $a, 'GET', $base . '/modules', null, 200,
    static fn(array $v): bool => count(array_filter($v['modules'] ?? [],
        static fn(array $m): bool => $m['id'] === $app)) === 1);
$chart = $t->check('read bank and counter accounts', $a, 'GET', $base . '/accounts', null, 200);
$byCode = [];
foreach ($chart['accounts'] as $account) {
    $byCode[$account['code']] = (int)$account['id'];
}
$bankId = $byCode['1000'];
$journalIncome = $t->check('post synthetic bank inflow', $a, 'POST', $base . '/journals',
    ['debitId' => $bankId, 'creditId' => $byCode['4000'],
     'amountMinor' => 10000, 'date' => $date,
     'memo' => 'Synthetic positive bank posting', 'externalKey' => 'bank:income:' . $tag], 201);
$journalExpense = $t->check('post synthetic bank outflow', $a, 'POST', $base . '/journals',
    ['debitId' => $byCode['5000'], 'creditId' => $bankId,
     'amountMinor' => 2500, 'date' => $date,
     'memo' => 'Synthetic negative bank posting', 'externalKey' => 'bank:expense:' . $tag], 201);
$csv = "id,date,amount,description\n"
    . "in-$tag,2026-11-20,100.00,Synthetic income\n"
    . "out-$tag,2026-11-20,-25.00,Synthetic expense\n"
    . "extra-$tag,2026-11-20,5.00,\"Quoted, description\"\n";
$t->checkModule('bank provider contract is discoverable', $a, $app, 'GET', $base . '/providers', null, 200,
    static fn(array $v): bool => count($v['providers'] ?? []) >= 1
        && $v['providers'][0]['id'] === 'csv_manual'
        && ($v['automaticSyncEnabled'] ?? true) === false);
$t->checkModule('empty bank statement', $a, $app, 'GET', $base . '/transactions', null, 200,
    static fn(array $v): bool => ($v['transactions'] ?? null) === []);
$t->checkModule('B cannot view bank statement', $b, $app, 'GET', $base . '/transactions', null, 404);
$import = ['bankAccountId' => $bankId, 'csv' => $csv];
$previewPath = $base . '/imports/preview';
$ledgerBefore = $t->request($a, 'GET', $base . '/journals');
$balancesBefore = $t->request($a, 'GET', $base . '/accounts');
$preview = $t->checkModule('CSV preview has exact row evidence without posting', $a, $app, 'POST',
    $previewPath, $import, 200, static fn(array $v): bool =>
        $v['ready'] === true && $v['postingCreated'] === false
        && $v['counts'] === ['new' => 3, 'replayed' => 0, 'conflicting' => 0]
        && array_column($v['rows'], 'amountMinor') === [10000, -2500, 500]
        && $v['rows'][2]['description'] === 'Quoted, description'
        && $v['fileError'] === null && strlen($v['previewHash']) === 64);
$t->checkModule('preview left statement empty', $a, $app, 'GET', $base . '/transactions', null, 200,
    static fn(array $v): bool => $v['transactions'] === []);
$t->record('preview does not change journals or account balances',
    $t->request($a, 'GET', $base . '/journals') === $ledgerBefore
    && $t->request($a, 'GET', $base . '/accounts') === $balancesBefore, 'before/after ledger reads');
$t->checkModule('repeat preview has the same fingerprint for the same evidence', $a, $app, 'POST',
    $previewPath, $import, 200, static fn(array $v): bool => $v['previewHash'] === $preview['previewHash']);
$t->checkModule('stale changed valid file cannot be confirmed', $a, $app, 'POST',
    $base . '/imports', array_replace($import, ['csv' => str_replace('100.00', '101.00', $csv),
        'previewHash' => $preview['previewHash']]), 409,
    static fn(array $v): bool => $v['error'] === 'bank_import_preview_changed');
$t->checkModule('malformed file returns no fabricated counts and exact failing row', $a, $app, 'POST',
    $previewPath, array_replace($import, ['csv' => $csv . "bad-$tag,2026-11-20,invalid,Bad amount\n"]),
    200, static fn(array $v): bool => !$v['ready'] && $v['previewHash'] === null && $v['counts'] === null
        && $v['rows'] === [] && $v['fileError'] === [
            'classification' => 'malformed', 'code' => 'invalid_csv_amount', 'row' => 4]);
$t->checkModule('unsupported statement format diagnosed without parsing', $a, $app, 'POST',
    $previewPath, array_replace($import, ['format' => 'qif']), 200,
    static fn(array $v): bool => !$v['ready'] && $v['fileError']['classification'] === 'unsupported');
$t->checkModule('denied preview does not disclose rows to outsider', $b, $app, 'POST',
    $previewPath, $import, 404);
$t->checkModule('invalid account denied before preview parsing', $a, $app, 'POST',
    $previewPath, ['bankAccountId' => 9999999, 'csv' => 'malformed'], 404);
$t->checkModule('non-bank account denied before preview parsing', $a, $app, 'POST',
    $previewPath, ['bankAccountId' => $byCode['4000'], 'csv' => $csv], 404);

$t->checkModule('import 3 CSV rows', $a, $app, 'POST', $base . '/imports', $import + ['previewHash' => $preview['previewHash']], 201,
    static fn(array $v): bool => ($v['imported'] ?? null) === 3 && ($v['skipped'] ?? null) === 0);
$t->checkModule('original preview cannot authorize a different replay state', $a, $app, 'POST',
    $base . '/imports', $import + ['previewHash' => $preview['previewHash']], 409,
    static fn(array $v): bool => $v['error'] === 'bank_import_preview_changed');
$replay = $t->checkModule('fresh preview classifies all original rows as replays', $a, $app, 'POST',
    $previewPath, $import, 200, static fn(array $v): bool => $v['ready']
        && $v['counts'] === ['new' => 0, 'replayed' => 3, 'conflicting' => 0]
        && array_unique(array_column($v['rows'], 'classification')) === ['replayed']);
$t->checkModule('explicit replay confirmation creates no new entries', $a, $app, 'POST',
    $base . '/imports', $import + ['previewHash' => $replay['previewHash']], 201,
    static fn(array $v): bool => $v['imported'] === 0 && $v['skipped'] === 3);
$t->checkModule('conflict preview reports changed ID and no confirmation hash', $a, $app, 'POST',
    $previewPath, array_replace($import, ['csv' => str_replace('100.00', '99.00', $csv)]),
    200, static fn(array $v): bool => !$v['ready'] && $v['previewHash'] === null
        && $v['counts'] === ['new' => 0, 'replayed' => 2, 'conflicting' => 1]
        && $v['rows'][0]['classification'] === 'conflicting');
$t->record('confirmed pending evidence still does not change the ledger',
    $t->request($a, 'GET', $base . '/journals') === $ledgerBefore
    && $t->request($a, 'GET', $base . '/accounts') === $balancesBefore, 'before/after ledger reads');
$t->checkModule('re-import skips all rows', $a, $app, 'POST', $base . '/imports', $import, 201,
    static fn(array $v): bool => ($v['imported'] ?? null) === 0 && ($v['skipped'] ?? null) === 3);
$t->checkModule('same external id different amount is conflict', $a, $app, 'POST',
    $base . '/imports', ['bankAccountId' => $bankId,
    'csv' => "id,date,amount,description\nin-$tag,2026-11-20,99.00,Synthetic income\n"], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'bank_import_conflict');
$t->checkModule('invalid CSV header rejected', $a, $app, 'POST', $base . '/imports',
    ['bankAccountId' => $bankId, 'csv' => "date,amount,id\n2026-11-20,10.00,x\n"], 422);
$t->checkModule('invalid negative text rejected', $a, $app, 'POST', $base . '/imports',
    ['bankAccountId' => $bankId,
     'csv' => "id,date,amount,description\na,2026-11-20,-3,35,Bad amount\n"], 422);
$t->checkModule('bank account must belong to space', $a, $app, 'POST', $base . '/imports',
    ['bankAccountId' => 9999999, 'csv' => $csv], 404);
$t->checkModule('B denied importing into A', $b, $app, 'POST', $base . '/imports', $import, 404);
$lines = $t->checkModule('read three pending lines', $a, $app, 'GET', $base . '/transactions', null, 200,
    static fn(array $v): bool => count($v['transactions'] ?? []) === 3);
$byId = [];
foreach ($lines['transactions'] as $line) {
    $byId[$line['external_id']] = $line;
}
$in = $byId['in-' . $tag];
$out = $byId['out-' . $tag];
$extra = $byId['extra-' . $tag];
$t->checkModule('wrong bank posting amount rejected', $a, $app, 'POST',
    $base . '/transactions/' . $extra['id'] . '/match',
    ['journalId' => $journalIncome['id']], 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'journal_posting_not_found');
$t->checkModule('reconcile inflow with original journal', $a, $app, 'POST',
    $base . '/transactions/' . $in['id'] . '/match',
    ['journalId' => $journalIncome['id']], 200,
    static fn(array $v): bool => ($v['status'] ?? null) === 'matched');
$t->checkModule('reconcile outflow with original journal', $a, $app, 'POST',
    $base . '/transactions/' . $out['id'] . '/match',
    ['journalId' => $journalExpense['id']], 200,
    static fn(array $v): bool => ($v['status'] ?? null) === 'matched');
$t->check('journal linked to bank movement cannot be reversed silently', $a, 'POST',
    $base . '/journals/' . (int)$journalIncome['id'] . '/reverse', null, 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'journal_claimed');
$t->checkModule('cannot match same transaction twice', $a, $app, 'POST',
    $base . '/transactions/' . $in['id'] . '/match',
    ['journalId' => $journalIncome['id']], 409);
$t->checkModule('pending filter leaves only unmatched line', $a, $app, 'GET',
    $base . '/transactions?status=pending', null, 200,
    static fn(array $v): bool => count($v['transactions'] ?? []) === 1
        && $v['transactions'][0]['id'] === $extra['id']);
$t->checkModule('matched filter returns two rows', $a, $app, 'GET',
    $base . '/transactions?status=matched', null, 200,
    static fn(array $v): bool => count($v['transactions'] ?? []) === 2);
$t->check('disable bank extension in this space', $a, 'PUT',
    $base . '/modules/' . $app, ['enabled' => false], 200);
$t->checkModule('disabled bank extension also denies preview', $a, $app, 'POST',
    $previewPath, $import, 403);
$t->checkModule('disabled bank extension rejects prior valid fingerprint', $a, $app, 'POST',
    $base . '/imports', $import + ['previewHash' => $replay['previewHash']], 403);
$t->checkModule('disabled bank extension denies imports', $a, $app, 'POST',
    $base . '/imports', $import, 403,
    static fn(array $v): bool => ($v['error'] ?? null) === 'module_disabled');
$t->check('reactivate bank extension', $a, 'PUT',
    $base . '/modules/' . $app, ['enabled' => true], 200);
$t->check('grant B viewer', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('bank viewer can see imported lines', $b, $app, 'GET',
    $base . '/transactions', null, 200);
$t->checkModule('bank viewer cannot inspect upload evidence as an importer', $b, $app, 'POST',
    $previewPath, $import, 403);
$t->checkModule('bank viewer cannot match', $b, $app, 'POST',
    $base . '/transactions/' . $extra['id'] . '/match',
    ['journalId' => $journalIncome['id']], 403);
$t->check('revoke B', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->checkModule('revoked B denied bank lines', $b, $app, 'GET', $base . '/transactions', null, 404);
// OFX uses the same account-scoped, idempotent bank evidence store as CSV.
$ofx = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nCHARSET:1252\n"
    . "<OFX><STMTRS><CURDEF>BRL\n<BANKTRANLIST><DTSTART>20261120\n"
    . "<STMTTRN><DTPOSTED>20261120\n<TRNAMT>-9.35\n"
    . "<FITID>ofx-pay-$tag\n<MEMO>Synthetic OFX payment\n</STMTTRN>"
    . "<STMTTRN><DTPOSTED>20261121\n<TRNAMT>15.50\n"
    . "<FITID>ofx-income-$tag\n<NAME>Synthetic OFX income\n</STMTTRN>"
    . "</BANKTRANLIST></STMTRS></OFX>";
$ofxPayload = ['bankAccountId' => $bankId, 'format' => 'ofx', 'ofx' => $ofx];
$ofxReview = $t->checkModule('OFX preview returns exact FITID and signed amount', $a, $app, 'POST',
    $previewPath, $ofxPayload, 200, static fn(array $v): bool => $v['ready']
        && $v['counts'] === ['new' => 2, 'replayed' => 0, 'conflicting' => 0]
        && array_column($v['rows'], 'amountMinor') === [-935, 1550]
        && $v['currency'] === 'BRL');
$t->checkModule('OFX currency mismatch is an unsupported diagnostic', $a, $app, 'POST',
    $previewPath, array_replace($ofxPayload, ['ofx' => str_replace('<CURDEF>BRL', '<CURDEF>USD', $ofx)]),
    200, static fn(array $v): bool => !$v['ready'] && $v['fileError']['classification'] === 'unsupported'
        && $v['fileError']['code'] === 'bank_import_currency_mismatch');
$t->checkModule('OFX invalid second transaction identifies the logical row', $a, $app, 'POST',
    $previewPath, array_replace($ofxPayload, ['ofx' => str_replace('15.50', 'bad', $ofx)]),
    200, static fn(array $v): bool => !$v['ready'] && $v['fileError']['row'] === 2
        && $v['fileError']['code'] === 'invalid_ofx_amount' && $v['rows'] === []);

$t->checkModule('OFX 1.x imports two evidence rows', $a, $app, 'POST',
    $base . '/imports', $ofxPayload + ['previewHash' => $ofxReview['previewHash']], 201,
    static fn(array $v): bool => ($v['imported'] ?? null) === 2
        && ($v['skipped'] ?? null) === 0);
$t->checkModule('repeated OFX is idempotent', $a, $app, 'POST',
    $base . '/imports', $ofxPayload, 201,
    static fn(array $v): bool => ($v['imported'] ?? null) === 0
        && ($v['skipped'] ?? null) === 2);
$t->checkModule('OFX FITID collision with changed amount fails atomically',
    $a, $app, 'POST', $base . '/imports',
    ['bankAccountId'=>$bankId,'format'=>'ofx',
        'ofx'=>str_replace('-9.35', '-8.35', $ofx)], 409);
$t->checkModule('OFX currency mismatch denied', $a, $app, 'POST',
    $base . '/imports', ['bankAccountId'=>$bankId,'format'=>'ofx',
        'ofx'=>str_replace('<CURDEF>BRL', '<CURDEF>USD', $ofx)], 422);
$t->checkModule('OFX external entity rejected', $a, $app, 'POST',
    $base . '/imports', ['bankAccountId'=>$bankId,'format'=>'ofx',
        'ofx'=>'<!DOCTYPE OFX [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $ofx], 422);
$t->checkModule('OFX import cannot choose an unrelated account',
    $a, $app, 'POST', $base . '/imports',
    ['bankAccountId'=>9999999,'format'=>'ofx','ofx'=>$ofx], 404);
$t->checkModule('unrelated user cannot import OFX', $b, $app, 'POST',
    $base . '/imports', $ofxPayload, 404);
$t->checkModule('unknown bank format rejected', $a, $app, 'POST',
    $base . '/imports', ['bankAccountId'=>$bankId,'format'=>'qif','csv'=>$csv], 422);
// More than one page of earlier evidence must not hide the requested period.
$periodCsv = "id,date,amount,description\n";
for ($i = 0; $i < 51; ++$i) {
    $periodCsv .= "period-old-$tag-$i,2026-10-01,1.00,Synthetic older evidence\n";
}
$periodCsv .= "period-start-$tag,2026-12-01,2.00,Synthetic period start\n"
    . "period-end-$tag,2026-12-02,-3.00,Synthetic period end\n";
$t->checkModule('seed more than a page outside the statement period', $a, $app, 'POST',
    $base . '/imports', ['bankAccountId' => $bankId, 'csv' => $periodCsv], 201,
    static fn(array $v): bool => $v['imported'] === 53 && $v['skipped'] === 0);
$periodPath = $base . '/transactions?status=pending&bankAccountId=' . $bankId
    . '&from=2026-12-01&through=2026-12-02';
$period = $t->checkModule('inclusive statement dates filter before pagination', $a, $app,
    'GET', $periodPath, null, 200,
    static fn(array $v): bool => array_column($v['transactions'], 'external_id')
        === ["period-start-$tag", "period-end-$tag"]
        && array_column($v['transactions'], 'booking_date') === ['2026-12-01', '2026-12-02']
        && $v['nextAfterId'] === null);
$t->checkModule('date filter composes with the ID cursor', $a, $app, 'GET',
    $periodPath . '&afterId=' . $period['transactions'][0]['id'], null, 200,
    static fn(array $v): bool => array_column($v['transactions'], 'external_id') === ["period-end-$tag"]
        && $v['nextAfterId'] === null);
$t->checkModule('single-day statement period includes that day', $a, $app, 'GET',
    $base . '/transactions?from=2026-12-02&through=2026-12-02', null, 200,
    static fn(array $v): bool => array_column($v['transactions'], 'external_id') === ["period-end-$tag"]);
$t->checkModule('statement lower bound is independently optional', $a, $app, 'GET',
    $base . '/transactions?from=2026-12-01', null, 200,
    static fn(array $v): bool => array_column($v['transactions'], 'external_id')
        === ["period-start-$tag", "period-end-$tag"] && $v['nextAfterId'] === null);
$older = $t->checkModule('statement upper bound is independently optional', $a, $app, 'GET',
    $base . '/transactions?through=2026-10-01', null, 200,
    static fn(array $v): bool => count($v['transactions']) === 50 && $v['nextAfterId'] !== null
        && array_unique(array_column($v['transactions'], 'booking_date')) === ['2026-10-01']);
$t->checkModule('upper-bound statement pagination has no missing evidence', $a, $app, 'GET',
    $base . '/transactions?through=2026-10-01&afterId=' . $older['nextAfterId'], null, 200,
    static fn(array $v): bool => array_column($v['transactions'], 'external_id') === ["period-old-$tag-50"]
        && $v['nextAfterId'] === null);
$t->checkModule('invalid statement calendar date is rejected', $a, $app, 'GET',
    $base . '/transactions?from=2026-02-30', null, 422,
    static fn(array $v): bool => $v['error'] === 'invalid_date');
$t->checkModule('reversed statement period is rejected', $a, $app, 'GET',
    $base . '/transactions?from=2026-12-02&through=2026-12-01', null, 422,
    static fn(array $v): bool => $v['error'] === 'invalid_period');
$t->checkModule('period filter does not reveal another workspace', $b, $app,
    'GET', $periodPath, null, 404);

// Two confirmations of the very same new evidence must not silently
// reinterpret the second confirmation as a replay of a different state.
$race = ['bankAccountId' => $bankId, 'csv' => "id,date,amount,description\nrace-$tag,2026-11-22,1.23,Synthetic concurrent preview\n"];
$racePreview = $t->checkModule('preview racing confirmations', $a, $app, 'POST', $previewPath, $race, 200);
$race['previewHash'] = $racePreview['previewHash'];
[$raceA, $raceB] = $t->parallel(
    ['user' => $a, 'method' => 'POST', 'path' => $base . '/imports', 'data' => $race, 'module' => $app],
    ['user' => $a, 'method' => 'POST', 'path' => $base . '/imports', 'data' => $race, 'module' => $app],
);
$raceCodes = [$raceA['code'], $raceB['code']];
sort($raceCodes);
$t->record('only one confirmation succeeds for a new-row preview', $raceCodes === [201, 409],
    'codes ' . implode(',', $raceCodes));
$t->checkModule('racing preview writes exactly one pending transaction', $a, $app, 'GET',
    $base . '/transactions?from=2026-11-22&through=2026-11-22', null, 200,
    static fn(array $v): bool => count($v['transactions']) === 1
        && $v['transactions'][0]['amount_minor'] === 123
        && $v['transactions'][0]['status'] === 'pending');
$t->finish();
