<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\LibreFinance\Import\Interchange\BundleValidator;
use OCA\LibreFinance\Import\Interchange\BundleRepacker;

require_once dirname(__DIR__, 2) . '/lib/Import/Source/SourceSystem.php';
require_once dirname(__DIR__, 2) . '/lib/Import/Interchange/BundleValidator.php';
require_once dirname(__DIR__, 2) . '/lib/Import/Interchange/BundleRepacker.php';

function wire(mixed $v): string {
    return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
}
function money(string $role, string $minor, string $currency = 'BRL', int $scale = 2): array {
    return compact('role', 'minor', 'currency', 'scale');
}
function ref(string $resource, string $id, string $role, string $type = 'reference'): array {
    return ['workspaceId'=>'A','resource'=>$resource,'id'=>$id,'role'=>$role,'type'=>$type];
}
function record(string $id, string $kind, array $money = [], array $references = [],
    array $fields = [], array $artifacts = [], string $state = 'active'): array {
    return ['id'=>$id,'version'=>'1','kind'=>$kind,'state'=>$state,
        'createdAt'=>'2025-01-01T00:00:00Z','updatedAt'=>'2025-01-02T00:00:00Z',
        'money'=>$money,'references'=>$references,'artifacts'=>$artifacts,'fields'=>(object)$fields];
}
/** Independent synthetic source, not a destination database or live adapter. */
function fixture(): array {
    $resources = [
        'accounts'=>[record('cash','master_data',[],[],['label'=>'Synthetic cash'])],
        'dimensions'=>[record('project','master_data',[],[],['label'=>'Synthetic project'])],
        'funding_sources'=>[
            record('grant','master_data',[money('received','70000')]),
            record('own','master_data',[money('received','30000')]),
        ],
        'budget_lines'=>[
            record('line-grant','source_only',[money('approved','70000')],
                [ref('funding_sources','grant','funding_source','parent'),ref('dimensions','project','project')]),
            record('line-own','source_only',[money('approved','30000')],
                [ref('funding_sources','own','funding_source','parent'),ref('dimensions','project','project')]),
        ],
        'budget_revisions'=>[
            record('amendment','source_only',[money('increase','5000')],
                [ref('budget_lines','line-grant','revises','parent')],['reason'=>'Synthetic amendment']),
        ],
        'obligations'=>[
            record('expense','obligation',[money('total','100000')],[ref('accounts','cash','account','parent')]),
            record('full','obligation',[money('total','1000','KWD',3)]),
        ],
        'commitments'=>[record('commitment','source_only',[money('reserved','100000')],
            [ref('obligations','expense','obligation','parent')])],
        'settlements'=>[
            record('payment','settlement',[money('paid','60000')],[ref('obligations','expense','obligation','parent')]),
            record('reversal','settlement',[money('paid','-10000')],[ref('settlements','payment','reverses','parent')]),
            record('full-payment','settlement',[money('paid','1000','KWD',3)],[ref('obligations','full','obligation','parent')]),
        ],
        'funding_allocations'=>[
            record('grant-paid','source_only',[money('allocated','42000')],
                [ref('settlements','payment','settlement','parent'),ref('budget_lines','line-grant','budget_line')]),
            record('own-paid','source_only',[money('allocated','18000')],
                [ref('settlements','payment','settlement','parent'),ref('budget_lines','line-own','budget_line')]),
            record('grant-reversed','source_only',[money('allocated','-7000')],
                [ref('settlements','reversal','settlement','parent'),ref('funding_allocations','grant-paid','reverses')]),
            record('own-reversed','source_only',[money('allocated','-3000')],
                [ref('settlements','reversal','settlement','parent'),ref('funding_allocations','own-paid','reverses')]),
        ],
        'employees'=>[record('worker','source_only',[],[],['display_label'=>'Synthetic worker','staff_code'=>'EMP-01'])],
        'custom_fields'=>[record('field','source_only',[],[ref('employees','worker','subject')],['definition'=>'cost_code','value'=>'SYNTH-1'])],
        'fiscal_authorizations'=>[record('historic','fiscal_authorization',[],[],['reference'=>'Synthetic historical authorization'],
            [['id'=>'proof','role'=>'historical_evidence']])],
        'extension.restricted_funding'=>[
            record('review','source_only',[],[ref('commitments','commitment','commitment'),ref('budget_revisions','amendment','revision')],
                ['mapping_version'=>'librefinance.mapping.v1','review_status'=>'approved'],
                [['id'=>'proof','role'=>'evidence']],'archived'),
            record('obsolete','source_only',[],[],['note'=>'Deleted source-only metadata'],[],'deleted'),
        ],
    ];
    $artifacts = ['proof'=>"Synthetic evidence\n\x00\x01\xff"];
    $manifest = ['format'=>BundleValidator::FORMAT,'source'=>['system'=>'synthetic','instanceId'=>'funding-example','version'=>'1'],
        'snapshotId'=>'snapshot-1','cutoff'=>'2025-02-01T00:00:00Z',
        'workspaces'=>[['id'=>'A','resources'=>[]],['id'=>'B','resources'=>[['resource'=>'accounts','status'=>'complete','count'=>1]]]],
        'chunks'=>[],'artifacts'=>[['id'=>'proof','workspaceId'=>'A','bytes'=>strlen($artifacts['proof']),
            'sha256'=>hash('sha256',$artifacts['proof']),'mediaType'=>'application/octet-stream']]];
    $chunks = [];
    foreach ($resources as $name => $records) {
        $manifest['workspaces'][0]['resources'][] = ['resource'=>$name,'status'=>'complete','count'=>count($records)];
        $id = 'chunk-' . count($chunks);
        $chunks[$id] = wire(['format'=>BundleValidator::CHUNK_FORMAT,'id'=>$id,'workspaceId'=>'A','resource'=>$name,'records'=>$records]);
        $manifest['chunks'][] = ['id'=>$id,'workspaceId'=>'A','resource'=>$name,'count'=>count($records),'bytes'=>strlen($chunks[$id]),'sha256'=>hash('sha256',$chunks[$id])];
    }
    $manifest['workspaces'][0]['resources'][] = ['resource'=>'contacts','status'=>'empty','count'=>0];
    $chunks['other-account'] = wire(['format'=>BundleValidator::CHUNK_FORMAT,'id'=>'other-account','workspaceId'=>'B','resource'=>'accounts',
        'records'=>[record('cash','master_data',[],[],['label'=>'Independent synthetic cash'])]]);
    $manifest['chunks'][] = ['id'=>'other-account','workspaceId'=>'B','resource'=>'accounts','count'=>1,
        'bytes'=>strlen($chunks['other-account']),'sha256'=>hash('sha256',$chunks['other-account'])];
    return [$manifest,$chunks,$artifacts,$resources];
}
$checks = 0;
function check(string $message, bool $value): void {
    global $checks;
    if (!$value) throw new RuntimeException('FAILED: '.$message);
    ++$checks;
    echo 'PASS: ', $message, PHP_EOL;
}
function reject(string $message, string $code, callable $operation): void {
    try { $operation(); } catch (DomainException $e) {
        check($message,$e->getMessage()===$code);
        return;
    }
    throw new RuntimeException('Unexpected acceptance: '.$message);
}
[$m,$chunks,$artifacts,$source] = fixture();
$validator = new BundleValidator();
$repacker = new BundleRepacker($validator);
$raw = wire($m);
$initial = $validator->inspect($raw,$chunks,$artifacts);
check('mixed-source snapshot is complete but never authorized for apply',
    $initial['status']==='complete' && !$initial['applyAuthorized'] && !$initial['sourceAttested']);
