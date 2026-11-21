<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t=new TestHarness();
$a='lfmaintestA';
$b='lfmaintestB';
$tag=bin2hex(random_bytes(5));
$s=$t->check('category space',$a,'POST','/spaces',
    ['name'=>'Synthetic category taxonomy','kind'=>'collective','currency'=>'BRL'],201);
$id=(int)$s['id']; $base='/spaces/'.$id;
$acct=$t->check('category plan accounts',$a,'GET',$base.'/accounts',null,200);
$byCode=[];
foreach ($acct['accounts'] as $account){$byCode[$account['code']]=(int)$account['id'];}
$t->check('empty category list',$a,'GET',$base.'/categories',null,200,
    static fn(array $v):bool=>($v['categories']??null)===[]);
$parent=$t->check('create income parent',$a,'POST',$base.'/categories',
    ['code'=>'SALES_'.$tag,'name'=>'Sales','kind'=>'income','accountId'=>$byCode['4000']],201);
$p=(int)$parent['id'];
$child=$t->check('child inherits income account',$a,'POST',$base.'/categories',
    ['code'=>'CONSULT_'.$tag,'name'=>'Consulting','kind'=>'income','parentId'=>$p],201,
    static fn(array $v):bool=>($v['effective_account_id']??null)!==null);
$grandchild=$t->check('grandchild inherits original account',$a,'POST',$base.'/categories',
    ['code'=>'ADVICE_'.$tag,'name'=>'Advice','kind'=>'income','parentId'=>(int)$child['id']],201,
    static fn(array $v):bool=>(int)$v['effective_account_id']>0);
$t->check('cannot mix expense under income',$a,'POST',$base.'/categories',
    ['code'=>'INVALID_'.$tag,'name'=>'Cross kind','kind'=>'expense','parentId'=>$p],422);
$t->check('cannot inherit parent of another space',$b,'POST',$base.'/categories',
    ['code'=>'FOREIGN_'.$tag,'name'=>'Outside','kind'=>'income','parentId'=>$p],404);
$t->check('duplicate category code rejected',$a,'POST',$base.'/categories',
    ['code'=>'SALES_'.$tag,'name'=>'Another','kind'=>'income'],409);
$t->check('invalid account class rejected',$a,'POST',$base.'/categories',
    ['code'=>'ACC_'.$tag,'name'=>'Wrong account','kind'=>'income','accountId'=>$byCode['5000']],422);
$t->check('list shows resolved account',$a,'GET',$base.'/categories',null,200,
    static fn(array $v):bool=>count($v['categories']??[])===3
        && (int)$v['categories'][2]['effective_account_id']>0);
$t->check('unauthorized category list denied',$b,'GET',$base.'/categories',null,404);
$t->check('grant B viewer categories',$a,'POST',$base.'/members',['uid'=>$b,'role'=>'viewer'],200);
$t->check('viewer can read categories',$b,'GET',$base.'/categories',null,200);
$t->check('viewer cannot create categories',$b,'POST',$base.'/categories',
    ['code'=>'VIEW_'.$tag,'name'=>'Viewer','kind'=>'income'],403);
$t->check('revoke category viewer',$a,'DELETE',$base.'/members/'.$b,null,200);
$t->check('revoked category viewer blocked',$b,'GET',$base.'/categories',null,404);
$posting=$t->check('post journal with inherited category account',$a,'POST',
    $base.'/journals',['debitId'=>$byCode['1000'],'creditId'=>$byCode['4000'],
        'amountMinor'=>444,'date'=>'2026-11-20','memo'=>'Category tagged credit',
        'externalKey'=>'category:post:'.$tag,'categoryId'=>(int)$grandchild['id']],201);
$t->check('wrong category account denied',$a,'POST',$base.'/journals',
    ['debitId'=>$byCode['5000'],'creditId'=>$byCode['1000'],'amountMinor'=>111,
        'date'=>'2026-11-20','memo'=>'Wrong category assignment',
        'externalKey'=>'category:wrong:'.$tag,'categoryId'=>(int)$grandchild['id']],422,
    static fn(array $v):bool=>($v['error']??null)==='category_account_mismatch');
$t->check('journal list contains category reference',$a,'GET',$base.'/journals',null,200,
    static fn(array $v):bool=>count($v['journals']??[])===1
        && ($v['journals'][0]['category_id']??null)===(int)$grandchild['id']);
$t->check('reverse categorized journal',$a,'POST',
    $base.'/journals/'.(int)$posting['id'].'/reverse',null,200);
$t->check('reversal retains immutable category classification',$a,'GET',$base.'/journals',null,200,
    static fn(array $v):bool=>count($v['journals']??[])===2
        && ($v['journals'][0]['category_id']??null)===(int)$grandchild['id']
        && ($v['journals'][1]['category_id']??null)===(int)$grandchild['id']);
$t->finish();
