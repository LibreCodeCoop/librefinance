<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
require_once dirname(__DIR__, 5) . '/lib/base.php';
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
$bill=$t->checkModule('create supplier bill in the same account space', $a, $app,
    'POST', $base.'/invoices', array_replace($invoicePayload, [
        'number'=>'BILL-'.$tag, 'kind'=>'bill',
        'lines'=>[['description'=>'Supplier material','quantity'=>1,
            'unitPriceMinor'=>1000,'taxRuleId'=>null]],
    ]), 201, static fn(array $v):bool=>($v['status']??null)==='draft'
        && ($v['totalMinor']??null)===1000);
$t->checkModule('sales query excludes supplier bills before pagination', $a, $app,
    'GET', $base.'/invoices?kind=invoice', null, 200,
    static fn(array $v):bool=>count(array_filter($v['invoices']??[],
        static fn(array $row):bool=>$row['kind']!=='invoice'))===0
        && count($v['invoices']??[])===1);
$t->checkModule('supplier bills query excludes sales before pagination', $a, $app,
    'GET', $base.'/invoices?kind=bill', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===1
        && (int)$v['invoices'][0]['id']===(int)$bill['id']);
$t->checkModule('backward-compatible unfiltered invoices endpoint', $a, $app,
    'GET', $base.'/invoices', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===2);
$t->checkModule('work queue sees both original drafts but no invented payment', $a, $app,
    'GET', $base.'/invoices?status=draft', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===2
        && count(array_filter($v['invoices'],
            static fn(array $row):bool=>$row['status']!=='draft'))===0);
$t->checkModule('work queue filters sales drafts before pagination', $a, $app,
    'GET', $base.'/invoices?kind=invoice&status=draft', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===1
        && $v['invoices'][0]['kind']==='invoice');
$t->checkModule('attention projection includes current drafts before issuing', $a, $app,
    'GET', $base.'/invoices?status=attention', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===2);
$t->checkModule('unsupported work queue status rejected by server', $a, $app,
    'GET', $base.'/invoices?status=deleted', null, 422,
    static fn(array $v):bool=>($v['error']??null)==='invalid_invoice_status');
$t->checkModule('other space denied filtered draft list', $b, $app,
    'GET', $base.'/invoices?status=draft', null, 404);
$t->checkModule('other space denied action queue projection', $b, $app,
    'GET', $base.'/invoices?status=attention', null, 404);

$t->checkModule('invalid commercial document kind rejected', $a, $app,
    'GET', $base.'/invoices?kind=untrusted', null, 422,
    static fn(array $v):bool=>($v['error']??null)==='invalid_invoice_kind');

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
$t->checkModule('confirmed invoice disappears from work queue without deleting it', $a, $app,
    'GET', $base.'/invoices?status=draft', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===1
        && $v['invoices'][0]['kind']==='bill');
$t->checkModule('confirmed invoice still listed as commercial source document', $a, $app,
    'GET', $base.'/invoices?status=issued', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===1
        && $v['invoices'][0]['kind']==='invoice');
$t->checkModule('confirmed invoice removed from attention when obligation linked', $a, $app,
    'GET', $base.'/invoices?status=attention', null, 200,
    static fn(array $v):bool=>count($v['invoices']??[])===1
        && $v['invoices'][0]['kind']==='bill');

$t->checkModule('taxed commercial invoice blocks gross cash-as-revenue posting',
    $a, $app, 'POST', $base.'/invoices/'.$invoiceId.'/cash-payments',
    [
        'bankAccountId'=>$chart['1000'], 'counterAccountId'=>$chart['4000'],
        'amountMinor'=>12000, 'paidOn'=>$date,
        'operationKey'=>'workflow:tax-block:'.$tag,
    ], 409, static fn(array $r): bool =>
        ($r['error']??null)==='invoice_tax_allocation_required');
$t->checkModule('rejected gross tax posting did not pay the commercial invoice',
    $a, $app, 'GET', $base.'/invoices/'.$invoiceId, null, 200,
    static fn(array $r):bool=>
        ($r['invoice']['paid_minor']??null)===0
        && count($r['allocations']??[])===0);
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
$t->checkModule('linked obligation detail exposes exactly one originating invoice',
    $a, 'librefinance_obligations', 'GET',
    $base.'/obligations/'.$obligationId, null, 200,
    static fn(array $v): bool =>
        ($v['obligation']['linkedInvoiceId'] ?? null) === $invoiceId
        && ($v['obligation']['kind'] ?? null) === 'receivable');
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

