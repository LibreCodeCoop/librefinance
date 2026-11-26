<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';
use LibreFinance\Tests\Integration\TestHarness;

$t = new TestHarness();
$a = 'lfmaintestA';
$b = 'lfmaintestB';
$token = bin2hex(random_bytes(6));
$space = $t->check('create owner-managed financial space', $a, 'POST', '/spaces',
    ['name'=>'Synthetic finance '.$token,'kind'=>'organization','currency'=>'BRL',
        'color'=>'#1A2B3C'], 201,
    static fn(array $v):bool=>($v['accent_color']??null)==='#1A2B3C');
$id = (int)$space['id'];
$base = '/spaces/'.$id;
$t->check('legacy creation remains BRL-only', $a, 'POST', '/spaces',
    ['name'=>'Unsupported book '.$token,'kind'=>'organization','currency'=>'JPY'],422,
    static fn(array $v):bool=>($v['error']??null)==='invalid_currency');
$t->check('authorized legacy listing exposes exact currency metadata', $a, 'GET', '/spaces', null, 200,
    static fn(array $v):bool=>count(array_filter($v['spaces']??[],static fn(array $row):bool=>
        (int)$row['id']===$id && $row['currency']==='BRL'
        && ($row['currencyPrecision']??null)===2 && ($row['currencyMetadataVersion']??null)===1))===1);
$t->check('foreign listing cannot expose the workspace metadata', $b, 'GET', '/spaces', null, 200,
    static fn(array $v):bool=>!in_array($id,array_column($v['spaces']??[],'id'),true));
$t->check('foreign account metadata denied', $b, 'GET',$base.'/accounts',null,404);
$t->check('foreign user cannot rename a workspace', $b, 'PUT', $base,
    ['name'=>'Unauthorised rename'], 404);
$t->check('foreign user cannot archive a workspace', $b, 'POST',
    $base.'/archive', [], 404);
$t->check('invalid workspace name rejected', $a, 'PUT', $base,
    ['name'=>str_repeat('Z',151)], 422);
$t->check('invalid color rejected by backend', $a, 'PUT', $base,
    ['name'=>'Invalid accent', 'color'=>'red;url(unsafe)'], 422);
$t->check('foreign user cannot update color', $b, 'PUT', $base,
    ['name'=>'Unauthorised color', 'color'=>'#123456'], 404);
$t->check('owner can rename a workspace', $a, 'PUT', $base,
    ['name'=>'Renamed synthetic finance '.$token], 200,
    static fn(array $v): bool => ($v['name']??null)==='Renamed synthetic finance '.$token);
$t->check('owner can edit workspace color without changing ledger', $a, 'PUT',
    $base, ['name'=>'Renamed synthetic finance '.$token,'color'=>'#ABCDEF'], 200,
    static fn(array $v):bool=>($v['accent_color']??null)==='#ABCDEF');
$t->check('repeating the same workspace name is a safe no-op', $a, 'PUT', $base,
    ['name'=>'Renamed synthetic finance '.$token], 200,
    static fn(array $v): bool=>($v['name']??null)==='Renamed synthetic finance '.$token);
$t->check('renaming is reflected in authorized workspace listing', $a, 'GET',
    '/spaces', null, 200,
    static fn(array $v): bool =>
        count(array_filter($v['spaces']??[],static fn(array $row):bool=>
            (int)$row['id']===$id && $row['name']==='Renamed synthetic finance '.$token
            && ($row['accent_color']??null)==='#ABCDEF'))===1);
$accountResult = $t->check('owner obtains default cash and revenue accounts', $a,
    'GET',$base.'/accounts',null,200,
    static fn(array $v):bool=>count($v['accounts']??[])===4
        && count(array_filter($v['accounts'],static fn(array $row):bool=>
            $row['currency']==='BRL' && ($row['currencyPrecision']??null)===2
            && ($row['currencyMetadataVersion']??null)===1 && $row['balance_minor']===0))===4);
$accounts = [];
foreach ($accountResult['accounts'] as $row) {
    $accounts[$row['code']] = (int)$row['id'];
}
$journal = $t->check('post a synthetic entry before archiving', $a, 'POST',
    $base.'/journals', [
        'debitId'=>$accounts['1000'],'creditId'=>$accounts['4000'],
        'amountMinor'=>1800,'date'=>'2026-11-22',
        'memo'=>'Before archiving','externalKey'=>'spaces:test:'.$token,
    ], 201);
$t->check('share archived candidate before suspending access', $a, 'POST',
    $base.'/members', ['uid'=>$b,'role'=>'viewer'], 200);
$t->check('viewer cannot rename an owned-by-another workspace', $b,
    'PUT',$base,['name'=>'Attacker rename'],404);
$t->check('viewer cannot archive a shared workspace', $b,
    'POST',$base.'/archive', [],404);
$t->check('archive workspace without deleting ledger', $a,'POST',$base.'/archive',
    [],200,static fn(array $v):bool=>($v['archived']??null)===true);
$t->check('archived workspace hidden from ordinary listing', $a,'GET','/spaces',
    null,200,static fn(array $v):bool=>
        count(array_filter($v['spaces']??[],static fn(array $row):bool=>
            (int)$row['id']===$id))===0);
$t->check('archived workspace denies ledger access to owner', $a,'GET',
    $base.'/journals',null,404);
$t->check('archived workspace denies access to former viewer', $b,'GET',
    $base.'/accounts',null,404);
$t->check('archived workspace cannot be silently renamed', $a,'PUT',
    $base,['name'=>'No edit while archived'],409);
$t->check('archiving twice is rejected', $a,'POST',$base.'/archive',[],409);
$t->check('only owner can see archived workspace', $a, 'GET',
    '/spaces/archived',null,200,
    static fn(array $v):bool=>
        count(array_filter($v['spaces']??[],static fn(array $row):bool=>
            (int)$row['id']===$id && ($row['role']??null)==='owner'
            && $row['currency']==='BRL' && ($row['currencyPrecision']??null)===2
            && ($row['currencyMetadataVersion']??null)===1))===1);
$t->check('another user cannot enumerate owner archived workspaces', $b,
    'GET','/spaces/archived',null,200,
    static fn(array $v):bool=>
        count(array_filter($v['spaces']??[],static fn(array $row):bool=>
            (int)$row['id']===$id))===0);
$t->check('another user cannot restore an archived workspace', $b,
    'POST',$base.'/restore',[],404);
$t->check('owner can restore full financial workspace', $a,
    'POST',$base.'/restore',[],200,
    static fn(array $v):bool=>($v['archived']??null)===false);
$t->check('restoring twice is rejected', $a, 'POST',$base.'/restore',[],409);
$t->check('original financial journal preserved after restoration', $a,'GET',
    $base.'/journals',null,200,
    static fn(array $v):bool=>
        count(array_filter($v['journals']??[], static fn(array $r):bool=>
            (int)$r['id']===(int)$journal['id']))===1);
$t->check('previously granted viewer returns after restoration', $b,'GET',
    $base.'/accounts',null,200);
$t->finish();
