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
$day = new DateTimeImmutable('today');
$date = $day->format('Y-m-d');
$space = $t->check('create synthetic candidate workspace', $a, 'POST', '/spaces',
    ['name' => 'Synthetic reconciliation ' . $tag, 'kind' => 'personal', 'currency' => 'BRL'], 201);
$base = '/spaces/' . $space['id'];
$chart = $t->check('read bank and counterpart accounts', $a, 'GET', $base . '/accounts', null, 200);
$ids = array_column($chart['accounts'], 'id', 'code');
$bank = (int)$ids['1000'];
$income = (int)$ids['4000'];
$expense = (int)$ids['5000'];
$secondBank = $t->check('create separate bank account', $a, 'POST', $base . '/accounts',
    ['code' => '1100', 'name' => 'Synthetic other bank', 'class' => 'asset', 'isBank' => true], 201);
$make = static function(string $key, string $on, int $debit, int $credit, int $amount)
    use ($t, $a, $base, $tag): array {
    return $t->check('post candidate fixture ' . $key, $a, 'POST', $base . '/journals', [
        'debitId' => $debit, 'creditId' => $credit, 'amountMinor' => $amount,
        'date' => $on, 'memo' => 'Synthetic candidate ' . $key, 'externalKey' => 'rc:' . $tag . ':' . $key,
    ], 201);
};
$csv = "id,date,amount,description\n";
foreach (['target' => '42.00', 'claimed' => '42.00', 'late' => '42.00', 'out' => '-42.00', 'empty' => '19.00'] as $id => $amount) {
    $csv .= "$id-$tag,$date,$amount,Synthetic statement $id\n";
}
$t->checkModule('stage five pending rows without posting', $a, $app, 'POST', $base . '/imports',
    ['bankAccountId' => $bank, 'csv' => $csv], 201,
    static fn(array $v): bool => $v['imported'] === 5);
$statement = $t->checkModule('read pending rows', $a, $app, 'GET', $base . '/transactions', null, 200);
$lines = array_column($statement['transactions'], 'id', 'external_id');
$route = static fn(string $id): string => $base . '/transactions/' . $lines[$id . '-' . $tag] . '/reconciliation-candidates';
$match = static fn(string $id): string => $base . '/transactions/' . $lines[$id . '-' . $tag] . '/match';
$path = $route('target');
$t->checkModule('empty search explicitly reports no candidate', $a, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => $v['status'] === 'no_candidate' && $v['candidates'] === []
        && $v['nextAfterId'] === null && $v['evidence']['sourceReferenceComparison'] === 'unavailable'
        && $v['evidence']['sourceReference'] === 'target-' . $tag);
$make('wrong-account', $date, (int)$secondBank['id'], $income, 4200);
$opposite = $make('opposite-sign', $date, $expense, $bank, 4200);
$make('wrong-amount', $date, $bank, $income, 4201);
$make('outside-window', $day->modify('+4 days')->format('Y-m-d'), $bank, $income, 4200);
$reversed = $make('reversed', $date, $bank, $income, 4200);
$t->check('reverse synthetic candidate', $a, 'POST', $base . '/journals/' . $reversed['id'] . '/reverse', null, 200);
$claimed = $make('already-claimed', $date, $bank, $income, 4200);
$t->checkModule('claim a different statement row', $a, $app, 'POST', $match('claimed'), ['journalId' => $claimed['id']], 200);
$one = $make('unique', $date, $bank, $income, 4200);
$t->checkModule('unique candidate excludes wrong sign, account, date, reversed and claimed records',
    $a, $app, 'GET', $path, null, 200, static fn(array $v): bool =>
        $v['status'] === 'unique' && $v['candidateStatus'] === 'unique' && count($v['candidates']) === 1
        && $v['candidates'][0]['journalId'] === $one['id']
        && $v['candidates'][0]['amountMinor'] === 4200 && $v['candidates'][0]['accountId'] === $bank
        && $v['candidates'][0]['reasons'] === ['same_account', 'exact_signed_amount', 'same_booking_date']
        && $v['evidence']['from'] === $day->modify('-3 days')->format('Y-m-d')
        && $v['evidence']['through'] === $day->modify('+3 days')->format('Y-m-d'));
$t->checkModule('negative evidence only suggests actual negative non-reversal bank leg', $a, $app, 'GET',
    $route('out'), null, 200, static fn(array $v): bool => $v['status'] === 'unique'
        && $v['candidates'][0]['journalId'] === $opposite['id'] && $v['candidates'][0]['amountMinor'] === -4200);
