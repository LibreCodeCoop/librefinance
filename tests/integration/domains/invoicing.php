<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;
$t=new TestHarness();
$a='lfmaintestA';$b='lfmaintestB';$app='librefinance_invoicing';
$tag=bin2hex(random_bytes(5));$date='2026-11-20';
$space=$t->check('create invoicing workspace',$a,'POST','/spaces',
    ['name'=>'Synthetic invoicing','kind'=>'organization','currency'=>'BRL'],201);
$id=(int)$space['id'];$base='/spaces/'.$id;
$t->check('discover invoicing extension',$a,'GET',$base.'/modules',null,200,
    static fn(array $v):bool=>count(array_filter($v['modules']??[],
        static fn(array $m):bool=>$m['id']==='librefinance_invoicing'))===1);
$t->checkModule('B cannot list tax rules',$b,$app,'GET',$base.'/tax-rules',null,404);
$contact=$t->check('create invoice client',$a,'POST',$base.'/contacts',
    ['name'=>'Synthetic invoice contact','kind'=>'both'],201);
$cId=(int)$contact['id'];
$accounts=$t->check('read invoice chart',$a,'GET',$base.'/accounts',null,200);
$chart=[];
foreach($accounts['accounts'] as $acc){$chart[$acc['code']]=(int)$acc['id'];}
$baseTax=['code'=>'VAT_'.$tag,'label'=>'Synthetic VAT','country'=>'GB',
    'kind'=>'vat','rateBps'=>2000,'inclusive'=>false,'validFrom'=>'2026-01-01',
    'validThrough'=>'2026-12-31','sourceRef'=>'synthetic:tax-rule-v1'];
$vat=$t->checkModule('create 20 percent exclusive rule',$a,$app,'POST',
    $base.'/tax-rules',$baseTax,201);
$t->checkModule('overlapping tax periods rejected',$a,$app,'POST',$base.'/tax-rules',
    array_replace($baseTax,['validFrom'=>'2026-05-01']),409);
$t->checkModule('future tax revision permitted',$a,$app,'POST',$base.'/tax-rules',
    array_replace($baseTax,['validFrom'=>'2027-01-01','validThrough'=>null,'rateBps'=>2100]),201);
$inclusive=$t->checkModule('create inclusive VAT rule',$a,$app,'POST',$base.'/tax-rules',
    array_replace($baseTax,['code'=>'INCL_'.$tag,'inclusive'=>true]),201);
$withholding=$t->checkModule('create withholding rule',$a,$app,'POST',$base.'/tax-rules',
    array_replace($baseTax,['code'=>'WHT_'.$tag,'country'=>'BR','kind'=>'withholding','rateBps'=>500]),201);
$t->checkModule('withholding cannot be inclusive',$a,$app,'POST',$base.'/tax-rules',
    array_replace($baseTax,['code'=>'BAD_'.$tag,'kind'=>'withholding','inclusive'=>true]),422);
$t->checkModule('tax source required',$a,$app,'POST',$base.'/tax-rules',
    array_replace($baseTax,['code'=>'NOSRC_'.$tag,'sourceRef'=>'']),422);
$t->checkModule('tax list includes three active rules',$a,$app,'GET',$base.'/tax-rules',null,200,
    static fn(array $v):bool=>count($v['rules']??[])===4);
$raceCode='RACE_'.$tag;
$raceA=array_replace($baseTax,['code'=>$raceCode,'validFrom'=>'2025-01-01','validThrough'=>'2027-12-31']);
$raceB=array_replace($baseTax,['code'=>$raceCode,'validFrom'=>'2026-01-01','validThrough'=>'2026-12-31']);
[$ra,$rb]=$t->parallel(
    ['user'=>$a,'method'=>'POST','path'=>$base.'/tax-rules','data'=>$raceA,'module'=>$app],
    ['user'=>$a,'method'=>'POST','path'=>$base.'/tax-rules','data'=>$raceB,'module'=>$app],
);
$codes=[$ra['code'],$rb['code']];sort($codes);
$t->record('overlapping tax revisions under concurrency yield 201 and 409',
    $codes===[201,409],'codes '.implode(',',$codes),['one'=>$ra,'two'=>$rb]);
