// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { register, unregister, setLanguage } from '@nextcloud/l10n'

const catalog = JSON.parse(readFileSync(new URL('../../l10n/pt_BR.json', import.meta.url)))
setLanguage('pt-BR')
register('librefinance', catalog.translations)
const {
  PAGE_NAMES, MENU_STRUCTURE, availableSections, menuEntries, canOpenView, defaultGroupOpen,
  canonicalView, financeDirectionForView, navigationSearch, parentOfView,
  normalizeNavigationText, pageNameForSpace, fieldEntityKind,
} = await import('../../src/navigation.js')

const enabled=['librefinance_obligations','librefinance_invoicing','librefinance_banking',
  'librefinance_reports','librefinance_import','librefinance_nfse']
  .map(id=>({id,enabled:true}))
const getSections=(modules=enabled,manager=true,loaded=true)=>
  availableSections({modules,manager,modulesLoaded:loaded})
const nav=()=>menuEntries(getSections())

test('two visible finance destinations: movements and optional invoices, no redundant dashboards',()=>{
  const tree=getSections()
  const items=tree[0].items
  assert.deepEqual(items.map(item=>item.id),['dashboard','financeMenu','reports','management'])
  const finance=items.find(item=>item.id==='financeMenu')
  assert.deepEqual(finance.children.filter(child=>!child.hidden).map(child=>child.id),
    ['finance','documents'])
  assert.equal(finance.children.find(item=>item.id==='banking')?.hidden,true)
  assert.equal(defaultGroupOpen('financeMenu'),true)
  assert.equal(PAGE_NAMES.finance,'Movimentações')
  assert.equal(PAGE_NAMES.documents,'Faturas')
  assert.equal(nav().filter(item=>item.id==='dashboard').length,1)
  assert.equal(nav().filter(item=>item.id==='finance').length,1)
  assert.equal(nav().filter(item=>item.id==='documents').length,1)
  assert.equal(parentOfView(tree,'bankAccounts'),'management')
  assert.equal(parentOfView(tree,'transactions'),'management')
  assert.equal(PAGE_NAMES.bankAccounts,'Contas e bancos')
})

test('historic sales purchases obligations and payables still open the right unified page',()=>{
  for(const id of ['sales','purchases','invoicing','documents'])
    assert.equal(canonicalView(id),'documents')
  for(const id of ['payables','receivables','obligations','finance'])
    assert.equal(canonicalView(id),'finance')
  assert.equal(canonicalView('banking'),'banking')
  assert.equal(canonicalView('bankAccounts'),'bankAccounts')
  assert.equal(canonicalView('settings','payments'),'paymentMethods')
  assert.equal(financeDirectionForView('purchases'),'payable')
  assert.equal(financeDirectionForView('sales'),'receivable')
  assert.equal(financeDirectionForView('documents'),'all')
  for(const id of ['sales','purchases','finance','banking','bankAccounts',
    'payables','receivables','documents'])
    assert.equal(canOpenView(getSections(),id),true,id)
})

test('financial movement list remains usable if optional modules are disabled',()=>{
  const none=getSections([])
  assert.equal(canOpenView(none,'finance'),true)
  assert.equal(canOpenView(none,'documents'),false)
  assert.equal(canOpenView(none,'banking'),false)
  assert.equal(canOpenView(none,'bankAccounts'),true)
  assert.equal(canOpenView(none,'transactions'),true)
  assert.deepEqual(none[0].items.find(item=>item.id==='financeMenu').children.map(child=>child.id),
    ['finance'])
  const onlyInvoices=getSections([{id:'librefinance_invoicing',enabled:true}])
  assert.equal(canOpenView(onlyInvoices,'documents'),true)
  assert.equal(canOpenView(onlyInvoices,'finance'),true)
  assert.equal(canOpenView(onlyInvoices,'banking'),false)
  const noBank=getSections(enabled.filter(m=>m.id!=='librefinance_banking'))
  assert.equal(canOpenView(noBank,'banking'),false)
  assert.equal(canOpenView(noBank,'bankAccounts'),true)
  const unloaded=getSections(enabled,false,false)
  assert.equal(canOpenView(unloaded,'finance'),true)
  assert.equal(canOpenView(unloaded,'documents'),false)
})

