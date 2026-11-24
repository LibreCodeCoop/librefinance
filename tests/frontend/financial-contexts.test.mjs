// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { contextKey, financialContexts, authorizedInitialWorkspace } from '../../src/financialContexts.js'

const a = { id: 1, name: 'Book', saasManaged: true, financialEnvironment: { id: 'a', name: 'Shared name' } }
const b = { ...a, id: 2 }
const c = { ...a, id: 3, financialEnvironment: { id: 'b', name: 'Shared name' } }
const external = { ...a, id: 4, externalInvitation: true }
const legacy = { id: 5, name: 'Personal' }

test('distinct immutable environments partition authorized books even with equal names', () => {
  const rows = [a, b, c, external, legacy]
  const groups = financialContexts(rows)
  assert.deepEqual(groups.map(g => g.spaces.map(s => s.id)), [[1, 2], [3], [4], [5]])
  assert.equal(new Set(groups.map(g => g.label)).size, 4)
  assert.equal(contextKey(a), contextKey(b))
  assert.notEqual(contextKey(a), contextKey(c))
  assert.equal(contextKey(external), 'external')
  assert.equal(contextKey(legacy), 'legacy')
})
test('multiple contexts require explicit selection; a valid scoped deep link remains valid', () => {
  assert.equal(authorizedInitialWorkspace([a, b, c]), 0)
  assert.equal(authorizedInitialWorkspace([a, b, c], 3), 3)
  assert.equal(authorizedInitialWorkspace([a, b, c], 99), 0)
  assert.equal(authorizedInitialWorkspace([a, b], 99), 1)
  assert.equal(authorizedInitialWorkspace([legacy]), 5)
  assert.equal(authorizedInitialWorkspace([]), 0)
})
test('unknown managed metadata is isolated rather than merged into legacy', () => {
  const incomplete = { id: 9, name: 'Incomplete', saasManaged: true }
  assert.notEqual(contextKey(incomplete), contextKey(legacy))
  assert.equal(authorizedInitialWorkspace([incomplete, legacy]), 0)
})
