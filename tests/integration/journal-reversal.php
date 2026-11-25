<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use LibreFinance\Tests\Integration\TestHarness;
use OCA\LibreFinance\Service\WriteService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

// The basic suite supplies the loopback harness and reports completion only
// after these assertions have run.
if (!(($t ?? null) instanceof TestHarness)) {
    throw new RuntimeException('Journal reversal fixtures must run through the basic integration suite');
}
$owner = 'lfmaintestA';
$manager = 'lfmaintestB';
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$tag = bin2hex(random_bytes(6));
$workspace = ['kind' => 'organization', 'currency' => 'BRL'];
$spaceName = 'Synthetic journal reversals ' . $tag;
$space = $t->check('create isolated reversal workspace', $owner, 'POST', '/spaces',
    $workspace + ['name' => $spaceName], 201);
$other = $t->check('create second workspace owned by the same user', $owner, 'POST', '/spaces',
    $workspace + ['name' => 'Reversal isolation ' . $tag], 201);
$spaceId = (int)$space['id'];
$base = '/spaces/' . $spaceId;
$accounts = $t->check('read reversal accounts', $owner, 'GET', $base . '/accounts', null, 200);
$byCode = array_column($accounts['accounts'], 'id', 'code');
$expense = (int)$byCode['5000'];
$cash = (int)$byCode['1000'];

// Seed posted history with IDs long enough for the legacy key to pass the
// public 12-character minimum. Leave room below them for PostgreSQL's sequence.
$db = \OC::$server->get(IDBConnection::class);
$writer = \OC::$server->get(WriteService::class);
$q = $db->getQueryBuilder();
$q->select('id')->from('lf_spaces')
    ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
    ->andWhere($q->expr()->eq('owner_uid', $q->createNamedParameter($owner)))
    ->andWhere($q->expr()->eq('name', $q->createNamedParameter($spaceName)));
$t->record('HTTP and CLI share the newly created synthetic workspace', $q->executeQuery()->fetchOne() !== false,
    'workspace ID, owner and unique name match before seeding');
