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
$t->checkModule('import 3 CSV rows', $a, $app, 'POST', $base . '/imports', $import, 201,
    static fn(array $v): bool => ($v['imported'] ?? null) === 3 && ($v['skipped'] ?? null) === 0);
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
$t->checkModule('disabled bank extension denies imports', $a, $app, 'POST',
    $base . '/imports', $import, 403,
    static fn(array $v): bool => ($v['error'] ?? null) === 'module_disabled');
$t->check('reactivate bank extension', $a, 'PUT',
    $base . '/modules/' . $app, ['enabled' => true], 200);
$t->check('grant B viewer', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('bank viewer can see imported lines', $b, $app, 'GET',
    $base . '/transactions', null, 200);
$t->checkModule('bank viewer cannot match', $b, $app, 'POST',
    $base . '/transactions/' . $extra['id'] . '/match',
    ['journalId' => $journalIncome['id']], 403);
$t->check('revoke B', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->checkModule('revoked B denied bank lines', $b, $app, 'GET', $base . '/transactions', null, 404);
$t->finish();