$out = $repacker->repack($raw,$chunks,$artifacts,1);
check('repartition changes transport layout',count($out['chunks'])>count($chunks) && $out['manifest']!==$raw);
check('artifact bytes are exact including non-text content',$out['artifacts']===$artifacts);
$after = $validator->inspect($out['manifest'],$out['chunks'],$out['artifacts'],null,$initial);
check('all original record hashes remain unchanged', $after['recordIndex']===$initial['recordIndex']
    && $after['unchanged']===$initial['observedRecords'] && $after['conflicts']===0);
$actual = [];
foreach ($out['chunks'] as $bytes) {
    $chunk = json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    if ($chunk['workspaceId']==='A') {
        foreach ($chunk['records'] as $r) $actual[$chunk['resource']][]=$r;
    }
}
check('independent source records and relation order survive',wire($actual)===wire(json_decode(wire($source),true,32,JSON_THROW_ON_ERROR)));
check('BRL partial settlement net of reversal remains 50000', (int)$actual['settlements'][0]['money'][0]['minor']+
    (int)$actual['settlements'][1]['money'][0]['minor']===50000
    && $actual['obligations'][0]['money'][0]['minor']==='100000');
check('funding allocations net to grant 35000 and own 15000',
    (int)$actual['funding_allocations'][0]['money'][0]['minor']+(int)$actual['funding_allocations'][2]['money'][0]['minor']===35000
    && (int)$actual['funding_allocations'][1]['money'][0]['minor']+(int)$actual['funding_allocations'][3]['money'][0]['minor']===15000);
