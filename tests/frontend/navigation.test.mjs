// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  availableSections, canOpenView, defaultGroupOpen, menuEntries,
  navigationSearch, normalizeNavigationText, parentOfView, PAGE_NAMES,
} from '../../src/navigation.js'
const all = ['librefinance_banking', 'librefinance_obligations',
  'librefinance_reports', 'librefinance_invoicing', 'librefinance_nfse', 'librefinance_import']
  .map(id => ({ id, enabled: true }))

const s = (manager = true, modules = all, modulesLoaded = true) =>
  availableSections({ manager, modules, modulesLoaded })

test('seven common tasks are first-level and accounting is available without blocking them', () => {
  assert.deepEqual(s().map(g => g.label), ['LIBREFINANCE'])
  assert.deepEqual(s()[0].items.map(i => i.id),
    ['dashboard', 'receivables', 'payables', 'banking', 'reports', 'registers', 'advanced'])
  assert.deepEqual(s()[0].items.filter(i => i.children).map(i => i.id),
    ['registers', 'advanced'])
  assert.equal(parentOfView(s(), 'transactions'), 'advanced')
  assert.equal(parentOfView(s(), 'accounts'), 'advanced')
  assert.equal(PAGE_NAMES.receivables, 'A receber')
  assert.equal(PAGE_NAMES.payables, 'A pagar')
  assert.equal(PAGE_NAMES.banking, 'Bancos e caixa')
  assert.equal(PAGE_NAMES.transactions, 'Lançamentos contábeis')
})

test('disabled and undiscovered modules never enter navigation or search', () => {
  const view = s(false, all.filter(x => x.id !== 'librefinance_banking'), true)
  const paths = menuEntries(view).map(x => x.id)
  assert.equal(paths.includes('banking'), false)
  assert.equal(paths.includes('ai'), false)
  assert.equal(paths.includes('receivables'), true)
  assert.equal(paths.includes('payables'), true)
  assert.equal(navigationSearch(view, 'extrato').some(x => x.id === 'banking'), false)
  assert.equal(canOpenView(view, 'import', { migrationEnabled: true }), false)
  const fresh = s(true, all, false)
  assert.equal(menuEntries(fresh).some(x => x.module), false)
  assert.equal(menuEntries(fresh).some(x => x.id === 'transactions'), true)
})

test('search is accent-insensitive and finds nested paths by meaning', () => {
  assert.equal(normalizeNavigationText('  Conciliação  '), 'conciliacao')
  assert.equal(navigationSearch(s(), 'nota fiscal')[0].id, 'nfse')
  assert.equal(navigationSearch(s(), 'clientes')[0].id, 'contacts')
  assert.equal(navigationSearch(s(), 'CADASTROS').some(x => x.id === 'contacts'), true)
  assert.equal(navigationSearch(s(), 'migracao', { manager: true, migrationEnabled: true })[0].id, 'import')
  assert.equal(navigationSearch(s(false), 'migracao', { manager: false, migrationEnabled: true }).length, 0)
  assert.equal(navigationSearch(s(), 'contabilidade').some(x => x.id === 'accounts'), true)
  assert.equal(navigationSearch(s(), 'cobrança')[0].id, 'receivables')
  assert.equal(navigationSearch(s(), 'fornecedor')[0].id, 'contacts')
})

test('optional business features are progressively expanded, never permission-gated by kind', () => {
  assert.equal(defaultGroupOpen('billing', 'personal'), false)
  assert.equal(defaultGroupOpen('billing', 'collective'), false)
  assert.equal(defaultGroupOpen('billing', 'organization'), false)
  assert.equal(defaultGroupOpen('registers', 'organization'), false)
  assert.equal(canOpenView(s(), 'sales'), true) // legacy shared link
  assert.equal(canOpenView(s(), 'purchases'), true) // legacy shared link
  assert.equal(canOpenView(s(), 'invoicing'), true) // historic bookmarked route
  assert.equal(canOpenView(s(), 'obligations'), true)
  assert.equal(canOpenView(s(), 'receivables'), true)
  assert.equal(canOpenView(s(), 'payables'), true)
  assert.equal(canOpenView(s(), 'taxes'), true)
  assert.equal(canOpenView(s(), 'settings', { manager: true }), true)
  assert.equal(canOpenView(s(), 'import', { manager: true, migrationEnabled: false }), false)
})
