<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/lib/base.php';
require_once dirname(__DIR__) . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$tag = bin2hex(random_bytes(5));
$date = '2026-11-21';
$space = $t->check('create isolated cashflow test workspace', $a, 'POST', '/spaces',
    ['name'=>'Synthetic cashflow workflow', 'kind'=>'organization', 'currency'=>'BRL'], 201);
$id = (int)$space['id'];
$base = '/spaces/' . $id;
$chart = [];
$list = $t->check('read default double entry chart', $a, 'GET', $base.'/accounts', null, 200);
foreach ($list['accounts'] as $row) $chart[$row['code']] = (int)$row['id'];
$receivable = $t->check('create explicit invoice receivable asset account', $a, 'POST',
    $base.'/accounts', ['code'=>'1110','name'=>'Contas a receber sintéticas',
        'class'=>'asset','isBank'=>false], 201);
$contact = $t->check('create synthetic payer', $a, 'POST', $base.'/contacts',
    ['name'=>'Synthetic customer', 'kind'=>'both'], 201);
$contactId = (int)$contact['id'];
$app = 'librefinance_invoicing';
$oblig = 'librefinance_obligations';
$baseDoc = [
    'contactId'=>$contactId, 'issueOn'=>$date, 'dueOn'=>'2026-12-21', 'country'=>'BR',
    'lines'=>[['description'=>'Synthetic services','quantity'=>1,
        'unitPriceMinor'=>10000,'taxRuleId'=>null]],
];
$inv = $t->checkModule('create a draft to be invoiced', $a, $app, 'POST',
    $base.'/invoices', $baseDoc+['number'=>'RCV-'.$tag,'kind'=>'invoice'], 201);
$invoiceId = (int)$inv['id'];
$path = $base.'/invoices/'.$invoiceId.'/cash-payments';
$payment = [
    'bankAccountId'=>$chart['1000'],
    'counterAccountId'=>$chart['4000'],
    'amountMinor'=>2500,'paidOn'=>$date,'operationKey'=>'workflow:invoice:'.$tag,
];
$t->checkModule('cannot pay unissued invoice; journal is rolled back', $a, $app,
    'POST', $path, $payment, 409, static fn(array $r):bool =>
        ($r['error']??null)==='invoice_not_issued');
$t->checkModule('issue invoice into exactly one linked receivable', $a, $app,
    'POST', $base.'/invoices/'.$invoiceId.'/issue', [], 200);
$t->checkModule('source company B cannot pay invoice in A', $b, $app,
    'POST', $path, $payment, 404);
$t->checkModule('missing bank account never posts', $a, $app,
    'POST', $path, array_replace($payment,['bankAccountId'=>999999]), 404);
$t->checkModule('invoice payment cannot clear unrecognized liability', $a, $app,
    'POST', $path, array_replace($payment,['counterAccountId'=>(int)$receivable['id']]), 422,
    static fn(array $r):bool=>($r['error']??null)==='invalid_payment_counterpart');
$first = $t->checkModule('record 25 percent receipt in one transaction', $a, $app,
    'POST', $path, $payment, 201,
    static fn(array $r):bool=>($r['replayed']??null)===false
        && (int)($r['journalId']??0)>0);
$t->checkModule('idempotent receipt retry is not posted twice', $a, $app,
    'POST', $path, $payment, 201,
    static fn(array $r):bool=>($r['replayed']??null)===true
        && (int)($r['journalId']??0)===(int)$first['journalId']);
$t->checkModule('same key cannot post changed amount', $a, $app,
    'POST', $path, array_replace($payment,['amountMinor'=>2501]), 409,
    static fn(array $r):bool=>($r['error']??null)==='idempotency_conflict');
$t->checkModule('first receipt settles exactly a quarter', $a, $app,
    'GET', $base.'/invoices/'.$invoiceId, null, 200,
    static fn(array $r):bool=>($r['invoice']['paid_minor']??null)===2500
        && ($r['invoice']['status']??null)==='partially_paid'
        && count($r['allocations']??[])===1);
$second = $t->checkModule('finish the same receipt without a second title', $a, $app,
    'POST', $path, array_replace($payment, [
        'amountMinor'=>7500,'operationKey'=>'workflow:final:'.$tag]), 201);
$t->checkModule('completed invoice exact retry still replays previous payment', $a, $app,
    'POST', $path, array_replace($payment, [
        'amountMinor'=>7500,'operationKey'=>'workflow:final:'.$tag]), 201,
    static fn(array $r):bool=>($r['replayed']??null)===true
        && (int)$r['journalId']===(int)$second['journalId']);
$t->checkModule('overpayment is rejected without journal', $a, $app, 'POST', $path,
    array_replace($payment,['operationKey'=>'workflow:over:'.$tag]), 409);