check('KWD fully settled amount preserves its scale and currency without summing BRL',
    $actual['settlements'][2]['money'][0]===money('paid','1000','KWD',3)
    && $actual['obligations'][1]['money'][0]===money('total','1000','KWD',3));
check('historical extension, employee metadata and custom field remain source-only',
    $actual['extension.restricted_funding'][0]['state']==='archived'
    && $actual['extension.restricted_funding'][1]['state']==='deleted'
    && $actual['employees'][0]['fields']['staff_code']==='EMP-01'
    && $actual['custom_fields'][0]['fields']['value']==='SYNTH-1');
check('repartition itself is repeatable',$repacker->repack($out['manifest'],$out['chunks'],$out['artifacts'],1)===$out);
$missing=$chunks;unset($missing[array_key_first($missing)]);
reject('missing record chunk cannot be laundered into a complete snapshot','interchange_repack_requires_complete_bundle',
    fn()=>$repacker->repack($raw,$missing,$artifacts,1));
reject('missing linked file cannot be repacked','interchange_repack_requires_complete_bundle',
    fn()=>$repacker->repack($raw,$chunks,[],1));
$excluded=$m;
$excluded['workspaces'][0]['resources'][]=['resource'=>'reports','status'=>'excluded','count'=>null];
check('excluded is unknown for overall completeness',$validator->inspect(wire($excluded),$chunks,$artifacts)['status']==='incomplete');
reject('excluded resource cannot be presented as complete by repacker','interchange_repack_requires_complete_bundle',
    fn()=>$repacker->repack(wire($excluded),$chunks,$artifacts,1));
$excluded['workspaces'][0]['resources'][0]=['resource'=>'accounts','status'=>'excluded','count'=>null];
reject('excluded collection cannot transport records','interchange_excluded_resource_content',
    fn()=>$validator->inspect(wire($excluded),$chunks,$artifacts));
reject('invalid partition limit','invalid_interchange_chunk_size',fn()=>$repacker->repack($raw,$chunks,$artifacts,0));
// Change the source content while supplying honest new transport hashes.
function changeChunk(array $manifest, array $chunks, string $resource, callable $change): array {
    foreach ($manifest['chunks'] as &$desc) {
        if ($desc['workspaceId'] !== 'A' || $desc['resource'] !== $resource) continue;
        $v=json_decode($chunks[$desc['id']],false,32,JSON_THROW_ON_ERROR);
        $change($v);
        $chunks[$desc['id']]=wire($v);
        $desc['count']=count($v->records);
        $desc['bytes']=strlen($chunks[$desc['id']]);
        $desc['sha256']=hash('sha256',$chunks[$desc['id']]);
        foreach ($manifest['workspaces'][0]['resources'] as &$collection) {
            if ($collection['resource']===$resource) $collection['count']=$desc['count'];
        }
        unset($collection);
        break;
    }
    unset($desc);
    return [$manifest,$chunks];
}
[$changed,$modified]=changeChunk($m,$chunks,'funding_allocations',
    static fn($v)=>$v->records[0]->money[0]->minor='41000');
$conflict=$validator->inspect(wire($changed),$modified,$artifacts,null,$initial);
check('changed allocation with valid transport hashes is still a historical conflict',
    $conflict['conflicts']===1 && !$conflict['applyAuthorized']);
[$duplicate,$duplicates]=changeChunk($m,$chunks,'funding_allocations',
    static function ($v): void {$v->records[]=$v->records[0];});
reject('duplicate allocation identity never becomes a second allocation','interchange_duplicate_identity',
    fn()=>$repacker->repack(wire($duplicate),$duplicates,$artifacts,1));
[$missingRef,$missingReferences]=changeChunk($m,$chunks,'funding_allocations',
    static fn($v)=>$v->records[0]->references[1]->id='missing-line');
reject('unresolved budget line in a complete collection is not inferred','interchange_missing_reference',
    fn()=>$repacker->repack(wire($missingRef),$missingReferences,$artifacts,1));
$denied=$missingRef;
foreach ($denied['workspaces'][0]['resources'] as &$r) {
    if ($r['resource']==='budget_lines') {$r['status']='access_denied';$r['count']=null;}
}
unset($r);
$incomplete=$validator->inspect(wire($denied),$missingReferences,$artifacts);
check('unavailable budget reference prevents complete mapping, not a zero amount',
    $incomplete['status']==='incomplete' && $incomplete['unverifiedReferences']===1 && $incomplete['recordIndex']===[]);
echo "ALL CHECKS PASSED: $checks\n";
