// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict'
import test from 'node:test'
import {
  initialPolicyDraft, policyInputValue, formatPolicyValue,
} from '../../src/services/saasPolicyDraft.js'

const boolean = { key: 'imports.enabled', type: 'boolean', effectiveValue: false,
  editableByCurrentActor: true, allowedValues: [false] }
const ceiling = { key: 'workspaces.maxCount', type: 'ceiling', effectiveValue: 8,
  maxAllowed: 8, editableByCurrentActor: true }
const set = { key: 'regions.allowed', type: 'allowed_set', effectiveValue: ['BR'],
  allowedValues: ['BR', 'PT'], editableByCurrentActor: true }

test('typed drafts retain the exact policy type', () => {
  assert.equal(initialPolicyDraft(boolean), false)
  assert.equal(initialPolicyDraft(ceiling), '8')
  assert.equal(initialPolicyDraft(set), 'BR')
  assert.equal(policyInputValue({ ...boolean, allowedValues: [false, true] }, true), true)
  assert.equal(policyInputValue(ceiling, '2'), 2)
  assert.deepEqual(policyInputValue(set, ' PT, BR '), ['PT', 'BR'])
})

test('boolean child cannot reenable denied parent', () => {
  assert.throws(() => policyInputValue(boolean, true), /herdada/)
  assert.equal(policyInputValue(boolean, false), false)
  assert.throws(() => policyInputValue({ ...boolean, editableByCurrentActor: false }, false), /autorização/)
})

test('numeric ceiling rejects unsafe and widening values', () => {
  for (const invalid of ['-1', '2.4', '10', '9007199254740992', 'Infinity', 'a']) {
    assert.throws(() => policyInputValue(ceiling, invalid))
  }
  assert.equal(policyInputValue(ceiling, 0), 0)
  assert.equal(policyInputValue(ceiling, '8'), 8)
})

test('sets reject widened, duplicate and malformed entries', () => {
  assert.deepEqual(policyInputValue(set, ''), [])
  assert.throws(() => policyInputValue(set, 'BR, US'), /herdados/)
  assert.throws(() => policyInputValue(set, 'BR, BR'), /repetidos/)
  assert.throws(() => policyInputValue(set, 'X'.repeat(65)), /repetidos/)
})

test('visible effective values do not infer permissions', () => {
  assert.equal(formatPolicyValue(boolean), 'Bloqueado')
  assert.equal(formatPolicyValue(ceiling), '8')
  assert.equal(formatPolicyValue(set), 'BR')
  assert.equal(formatPolicyValue({ ...set, effectiveValue: [] }), 'Nenhum valor permitido')
  assert.throws(() => policyInputValue({ ...ceiling, editableByCurrentActor: false }, 0), /autorização/)
})
