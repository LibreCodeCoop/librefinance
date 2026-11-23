// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  availableSections, canOpenView, defaultGroupOpen, menuEntries,
  navigationSearch, normalizeNavigationText, parentOfView, PAGE_NAMES,
  canonicalView, financeDirectionForView, pageNameForSpace, fieldEntityKind,
} from '../../src/navigation.js'

const enabled=['librefinance_banking','librefinance_obligations',
  'librefinance_reports','librefinance_invoicing','librefinance_nfse',
  'librefinance_import'].map(id=>({id,enabled:true}))
const sections=(manager=true,modules=enabled,modulesLoaded=true)=>
  availableSections({manager,modules,modulesLoaded})
const financeChildren=scope=>sections(false,scope).find(section=>section.id==='operations')
  .items.find(item=>item.id==='financeMenu').children.map(item=>item.id)

test('Financeiro has one payable/receivable list and one bank register without duplicate dashboard',()=>{
  const tree=sections()
  assert.deepEqual(tree.map(section=>section.label),['LIBREFINANCE'])
  assert.deepEqual(tree[0].items.map(item=>item.id),
    ['dashboard','financeMenu','reports','management'])
  assert.deepEqual(financeChildren(enabled),
    ['finance','sales','purchases','bankAccounts','banking'])
  for(const id of financeChildren(enabled))assert.equal(parentOfView(tree,id),'financeMenu')
  assert.equal(menuEntries(tree).filter(item=>item.id==='finance').length,1)
  assert.equal(menuEntries(tree).filter(item=>item.id==='bankAccounts').length,1)
  assert.equal(menuEntries(tree).filter(item=>item.id==='transactions').length,1)
  assert.equal(parentOfView(tree,'transactions'),'management')
  assert.equal(parentOfView(tree,'accounts'),'management')
  assert.equal(defaultGroupOpen('financeMenu'),true)
  assert.equal(defaultGroupOpen('management'),false)
  assert.equal(PAGE_NAMES.finance,'A pagar e receber')
  assert.equal(PAGE_NAMES.sales,'Vendas')
  assert.equal(PAGE_NAMES.purchases,'Compras')
  assert.equal(PAGE_NAMES.bankAccounts,'Contas bancárias e caixa')
  assert.equal(PAGE_NAMES.banking,'Extratos e conciliação')
  const management=tree[0].items.find(item=>item.id==='management').children
  assert.deepEqual([...new Set(management.map(item=>item.group))],
    ['Gestões e identidade','Cadastros','Financeiro e fiscal','Equipe e personalização','Recursos e dados'])
})

test('legacy finance route aliases lead to safe canonical task destinations',()=>{
  for(const id of ['obligations','payables','receivables'])
    assert.equal(canonicalView(id),'finance')
  for(const id of ['invoicing','documents'])assert.equal(canonicalView(id),'sales')
  assert.equal(canonicalView('sales'),'sales')
  assert.equal(canonicalView('purchases'),'purchases')
  assert.equal(canonicalView('bankAccounts'),'bankAccounts')
  assert.equal(canonicalView('transactions'),'transactions')
  assert.equal(canonicalView('settings','payments'),'paymentMethods')
  assert.equal(canonicalView('settings','data'),'data')
  for(const id of ['finance','sales','purchases','bankAccounts','banking',
    'transactions','obligations','invoicing','documents','payables','receivables'])
    assert.equal(canOpenView(sections(),id),true,id)
  assert.equal(financeDirectionForView('obligations'),'all')
  assert.equal(financeDirectionForView('finance'),'all')
  assert.equal(financeDirectionForView('payables'),'payable')
  assert.equal(financeDirectionForView('receivables'),'receivable')
  assert.equal(financeDirectionForView('purchases'),'payable')
  assert.equal(financeDirectionForView('sales'),'receivable')
})