$t->checkModule('linked title and invoice are both settled', $a, $app,
    'GET', $base.'/invoices/'.$invoiceId, null, 200,
    static fn(array $r):bool=>($r['invoice']['paid_minor']??null)===10000
        && ($r['invoice']['status']??null)==='paid'
        && count($r['allocations']??[])===2);
$t->check('posted payment cannot be silently reversed', $a, 'POST',
    $base.'/journals/'.(int)$first['journalId'].'/reverse', [], 409);

$bill = $t->checkModule('create purchase document in same company', $a, $app,
    'POST', $base.'/invoices',
    array_replace($baseDoc,['number'=>'PAY-'.$tag,'kind'=>'bill']), 201);
$billId = (int)$bill['id'];
$t->checkModule('issue purchase with linked payable', $a, $app,
    'POST', $base.'/invoices/'.$billId.'/issue', [], 200);
$t->checkModule('supplier payment posts one expense in cash basis', $a, $app,
    'POST', $base.'/invoices/'.$billId.'/cash-payments', [
        'bankAccountId'=>$chart['1000'],'counterAccountId'=>$chart['5000'],
        'amountMinor'=>10000,'paidOn'=>$date,
        'operationKey'=>'workflow:bill:'.$tag,
    ], 201);
$t->checkModule('supplier document settled without duplicate title', $a, $app,
    'GET', $base.'/invoices/'.$billId, null, 200,
    static fn(array $r):bool=>($r['invoice']['status']??null)==='paid'
        && count($r['allocations']??[])===1);

$manual = $t->checkModule('create independent utility expense obligation', $a,
    $oblig, 'POST', $base.'/obligations', [
        'kind'=>'payable','description'=>'Synthetic monthly utility',
        'reference'=>'UTILITY-'.$tag,'amountMinor'=>10000,
        'issuedOn'=>$date,'dueOn'=>'2026-12-21',
    ], 201);
$manualId = (int)$manual['id'];
$manualPath = $base.'/obligations/'.$manualId.'/cash-payments';
$manualPayment = [
    'bankAccountId'=>$chart['1000'],'counterAccountId'=>$chart['5000'],
    'amountMinor'=>4000,'paidOn'=>$date,
    'operationKey'=>'workflow:utility:'.$tag,
];
$partial = $t->checkModule('manual bill payment posts and settles atomically',
    $a, $oblig, 'POST', $manualPath, $manualPayment, 201);
$t->checkModule('manual bill duplicate is a replay', $a, $oblig, 'POST',
    $manualPath, $manualPayment, 201,
    static fn(array $r):bool=>($r['replayed']??null)===true
        && (int)($r['journalId']??0)===(int)$partial['journalId']);
$t->checkModule('standalone bill shows ledger-backed payment without mislabeling it administrative',
    $a, $oblig, 'GET', $base.'/obligations/'.$manualId, null, 200,
    static fn(array $r):bool=>
        ($r['obligation']['paid_minor']??null)===4000
        && ($r['obligation']['ledgerPaidMinor']??null)===4000
        && ($r['obligation']['administrativePaidMinor']??null)===0);
$t->checkModule('manual bill settlement stores actual bank journal', $a, $oblig,
    'GET', $base.'/obligations/'.$manualId.'/settlements', null, 200,
    static fn(array $r):bool=>
        ($r['settlements'][0]['journal_id']??null)===(int)$partial['journalId']
        && ($r['settlements'][0]['amount_minor']??null)===4000);
$t->check('journal claimed by manual bill cannot be reversed', $a, 'POST',
    $base.'/journals/'.(int)$partial['journalId'].'/reverse', [], 409);
$t->checkModule('manually paid bill cannot also receive another zero/over payment',
    $a, $oblig, 'POST', $manualPath,
    array_replace($manualPayment, ['amountMinor'=>7000,
        'operationKey'=>'workflow:utility-over:'.$tag]), 409);
$t->checkModule('manual bill remaining amount can be paid', $a, $oblig, 'POST',
    $manualPath, array_replace($manualPayment, ['amountMinor'=>6000,
        'operationKey'=>'workflow:utility-final:'.$tag]), 201);
$t->checkModule('manual title now has zero balance with two ledger-backed settlements',
    $a, $oblig, 'GET', $base.'/obligations/'.$manualId, null, 200,
    static fn(array $r):bool=>
        ($r['obligation']['paid_minor']??null)===10000
        && ($r['obligation']['status']??null)==='settled'
        && ($r['obligation']['ledgerPaidMinor']??null)===10000
        && ($r['obligation']['administrativePaidMinor']??null)===0);
$already = $t->checkModule('prepare receivable with already posted journal',
    $a, $oblig, 'POST', $base.'/obligations', [
        'kind'=>'receivable', 'description'=>'Synthetic previously received money',
        'reference'=>'POSTED-'.$tag,'amountMinor'=>5000,
        'issuedOn'=>$date,'dueOn'=>'2026-12-21',
    ], 201);