$invoicePayload=['number'=>'INV-'.$tag,'kind'=>'invoice','contactId'=>$cId,
    'issueOn'=>$date,'dueOn'=>'2026-12-20','country'=>'GB',
    'lines'=>[['description'=>'Synthetic consultation','quantity'=>1,
        'unitPriceMinor'=>10000,'taxRuleId'=>(int)$vat['id']]]];
$inv=$t->checkModule('create draft with 20 percent tax',$a,$app,'POST',
    $base.'/invoices',$invoicePayload,201,static fn(array $v):bool=>
    ($v['status']??null)==='draft' && ($v['totalMinor']??null)===12000
    && ($v['taxMinor']??null)===2000);
$invoiceId=(int)$inv['id'];
$t->checkModule('duplicate invoice reference rejected',$a,$app,'POST',
    $base.'/invoices',$invoicePayload,409);
$t->checkModule('B cannot inspect another space invoice',$b,$app,'GET',
    $base.'/invoices/'.$invoiceId,null,404);
$detail=$t->checkModule('draft retains tax snapshot',$a,$app,'GET',
    $base.'/invoices/'.$invoiceId,null,200,
    static fn(array $v):bool=>(int)$v['lines'][0]['tax_snapshot']['rate_bps']===2000);
$t->checkModule('future rule not applicable today',$a,$app,'POST',
    $base.'/invoices',array_replace($invoicePayload,[
        'number'=>'FUT-'.$tag,
        'lines'=>[['description'=>'Future','quantity'=>1,'unitPriceMinor'=>10000,
            'taxRuleId'=>(int)$vat['id']+1]]]),422);
$t->checkModule('operator cannot link draft',$a,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/allocations',
    ['journalId'=>999,'bankAccountId'=>$chart['1000'],'amountMinor'=>12000],409);
$t->checkModule('issue commercial invoice',$a,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/issue',null,200,
    static fn(array $v):bool=>($v['status']??null)==='issued'
        && ($v['isFiscalDocument']??true)===false);
$linked = $t->checkModule('commercial invoice creates a linked receivable', $a,
    'librefinance_obligations', 'GET', $base.'/obligations', null, 200,
    static fn(array $v): bool =>
        count(array_filter($v['obligations'] ?? [],
            static fn(array $row): bool =>
                ($row['linkedInvoiceId'] ?? null) === $invoiceId
                && ($row['kind'] ?? null) === 'receivable'
                && ($row['paid_minor'] ?? -1) === 0
                && ($row['amount_minor'] ?? null) === 12000)) === 1);
$linkedRows = array_values(array_filter($linked['obligations'] ?? [],
    static fn(array $row): bool => ($row['linkedInvoiceId'] ?? null) === $invoiceId));
$obligationId = (int)$linkedRows[0]['id'];
$t->checkModule('issued invoice shows its linked obligation ID', $a, $app, 'GET',
    $base.'/invoices/'.$invoiceId, null, 200,
    static fn(array $v): bool => ($v['obligationId'] ?? null) === $obligationId);
