<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA'; $b = 'lfmaintestB'; $app = 'librefinance_obligations';
$token = bin2hex(random_bytes(6));
$space = $t->check('search fixture workspace', $a, 'POST', '/spaces',
    ['name' => 'Agenda sorting ' . $token, 'kind' => 'personal', 'currency' => 'BRL'], 201);
$base = '/spaces/' . (int)$space['id'];
$ids = [];
for ($i = 1; $i <= 57; ++$i) {
    $amount = 1000 + ($i % 8) * 100;
    $due = sprintf('2026-12-%02d', 1 + ($i % 20));
    $created = $t->checkModule('create sortable agenda title '.$i,
        $a, $app, 'POST', $base.'/obligations', [
            'reference' => sprintf('AGENDASORT-%s-%02d', $token, $i),
            'kind' => $i % 2 ? 'payable' : 'receivable',
            'description' => 'AgendaCase '. $token . ' entry ' . sprintf('%02d', $i),
            'amountMinor' => $amount,
            'issuedOn' => '2026-11-20', 'dueOn' => $due,
        ], 201);
    $ids[] = (int)$created['id'];
}
$t->checkModule('bystander cannot text search another space', $b, $app, 'GET',
    $base.'/obligations?search=AgendaCase', null, 404);
$t->checkModule('unsupported sort fields are denied', $a, $app, 'GET',
    $base.'/obligations?sortBy=space_id', null, 422,
    static fn(array $v): bool => ($v['error'] ?? '') === 'invalid_sort');
$t->checkModule('unexpected SQL sort direction denied', $a, $app, 'GET',
    $base.'/obligations?sortDirection=DROP', null, 422);
$t->checkModule('overlong search term rejected', $a, $app, 'GET',
    $base.'/obligations?search='.str_repeat('a', 121), null, 422);
$t->checkModule('wildcard only is not an unfiltered search', $a, $app, 'GET',
    $base.'/obligations?search=%25%5F', null, 200,
    static fn(array $v): bool => ($v['obligations'] ?? null) === []);
$run = static function (string $column, string $direction) use ($t, $a, $app, $base, $token): array {
    $list = []; $cursor = null;
    for ($page = 0; $page < 3; ++$page) {
        $url = $base.'/obligations?sortBy='.$column
            .'&sortDirection='.$direction.'&search=AgendaCase%20'.$token
            .($cursor ? '&afterId='.$cursor : '');
        $result = $t->checkModule('sorted '.$column.' '.$direction.' page '.($page + 1),
            $a, $app, 'GET', $url, null, 200);
        foreach ($result['obligations'] as $row) {
            $list[] = $row;
        }
        $cursor = $result['nextAfterId'];
        if (!$cursor) break;
    }
    return $list;
};
foreach ([['amount','desc'], ['amount','asc'], ['due','asc'],
    ['description','desc'], ['reference','asc']] as [$column, $direction]) {
    $rows = $run($column, $direction);
    if (count($rows) !== 57 || count(array_unique(array_column($rows,'id'))) !== 57) {
        throw new RuntimeException('Pagination lost or repeated a record for '.$column.' '.$direction);
    }
    $property = ['amount'=>'amount_minor','due'=>'due_on',
        'description'=>'description','reference'=>'reference'][$column];
    for ($i = 1; $i < count($rows); ++$i) {
        $previous = $rows[$i-1];
        $current = $rows[$i];
        $compare = $previous[$property] <=> $current[$property];
        if ($compare === 0) $compare = (int)$previous['id'] <=> (int)$current['id'];
        if (($direction === 'asc' && $compare > 0)
            || ($direction === 'desc' && $compare < 0)) {
            throw new RuntimeException('Misordered '.$column.' '.$direction);
        }
    }
    echo 'PASS stable sorted complete '.$column.' '.$direction.' 57 unique rows' . PHP_EOL;
}
$t->checkModule('user text search filters before server pagination', $a, $app,
    'GET', $base.'/obligations?search=entry%2057', null, 200,
    static fn(array $v): bool => count($v['obligations'] ?? []) === 1);
$t->finish();