$alreadyId=(int)$already['id'];
$previous=$t->check('post bank receipt BEFORE obligation association', $a,
    'POST', $base.'/journals', [
        'debitId'=>$chart['1000'], 'creditId'=>$chart['4000'],
        'amountMinor'=>5000,'date'=>$date,'memo'=>'Synthetic preexisting receipt',
        'externalKey'=>'existing-receipt:'.$tag,
    ], 201);
$journalCountBefore=$t->check('count posted journals before linking existing receipt',
    $a, 'GET', $base.'/journals', null, 200);
$existingPath=$base.'/obligations/'.$alreadyId.'/existing-journal-payments';
$existingData=[
    'bankAccountId'=>$chart['1000'],'journalId'=>(int)$previous['id'],
    'amountMinor'=>5000, 'paidOn'=>$date,
    'operationKey'=>'workflow:existing:'.$tag,
];
$t->checkModule('existing cash movement links without new posting', $a, $oblig,
    'POST', $existingPath, $existingData, 201,
    static fn(array $r):bool=>($r['journalId']??null)===(int)$previous['id']
        && ($r['replayed']??null)===false);
$t->checkModule('link existing movement is idempotent', $a, $oblig,
    'POST', $existingPath, $existingData, 201,
    static fn(array $r):bool=>($r['journalId']??null)===(int)$previous['id']
        && ($r['replayed']??null)===true);
$t->check('link existing movement creates no second accounting entry',
    $a, 'GET', $base.'/journals', null, 200,
    static fn(array $r):bool=>count($r['journals']??[])
        ===count($journalCountBefore['journals']??[]));
$t->checkModule('link existing movement records journal-backed receipt',
    $a, $oblig, 'GET', $base.'/obligations/'.$alreadyId, null, 200,
    static fn(array $r):bool=>($r['obligation']['ledgerPaidMinor']??null)===5000
        && ($r['obligation']['administrativePaidMinor']??null)===0);
$t->check('already-linked cash journal is protected against silent reversal',
    $a, 'POST', $base.'/journals/'.(int)$previous['id'].'/reverse', [],409);

$racing = $t->checkModule('prepare independent supplier title for concurrency test',
    $a, $oblig, 'POST', $base.'/obligations', [
        'kind'=>'payable', 'description'=>'Synthetic concurrent supplier bill',
        'reference'=>'RACEPAY-'.$tag, 'amountMinor'=>3000,
        'issuedOn'=>$date,'dueOn'=>'2026-12-21',
    ], 201);
$raceId = (int)$racing['id'];
$racePayload = [
    'bankAccountId'=>$chart['1000'], 'counterAccountId'=>$chart['5000'],
    'amountMinor'=>3000, 'paidOn'=>$date, 'operationKey'=>'workflow:race:'.$tag,
];
[$raceFirst,$raceSecond] = $t->parallel(
    ['user'=>$a,'module'=>$oblig,'method'=>'POST',
        'path'=>$base.'/obligations/'.$raceId.'/cash-payments','data'=>$racePayload],
    ['user'=>$a,'module'=>$oblig,'method'=>'POST',
        'path'=>$base.'/obligations/'.$raceId.'/cash-payments','data'=>$racePayload],
);
$codes=[$raceFirst['code'],$raceSecond['code']]; sort($codes);
$t->record('simultaneous identical payments cannot post two full settlements',
    $codes===[201,201] || $codes===[201,409],
    'HTTP '.implode(',',$codes),
    ['first'=>$raceFirst,'second'=>$raceSecond]);
$t->checkModule('concurrent double-submit leaves one journal-backed settlement',
    $a, $oblig, 'GET', $base.'/obligations/'.$raceId, null, 200,
    static fn(array $r):bool=>
        ($r['obligation']['paid_minor']??null)===3000
        && ($r['obligation']['ledgerPaidMinor']??null)===3000
        && ($r['obligation']['administrativePaidMinor']??null)===0);
$t->checkModule('concurrent double-submit stores exactly one settlement',
    $a, $oblig, 'GET', $base.'/obligations/'.$raceId.'/settlements', null, 200,
    static fn(array $r):bool=>count($r['settlements']??[])===1
        && (int)($r['settlements'][0]['journal_id']??0)>0);

$t->checkModule('invoice-linked title cannot use standalone payment API',
    $a, $oblig, 'POST', $base.'/obligations/'.
        (int)$t->checkModule('read invoice linked obligation for denial', $a, $app,
            'GET', $base.'/invoices/'.$invoiceId, null, 200)['obligationId']
        .'/cash-payments', $manualPayment, 409,
    static fn(array $r):bool=>($r['error']??null)==='invoice_payment_via_allocation');
$t->finish();