$t->checkModule('already reconciled evidence refuses lookup', $a, $app, 'GET', $route('claimed'), null, 409);
$two = $make('boundary', $day->modify('+3 days')->format('Y-m-d'), $bank, $income, 4200);
$t->checkModule('equal candidates remain ambiguous on inclusive window boundary', $a, $app, 'GET', $path,
    null, 200, static fn(array $v): bool => $v['status'] === 'ambiguous'
        && array_column($v['candidates'], 'journalId') === [$one['id'], $two['id']]
        && $v['candidates'][1]['reasons'][2] === 'within_date_window');
$t->checkModule('explicit zero-day window includes exact booking date only', $a, $app, 'GET',
    $path . '?windowDays=0', null, 200, static fn(array $v): bool => $v['status'] === 'unique'
        && $v['candidates'][0]['journalId'] === $one['id']);
$t->checkModule('wider explicitly requested window includes day four', $a, $app, 'GET',
    $path . '?windowDays=4', null, 200, static fn(array $v): bool => count($v['candidates']) === 3);
$t->checkModule('invalid date window denied', $a, $app, 'GET', $path . '?windowDays=31', null, 422);
$t->checkModule('negative cursor denied', $a, $app, 'GET', $path . '?afterId=-1', null, 422);
for ($i = 0; $i < 49; ++$i) {
    $make('page-' . $i, $date, $bank, $income, 4200);
}
$before = [
    $t->request($a, 'GET', $base . '/accounts'),
    $t->request($a, 'GET', $base . '/journals'),
    $t->requestModule($a, $app, 'GET', $base . '/transactions'),
];
$page = $t->checkModule('bounded first page discloses incomplete lookup, not a unique match', $a, $app,
    'GET', $path, null, 200, static fn(array $v): bool => $v['status'] === 'incomplete_lookup'
        && $v['candidateStatus'] === 'ambiguous' && count($v['candidates']) === 50 && $v['nextAfterId'] !== null);
$next = $t->checkModule('final page preserves global ambiguity even with one remaining candidate', $a, $app,
    'GET', $path . '?afterId=' . $page['nextAfterId'], null, 200,
    static fn(array $v): bool => $v['status'] === 'ambiguous' && $v['candidateStatus'] === 'ambiguous'
        && count($v['candidates']) === 1 && $v['nextAfterId'] === null);
$t->record('all 51 journal IDs are distinct and ordered',
    count(array_unique(array_column(array_merge($page['candidates'], $next['candidates']), 'journalId'))) === 51
        && $next['candidates'][0]['journalId'] > $page['nextAfterId'], 'stable keyset continuation');
$after = [
    $t->request($a, 'GET', $base . '/accounts'),
    $t->request($a, 'GET', $base . '/journals'),
    $t->requestModule($a, $app, 'GET', $base . '/transactions'),
];
$t->record('candidate reads create no journals or matches and do not alter balances', $before === $after, 'identical authorized state');
$t->checkModule('unrelated actor cannot discover candidates', $b, $app, 'GET', $path, null, 404);
$other = $t->check('create unrelated actor workspace', $b, 'POST', '/spaces',
    ['name' => 'Synthetic other reconciliation', 'kind' => 'personal', 'currency' => 'BRL'], 201);
$t->checkModule('foreign transaction ID in own workspace is denied', $b, $app, 'GET',
    '/spaces/' . $other['id'] . '/transactions/' . $lines['target-' . $tag] . '/reconciliation-candidates', null, 404);
$t->check('grant viewer access', $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('viewer cannot look up actionable reconciliation candidates', $b, $app, 'GET', $path, null, 403);
$t->check('grant operator access', $a, 'POST', $base . '/members', ['uid' => $b, 'role' => 'operator'], 200);
$t->checkModule('authorized operator sees same scoped candidates', $b, $app, 'GET', $path, null, 200);
$t->checkModule('another actor claims one candidate via authoritative match', $b, $app, 'POST', $match('late'),
    ['journalId' => $one['id']], 200);
$t->checkModule('stale suggestion never authorizes a second claim', $a, $app, 'POST', $match('target'),
    ['journalId' => $one['id']], 409);
$t->checkModule('refresh excludes newly claimed candidate', $a, $app, 'GET', $path, null, 200,
    static fn(array $v): bool => !in_array($one['id'], array_column($v['candidates'], 'journalId'), true)
        && count($v['candidates']) === 50 && $v['nextAfterId'] === null);
$t->checkModule('explicit confirmation of remaining candidate uses original matching path', $a, $app, 'POST',
    $match('target'), ['journalId' => $two['id']], 200);
$t->checkModule('matched evidence no longer accepts suggestions', $a, $app, 'GET', $path, null, 409);
$t->check('revoke operator', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->checkModule('revoked operator cannot query another pending row', $b, $app, 'GET', $route('empty'), null, 404);
$t->finish();
