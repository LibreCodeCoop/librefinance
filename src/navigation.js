// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// User-task navigation for a personal, collective or business finance space.
// These descriptors expose no server permissions: PHP remains authoritative.

export const PAGE_NAMES = Object.freeze({
  dashboard: 'Visão geral',
  transactions: 'Lançamentos contábeis',
  banking: 'Bancos e conciliação',
  obligations: 'Contas a pagar e receber',
  reports: 'Relatórios',
  invoicing: 'Faturas de venda', // legacy deep-link alias
  sales: 'Faturas de venda',
  purchases: 'Documentos de compra',
  taxes: 'Regras tributárias',
  nfse: 'NFS-e Brasil',
  contacts: 'Clientes e fornecedores',
  items: 'Produtos e serviços',
  categories: 'Categorias',
  accounts: 'Plano de contas',
  companies: 'Empresas',
  ai: 'Assistente financeiro',
  settings: 'Configurações',
  import: 'Migração de dados',
})

// Icon names are mapped to local @mdi/js paths in the shell. No extension is
// allowed to supply remote JS, HTML, arbitrary URL or user-controlled markup.
export const MENU_STRUCTURE = Object.freeze([
  { id: 'home', label: 'INÍCIO', items: [
    { id: 'dashboard', icon: 'home', keywords: 'inicio painel resumo home' },
  ] },
  { id: 'commercial', label: 'VENDAS E COMPRAS', items: [
    { id: 'sales', icon: 'billing', module: 'librefinance_invoicing',
      keywords: 'fatura de venda cobranças clientes vendas documentos receita' },
    { id: 'purchases', icon: 'billing', module: 'librefinance_invoicing',
      keywords: 'compra fornecedores faturas recebidas contas de fornecedor despesas documento' },
  ] },
  { id: 'financial', label: 'FINANCEIRO', items: [
    { id: 'obligations', icon: 'obligations', module: 'librefinance_obligations',
      keywords: 'a pagar a receber titulos vencimento compromissos pagamentos recebimentos contas' },
    { id: 'banking', icon: 'banking', module: 'librefinance_banking',
      keywords: 'banco extrato conciliacao transferencia movimentacao conta bancaria' },
  ] },
  { id: 'accounting', label: 'CONTABILIDADE', items: [
    { id: 'transactions', icon: 'transactions',
      keywords: 'lancamentos contabeis movimentos contabilizados receita paga despesa paga diario debito credito' },
    { id: 'accounts', icon: 'accounts',
      keywords: 'plano de contas contas contabilidade patrimonio ativo passivo' },
    { id: 'reports', icon: 'reports', module: 'librefinance_reports',
      keywords: 'analise relatorios balancete dre resultado demonstrativos caixa' },
  ] },
  { id: 'resources', label: 'OUTROS RECURSOS', items: [
    { id: 'fiscal', label: 'Tributação', icon: 'fiscal', children: [
      { id: 'taxes', icon: 'fiscal', module: 'librefinance_invoicing',
        keywords: 'impostos jurisdicao tributacao regras aliquotas vat retencao' },
      { id: 'nfse', icon: 'fiscal', module: 'librefinance_nfse',
        keywords: 'nota fiscal servico rascunho nacional' },
    ] },
    { id: 'registers', label: 'Cadastros', icon: 'contacts', children: [
      { id: 'contacts', icon: 'contacts',
        keywords: 'clientes fornecedores pessoas contatos' },
      { id: 'items', icon: 'contacts',
        keywords: 'produtos servicos itens preco catalogo' },
      { id: 'categories', icon: 'categories',
        keywords: 'categorias classificacao receitas despesas' },
      { id: 'companies', icon: 'companies',
        keywords: 'empresa cnpj organizacao identificacao legal' },
    ] },
    { id: 'ai', icon: 'assistant', managerOnly: true,
      keywords: 'inteligencia artificial resumo analise' },
  ] },
])

export function normalizeNavigationText(value) {
  return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase('pt-BR').trim().replace(/\s+/g, ' ')
}

/** Only enabled, user-visible module descriptors enter the navigation. */
export function availableSections({ manager = false, modules = [], modulesLoaded = false } = {}) {
  const enabled = new Set(modulesLoaded ? modules.filter(m => m.enabled).map(m => m.id) : [])
  function allowed(item) {
    return (!item.managerOnly || manager) && (!item.module || enabled.has(item.module))
  }
  return MENU_STRUCTURE.map(section => ({ ...section,
    items: section.items.map(item => item.children
      ? { ...item, children: item.children.filter(allowed) }
      : item).filter(item => item.children ? item.children.length > 0 : allowed(item)),
  })).filter(section => section.items.length > 0)
}

export function menuEntries(sections) {
  return sections.flatMap(section => section.items.flatMap(item => item.children
    ? item.children.map(child => ({ ...child, section: section.label, parent: item.label, parentId: item.id }))
    : [{ ...item, section: section.label, parent: '', parentId: null }]))
}

// Preserve existing ?view=invoicing bookmarks while giving each operation
// a distinct, user-facing route. Never use freeform view names as URLs.
export function canonicalView(id) {
  return id === 'invoicing' ? 'sales' : id
}

export function canOpenView(sections, id, { manager = false, migrationEnabled = false } = {}) {
  if (id === 'settings') return manager
  if (id === 'import') return manager && migrationEnabled
  return menuEntries(sections).some(item => item.id === canonicalView(id))
}

export function navigationSearch(sections, query, { manager = false, migrationEnabled = false } = {}) {
  const search = normalizeNavigationText(query).slice(0, 90)
  if (!search) return []
  const extras = []
  if (manager) {
    extras.push({ id: 'settings', icon: 'settings', section: 'ADMINISTRAÇÃO',
      parent: '', keywords: 'configuracao acesso membros usuarios grupos modulos', parentId: null })
    if (migrationEnabled) extras.push({ id: 'import', icon: 'import', section: 'ADMINISTRAÇÃO',
      parent: 'Dados e migração', keywords: 'migrar importar exportar origem conversao', parentId: null })
  }
  // A direct screen-name match is more useful than a keyword hit on a
  // different screen (e.g. "clientes" must prioritize customer records).
  const rank = item => {
    const title = normalizeNavigationText(PAGE_NAMES[item.id])
    const parent = normalizeNavigationText(item.parent)
    const keywords = normalizeNavigationText(item.keywords)
    if (title === search) return 0
    if (title.startsWith(search)) return 1
    if (title.includes(search)) return 2
    if (parent === search || parent.includes(search)) return 3
    if (keywords.includes(search)) return 4
    return -1
  }
  return [...menuEntries(sections), ...extras].map((item, order) => ({
    item, order, score: rank(item),
  })).filter(found => found.score >= 0)
    .sort((a, b) => a.score - b.score || a.order - b.order)
    .map(found => found.item)
}

export function parentOfView(sections, id) {
  return menuEntries(sections).find(item => item.id === id)?.parentId ?? null
}

// Task-oriented primary areas are always visible. Only supplementary
// fiscal/register groups are collapsed by default for all workspace kinds.
export function defaultGroupOpen() {
  return false
}
