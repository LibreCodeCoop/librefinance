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
$tag=bin2hex(random_bytes(5));
$space=$t->check('create field-studio v3 finance space',$a,'POST','/spaces',
    ['name'=>'Fields v3 '.$tag,'kind'=>'organization','currency'=>'BRL'],201);
$id=(int)$space['id'];$base='/spaces/'.$id;
$defs=$base.'/custom-fields/contact';
$internal=$t->check('define manager-only internal contact metadata',$a,'POST',$defs,
    ['key'=>'internal_ref','label'=>'Referência interna','type'=>'text',
        'visible'=>false,'apiVisible'=>false],201,
    static fn(array $v):bool=>($v['apiVisible']??true)===false && ($v['visible']??true)===false);
$fieldId=(int)$internal['id'];
$t->check('cannot require a field hidden from routine members',$a,'POST',$defs,
    ['key'=>'hidden_required','label'=>'Hidden mandatory','type'=>'text',
        'visible'=>true,'apiVisible'=>false,'required'=>true],422);
$contact=$t->check('manager creates contact with internal metadata',$a,'POST',
    $base.'/contacts',['name'=>'Synthetic Privacy Contact '.$tag,'kind'=>'both',
        'customFields'=>[(string)$fieldId=>'Internal-only contract '.$tag]],201);
$contactId=(int)$contact['id'];
$t->check('manager retrieves internal metadata for authorized editor',$a,'GET',
    $base.'/contacts',null,200,
    static fn(array $v):bool=>($v['contacts'][0]['custom_fields'][(string)$fieldId]??null)
        !==null);
$t->check('add B as viewer',$a,'POST',$base.'/members',
    ['uid'=>$b,'role'=>'viewer'],200);
$t->check('viewer cannot list internal metadata definition',$b,'GET',$defs,null,200,
    static fn(array $v):bool=>($v['fields']??null)===[]);
$t->check('viewer does not receive internal metadata values',$b,'GET',
    $base.'/contacts',null,200,
    static fn(array $v):bool=>!array_key_exists((string)$fieldId,
        $v['contacts'][0]['custom_fields']??[]));
$t->check('viewer cannot set internal metadata on contact',$b,'PUT',
    $base.'/contacts/'.$contactId,
    ['name'=>'Changed','kind'=>'both',
        'customFields'=>[(string)$fieldId=>'stolen']],403);
$t->check('manager can rename and permit read access',$a,'PUT',
    $defs.'/'.$fieldId,['label'=>'Internal reference (revised)',
        'required'=>false,'visible'=>true,'apiVisible'=>true],200);
$t->check('viewer now sees manager-shared field definition',$b,'GET',
    $defs,null,200,
    static fn(array $v):bool=>count($v['fields']??[])===1);
$t->check('viewer now sees explicitly shared historical value',$b,'GET',
    $base.'/contacts',null,200,
    static fn(array $v):bool=>str_contains(
        (string)($v['contacts'][0]['custom_fields'][(string)$fieldId]??''),'Internal-only contract'));
$t->check('viewer cannot edit settings',$b,'PUT',
    $defs.'/'.$fieldId,['label'=>'Leaked'],403);
$t->check('remove viewer',$a,'DELETE',$base.'/members/'.$b,null,200);

// Distinct permissions: an API-only field can be read by authorized viewers
// even though they cannot see it on the regular form. The converse is not
// equivalent: a manager-visible field with apiVisible=false stays private.
$apiOnly=$t->check('create API-only metadata field',$a,'POST',$defs,
    ['key'=>'api_only_ref','label'=>'Reference exposed to integrations','type'=>'text',
        'visible'=>false,'apiVisible'=>true],201);
$private=$t->check('create manager-editable, API-restricted metadata field',$a,'POST',$defs,
    ['key'=>'manager_only_ref','label'=>'Manager audit note','type'=>'text',
        'visible'=>true,'apiVisible'=>false],201);