test('module and role gates preserve bank registration and hide disabled reconciliation',()=>{
  const withoutBank=sections(false,enabled.filter(item=>item.id!=='librefinance_banking'))
  const names=menuEntries(withoutBank).map(item=>item.id)
  assert.ok(names.includes('bankAccounts'))
  assert.ok(names.includes('finance'))
  assert.ok(names.includes('sales'))
  assert.ok(!names.includes('banking'))
  assert.equal(canOpenView(withoutBank,'banking'),false)
  assert.equal(canOpenView(withoutBank,'bankAccounts'),true)
  assert.equal(canOpenView(withoutBank,'fields',{manager:false}),false)
  assert.equal(canOpenView(withoutBank,'import',{manager:false,migrationEnabled:true}),false)
  const noFeatures=sections(false,[])
  assert.deepEqual(financeChildren([]),['bankAccounts'])
  assert.equal(canOpenView(noFeatures,'finance'),false)
  assert.equal(canOpenView(noFeatures,'sales'),false)
  assert.equal(canOpenView(noFeatures,'bankAccounts'),true)
  assert.equal(canOpenView(noFeatures,'banking'),false)
  assert.equal(canOpenView(noFeatures,'transactions'),true)
  const unloaded=sections(false,enabled,false)
  assert.deepEqual(menuEntries(unloaded).filter(item=>item.parentId==='financeMenu')
    .map(item=>item.id),['bankAccounts'])
})

test('search uses the users everyday words before financial internals',()=>{
  assert.equal(normalizeNavigationText('  Conciliação  '),'conciliacao')
  assert.equal(navigationSearch(sections(),'clientes')[0].id,'contacts')
  assert.equal(navigationSearch(sections(),'a pagar e receber')[0].id,'finance')
  assert.equal(navigationSearch(sections(),'vendas')[0].id,'sales')
  assert.equal(navigationSearch(sections(),'compras')[0].id,'purchases')
  assert.equal(navigationSearch(sections(),'contas bancarias')[0].id,'bankAccounts')
  assert.equal(navigationSearch(sections(),'extrato')[0].id,'banking')
  assert.equal(navigationSearch(sections(),'cobranca')[0].id,'finance')
  assert.equal(navigationSearch(sections(),'lancamentos contabeis')[0].id,'transactions')
  assert.equal(navigationSearch(sections(),'nota fiscal')[0].id,'nfse')
  assert.equal(navigationSearch(sections(),'formas de pagamento')[0].id,'paymentMethods')
  assert.equal(navigationSearch(sections(),'migracao',
    {manager:true,migrationEnabled:true})[0].id,'data')
})

test('personal and organization spaces have the same financial routes',()=>{
  assert.equal(pageNameForSpace('contacts','personal'),'Pessoas e estabelecimentos')
  assert.equal(pageNameForSpace('contacts','organization'),'Clientes e fornecedores')
  assert.equal(pageNameForSpace('companies','personal'),'Organizações (opcional)')
  assert.equal(pageNameForSpace('finance','personal'),'A pagar e receber')
  assert.equal(pageNameForSpace('bankAccounts','personal'),'Contas bancárias e caixa')
  assert.equal(navigationSearch(sections(),'pessoas e estabelecimentos',
    {spaceKind:'personal'})[0].id,'contacts')
})

test('custom field kinds remain compatible with server-supported entities',()=>{
  for(const kind of ['company','contact','item','journal'])
    assert.equal(fieldEntityKind(kind),kind)
  for(const unsafe of ['member','group','../../user','<script>',''])
    assert.equal(fieldEntityKind(unsafe),'company')
})

test('bank providers and disabling modules cannot expose shadow finance screens',()=>{
  const onlyObligations=sections(false,[{id:'librefinance_obligations',enabled:true}])
  assert.deepEqual(financeChildren([{id:'librefinance_obligations',enabled:true}]),
    ['finance','bankAccounts'])
  assert.equal(canOpenView(onlyObligations,'documents'),false)
  assert.equal(canOpenView(onlyObligations,'invoicing'),false)
  assert.equal(canOpenView(onlyObligations,'purchases'),false)
  assert.equal(canOpenView(onlyObligations,'bankAccounts'),true)
  assert.equal(canOpenView(onlyObligations,'banking'),false)
  assert.equal(navigationSearch(onlyObligations,'extratos').some(i=>i.id==='banking'),false)
})
