<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\LibreFinance\Import\Interchange\BundleValidator;

require_once dirname(__DIR__, 2) . '/lib/Import/Source/SourceSystem.php';
require_once dirname(__DIR__, 2) . '/lib/Import/Interchange/BundleValidator.php';

$dir = __DIR__ . '/fixtures/interchange-v1';
$bytes = file_get_contents($dir . '/manifest.json');
$manifest = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
$chunks = [];
foreach ($manifest->chunks as $desc) {
    $chunks[$desc->id] = file_get_contents($dir . '/' . $desc->id . '.json');
}
$artifacts = ['evidence-a' => file_get_contents($dir . '/evidence-a.txt')];
$validator = new BundleValidator();
$checks = 0;

function encode(mixed $v): string {
    return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
}
function check(string $message, bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAILED: ' . $message);
    ++$checks;
    echo 'PASS: ', $message, PHP_EOL;
}
function reject(string $message, string $expected, callable $fn): void {
    try {
        $fn();
    } catch (DomainException $e) {
        check($message, $e->getMessage() === $expected);
        return;
    }
    throw new RuntimeException('FAILED: accepted ' . $message);
}
function copyManifest(): stdClass {
    global $bytes;
    return json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
}
function mutate(string $id, callable $change): array {
    global $chunks;
    $m = copyManifest();
    $c = $chunks;
    $v = json_decode($c[$id], false, 32, JSON_THROW_ON_ERROR);
    $change($v);
    $c[$id] = encode($v);
    foreach ($m->chunks as $desc) {
        if ($desc->id === $id) {
            $desc->sha256 = hash('sha256', $c[$id]);
            $desc->bytes = strlen($c[$id]);
        }
    }
    return [encode($m), $c];
}

$full = $validator->inspect($bytes, $chunks, $artifacts);
check('complete two-company bundle with no authority to apply', $full['status'] === 'complete'
    && $full['workspaceCount'] === 2 && $full['observedRecords'] === 7
    && !$full['applyAuthorized'] && !$full['sourceAttested'] && $full['mappingRequired']);
check('same source record ID in separate books is not a duplicate', isset(
    $full['recordIndex']['company-a/accounts/cash'], $full['recordIndex']['company-b/accounts/cash']));
check('distinct historical semantic types and archival state', $full['recordIndex']['company-a/journal_entries/entry-1']['kind'] === 'accounting_entry'
    && $full['recordIndex']['company-a/bank_statements/statement-1']['kind'] === 'bank_evidence'
    && $full['recordIndex']['company-a/obligations/bill-1']['state'] === 'archived'
    && $full['recordIndex']['company-a/fiscal_authorizations/auth-1']['kind'] === 'fiscal_authorization'
    && $full['recordIndex']['company-a/extension.custom/old-1']['state'] === 'deleted');
check('checkpoint includes all verified chunks and artifact', count($full['checkpoint']['verifiedChunks']) === 7
    && count($full['checkpoint']['verifiedArtifacts']) === 1 && $full['checkpoint']['missingChunks'] === []);
$again = $validator->inspect($bytes, array_reverse($chunks, true), $artifacts, $full['checkpoint'], $full);
check('identical replay regardless of delivery map order', $again['unchanged'] === 7 && $again['conflicts'] === 0
    && $again['recordIndex'] === $full['recordIndex']);
$partial = $chunks;
unset($partial['account-a']);
$incomplete = $validator->inspect($bytes, $partial);
check('partial transport is incomplete, not missing-parent proof', $incomplete['status'] === 'incomplete'
    && $incomplete['unverifiedReferences'] === 3 && $incomplete['recordIndex'] === []
    && $incomplete['checkpoint']['missingChunks'] === ['account-a']
    && $incomplete['checkpoint']['missingArtifacts'] === ['evidence-a']);
$resumed = $validator->inspect($bytes, $chunks, $artifacts, $incomplete['checkpoint']);
check('resumption revalidates actual bytes', $resumed === $full);
$empty = $validator->inspect($bytes, [], [], $full['checkpoint']);
check('checkpoint cannot claim absent bytes have been verified', $empty['status'] === 'incomplete'
    && $empty['observedRecords'] === 0 && count($empty['checkpoint']['missingChunks']) === 7);
reject('wrong snapshot checkpoint', 'interchange_checkpoint_mismatch',
    fn() => $validator->inspect($bytes, $chunks, $artifacts, ['manifestSha256' => str_repeat('0',64)]));
$broken = $chunks;
$broken['account-a'] = str_replace('Synthetic cash','Synthetic bank',$broken['account-a']);
reject('changed raw chunk bytes', 'interchange_content_integrity', fn() => $validator->inspect($bytes, $broken, $artifacts));
reject('changed attachment bytes', 'interchange_content_integrity', fn() => $validator->inspect($bytes, $chunks, ['evidence-a'=>'wrong']));
reject('undeclared supplied content', 'interchange_undeclared_content', fn() => $validator->inspect($bytes, $chunks + ['extra'=>'x'], $artifacts));