$apiId=(int)$apiOnly['id']; $privateId=(int)$private['id'];
$t->check('manager records both metadata classes',$a,'PUT',
    $base.'/contacts/'.$contactId,[
        'name'=>'Synthetic Privacy Contact '.$tag,'kind'=>'both',
        'customFields'=>[(string)$apiId=>'Shared with integrations '.$tag,
            (string)$privateId=>'Manager internal '.$tag],
    ],200);
$t->check('regrant viewer for independent API visibility checks',$a,'POST',
    $base.'/members',['uid'=>$b,'role'=>'viewer'],200);
$t->check('API-only definition is available but restricted definition is not',$b,'GET',
    $defs,null,200,
    static function(array $result)use($fieldId,$apiId,$privateId):bool{
        $ids=array_column($result['fields']??[],'id');
        return in_array($fieldId,$ids,true) && in_array($apiId,$ids,true)
            && !in_array($privateId,$ids,true);
    });
$t->check('API-only value is available but manager-only value is withheld',$b,'GET',
    $base.'/contacts',null,200,
    static function(array $result)use($apiId,$privateId):bool{
        $fields=$result['contacts'][0]['custom_fields']??[];
        return isset($fields[(string)$apiId])
            && !array_key_exists((string)$privateId,$fields);
    });
$t->check('revoke viewer after independent API exposure checks',$a,'DELETE',
    $base.'/members/'.$b,null,200);

// Sales and purchases have independent field definitions but share one
// invoice endpoint; values remain attached to their real records.
$invoiceKey=$t->check('create sale field',$a,'POST',
    $base.'/custom-fields/invoice',
    ['key'=>'sales_project','label'=>'Projeto da venda','type'=>'text'],201);
$billKey=$t->check('create purchase field',$a,'POST',
    $base.'/custom-fields/bill',
    ['key'=>'purchase_code','label'=>'Código da compra','type'=>'text'],201);
$invoiceId=(int)$invoiceKey['id'];$billId=(int)$billKey['id'];
$create=function(string $kind,string $number,array $fields)use($t,$a,$base,$contactId):array{
    return $t->check('create '.$kind.' with typed fields',$a,'POST',
        $base.'/invoices',[
            'number'=>$number,'kind'=>$kind,'contactId'=>$contactId,
            'issueOn'=>'2026-11-22','dueOn'=>'2026-11-30','country'=>'BR',
            'lines'=>[['description'=>'Service for '.$kind,
                'quantity'=>1,'unitPriceMinor'=>1234,'taxRuleId'=>null]],
            'customFields'=>$fields,
        ],201);
};
$sale=$create('invoice','SALE-FIELD-'.$tag,[(string)$invoiceId=>'Sales project '.$tag]);
$bill=$create('bill','BILL-FIELD-'.$tag,[(string)$billId=>'Purchase reference '.$tag]);
$t->check('sale shows matching custom field only',$a,'GET',
    $base.'/invoices/'.(int)$sale['id'],null,200,
    static fn(array $v):bool=>($v['invoice']['custom_fields'][(string)$invoiceId]??null)!==null
        && !array_key_exists((string)$billId,$v['invoice']['custom_fields']??[]));
$t->check('purchase shows its own custom field',$a,'GET',
    $base.'/invoices/'.(int)$bill['id'],null,200,
    static fn(array $v):bool=>($v['invoice']['custom_fields'][(string)$billId]??null)!==null);
$t->check('unified invoice list preserves two kinds and their fields',$a,'GET',
    $base.'/invoices',null,200,
    static fn(array $v):bool=>count($v['invoices']??[])===2
        && count(array_filter($v['invoices'],
            static fn(array $row):bool=>isset($row['custom_fields'])))===2);
$t->check('cross-space contact cannot set field value',$b,'POST',
    $base.'/invoices',[
        'number'=>'DENIED-'.$tag,'kind'=>'invoice','contactId'=>$contactId,
        'issueOn'=>'2026-11-22','dueOn'=>'2026-11-30','country'=>'BR',
        'lines'=>[['description'=>'Denied','quantity'=>1,'unitPriceMinor'=>100]],],404);
$t->finish();
