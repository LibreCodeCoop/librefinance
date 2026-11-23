<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA'; $b = 'lfmaintestB';
$nonce = bin2hex(random_bytes(5));
$space = $t->check('create isolated activity test space', $a, 'POST', '/spaces',
    ['name' => 'Synthetic money activity ' . $nonce, 'kind' => 'personal', 'currency' => 'BRL'], 201);
$base = '/spaces/' . (int)$space['id'];
$t->check('new workspace has empty activity', $a, 'GET', $base.'/activity', null, 200,
    static fn(array $v): bool => ($v['items'] ?? null) === [] && ($v['nextCursor'] ?? null) === null);
$t->check('unauthorized reader cannot see activity', $b, 'GET', $base.'/activity', null, 404);
foreach (['state=DELETED', 'direction=income;drop', 'accountId=-1',
    'cursor=2026-02-30:3:1', 'cursor=2026-11-20:2:0',
    'search='.str_repeat('a',121)] as $invalid) {
    $t->check('invalid query rejected '.$invalid[0], $a, 'GET',
        $base.'/activity?'.$invalid, null, 422);
}
$accounts = $t->check('read default chart', $a, 'GET', $base.'/accounts', null, 200);
$chart = [];
foreach ($accounts['accounts'] as $account) $chart[$account['code']] = (int)$account['id'];
$bank = $chart['1000']; $income = $chart['4000']; $expense = $chart['5000'];
$planned = $t->check('create expected income', $a, 'POST', $base.'/obligations',
    ['kind'=>'receivable','description'=>'Scheduled rental payment '.$nonce,
     'reference'=>'ACT-'.$nonce, 'amountMinor'=>15000,
     'issuedOn'=>'2026-11-15','dueOn'=>'2026-11-23'],201);
$posted = $t->check('create actual expense', $a, 'POST', $base.'/journals',
    ['debitId'=>$expense, 'creditId'=>$bank, 'amountMinor'=>2340,
     'date'=>'2026-11-20','memo'=>'Actual electricity expense '.$nonce,
     'externalKey'=>'activity:expense:'.$nonce],201);
$import = $t->check('import independent bank evidence', $a, 'POST',
    $base.'/imports',
    ['bankAccountId'=>$bank,
     'csv'=>"id,date,amount,description\n"
       . "act-$nonce,2026-11-21,-23.40,Unmatched electricity statement $nonce\n"],201);
$read = $t->check('activity has three distinct source records', $a, 'GET',
    $base.'/activity?search='.$nonce, null, 200,
    static fn(array $v):bool=>count($v['items']??[])===3
        && ($v['items'][0]['state']??null)==='planned'
        && ($v['items'][1]['state']??null)==='pending'
        && ($v['items'][2]['state']??null)==='posted');
$map = [];
foreach($read['items'] as $row) $map[$row['source']]=$row;
$t->record('only journal affects booked cash', $map['journal']['countsAsCash'] === true
        && $map['journal']['amountMinor'] === -2340
        && $map['statement']['countsAsCash'] === false
        && $map['statement']['amountMinor'] === -2340
        && $map['obligation']['countsAsCash'] === false
        && $map['obligation']['amountMinor'] === 15000,
    'cash != imported evidence != future due');
$t->check('bank filter excludes unassigned future obligations', $a,'GET',
    $base.'/activity?accountId='.$bank.'&search='.$nonce,null,200,
    static fn(array $v):bool=>count($v['items']??[])===2
        && !in_array('obligation',array_column($v['items'],'source'),true));
$t->check('realised filter does not include bank evidence', $a,'GET',
    $base.'/activity?state=posted&search='.$nonce,null,200,
    static fn(array $v):bool=>count($v['items']??[])===1
        && ($v['items'][0]['source']??null)==='journal');
$t->check('income filter does not include expense', $a,'GET',
    $base.'/activity?direction=in&search='.$nonce,null,200,
    static fn(array $v):bool=>count($v['items']??[])===1
        && ($v['items'][0]['source']??null)==='obligation');
$t->check('other spaces cannot leak through bank id', $b, 'GET',
    $base.'/activity?accountId='.$bank, null, 404);
for($i=0;$i<55;$i++){
    $t->check('create paginated obligation '.$i, $a,'POST',$base.'/obligations',
      ['kind'=>'payable','description'=>'Future batch '.$nonce.' '.$i,
       'reference'=>'BATCH-'.$nonce.'-'.$i, 'amountMinor'=>1000+$i,
       'issuedOn'=>'2026-11-15', 'dueOn'=>'2026-12-01'],201);
}
$first=$t->check('keyset first 50 results', $a,'GET',
    $base.'/activity?search='.$nonce,null,200,
    static fn(array $v):bool=>count($v['items']??[])===50
        && is_string($v['nextCursor']??null));
$next=$t->check('keyset remaining results', $a,'GET',
    $base.'/activity?search='.$nonce.'&cursor='.rawurlencode($first['nextCursor']),null,200,
    static fn(array $v):bool=>count($v['items']??[])===8
        && ($v['nextCursor']??null)===null);
$keys=array_map(static fn(array $r)=>$r['source'].':'.$r['sourceId'],
    array_merge($first['items'],$next['items']));
$t->record('no duplicates and date-order complete',count(array_unique($keys))===58
    && count($keys)===58,'58 source identities on 2 pages');
$t->check('disable optional obligation feature for this space', $a,'PUT',
    $base.'/modules/librefinance_obligations',['enabled'=>false],200);
$t->check('core activity still available without obligations', $a,'GET',
    $base.'/activity?search='.$nonce,null,200,
    static fn(array $v):bool=>count($v['items']??[])===2
      && !in_array('obligation',array_column($v['items'],'source'),true));
$t->finish();
