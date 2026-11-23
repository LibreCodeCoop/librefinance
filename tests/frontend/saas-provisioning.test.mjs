// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict'
import test from 'node:test'
import {
  chooseSaasEnvironment,
  eligibleSaasEnvironments,
  createWorkspaceClaimKeys,
} from '../../src/services/saasProvisioning.js'

const a = { id: 'environment-A', state: 'active', canManage: true }
const b = { id: 'environment-B', state: 'active', canManage: true }
const pending = { id: 'environment-C', state: 'enabled_pending_admin', canManage: true }
const revoked = { id: 'environment-D', state: 'active', canManage: false }

test('multiple qualified environments require explicit selection', () => {
  assert.equal(chooseSaasEnvironment([a, b]), '')
  assert.equal(chooseSaasEnvironment([a, b], b.id), b.id)
  assert.equal(chooseSaasEnvironment([a, b], 'revoked-environment'), '')
})

test('only qualified active environments are eligible', () => {
  assert.deepEqual(eligibleSaasEnvironments([a, pending, revoked]), [a])
  assert.equal(chooseSaasEnvironment([pending, revoked]), '')
  assert.equal(chooseSaasEnvironment([a, pending, revoked]), a.id)
  assert.equal(chooseSaasEnvironment([pending, revoked], a.id), '')
})

test('idempotency key is stable for safe retry and rotates with target or data', () => {
  let next = 0
  const claims = createWorkspaceClaimKeys(() => `safe-key-${++next}-2026`)
  const draft = { name: 'Group A', ownerUid: 'personA', kind: 'organization' }
  const one = claims.get(a.id, draft)
  assert.equal(claims.get(a.id, { ...draft, name: ' Group A ' }), one)
  const changedOwner = claims.get(a.id, { ...draft, ownerUid: 'personB' })
  assert.notEqual(changedOwner, one)
  assert.notEqual(claims.get(b.id, { ...draft, ownerUid: 'personB' }), changedOwner)
  claims.clear()
  assert.notEqual(claims.get(b.id, { ...draft, ownerUid: 'personB' }), one)
  assert.equal(next, 4)
})

test('pending claims remain stable across environment switches until success', () => {
  let next = 0
  const claims = createWorkspaceClaimKeys(() => `stable-key-${++next}-2026`)
  const draftA = { name: 'Contabilidade A', ownerUid: 'ownerA', kind: 'organization' }
  const draftB = { name: 'Contabilidade B', ownerUid: 'ownerB', kind: 'organization' }
  const keyA = claims.get(a.id, draftA)
  const keyB = claims.get(b.id, draftB)
  assert.notEqual(keyA, keyB)
  assert.equal(claims.get(a.id, draftA), keyA)
  claims.clear(a.id, draftA)
  assert.notEqual(claims.get(a.id, draftA), keyA)
  assert.equal(claims.get(b.id, draftB), keyB)
})

test('unsafe or unavailable browser key generator fails without a write', () => {
  assert.throws(() => createWorkspaceClaimKeys(() => '')
    .get(a.id, { name: 'New', ownerUid: 'owner', kind: 'organization' }), /seguro/)
  assert.throws(() => createWorkspaceClaimKeys(() => 'bad key!')
    .get(a.id, { name: 'New', ownerUid: 'owner', kind: 'organization' }), /seguro/)
})
