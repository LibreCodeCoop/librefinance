<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/TestHarness.php';

use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$app = 'librefinance_obligations';
$token = bin2hex(random_bytes(8));
$issued = '2026-11-20';
$due = '2026-12-05';
$space = $t->check('create obligations test space', $a, 'POST', '/spaces',
    ['name' => 'Synthetic payable and receivable', 'kind' => 'organization', 'currency' => 'BRL'], 201);
$spaceId = (int)$space['id'];
$base = '/spaces/' . $spaceId;
$t->check('discover independent obligations app', $a, 'GET', $base . '/modules', null, 200,
    static fn(array $v): bool => count(array_filter($v['modules'] ?? [],
        static fn(array $m): bool => $m['id'] === $app)) === 1);
$t->checkModule('initial obligations empty', $a, $app, 'GET', $base . '/obligations', null, 200,
    static fn(array $v): bool => ($v['obligations'] ?? null) === []);
$t->checkModule('B cannot see A obligations', $b, $app, 'GET', $base . '/obligations', null, 404);
$contact = $t->check('create contact for payable', $a, 'POST', $base . '/contacts',
    ['name' => 'Synthetic vendor', 'kind' => 'vendor'], 201);
$data = ['kind' => 'payable', 'description' => 'Synthetic supplier bill',
    'amountMinor' => 12345, 'issuedOn' => $issued, 'dueOn' => $due,
    'reference' => 'SYN-' . $token, 'contactId' => (int)$contact['id']];
$obligation = $t->checkModule('create payable', $a, $app, 'POST', $base . '/obligations',
    $data, 201, static fn(array $v): bool => ($v['amount_minor'] ?? null) === 12345
        && ($v['status'] ?? null) === 'open');
$obligationId = (int)$obligation['id'];
$t->checkModule('detail returns the same title and its source without duplicating it',
    $a, $app, 'GET', $base.'/obligations/'.$obligationId, null, 200,
    static fn(array $v): bool=>(int)($v['obligation']['id']??0)===$obligationId
        && array_key_exists('linkedInvoiceId', $v['obligation'])
        && $v['obligation']['linkedInvoiceId']===null);
$t->checkModule('missing title returns 404 without falling back to another workspace',
    $a, $app, 'GET', $base.'/obligations/99999999', null, 404);
$t->checkModule('reject duplicate reference', $a, $app, 'POST', $base . '/obligations',
    array_replace($data, ['amountMinor' => 1000]), 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'reference_conflict');
$t->checkModule('reject nonexistent contact id', $a, $app, 'POST', $base . '/obligations',
    array_replace($data, ['reference' => 'MISSING-' . $token, 'contactId' => 99999999]), 404);
$t->checkModule('reject invalid due date', $a, $app, 'POST', $base . '/obligations',
    array_replace($data, ['reference' => 'INVALID-' . $token, 'dueOn' => '2026-02-30']), 422);
$t->checkModule('reject negative amount', $a, $app, 'POST', $base . '/obligations',
    array_replace($data, ['reference' => 'NEG-' . $token, 'amountMinor' => -1]), 422);
$t->checkModule('B cannot create into A space', $b, $app, 'POST', $base . '/obligations',
    array_replace($data, ['reference' => 'ABUSE-' . $token]), 404);

$payment = ['amountMinor' => 2345, 'paidOn' => '2026-11-20',
    'operationKey' => 'test:pay:' . $token, 'note' => 'partial synthetic'];
$settlementRoute = $base . '/obligations/' . $obligationId . '/settlements';
$partial = $t->checkModule('record partial operational settlement', $a, $app, 'POST',
    $settlementRoute, $payment, 201, static fn(array $v): bool => ($v['status'] ?? null) === 'partial'
        && ($v['paidMinor'] ?? null) === 2345);
$t->checkModule('idempotent settlement replay', $a, $app, 'POST', $settlementRoute,
    $payment, 200, static fn(array $v): bool => ($v['replayed'] ?? null) === true
        && ($v['id'] ?? null) === $partial['id']);
$t->checkModule('same key different amount conflicts', $a, $app, 'POST', $settlementRoute,
    array_replace($payment, ['amountMinor' => 2346]), 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'idempotency_conflict');
$t->checkModule('overpayment denied', $a, $app, 'POST', $settlementRoute,
    array_replace($payment, ['amountMinor' => 12000, 'operationKey' => 'test:over:' . $token]), 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'amount_exceeds_balance');
