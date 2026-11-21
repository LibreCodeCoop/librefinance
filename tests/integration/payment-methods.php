<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require_once __DIR__.'/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;
$t=new TestHarness();
$a='lfmaintestA';$b='lfmaintestB';$tag=bin2hex(random_bytes(4));
$space=$t->check('payment methods isolated space',$a,'POST','/spaces',
    ['name'=>'Payment test '.$tag,'kind'=>'organization','currency'=>'BRL'],201);
$base='/spaces/'.(int)$space['id'];
$first=$t->check('default offline methods seeded',$a,'GET',$base.'/payment-methods',null,200,
    static fn(array $v):bool=>count($v['methods']??[])===2 &&
        array_column($v['methods'],'kind')===['cash','bank_transfer']
        && $v['automaticProvidersConnected']===false);
$t->check('stranger cannot read payment methods',$b,'GET',$base.'/payment-methods',null,404);
$t->check('stranger cannot create payment method',$b,'POST',$base.'/payment-methods',
    ['name'=>'Forbidden','kind'=>'digital_wallet'],404);
$accounts=$t->check('read available financial accounts',$a,'GET',$base.'/accounts',null,200);
$byCode=array_column($accounts['accounts'],'id','code');
$bank=$t->check('create secondary bank account',$a,'POST',$base.'/accounts',
    ['code'=>'PAYBANK_'.$tag,'name'=>'Payment linked bank','class'=>'asset','isBank'=>true],201);
$bid=(int)$bank['id'];
$method=$t->check('create linked payment method',$a,'POST',$base.'/payment-methods',
    ['name'=>'Company card','kind'=>'credit_card','bankAccountId'=>$bid],201,
    static fn(array $v):bool=>($v['kind']??null)==='credit_card'
        && ($v['bank_account_id']??null)===$bid);
$mid=(int)$method['id'];
$t->check('nonexistent bank account rejected',$a,'POST',$base.'/payment-methods',
    ['name'=>'Bad bank','kind'=>'bank_transfer','bankAccountId'=>999999],422);
$t->check('invalid method type rejected',$a,'POST',$base.'/payment-methods',
    ['name'=>'Bad provider','kind'=>'stripe_connected'],422);
$t->check('payment association protects account deletion',$a,'GET',
    $base.'/accounts/'.$bid.'/deletion',null,200,
    static fn(array $v):bool=>in_array('payment_methods',$v['reasons']??[],true));
$baseJournal=['debitId'=>(int)$byCode['5000'],'creditId'=>$bid,'amountMinor'=>1555,
    'date'=>'2026-11-21','memo'=>'Company card test',
    'paymentMethodId'=>$mid,'externalKey'=>'pm:'.$tag.':pay'];
$t->check('linked payment method cannot be used on another account',$a,'POST',
    $base.'/journals',array_replace($baseJournal,[
        'creditId'=>(int)$byCode['1000'],'externalKey'=>'pm:'.$tag.':wrong',
    ]),422);
$entry=$t->check('journal persists payment method id',$a,'POST',
    $base.'/journals',$baseJournal,201);
$t->check('journal list includes payment method metadata',$a,'GET',
    $base.'/journals',null,200,
    static fn(array $v):bool=>($v['journals'][0]['payment_method_id']??null)===$mid);
$t->check('used method bank link cannot be rewritten',$a,'PUT',
    $base.'/payment-methods/'.$mid,['name'=>'Company card','bankAccountId'=>$byCode['1000']],409,
    static fn(array $v):bool=>($v['error']??null)==='payment_method_in_use');
$t->check('used payment method can be deactivated',$a,'PUT',
    $base.'/payment-methods/'.$mid,
    ['name'=>'Company card retired','bankAccountId'=>$bid,'active'=>false],200,
    static fn(array $v):bool=>$v['active']===false);
$t->check('disabled method rejected in new journal',$a,'POST',
    $base.'/journals',array_replace($baseJournal,['externalKey'=>'pm:'.$tag.':inactive']),404);
$t->check('used method cannot be deleted',$a,'DELETE',
    $base.'/payment-methods/'.$mid,null,409);
$unused=$t->check('create one-off method never used',$a,'POST',
    $base.'/payment-methods',['name'=>'Temporary wallet','kind'=>'digital_wallet'],201);
$t->check('unused method is deletable',$a,'DELETE',
    $base.'/payment-methods/'.(int)$unused['id'],null,200);
$t->check('deleted payment method gone',$a,'GET',$base.'/payment-methods',null,200,
    static fn(array $v):bool=>count($v['methods']??[])===3);
$t->check('grant viewer',$a,'POST',$base.'/members',['uid'=>$b,'role'=>'viewer'],200);
$t->check('viewer sees methods',$b,'GET',$base.'/payment-methods',null,200);
$t->check('viewer cannot rename method',$b,'PUT',$base.'/payment-methods/'.$mid,
    ['name'=>'Changed'],403);
$t->check('viewer cannot remove method',$b,'DELETE',$base.'/payment-methods/'.$mid,null,403);
$t->check('revoke viewer',$a,'DELETE',$base.'/members/'.$b,null,200);
$t->finish();
