<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;
$t = new TestHarness();
$a='lfmaintestA'; $b='lfmaintestB'; $tag=bin2hex(random_bytes(5));
$space=$t->check('create delete-test space', $a, 'POST', '/spaces',
    ['name'=>'Account deletion '.$tag,'kind'=>'organization','currency'=>'BRL'], 201);
$base='/spaces/'.(int)$space['id'];
$plan=$t->check('load original chart', $a, 'GET', $base.'/accounts',null,200);
$byCode=array_column($plan['accounts'],'id','code');
$created=$t->check('create new unused account',$a,'POST',$base.'/accounts',
    ['code'=>'TEST_'.strtoupper($tag),'name'=>'Disposable account','class'=>'asset','isBank'=>true],201);
$unused=(int)$created['id'];
$t->check('stranger cannot preview',$b,'GET',$base.'/accounts/'.$unused.'/deletion',null,404);
$t->check('unused account preflight',$a,'GET',$base.'/accounts/'.$unused.'/deletion',null,200,
    static fn(array $v):bool=>($v['canDelete']??null)===true && $v['reasons']===[]);
$t->check('stranger denied account deletion',$b,'DELETE',$base.'/accounts/'.$unused,null,404);
$t->check('delete unused account',$a,'DELETE',$base.'/accounts/'.$unused,null,200,
    static fn(array $v):bool=>($v['deleted']??false)===true);
$t->check('repeat delete is not found',$a,'DELETE',$base.'/accounts/'.$unused,null,404);
$t->check('unused account absent from list',$a,'GET',$base.'/accounts',null,200,
    static fn(array $v):bool=>count(array_filter($v['accounts'],
        static fn(array $x):bool=>$x['id']===$unused))===0);
$t->check('create category referencing account',$a,'POST',$base.'/categories',
    ['code'=>'LINK_'.$tag,'name'=>'Linked expense','kind'=>'expense',
      'accountId'=>(int)$byCode['5000']],201);
$t->check('account with category is blocked',$a,'GET',$base.'/accounts/'.$byCode['5000'].'/deletion',null,200,
    static fn(array $v):bool=>in_array('category_links',$v['reasons'],true));
$t->check('reject category-linked deletion',$a,'DELETE',$base.'/accounts/'.$byCode['5000'],null,409,
    static fn(array $v):bool=>($v['error']??null)==='account_in_use');
$hist=$t->check('create account for historical reversal',$a,'POST',$base.'/accounts',
    ['code'=>'HIST_'.strtoupper($tag),'name'=>'History account','class'=>'asset','isBank'=>true],201);
$hid=(int)$hist['id'];
$post=$t->check('post historical entry',$a,'POST',$base.'/journals',
    ['debitId'=>(int)$byCode['5000'],'creditId'=>$hid,
      'amountMinor'=>2599,'date'=>'2026-11-21','memo'=>'Posted then reversed',
      'externalKey'=>'hist:'.$tag.':A'],201);
$t->check('reverse entry and net balance',$a,'POST',$base.'/journals/'.(int)$post['id'].'/reverse',null,200);
$t->check('zero-balance account still has activity',$a,'GET',$base.'/accounts/'.$hid.'/deletion',null,200,
    static fn(array $v):bool=>in_array('journal_entries',$v['reasons'],true));
$t->check('zero-balance historical account cannot be deleted',$a,'DELETE',$base.'/accounts/'.$hid,null,409);
$t->check('enable banking',$a,'PUT',$base.'/modules/librefinance_banking',['enabled'=>true],200);
$bank=$t->check('create bank for pending statement',$a,'POST',$base.'/accounts',
    ['code'=>'STMT_'.strtoupper($tag),'name'=>'Pending statement','class'=>'asset','isBank'=>true],201);
$bid=(int)$bank['id'];
$csv="id,date,amount,description\n".$tag.",2026-11-21,1.00,Synthetic bank statement\n";
$t->checkModule('import unmatched statement',$a,'librefinance_banking','POST',$base.'/imports',
    ['bankAccountId'=>$bid,'csv'=>$csv],201);
$t->check('unposted statement blocks deletion',$a,'GET',$base.'/accounts/'.$bid.'/deletion',null,200,
    static fn(array $v):bool=>in_array('bank_transactions',$v['reasons'],true));
$t->check('unposted bank transaction prevents deletion',$a,'DELETE',$base.'/accounts/'.$bid,null,409);
$t->check('share viewer for ACL',$a,'POST',$base.'/members',['uid'=>$b,'role'=>'viewer'],200);
$t->check('viewer denied deletion preflight',$b,'GET',$base.'/accounts/'.$bid.'/deletion',null,403);
$t->check('viewer denied DELETE',$b,'DELETE',$base.'/accounts/'.$bid,null,403);
$t->check('owner retains account after rejected DELETE',$a,'GET',$base.'/accounts',null,200,
    static fn(array $v):bool=>count(array_filter($v['accounts'],
      static fn(array $x):bool=>$x['id']===$bid && $x['active']))===1);
$t->check('revoke viewer',$a,'DELETE',$base.'/members/'.$b,null,200);
$t->finish();