$t->checkModule('partial settlement history', $a, $app, 'GET', $settlementRoute, null, 200,
    static fn(array $v): bool => count($v['settlements'] ?? []) === 1
        && $v['settlements'][0]['amount_minor'] === 2345);
$t->checkModule('final settlement', $a, $app, 'POST', $settlementRoute,
    array_replace($payment, ['amountMinor' => 10000, 'operationKey' => 'test:final:' . $token]), 201,
    static fn(array $v): bool => ($v['status'] ?? null) === 'settled' && $v['paidMinor'] === 12345);
$t->checkModule('cannot cancel a settled title', $a, $app, 'POST',
    $base . '/obligations/' . $obligationId . '/cancel', null, 409);
$t->checkModule('obligation report has exact remaining', $a, $app, 'GET',
    $base . '/obligations', null, 200,
    static fn(array $v): bool => count($v['obligations'] ?? []) === 1
        && $v['obligations'][0]['paid_minor'] === 12345
        && $v['obligations'][0]['status'] === 'settled');
$other = $t->checkModule('create independent receivable', $a, $app, 'POST',
    $base . '/obligations', array_replace($data, [
        'kind' => 'receivable', 'description' => 'Synthetic customer invoice',
        'contactId' => null, 'reference' => 'REC-' . $token, 'amountMinor' => 5000]), 201);
$otherId = (int)$other['id'];
$t->checkModule('payables excludes unrelated receivables before pagination',
    $a, $app, 'GET', $base . '/obligations?kind=payable', null, 200,
    static fn(array $v): bool => count($v['obligations'] ?? []) === 1
        && ($v['obligations'][0]['kind'] ?? null) === 'payable');
$t->checkModule('receivables excludes unrelated payables before pagination',
    $a, $app, 'GET', $base . '/obligations?kind=receivable', null, 200,
    static fn(array $v): bool => count($v['obligations'] ?? []) === 1
        && ($v['obligations'][0]['kind'] ?? null) === 'receivable');
