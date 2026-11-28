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
$app = 'librefinance_reports';
$tag = bin2hex(random_bytes(7));
$space = $t->check('create isolated general-ledger space', $a, 'POST', '/spaces',
    ['name' => 'Synthetic general ledger ' . $tag, 'kind' => 'organization', 'currency' => 'BRL'], 201);
$base = '/spaces/' . $space['id'];
$chart = $t->check('read chart', $a, 'GET', $base . '/accounts', null, 200);
$accounts = array_column($chart['accounts'], 'id', 'code');
$cash = (int)$accounts['1000'];
$income = (int)$accounts['4000'];
$expense = (int)$accounts['5000'];
$period = '?accountId=' . $cash . '&from=2026-01-01&through=2099-12-31';
$reportPath = $base . '/general-ledger' . $period;
$exportPath = $base . '/general-ledger/export' . $period;
$empty = $t->checkModule('empty account has exact zero balance and no cursor', $a, $app, 'GET', $reportPath,
    null, 200, static fn(array $v): bool => $v['openingMinor'] === '0' && $v['closingMinor'] === '0'
        && $v['rowCount'] === 0 && $v['rows'] === [] && $v['nextCursor'] === null);
$make = static function(string $suffix, string $date, int $debit, int $credit, int $amount) use ($t, $a, $base, $tag): array {
    return $t->check('post ledger journal ' . $suffix, $a, 'POST', $base . '/journals',
        ['debitId' => $debit, 'creditId' => $credit, 'amountMinor' => $amount,
         'date' => $date, 'memo' => 'Synthetic ledger ' . $suffix,
         'externalKey' => 'gl:' . $tag . ':' . $suffix], 201);
};
$make('opening', '2025-12-31', $cash, $income, 10000);
$make('outside', '2100-01-01', $cash, $income, 777);
$first = $make('debit', '2026-01-01', $cash, $income, 1250);
$second = $make('credit', '2026-01-01', $expense, $cash, 350);
for ($i = 0; $i < 50; ++$i) {
    $make('same-day-' . $i, '2026-01-01', $cash, $income, 1);
}
$reversal = $t->check('reverse original credit without deleting either journal', $a, 'POST',
    $base . '/journals/' . $second['id'] . '/reverse', null, 200);
$make('unrelated', '2026-01-01', $expense, $income, 555);
$t->checkModule('prior empty snapshot is stale after postings', $a, $app, 'GET',
    $reportPath . '&snapshot=' . $empty['snapshot'], null, 409);
$page = $t->checkModule('ledger opening, closing and total independent of 50-row page', $a, $app, 'GET',
    $reportPath, null, 200, static fn(array $v): bool =>
        $v['basis'] === 'posted_debit_positive' && $v['currency'] === 'BRL'
        && $v['openingMinor'] === '10000' && $v['closingMinor'] === '11300'
        && $v['pageOpeningMinor'] === '10000' && $v['rowCount'] === 53
        && count($v['rows']) === 50 && $v['nextCursor'] !== null
        && $v['rows'][0]['journalId'] === $first['id'] && $v['rows'][0]['amountMinor'] === '1250'
        && $v['rows'][0]['debitMinor'] === '1250' && $v['rows'][0]['creditMinor'] === '0'
        && $v['rows'][1]['journalId'] === $second['id'] && $v['rows'][1]['amountMinor'] === '-350'
        && $v['rows'][1]['debitMinor'] === '0' && $v['rows'][1]['creditMinor'] === '350');
$snapshot = '&snapshot=' . $page['snapshot'];
$cursor = '&' . http_build_query($page['nextCursor']);
$next = $t->checkModule('same-day cursor resumes true running balance and preserves reversal', $a, $app,
    'GET', $reportPath . $snapshot . $cursor, null, 200,
    static fn(array $v): bool => $v['rowCount'] === 53 && count($v['rows']) === 3
        && $v['pageOpeningMinor'] === $page['rows'][49]['runningMinor']
        && $v['rows'][2]['journalId'] === $reversal['id']
        && $v['rows'][2]['reversedJournalId'] === $second['id']
        && $v['rows'][2]['amountMinor'] === '350' && $v['rows'][2]['runningMinor'] === '11300'
        && $v['closingMinor'] === '11300' && $v['nextCursor'] === null);
$all = array_merge($page['rows'], $next['rows']);
$t->record('all 53 posting IDs unique and ordered, exact balances reconcile',
    count(array_unique(array_column($all, 'postingId'))) === 53
    && array_reduce($all, static fn(int $sum, array $r): int => $sum + (int)$r['amountMinor'], 10000) === 11300,
    '53 rows, opening plus signed movements equals closing');
$t->checkModule('export begins at first row despite browsing a later page', $a, $app, 'GET',
    $exportPath . $snapshot, null, 200, static fn(array $v): bool => $v === $page);
