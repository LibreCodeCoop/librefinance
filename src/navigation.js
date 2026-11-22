// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// User-task navigation for a personal, collective or business finance space.
// These descriptors expose no server permissions: PHP remains authoritative.

export const PAGE_NAMES = Object.freeze({
  dashboard: 'Visão geral',
  transactions: 'Lançamentos contábeis',
  banking: 'Bancos e caixa',
  obligations: 'A receber', // historic combined-list bookmark
  reports: 'Relatórios',
  invoicing: 'Faturas de venda', // legacy deep-link alias
  sales: 'A receber', // historic commercial bookmark
  receivables: 'A receber',
  payables: 'A pagar',
  purchases: 'A pagar', // historic commercial bookmark
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
  { id: 'operations', label: 'LIBREFINANCE', items: [
    { id: 'dashboard', icon: 'home', keywords: 'inicio visao geral resumo painel home' },
    { id: 'receivables', icon: 'receivables', module: 'librefinance_obligations',
      keywords: 'receber cliente cobranca venda fatura receita pagamento entrada pendente' },
    { id: 'payables', icon: 'payables', module: 'librefinance_obligations',
      keywords: 'pagar fornecedor conta despesa compra fatura pagamento saida vencimento' },
    { id: 'banking', icon: 'banking', module: 'librefinance_banking',
      keywords: 'bancos caixa extrato saldo transferencia conciliacao movimento' },
    { id: 'reports', icon: 'reports', module: 'librefinance_reports',
      keywords: 'relatorios demonstrativos fluxo de caixa resultados dre balancete' },
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
    { id: 'advanced', label: 'Mais ferramentas', icon: 'settings', children: [
      { id: 'transactions', icon: 'transactions',
        keywords: 'lancamentos contabeis diario debito credito movimentacao' },
      { id: 'accounts', icon: 'accounts',
        keywords: 'plano de contas contabilidade patrimonio ativo passivo' },
      { id: 'taxes', icon: 'fiscal', module: 'librefinance_invoicing',
        keywords: 'impostos jurisdicao tributacao aliquotas retencao' },
      { id: 'nfse', icon: 'fiscal', module: 'librefinance_nfse',
        keywords: 'nota fiscal servico rascunho nacional' },
      { id: 'ai', icon: 'assistant', managerOnly: true,
        keywords: 'assistente inteligencia artificial resumo analise' },
    ] },
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
  // Preserve previously shared bookmarks. Legacy commercial URLs open the
  // same financial operation in its document-detail mode.
  if (id === 'invoicing' || id === 'sales' || id === 'obligations') return 'receivables'
  if (id === 'purchases') return 'payables'
  return id
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