$q = $db->getQueryBuilder();
$q->selectAlias($q->createFunction('MAX(id)'), 'max_id')->from('lf_journals');
$target = max(100000, (int)$q->executeQuery()->fetchOne() + 10000);
$legacyOriginal = $target + 1;
$legacyReversal = $target + 2;
$collisionKey = 'reverse:' . $target;
$legacyKey = 'reverse:' . $legacyOriginal;
$seedJournal = static function (int $id, string $key, int $amount, ?int $link = null) use (
    $writer, $spaceId, $owner, $today, $expense, $cash,
): void {
    $memo = $link === null ? 'Synthetic historical expense' : 'Estorno: Synthetic historical expense';
    $hash = $link === null
        ? hash('sha256', json_encode([$expense, $cash, $amount, $today, $memo, 'BRL', null], JSON_THROW_ON_ERROR))
        : hash('sha256', $key);
    $writer->insert('lf_journals', [
        'id' => $id, 'space_id' => $spaceId, 'effective_on' => $today, 'memo' => $memo,
        'external_key' => $key, 'request_hash' => $hash, 'status' => 'posted',
        'reversed_journal_id' => $link, 'created_by' => $owner, 'created_at' => time(),
    ]);
    foreach ([$expense => $amount, $cash => -$amount] as $account => $signedAmount) {
        $writer->insert('lf_postings', [
            'space_id' => $spaceId, 'journal_id' => $id, 'account_id' => $account,
            'amount_minor' => $signedAmount, 'currency' => 'BRL',
        ]);
    }
};
$db->beginTransaction();
try {
    $seedJournal($target, 'history:target:' . $tag, 2599);
    $seedJournal($legacyOriginal, 'history:legacy:' . $tag, 730);
    $seedJournal($legacyReversal, $legacyKey, -730, $legacyOriginal);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$ordinary = [
    'debitId' => $expense, 'creditId' => $cash, 'amountMinor' => 137,
    'date' => $today, 'memo' => 'Ordinary journal using a legacy reversal key', 'externalKey' => $collisionKey,
];
$collision = $t->check('ordinary journal can own the legacy key', $owner, 'POST', $base . '/journals',
    $ordinary, 201, static fn(array $v): bool => ($v['replayed'] ?? null) === false);
$collisionId = (int)$collision['id'];
$reverse = $base . '/journals/' . $target . '/reverse';
$t->check('nonmember cannot reverse', $manager, 'POST', $reverse, null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$t->check('owner cannot reverse a journal ID through another workspace', $owner, 'POST',
    '/spaces/' . $other['id'] . '/journals/' . $target . '/reverse', null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'journal_not_found');
$t->check('grant viewer', $owner, 'POST', $base . '/members', ['uid' => $manager, 'role' => 'viewer'], 200);
$t->check('viewer cannot reverse', $manager, 'POST', $reverse, null, 403,
    static fn(array $v): bool => ($v['error'] ?? null) === 'permission_denied');
$t->check('grant manager for parallel request', $owner, 'POST', $base . '/members',
    ['uid' => $manager, 'role' => 'manager'], 200);

$readJournals = static function (string $label) use ($t, $owner, $base): array {
    $page = $t->check($label, $owner, 'GET', $base . '/journals', null, 200);
    foreach ($page['journals'] as &$row) {
        usort($row['postings'], static fn(array $a, array $b): int => $a['accountId'] <=> $b['accountId']);
    }
    unset($row);
    return array_column($page['journals'], null, 'id');
};
$readJournalKeys = static function () use ($db, $spaceId): array {
    $q = $db->getQueryBuilder();
    $q->select('id', 'external_key', 'request_hash', 'status')->from('lf_journals')
        ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
        ->orderBy('id', 'ASC');
    return array_column($q->executeQuery()->fetchAllAssociative(), null, 'id');
};
$before = $readJournals('read original and historic journals');
$keysBefore = $readJournalKeys();
$request = ['user' => $owner, 'method' => 'POST', 'path' => $reverse, 'data' => null];
$results = $t->parallel($request, array_replace($request, ['user' => $manager]));
$created = array_filter($results, static fn(array $r): bool => $r['code'] === 200 && ($r['body']['replayed'] ?? null) === false);
$replayed = array_filter($results, static fn(array $r): bool => $r['code'] === 200 && ($r['body']['replayed'] ?? null) === true);
$newId = (int)(reset($created)['body']['id'] ?? 0);
$t->record('colliding key still allows one reversal and one parallel replay',
    count($created) === 1 && count($replayed) === 1 && $newId > 0 && $newId !== $collisionId
        && (int)(reset($replayed)['body']['id'] ?? 0) === $newId,
    'one new linked journal ID', $results);
$t->check('later retries replay the same reversal', $owner, 'POST', $reverse, null, 200,
    static fn(array $v): bool => ($v['replayed'] ?? null) === true && (int)($v['id'] ?? 0) === $newId);
$t->check('another workspace cannot replay the reversal', $owner, 'POST',
    '/spaces/' . $other['id'] . '/journals/' . $target . '/reverse', null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'journal_not_found');
$t->check('revoke manager access', $owner, 'DELETE', $base . '/members/' . $manager, null, 200);
$t->check('revoked manager cannot replay the reversal', $manager, 'POST', $reverse, null, 404,
    static fn(array $v): bool => ($v['error'] ?? null) === 'space_not_found');
$t->check('historic linked reversal still replays', $owner, 'POST',
    $base . '/journals/' . $legacyOriginal . '/reverse', null, 200,
    static fn(array $v): bool => ($v['replayed'] ?? null) === true && (int)($v['id'] ?? 0) === $legacyReversal);
$t->check('historic reversal retains its existing key', $owner, 'POST', $base . '/journals',
    array_replace($ordinary, ['externalKey' => $legacyKey]), 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'idempotency_conflict');
$t->check('ordinary journal still replays with its legacy-shaped key', $owner, 'POST', $base . '/journals',
    $ordinary, 201, static fn(array $v): bool => ($v['replayed'] ?? null) === true && (int)($v['id'] ?? 0) === $collisionId);
$t->check('ordinary key still conflicts for changed content', $owner, 'POST', $base . '/journals',
    array_replace($ordinary, ['amountMinor' => 138]), 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'idempotency_conflict');
$t->check('internal fallback key is outside the ordinary key format', $owner, 'POST', $base . '/journals',
    array_replace($ordinary, ['externalKey' => 'reverse/' . $target]), 422,
    static fn(array $v): bool => ($v['error'] ?? null) === 'invalid_idempotency_key');
$t->check('inverse journal cannot itself be reversed', $owner, 'POST', $base . '/journals/' . $newId . '/reverse',
    null, 404, static fn(array $v): bool => ($v['error'] ?? null) === 'journal_not_found');

$writer->insert('lf_journal_claims', [
    'space_id' => $spaceId, 'journal_id' => $collisionId, 'module_id' => 'librefinance_banking',
    'reference' => 'synthetic:' . $tag, 'claimed_by' => $owner, 'claimed_at' => time(),
]);
$t->check('claimed ordinary journal remains ineligible for reversal', $owner, 'POST',
    $base . '/journals/' . $collisionId . '/reverse', null, 409,
    static fn(array $v): bool => ($v['error'] ?? null) === 'journal_claimed');

$after = $readJournals('read journal history after reversals and retries');
$new = $after[$newId] ?? [];
unset($after[$newId]);
$t->record('original, ordinary collision and historic reversal remain unchanged', $after === $before,
    'all previous journal rows and postings match');
$keysAfter = $readJournalKeys();
unset($keysAfter[$newId]);
$t->record('historic journal keys, hashes and statuses remain unchanged', $keysAfter === $keysBefore,
    'the reversal only adds its own journal row');
$postings = array_column($new['postings'] ?? [], 'amountMinor', 'accountId');
$t->record('one exact balanced inverse is linked to the original',
    (int)($new['reversed_journal_id'] ?? 0) === $target && count($new['postings'] ?? []) === 2
        && ($postings[$expense] ?? null) === -2599 && ($postings[$cash] ?? null) === 2599,
    'two opposite integer postings with the original account IDs', $new);
$t->check('only the ordinary expense remains in account balances', $owner, 'GET', $base . '/accounts', null, 200,
    static function (array $v): bool {
        $byCode = array_column($v['accounts'] ?? [], 'balance_minor', 'code');
        return ($byCode['5000'] ?? null) === 137 && ($byCode['1000'] ?? null) === -137;
    });
$q = $db->getQueryBuilder();
$q->selectAlias($q->createFunction('COUNT(*)'), 'total')->from('lf_audit')
    ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId, IQueryBuilder::PARAM_INT)))
    ->andWhere($q->expr()->eq('action', $q->createNamedParameter('journal.reversed')))
    ->andWhere($q->expr()->eq('entity_id', $q->createNamedParameter($target, IQueryBuilder::PARAM_INT)));
$t->record('parallel retries record one reversal audit entry', (int)$q->executeQuery()->fetchOne() === 1,
    'audit entry references the original');