$t->checkModule('export includes all remaining rows in the same snapshot', $a, $app, 'GET',
    $exportPath . $snapshot . $cursor, null, 200, static fn(array $v): bool => $v === $next);
$t->checkModule('trial balance agrees with full ledger closing', $a, $app, 'GET',
    $base . '/trial-balance?through=2099-12-31', null, 200,
    static fn(array $v): bool => (int)array_column($v['accounts'], 'balanceMinor', 'id')[$cash] === 11300);
$t->checkModule('one-day inclusive period has 52 original movements', $a, $app, 'GET',
    $base . '/general-ledger?accountId=' . $cash . '&from=2026-01-01&through=2026-01-01', null, 200,
    static fn(array $v): bool => $v['rowCount'] === 52 && $v['openingMinor'] === '10000'
        && $v['closingMinor'] === '10950');
$t->checkModule('revenue credit balance uses same signed accounting basis', $a, $app, 'GET',
    $base . '/general-ledger?accountId=' . $income . '&from=2026-01-01&through=2026-01-01', null, 200,
    static fn(array $v): bool => $v['openingMinor'] === '-10000' && $v['closingMinor'] === '-11855');
$journalPath = $base . '/general-ledger/journals/' . $reversal['id'];
$t->checkModule('journal detail includes actual reversed journal and exact balanced sides', $a, $app, 'GET',
    $journalPath, null, 200, static fn(array $v): bool => $v['reversedJournalId'] === $second['id']
        && count($v['postings']) === 2
        && array_sum(array_column($v['postings'], 'amountMinor')) === 0);
$t->checkModule('invalid cursor without snapshot denied', $a, $app, 'GET', $reportPath . $cursor, null, 422);
$t->checkModule('cursor not pointing at a real scoped posting denied', $a, $app, 'GET',
    $reportPath . $snapshot . '&afterDate=2026-01-01&afterId=999999999', null, 422);
$t->checkModule('snapshot cannot be reused across period', $a, $app, 'GET',
    $base . '/general-ledger?accountId=' . $cash . '&from=2026-02-01&through=2099-12-31' . $snapshot, null, 409);
$t->checkModule('invalid calendar date denied', $a, $app, 'GET',
    $base . '/general-ledger?accountId=' . $cash . '&from=2026-02-30&through=2099-12-31', null, 422);
$t->checkModule('outside user cannot read report', $b, $app, 'GET', $reportPath, null, 404);
$t->checkModule('outside user cannot export report', $b, $app, 'GET', $exportPath . $snapshot, null, 404);
$t->checkModule('outside user cannot inspect journal', $b, $app, 'GET', $journalPath, null, 404);
$other = $t->check('create another workspace', $b, 'POST', '/spaces',
    ['name' => 'Synthetic unrelated ledger', 'kind' => 'personal', 'currency' => 'BRL'], 201);
$otherChart = $t->check('read unrelated chart', $b, 'GET', '/spaces/' . $other['id'] . '/accounts', null, 200);
$foreign = (int)$otherChart['accounts'][0]['id'];
$t->checkModule('foreign account ID does not leak', $a, $app, 'GET',
    $base . '/general-ledger?accountId=' . $foreign . '&from=2026-01-01&through=2099-12-31', null, 404);
$t->checkModule('own workspace cannot read foreign journal ID', $b, $app, 'GET',
    '/spaces/' . $other['id'] . '/general-ledger/journals/' . $reversal['id'], null, 404);
$t->check('grant viewer report access', $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('viewer can read but not export', $b, $app, 'GET', $reportPath, null, 200);
$t->checkModule('viewer export capability is denied', $b, $app, 'GET', $exportPath . $snapshot, null, 403);
$t->check('grant auditor export access', $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'auditor'], 200);
$t->checkModule('auditor can export the authorized account', $b, $app, 'GET', $exportPath . $snapshot, null, 200);
$t->check('revoke auditor access', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->checkModule('revocation denies continuation of previously authorized export', $b, $app, 'GET',
    $exportPath . $snapshot . $cursor, null, 404);
$make('new-backdated', '2025-12-30', $cash, $income, 9);
$t->checkModule('backdated posting invalidates prior page cursor', $a, $app, 'GET',
    $reportPath . $snapshot . $cursor, null, 409);
$t->checkModule('backdated posting invalidates prior export', $a, $app, 'GET',
    $exportPath . $snapshot, null, 409);
$t->checkModule('fresh report includes backdated opening exactly', $a, $app, 'GET',
    $reportPath, null, 200, static fn(array $v): bool => $v['openingMinor'] === '10009'
        && $v['closingMinor'] === '11309' && $v['rowCount'] === 53);
$t->finish();
