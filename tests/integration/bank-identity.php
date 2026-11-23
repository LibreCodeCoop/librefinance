<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t=new TestHarness();
$a='lfmaintestA'; $b='lfmaintestB';
$token=bin2hex(random_bytes(5));
$space=$t->check('create isolated synthetic bank identity space', $a, 'POST', '/spaces',
    ['name'=>'Bank identity '.$token,'kind'=>'organization','currency'=>'BRL'], 201);
$base='/spaces/'.(int)$space['id'];
$bank=$t->check('create bank with optional structured identity',$a,'POST',$base.'/accounts',[
    'code'=>'BK_'.$token,'name'=>'Synthetic savings','class'=>'asset','isBank'=>true,
    'bankDetails'=>[
        'institutionName'=>'Test Financial Institution',
        'institutionCode'=>'TEST-123',
        'branchNumber'=>'0001','accountNumber'=>'9999-0','accountType'=>'savings',
        'iban'=>'GB82WEST12345698765432','swiftBic'=>'DEUTDEFF',
    ],
],201);
$id=(int)$bank['id'];
$api=$base.'/accounts/'.$id.'/logo';
$t->check('bank profile visible in scoped listing',$a,'GET',$base.'/accounts',null,200,
    static fn(array $response):bool => count(array_filter($response['accounts']??[],
        static fn(array $item):bool => (int)$item['id']===$id
            && $item['institution_name']==='Test Financial Institution'
            && $item['branch_number']==='0001'
            && $item['swift_bic']==='DEUTDEFF'))===1);
$t->check('non-member cannot inspect bank profile',$b,'GET',$base.'/accounts',null,404);
$t->check('reject unsupported bank identity keys',$a,'PUT',$base.'/accounts/'.$id,
    ['name'=>'Synthetic savings','bankDetails'=>['password'=>'not-allowed']],422);
$t->check('reject malformed SWIFT identifier',$a,'PUT',$base.'/accounts/'.$id,
    ['name'=>'Synthetic savings','bankDetails'=>['swiftBic'=>'XXX']],422);
$t->check('update optional branch without rewriting the ledger',$a,'PUT',$base.'/accounts/'.$id,
    ['name'=>'Synthetic savings','active'=>true,'bankDetails'=>['branchNumber'=>'0002']],200);
$png=trim(file_get_contents(__DIR__.'/fixtures/logo-valid.b64'));
$t->check('reject outsider uploading a bank logo',$b,'POST',$api,['imageBase64'=>$png],404);
$t->check('reject SVG used as bank logo',$a,'POST',$api,[
    'imageBase64'=>base64_encode('<svg onload=alert(1)>x</svg>')],422);
$logo=$t->check('store validated bank logo privately',$a,'POST',$api,
    ['imageBase64'=>$png],200,static fn(array $r):bool=>is_int($r['logoVersion']??null));
$t->check('logo version attached to account in listing',$a,'GET',$base.'/accounts',null,200,
    static fn(array $r):bool=>count(array_filter($r['accounts']??[],
        static fn(array $item):bool=>(int)$item['id']===$id
            && (int)$item['logo_version']===$logo['logoVersion']))===1);
$t->check('account with private logo cannot be physically deleted',$a,'GET',
    $base.'/accounts/'.$id.'/deletion',null,200,
    static fn(array $r):bool => $r['canDelete']===false
        && in_array('bank_logo',$r['reasons'],true));
$t->check('remove bank logo',$a,'DELETE',$api,null,200);
$t->check('removed bank logo is not served',$a,'GET',$api,null,404);
$t->finish();
