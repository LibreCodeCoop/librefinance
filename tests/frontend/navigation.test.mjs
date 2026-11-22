// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  availableSections, canOpenView, defaultGroupOpen, menuEntries,
  navigationSearch, normalizeNavigationText, parentOfView, PAGE_NAMES,
  canonicalView, financeDirectionForView, pageNameForSpace, fieldEntityKind,
} from '../../src/navigation.js'
const all = ['librefinance_banking', 'librefinance_obligations',
  'librefinance_reports', 'librefinance_invoicing', 'librefinance_nfse', 'librefinance_import']
  .map(id => ({ id, enabled: true }))

const s = (manager = true, modules = all, modulesLoaded = true) =>
  availableSections({ manager, modules, modulesLoaded })

test('one financial agenda and one management group without duplicate settings', () => {
  assert.deepEqual(s().map(g => g.label), ['LIBREFINANCE'])
  assert.deepEqual(s()[0].items.map(i => i.id),
    ['dashboard', 'finance', 'banking', 'reports', 'management'])
  assert.deepEqual(s()[0].items.filter(i => i.children).map(i => i.id), ['management'])
  for (const id of ['spaces', 'contacts', 'items', 'categories', 'companies',
    'accounts', 'transactions', 'taxes', 'nfse', 'ai',
    'access', 'fields', 'paymentMethods', 'modules', 'data']) {
    assert.equal(parentOfView(s(), id), 'management', id)
  }
  assert.equal(PAGE_NAMES.finance, 'A pagar e receber')
  assert.equal(PAGE_NAMES.banking, 'Bancos e caixa')
  assert.equal(menuEntries(s()).filter(x => x.id === 'settings').length, 0)
  const children = s()[0].items.find(item => item.id === 'management').children
  assert.deepEqual([...new Set(children.map(child => child.group))],
    ['Cadastros', 'Financeiro e fiscal', 'Equipe e personalização', 'Recursos e dados'])
  assert.ok(children.every(child => child.group))
  assert.equal(canonicalView('settings'), 'access')
  assert.equal(canonicalView('settings', 'payments'), 'paymentMethods')
  assert.equal(canonicalView('settings', 'data'), 'data')
  assert.equal(canonicalView('import'), 'data')
})
test('old pay/receive links are routed to the common agenda with direction intent', () => {
  for (const alias of ['obligations','sales','purchases','invoicing','receivables','payables']) {
    assert.equal(canonicalView(alias), 'finance')
    assert.equal(canOpenView(s(),alias), true)
  }
  for (const alias of ['sales','invoicing','receivables'])
    assert.equal(financeDirectionForView(alias),'receivable')
  for (const alias of ['purchases','payables'])
    assert.equal(financeDirectionForView(alias),'payable')
  assert.equal(financeDirectionForView('obligations'),'all')
  assert.equal(financeDirectionForView('finance'),'all')
})
test('module and role checks still hide disabled or manager-only actions', () => {
  const view = s(false, all.filter(x => x.id !== 'librefinance_banking'), true)
  const names = menuEntries(view).map(x => x.id)
  assert.equal(names.includes('banking'), false)
  assert.equal(names.includes('ai'), false)
  for (const sensitive of ['settings','access','fields','paymentMethods','modules','data']) {
    assert.equal(names.includes(sensitive), false, sensitive)
  }
  assert.equal(names.includes('finance'), true)
  assert.equal(names.includes('spaces'), true) // every user may create an owned space
  assert.equal(navigationSearch(view, 'extrato').some(x => x.id === 'banking'), false)
  assert.equal(canOpenView(view,'settings',{manager:false}),false)
  assert.equal(canOpenView(view,'import',{manager:false,migrationEnabled:true}),false)
  assert.equal(canOpenView(s(),'settings',{manager:true}),true)
  assert.equal(canOpenView(s(),'import',{manager:true,migrationEnabled:true}),true)
  const fresh = s(true, all, false)
  assert.equal(menuEntries(fresh).some(x => x.module), false)
  assert.equal(menuEntries(fresh).some(x => x.id === 'spaces'), true)
})
test('navigation search includes ordinary work and administrative capabilities', () => {
  assert.equal(normalizeNavigationText('  Conciliação  '), 'conciliacao')
  assert.equal(navigationSearch(s(), 'clientes')[0].id, 'contacts')
  assert.equal(navigationSearch(s(), 'cobrança')[0].id, 'finance')
  assert.equal(navigationSearch(s(), 'nota fiscal')[0].id, 'nfse')
  assert.equal(navigationSearch(s(), 'gestoes financeiras')[0].id, 'spaces')
  assert.equal(navigationSearch(s(), 'fornecedor')[0].id, 'contacts')
  assert.equal(navigationSearch(s(), 'configuracoes')[0].id, 'modules')
  assert.equal(navigationSearch(s(), 'migracao', {manager:true,migrationEnabled:true})[0].id, 'data')
  assert.equal(navigationSearch(s(), 'formas de pagamento')[0].id, 'paymentMethods')
  assert.equal(navigationSearch(s(), 'campos personalizados')[0].id, 'fields')
  assert.equal(navigationSearch(s(), 'acessos e compartilhamento')[0].id, 'access')
  assert.equal(navigationSearch(s(), 'pessoas e grupos')[0].id, 'access')
  assert.equal(navigationSearch(s(false),'migracao',{manager:false,migrationEnabled:true}).length,0)
  assert.equal(defaultGroupOpen('management', 'organization'), false)
})

test('personal space uses household labels but preserves the same authorized routes', () => {
  assert.equal(pageNameForSpace('contacts', 'personal'), 'Pessoas e estabelecimentos')
  assert.equal(pageNameForSpace('contacts', 'organization'), 'Clientes e fornecedores')
  assert.equal(pageNameForSpace('companies', 'personal'), 'Empresas (opcional)')
  assert.equal(pageNameForSpace('companies', 'organization'), 'Empresas')
  assert.equal(pageNameForSpace('finance', 'personal'), 'A pagar e receber')
  assert.equal(navigationSearch(s(), 'pessoas e estabelecimentos', {spaceKind:'personal'})[0].id,'contacts')
  assert.equal(navigationSearch(s(), 'clientes', {spaceKind:'organization'})[0].id,'contacts')
  assert.equal(menuEntries(s()).some(item => item.id === 'companies'), true)
})

test('only currently supported field entity kinds may be deep-linked', () => {
  for (const kind of ['company', 'contact', 'item', 'journal']) {
    assert.equal(fieldEntityKind(kind), kind)
  }
  for (const invalid of ['member', 'group', '../../user', '<script>', ''])
    assert.equal(fieldEntityKind(invalid), 'company')
})