$t->checkModule('invalid obligation flow kind rejected',
    $a, $app, 'GET', $base . '/obligations?kind=untrusted', null, 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_kind');

$utcToday=(new DateTimeImmutable('today',new DateTimeZone('UTC')));
$dueSoon=$utcToday->modify('+3 days')->format('Y-m-d');
$duePast=$utcToday->modify('-1 day')->format('Y-m-d');
$dueSoonId=$t->checkModule('create a payment due in the coming week',
    $a, $app, 'POST', $base.'/obligations',
    array_replace($data, ['reference'=>'SOON-'.$token,
        'contactId'=>null,'dueOn'=>$dueSoon,'amountMinor'=>700,
        'description'=>'Synthetic due in 3 days']), 201);
$t->checkModule('create an overdue payable', $a, $app, 'POST',
    $base.'/obligations', array_replace($data, ['reference'=>'LATE-'.$token,
        'contactId'=>null,'dueOn'=>$duePast,'amountMinor'=>800,
        'description'=>'Synthetic overdue bill']), 201);
$t->checkModule('filter next week before pagination', $a, $app,
    'GET',$base.'/obligations?kind=payable&dueRange=next7',null,200,
    static fn(array $v):bool=>count($v['obligations']??[])===1
        && (int)$v['obligations'][0]['id']===(int)$dueSoonId['id']);
$t->checkModule('filter overdue open titles before pagination', $a, $app,
    'GET',$base.'/obligations?kind=payable&dueRange=overdue',null,200,
    static fn(array $v):bool=>count($v['obligations']??[])===1
        && ($v['obligations'][0]['description']??null)==='Synthetic overdue bill');
$t->checkModule('invalid due window rejected by PHP', $a, $app,
    'GET',$base.'/obligations?dueRange=all%20OR%201=1',null,422,
    static fn(array $v):bool=>($v['error']??null)==='invalid_due_range');

$t->checkModule('cancel unpaid receivable', $a, $app, 'POST',
    $base . '/obligations/' . $otherId . '/cancel', null, 200,
    static fn(array $v): bool => ($v['status'] ?? null) === 'cancelled');
$t->checkModule('cannot pay cancelled title', $a, $app, 'POST',
    $base . '/obligations/' . $otherId . '/settlements',
    array_replace($payment, ['operationKey' => 'test:cancelled:' . $token]), 409);

$t->check('grant B viewer', $a, 'POST', $base . '/members',
    ['uid' => $b, 'role' => 'viewer'], 200);
$t->checkModule('viewer B may read obligations', $b, $app, 'GET', $base . '/obligations', null, 200);
$t->checkModule('viewer B cannot settle', $b, $app, 'POST',
    $base . '/obligations/' . $obligationId . '/settlements',
    array_replace($payment, ['operationKey' => 'test:viewer:' . $token]), 403);
$t->checkModule('viewer B cannot create', $b, $app, 'POST',
    $base . '/obligations', array_replace($data, ['reference' => 'VIEW-' . $token]), 403);
$t->check('revoke B', $a, 'DELETE', $base . '/members/' . $b, null, 200);
$t->checkModule('revoked B cannot inspect title detail', $b, $app,
    'GET', $base . '/obligations/' . $obligationId, null, 404);
$t->checkModule('revoked B cannot read obligations', $b, $app, 'GET',
    $base . '/obligations', null, 404);
    $standaloneOpen = $t->checkModule('create distinct standalone open payable', $a, $app, 'POST', $base . '/obligations',
        array_replace($data, ['reference' => 'RECUR-' . $token, 'issuedOn' => '2028-01-31', 'dueOn' => '2028-01-31', 'amountMinor' => 12345, 'contactId' => null]), 201,
        static fn(array $v): bool => ($v['status'] ?? null) === 'open');
    $standaloneOpenId = (int)$standaloneOpen['id'];

    $previewRoute = $base . '/obligations/' . $standaloneOpenId . '/recurrence-preview';
    $preview = $t->checkModule('get recurrence preview valid', $a, $app, 'GET',
        $previewRoute . '?count=2&stepMonths=1&endOfMonth=clamp', null, 200,
        static fn(array $v): bool => ($v['templateId'] ?? null) === $standaloneOpenId
            && ($v['kind'] ?? null) === 'payable'
            && ($v['amountMinor'] ?? null) === 12345
            && ($v['currency'] ?? null) === 'BRL'
            && count($v['occurrences'] ?? []) === 2
            && $v['occurrences'][0]['issuedOn'] === '2028-02-29'
            && $v['occurrences'][0]['dueOn'] === '2028-02-29'
            && $v['occurrences'][1]['issuedOn'] === '2028-03-31'
            && $v['occurrences'][1]['dueOn'] === '2028-03-31'
            && !array_key_exists('reference', $v)
            && !array_key_exists('operationKey', $v)
            && !array_key_exists('payment', $v));

    $t->checkModule('repeat preview unchanged', $a, $app, 'GET',
        $previewRoute . '?count=2&stepMonths=1&endOfMonth=clamp', null, 200,
        static fn(array $v): bool => $v === $preview);

    $t->checkModule('show unchanged open unpaid after preview', $a, $app, 'GET',
        $base . '/obligations/' . $standaloneOpenId, null, 200,
        static fn(array $v): bool => (int)($v['obligation']['paid_minor'] ?? 1) === 0
            && ($v['obligation']['status'] ?? null) === 'open');

    $t->checkModule('reject invalid policy', $a, $app, 'GET',
        $previewRoute . '?count=2&stepMonths=1&endOfMonth=invalid', null, 422,
        static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_recurrence');

    $t->checkModule('reject invalid count limit', $a, $app, 'GET',
        $previewRoute . '?count=25&stepMonths=1&endOfMonth=clamp', null, 422,
        static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_recurrence');

    $t->checkModule('reject feb day under reject policy', $a, $app, 'GET',
        $previewRoute . '?count=2&stepMonths=1&endOfMonth=reject', null, 422,
        static fn(array $v): bool => ($v['error'] ?? null) === 'recurrence_day_unavailable');

    $t->checkModule('outsider denial recurrence preview', $b, $app, 'GET',
        $previewRoute . '?count=2&stepMonths=1&endOfMonth=clamp', null, 404);

    $t->checkModule('settled obligation template rejected', $a, $app, 'GET',
        $base . '/obligations/' . $obligationId . '/recurrence-preview?count=2&stepMonths=1&endOfMonth=clamp', null, 422,
        static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_recurrence_template');

$t->finish();