$t->checkModule('cannot bypass invoice ledger allocation with manual settlement', $a,
    'librefinance_obligations', 'POST',
    $base.'/obligations/'.$obligationId.'/settlements',
    ['amountMinor'=>100,'paidOn'=>$date,'operationKey'=>'invoice-test-direct-'.$tag],409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invoice_payment_via_allocation');
$t->checkModule('cannot cancel a live invoice receivable independently', $a,
    'librefinance_obligations', 'POST',
    $base.'/obligations/'.$obligationId.'/cancel', [],409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invoice_payment_via_allocation');
$t->checkModule('cannot issue same invoice twice',$a,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/issue',null,409);
$payment=$t->check('post synthetic invoice bank deposit',$a,'POST',
    $base.'/journals',['debitId'=>$chart['1000'],'creditId'=>$chart['4000'],
        'amountMinor'=>12000,'date'=>$date,'memo'=>'Synthetic invoice settlement',
        'externalKey'=>'inv:bank:'.$tag],201);
$t->checkModule('cannot allocate wrong amount',$a,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/allocations',
    ['journalId'=>(int)$payment['id'],'bankAccountId'=>$chart['1000'],'amountMinor'=>11000],404);
$t->checkModule('link posted bank transaction to invoice',$a,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/allocations',
    ['journalId'=>(int)$payment['id'],'bankAccountId'=>$chart['1000'],'amountMinor'=>12000],201,
    static fn(array $v):bool=>($v['status']??null)==='paid' && ($v['paidMinor']??null)===12000);
$t->checkModule('ledger allocation closes the exact linked receivable', $a,
    'librefinance_obligations', 'GET', $base.'/obligations', null, 200,
    static fn(array $v): bool =>
        count(array_filter($v['obligations'] ?? [],
            static fn(array $row): bool =>
                ($row['linkedInvoiceId'] ?? null) === $invoiceId
                && ($row['status'] ?? null) === 'settled'
                && ($row['paid_minor'] ?? null) === 12000)) === 1);
$t->checkModule('one obligation settlement points to exact ledger journal', $a,
    'librefinance_obligations', 'GET',
    $base.'/obligations/'.$obligationId.'/settlements', null, 200,
    static fn(array $v): bool => count($v['settlements'] ?? []) === 1
        && ($v['settlements'][0]['amount_minor'] ?? null) === 12000
        && ($v['settlements'][0]['journal_id'] ?? null) === (int)$payment['id']);
$t->check('journal linked to commercial invoice cannot be reversed silently',$a,'POST',
    $base.'/journals/'.(int)$payment['id'].'/reverse',null,409,
    static fn(array $v):bool=>($v['error']??null)==='journal_claimed');
$t->checkModule('paid invoice cannot be allocated again',$a,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/allocations',
    ['journalId'=>(int)$payment['id'],'bankAccountId'=>$chart['1000'],'amountMinor'=>12000],409);
$t->checkModule('invoice detail retains journal link',$a,$app,'GET',
    $base.'/invoices/'.$invoiceId,null,200,
    static fn(array $v):bool=>count($v['allocations']??[])===1
        && $v['invoice']['status']==='paid');
$t->checkModule('inclusive VAT decomposed without float',$a,$app,'POST',
    $base.'/invoices',array_replace($invoicePayload,[
        'number'=>'INCL-'.$tag,'lines'=>[['description'=>'Inclusive','quantity'=>1,
            'unitPriceMinor'=>10000,'taxRuleId'=>(int)$inclusive['id']]]]),201,
    static fn(array $v):bool=>($v['totalMinor']??null)===10000
        && ($v['taxMinor']??null)===1667 && ($v['subtotalMinor']??null)===8333);
$t->checkModule('withholding reduces net amount',$a,$app,'POST',
    $base.'/invoices',array_replace($invoicePayload,[
        'number'=>'WHT-'.$tag,'country'=>'BR','lines'=>[['description'=>'Withheld','quantity'=>1,
            'unitPriceMinor'=>10000,'taxRuleId'=>(int)$withholding['id']]]]),201,
    static fn(array $v):bool=>($v['totalMinor']??null)===9500
        && ($v['withheldMinor']??null)===500);
$t->checkModule('tax jurisdiction mismatch denied',$a,$app,'POST',
    $base.'/invoices',array_replace($invoicePayload,[
        'number'=>'REGION-'.$tag,'country'=>'BR']),422,
    static fn(array $v):bool=>($v['error']??null)==='tax_jurisdiction_mismatch');
$t->checkModule('invalid issue date rejected',$a,$app,'POST',
    $base.'/invoices',array_replace($invoicePayload,[
        'number'=>'DATE-'.$tag,'issueOn'=>'2026-02-30']),422);
$t->checkModule('cross-space contact denied',$a,$app,'POST',
    $base.'/invoices',array_replace($invoicePayload,[
        'number'=>'CONTACT-'.$tag,'contactId'=>999999]),404);
$t->checkModule('B denied creating invoice',$b,$app,'POST',$base.'/invoices',
    array_replace($invoicePayload,['number'=>'INTRUDER-'.$tag]),404);
$t->check('grant invoice viewer',$a,'POST',$base.'/members',['uid'=>$b,'role'=>'viewer'],200);
$t->checkModule('viewer sees invoices',$b,$app,'GET',$base.'/invoices',null,200);
$t->checkModule('viewer cannot create tax rules',$b,$app,'POST',
    $base.'/tax-rules',array_replace($baseTax,['code'=>'VIEW_'.$tag]),403);
$t->checkModule('viewer cannot issue invoice',$b,$app,'POST',
    $base.'/invoices/'.$invoiceId.'/issue',null,403);
$t->check('revoke invoice viewer',$a,'DELETE',$base.'/members/'.$b,null,200);
$t->checkModule('revoked viewer cannot see invoices',$b,$app,'GET',$base.'/invoices',null,404);
$t->finish();