test('search uses everyday words and omits hidden technical bank route',()=>{
  assert.equal(normalizeNavigationText(' Conciliação  '),'conciliacao')
  assert.equal(navigationSearch(getSections(),'movimentações')[0]?.id,'finance')
  assert.equal(navigationSearch(getSections(),'faturas')[0]?.id,'documents')
  assert.equal(navigationSearch(getSections(),'compras')[0]?.id,'documents')
  assert.equal(navigationSearch(getSections(),'contas e bancos')[0]?.id,'bankAccounts')
  assert.equal(navigationSearch(getSections(),'extrato').some(item=>item.id==='banking'),false)
  assert.equal(navigationSearch(getSections(),'clientes')[0]?.id,'contacts')
  assert.equal(navigationSearch(getSections(),'formas de pagamento')[0]?.id,'paymentMethods')
})

test('role checks and technical screens are retained under management',()=>{
  const viewer=getSections(enabled,false)
  assert.equal(canOpenView(viewer,'fields'),false)
  assert.equal(canOpenView(viewer,'paymentMethods'),false)
  assert.equal(canOpenView(viewer,'accounts'),true)
  assert.equal(canOpenView(viewer,'finance'),true)
  assert.equal(canOpenView(viewer,'banking'),true)
  assert.equal(canOpenView(viewer,'import',{manager:false,migrationEnabled:true}),false)
  assert.equal(canOpenView(getSections(),'import',{manager:true,migrationEnabled:true}),true)
  assert.equal(parentOfView(getSections(),'transactions'),'management')
})

test('personal finance retains simple labels and server field boundaries',()=>{
  assert.equal(pageNameForSpace('contacts','personal'),'Pessoas e estabelecimentos')
  assert.equal(pageNameForSpace('contacts','organization'),'Clientes e fornecedores')
  assert.equal(pageNameForSpace('finance','personal'),'Movimentações')
  assert.equal(pageNameForSpace('documents','personal'),'Faturas')
  for(const kind of ['company','contact','item','journal','invoice','bill'])
    assert.equal(fieldEntityKind(kind),kind)
  for(const unsafe of ['member','../../etc','<script>',''])
    assert.equal(fieldEntityKind(unsafe),'company')
})

test('organization profiles are cadastros, while finance spaces manage independent ledgers',()=>{
  const manage=MENU_STRUCTURE[0].items.find(item=>item.id==='management')
  const spaces=manage.children.find(item=>item.id==='spaces')
  const companies=manage.children.find(item=>item.id==='companies')
  const banks=manage.children.find(item=>item.id==='bankAccounts')
  assert.equal(spaces.group,'workspaces')
  assert.equal(spaces.groupLabel,'Gestões financeiras')
  assert.equal(companies.group,'records')
  assert.equal(companies.groupLabel,'Cadastros')
  assert.equal(banks.group,'records')
  assert.notEqual(spaces.group,companies.group)
})

test('English fallback and Portuguese translations preserve route and group keys', async () => {
  unregister('librefinance')
  setLanguage('en')
  try {
    const en = await import('../../src/navigation.js?locale=en')
    const sections = en.availableSections({ modules: enabled, manager: true, modulesLoaded: true })
    assert.equal(en.PAGE_NAMES.finance, 'Financial movements')
    assert.equal(en.pageNameForSpace('contacts', 'personal'), 'People and businesses')
    assert.equal(en.navigationSearch(sections, 'accounts and banks')[0]?.id, 'bankAccounts')
    assert.equal(en.navigationSearch(sections, 'invoices')[0]?.id, 'documents')
    assert.equal(en.canonicalView('receivables'), 'finance')
    assert.equal(en.menuEntries(sections).find(item => item.id === 'bankAccounts').group, 'records')
  } finally {
    setLanguage('pt-BR')
    register('librefinance', catalog.translations)
  }
})