// A commercial document issued in the historical seven-app layout must
// NOT be guessed into an unrelated title/settlement. Simulate the exact
// legacy state by creating a valid draft and upgrading its state directly
// in this disposable test database, without executing the new issue path.
$old = $t->checkModule('make synthetic pre-consolidation invoice draft',
    $a, $app, 'POST', $base.'/invoices',
    array_replace($invoicePayload, ['number'=>'LEG-'.$tag,
        'lines'=>[['description'=>'Legacy item','quantity'=>1,
            'unitPriceMinor'=>1000,'taxRuleId'=>null]]]), 201);

$db = \OC::$server->get(\OCP\IDBConnection::class);
$q = $db->getQueryBuilder();
$changed = $q->update('lfi_invoices')
    ->set('status', $q->createNamedParameter('issued'))
    ->set('version', $q->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
    ->set('issued_at', $q->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
    ->where($q->expr()->eq('id',
        $q->createNamedParameter((int)$old['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
    ->andWhere($q->expr()->eq('space_id',
        $q->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
    ->andWhere($q->expr()->eq('status', $q->createNamedParameter('draft')))
    ->executeStatement();
$t->record('legacy issued invoice state created only in synthetic database',
    $changed===1, 'updated rows '.$changed);
$t->checkModule('historical issued invoice has no invented link', $a, $app,
    'GET', $base.'/invoices/'.$old['id'], null, 200,
    static fn(array $v): bool => ($v['obligationId'] ?? null) === null
        && $v['invoice']['status'] === 'issued'
        && $v['allocations'] === []);
$t->checkModule('legacy unlinked issued invoice remains actionable without invented debt',
    $a, $app, 'GET', $base.'/invoices?status=attention', null, 200,
    static fn(array $v):bool=>count(array_filter($v['invoices']??[],
        static fn(array $row):bool=>(int)$row['id']===(int)$old['id']
            && $row['status']==='issued'))===1
        && count(array_filter($v['invoices']??[],
            static fn(array $row):bool=>(int)$row['id']===$invoiceId))===0);
$oldJournal = $t->check('post distinct legacy bank settlement candidate',
    $a, 'POST', $base.'/journals',
    ['debitId'=>$chart['1000'],'creditId'=>$chart['4000'],
        'amountMinor'=>1000,'date'=>$date,'memo'=>'Legacy invoice candidate',
        'externalKey'=>'legacy:bank:'.$tag], 201);
$t->checkModule('legacy invoice cannot allocate without verified obligation', $a,
    $app, 'POST', $base.'/invoices/'.$old['id'].'/allocations',
    ['journalId'=>(int)$oldJournal['id'], 'bankAccountId'=>$chart['1000'],
        'amountMinor'=>1000], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invoice_obligation_unlinked');
$t->checkModule('rejected allocation does not modify legacy invoice or ledger refs',
    $a, $app, 'GET', $base.'/invoices/'.$old['id'], null, 200,
    static fn(array $v): bool => $v['invoice']['paid_minor'] === 0
        && $v['allocations'] === []);
$t->check('failed legacy allocation did not claim the bank journal', $a,
    'POST', $base.'/journals/'.(int)$oldJournal['id'].'/reverse',
    null, 200, static fn(array $v): bool => ($v['replayed'] ?? null) === false);

$t->checkModule('legacy invoice cannot allocate without verified obligation', $a,
    $app, 'POST', $base.'/invoices/'.$old['id'].'/allocations',
    ['journalId'=>(int)$oldJournal['id'], 'bankAccountId'=>$chart['1000'],
        'amountMinor'=>1000], 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invoice_obligation_unlinked');
$t->checkModule('rejected allocation does not modify legacy invoice or ledger refs',
    $a, $app, 'GET', $base.'/invoices/'.$old['id'], null, 200,
    static fn(array $v): bool => $v['invoice']['paid_minor'] === 0
        && $v['allocations'] === []);
$t->check('failed legacy allocation did not claim the bank journal', $a,
    'POST', $base.'/journals/'.(int)$oldJournal['id'].'/reverse',
    null, 200, static fn(array $v): bool => ($v['replayed'] ?? null) === false);

$periodSpace = $t->check('create period workspace', $a, 'POST', '/spaces', [
    'name' => 'Synthetic invoice period',
    'kind' => 'organization',
    'currency' => 'BRL',
], 201);
$periodBase = '/spaces/' . (int)$periodSpace['id'];

$periodClient = $t->check('create period client', $a, 'POST', $periodBase . '/contacts', [
    'name' => 'Period client',
    'kind' => 'both',
], 201);
$periodClientDid = (int)$periodClient['id'];

$periodPayload1 = [
    'number' => 'PINV1-' . $tag,
    'kind' => 'invoice',
    'contactId' => $periodClientDid,
    'issueOn' => '2026-03-10',
    'dueOn' => '2026-12-20',
    'country' => 'GB',
    'lines' => [
        ['description' => 'Synthetic consultation 1', 'quantity' => 1, 'unitPriceMinor' => 10000, 'taxRuleId' => null],
    ],
];

$periodPayload2 = [
    'number' => 'PINV2-' . $tag,
    'kind' => 'invoice',
    'contactId' => $periodClientDid,
    'issueOn' => '2026-03-20',
    'dueOn' => '2026-12-20',
    'country' => 'GB',
    'lines' => [
        ['description' => 'Synthetic consultation 2', 'quantity' => 1, 'unitPriceMinor' => 10000, 'taxRuleId' => null],
    ],
];

$inv1 = $t->checkModule('create period invoice one', $a, $app, 'POST', $periodBase . '/invoices', $periodPayload1, 201,
    static fn(array $v): bool => ($v['status'] ?? null) === 'draft' && ($v['issue_on'] ?? null) === '2026-03-10');
$inv2 = $t->checkModule('create period invoice two', $a, $app, 'POST', $periodBase . '/invoices', $periodPayload2, 201,
    static fn(array $v): bool => ($v['status'] ?? null) === 'draft' && ($v['issue_on'] ?? null) === '2026-03-20');

$inv1Id = (int)$inv1['id'];
$inv2Id = (int)$inv2['id'];

$t->checkModule('period inclusive same day match', $a, $app, 'GET',
    $periodBase . '/invoices?issueFrom=2026-03-10&issueThrough=2026-03-10', null, 200,
    static fn(array $v): bool => count($v['invoices'] ?? []) === 1 && (int)$v['invoices'][0]['id'] === $inv1Id);

$t->checkModule('period both bounds match both', $a, $app, 'GET',
    $periodBase . '/invoices?issueFrom=2026-03-01&issueThrough=2026-03-31', null, 200,
    static fn(array $v): bool => count($v['invoices'] ?? []) === 2);

$t->checkModule('period from only bound', $a, $app, 'GET',
    $periodBase . '/invoices?issueFrom=2026-03-15', null, 200,
    static fn(array $v): bool => count($v['invoices'] ?? []) === 1 && (int)$v['invoices'][0]['id'] === $inv2Id);

$t->checkModule('period through only bound', $a, $app, 'GET',
    $periodBase . '/invoices?issueThrough=2026-03-15', null, 200,
    static fn(array $v): bool => count($v['invoices'] ?? []) === 1 && (int)$v['invoices'][0]['id'] === $inv1Id);

$t->checkModule('period composition with kind status and afterId cursor', $a, $app, 'GET',
    $periodBase . '/invoices?kind=invoice&status=draft&issueFrom=2026-03-01&afterId=' . $inv1Id, null, 200,
    static fn(array $v): bool => count($v['invoices'] ?? []) === 1 && (int)$v['invoices'][0]['id'] === $inv2Id);

$t->checkModule('invalid issueFrom date format', $a, $app, 'GET',
    $periodBase . '/invoices?issueFrom=2026-02-30', null, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_invoice_period');

$t->checkModule('year zero issueThrough date rejected', $a, $app, 'GET',
    $periodBase . '/invoices?issueThrough=0000-01-01', null, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_invoice_period');

$t->checkModule('reversed issue period bounds rejected', $a, $app, 'GET',
    $periodBase . '/invoices?issueFrom=2026-03-25&issueThrough=2026-03-10', null, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_invoice_period');

$t->checkModule('outsider denied period space invoices with bounds', $b, $app, 'GET',
    $periodBase . '/invoices?issueFrom=2026-03-01', null, 404);

$t->finish();$t->finish();
