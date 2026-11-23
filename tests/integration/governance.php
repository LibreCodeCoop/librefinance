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
$tag = bin2hex(random_bytes(5));

// No test changes an instance-wide setting or uses a real bank statement.
// Both fixture users are deliberately non-administrators.
$t->check('non-admin cannot read instance governance', $a, 'GET',
    '/admin/governance', null, 403);
$t->check('non-admin cannot enable managed provisioning', $a, 'PUT',
    '/admin/governance', ['managed' => true], 403);
$t->check('non-admin cannot search instance Nextcloud groups', $a, 'GET',
    '/admin/groups?search=finance', null, 403);
$t->check('non-admin cannot list administrative contexts', $a, 'GET',
    '/admin/contexts', null, 403);
$t->check('non-admin cannot provision group-owned space', $a, 'POST',
    '/admin/contexts', ['name' => 'Denied ' . $tag, 'kind' => 'organization',
        'gid' => 'lf_synth_finance_group', 'currency' => 'BRL'], 403);
$t->check('non-admin cannot bind existing space to another group', $b, 'PUT',
    '/admin/contexts/999999999/group', ['gid' => 'lf_synth_finance_group'], 403);
$t->check('capabilities read is available without admin privileges', $a, 'GET',
    '/governance/capabilities', null, 200,
    static fn(array $value): bool => isset($value['managed'], $value['canCreate']));

// Creation in managed mode is covered by a separate disposable admin fixture:
// it must not toggle shared state in the integration suite.

$t->check('non-admin cannot view group delegates', $a, 'GET',
    '/admin/groups/lf_synth_finance_group/delegates', null, 403);
$t->check('non-admin cannot grant group delegation', $a, 'POST',
    '/admin/groups/lf_synth_finance_group/delegates',
    ['uid' => $b], 403);
$t->check('non-admin cannot revoke group delegation', $a, 'DELETE',
    '/admin/groups/lf_synth_finance_group/delegates/' . $b, null, 403);

$t->finish();