[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->references[0]->workspaceId = 'company-b');
reject('cross-company reference even when ID exists', 'interchange_reference_scope_mismatch', fn() => $validator->inspect($m,$c,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->references[0]->id = 'missing');
reject('missing parent with complete collection', 'interchange_missing_reference', fn() => $validator->inspect($m,$c,$artifacts));
$m = copyManifest();
$m->chunks = array_merge([$m->chunks[2]],array_slice($m->chunks,0,2),array_slice($m->chunks,3));
reject('parent must precede child in manifest order','interchange_parent_order',fn() => $validator->inspect(encode($m),$chunks,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->workspaceId = 'company-b');
reject('chunk descriptor cannot be rebound','interchange_chunk_scope_mismatch',fn() => $validator->inspect($m,$c,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->kind = 'bank_evidence');
reject('bank evidence is not a posted fact','interchange_semantic_kind_mismatch',fn() => $validator->inspect($m,$c,$artifacts));
foreach ([12.34, 1234, '-0','1e3','00',str_repeat('9',39)] as $value) {
    [$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->money[0]->minor = $value);
    reject('reject non-exact minor-unit representation '.get_debug_type($value), 'invalid_interchange_money',fn() => $validator->inspect($m,$c,$artifacts));
}
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->money[0]->scale = 10);
reject('unsupported precision','invalid_interchange_money',fn() => $validator->inspect($m,$c,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->updatedAt = '2025-02-30T00:00:00Z');
reject('invalid calendar date','invalid_interchange_record',fn() => $validator->inspect($m,$c,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->updatedAt = "2025-01-02T00:00:0\0Z");
reject('invalid timestamp control character','invalid_interchange_record',fn() => $validator->inspect($m,$c,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->updatedAt = '2025-03-01T00:00:00Z');
reject('record newer than snapshot','invalid_interchange_record',fn() => $validator->inspect($m,$c,$artifacts));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->fields->api_key = 'synthetic');
reject('credential-shaped field is rejected without echoing value','invalid_interchange_fields',fn() => $validator->inspect($m,$c,$artifacts));
$m = copyManifest();
$m->artifacts[0]->workspaceId = 'company-b';
reject('cross-company artifact','interchange_artifact_scope_mismatch',fn() => $validator->inspect(encode($m),$chunks,$artifacts));
$m = copyManifest();
$m->workspaces[0]->resources[0]->count = 2;
reject('manifest count does not match declared chunks','interchange_resource_count_mismatch',fn() => $validator->inspect(encode($m),$chunks,$artifacts));
$m = copyManifest();
$m->workspaces[0]->resources[0]->status = 'access_denied';
$m->workspaces[0]->resources[0]->count = null;
$unknown = $validator->inspect(encode($m),$chunks,$artifacts);
check('unknown collection does not imply completeness even with bytes', $unknown['status']==='incomplete');
$m = copyManifest();
$m->format = 'librefinance.interchange.v2';
reject('unsupported version rejected with actionable code','unsupported_interchange_format',fn() => $validator->inspect(encode($m),[]));
reject('duplicate JSON keys do not normalize silently','invalid_interchange_encoding',
    fn() => $validator->inspect(str_replace('"snapshotId":','"snapshotId":"other","snapshotId":',$bytes),[]));
[$m,$c] = mutate('journal-a', fn($v) => $v->records[0]->money[0]->minor = '123');
$changed = $validator->inspect($m,$c,$artifacts,null,$full);
check('content change without version increase is a conflict, not overwrite', $changed['conflicts'] === 1
    && $changed['unchanged'] === 6 && !$changed['applyAuthorized']);
$m = copyManifest();
$newArtifact = 'New synthetic evidence';
$m->artifacts[0]->bytes = strlen($newArtifact);
$m->artifacts[0]->sha256 = hash('sha256',$newArtifact);
$changed = $validator->inspect(encode($m),$chunks,['evidence-a'=>$newArtifact],null,$full);
check('attachment-only change is a record conflict',$changed['conflicts'] === 1 && $changed['unchanged'] === 6);
$m = copyManifest();
$m->source->instanceId = 'other-instance';
reject('previous index belongs to another source instance','interchange_previous_scope_mismatch',fn() => $validator->inspect(encode($m),$chunks,$artifacts,null,$full));
$previous = $full;
$previous['recordIndex']['company-a/accounts/other'] = $previous['recordIndex']['company-a/accounts/cash'];
reject('previous index cannot forge identity','invalid_interchange_previous_index',fn() => $validator->inspect($bytes,$chunks,$artifacts,null,$previous));
$m = copyManifest();
$m->chunks[] = clone $m->chunks[0];
reject('duplicate chunk declaration','invalid_interchange_chunk_descriptor',fn() => $validator->inspect(encode($m),$chunks,$artifacts));
[$mBytes,$c] = mutate('account-a', function($v) { $v->records[] = clone $v->records[0]; });
$m = json_decode($mBytes);
$m->chunks[0]->count = 2;
$m->workspaces[0]->resources[0]->count = 2;
reject('duplicate record within workspace and resource','interchange_duplicate_identity',fn() => $validator->inspect(encode($m),$c,$artifacts));
$m = copyManifest();
$m->workspaces[0]->resources[0]->count = 0;
$m->workspaces[0]->resources[0]->status = 'empty';
unset($m->chunks[0]);$m->chunks = array_values($m->chunks);
$changed = $chunks;unset($changed['account-a']);
reject('empty parent collection cannot hide references','interchange_missing_reference',fn() => $validator->inspect(encode($m),$changed,$artifacts));
echo "ALL CHECKS PASSED: $checks\n";
