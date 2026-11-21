<?php
/** SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;
$t=new TestHarness();
$a='lfmaintestA'; $b='lfmaintestB'; $tag=bin2hex(random_bytes(4));
$space=$t->check('logo space',$a,'POST','/spaces',
    ['name'=>'Logo test '.$tag,'kind'=>'organization','currency'=>'BRL'],201);
$base='/spaces/'.(int)$space['id'];
$company=$t->check('legal entity without logo',$a,'POST',$base.'/companies',
    ['legalName'=>'Synthetic logo company','country'=>'PT','taxId'=>null],201);
$id=(int)$company['id'];
$api=$base.'/companies/'.$id.'/logo';
$png=trim(file_get_contents(__DIR__.'/fixtures/logo-valid.b64'));
$t->check('stranger denied upload',$b,'POST',$api,['imageBase64'=>$png],404);
$t->check('reject script or SVG upload',$a,'POST',$api,
    ['imageBase64'=>base64_encode('<svg onload=alert(1)>x</svg>')],422);
$t->check('reject malformed base64',$a,'POST',$api,['imageBase64'=>'%%%'],422);
$t->check('reject wrong company ID',$a,'POST',$base.'/companies/999991/logo',
    ['imageBase64'=>$png],404);
$uploaded=$t->check('upload valid scoped logo',$a,'POST',$api,
    ['imageBase64'=>$png],200,
    static fn(array $v):bool=>is_int($v['logoVersion']??null));
$t->check('list companies reveals logo version only',$a,'GET',$base.'/companies',null,200,
    static fn(array $v):bool=>count($v['companies']??[])===1
        && $v['companies'][0]['logo_version']===$uploaded['logoVersion']);
$t->check('viewer B cannot download without membership',$b,'GET',$api,null,404);
$t->check('grant B viewer',$a,'POST',$base.'/members',['uid'=>$b,'role'=>'viewer'],200);
$t->check('viewer cannot remove logo',$b,'DELETE',$api,null,403);
$t->check('owner removes logo',$a,'DELETE',$api,null,200,
    static fn(array $v):bool=>array_key_exists('logoVersion',$v)&&$v['logoVersion']===null);
$t->check('logo version absent from company listing',$a,'GET',$base.'/companies',null,200,
    static fn(array $v):bool=>($v['companies'][0]['logo_version']??'none')==='none');
$t->check('logo returns explicit 404 after deletion',$a,'GET',$api,null,404);
$t->finish();
