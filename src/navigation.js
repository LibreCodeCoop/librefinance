// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// User-task navigation for a personal, collective or business finance space.
// These descriptors expose no server permissions: PHP remains authoritative.

export const PAGE_NAMES = Object.freeze({
  dashboard: 'Visão geral',
  transactions: 'Lançamentos contábeis',
  banking: 'Bancos e caixa',
  obligations: 'A pagar e receber', // historic bookmark
  reports: 'Relatórios',
  invoicing: 'A pagar e receber', // legacy deep-link alias
  sales: 'A pagar e receber', // historic commercial bookmark
  receivables: 'A pagar e receber',
  finance: 'A pagar e receber',
  documents: 'Documentos comerciais',
  spaces: 'Áreas financeiras',
  payables: 'A pagar e receber',
  purchases: 'A pagar e receber', // historic commercial bookmark
  taxes: 'Regras tributárias',
  nfse: 'NFS-e Brasil',
  contacts: 'Clientes e fornecedores',
  items: 'Produtos e serviços',
  categories: 'Categorias',
  accounts: 'Plano de contas',
  companies: 'Dados fiscais da empresa',
  ai: 'Assistente financeiro',
  settings: 'Acessos e compartilhamento', // legacy route aliases access control
  access: 'Acessos e compartilhamento',
  fields: 'Campos personalizados',
  paymentMethods: 'Formas de pagamento',
  modules: 'Recursos instalados',
  data: 'Dados e migração',
  import: 'Migração de dados',
})

// Icon names are mapped to local @mdi/js paths in the shell. No extension is
// allowed to supply remote JS, HTML, arbitrary URL or user-controlled markup.
export const MENU_STRUCTURE = Object.freeze([
  { id: 'operations', label: 'LIBREFINANCE', items: [
    { id: 'dashboard', icon: 'home', keywords: 'inicio visao geral resumo painel home' },
    { id: 'finance', icon: 'obligations', module: 'librefinance_obligations',
      keywords: 'a pagar receber cliente fornecedor cobranca venda fatura receita despesa vencimentos agenda entradas saidas' },
    { id: 'documents', icon: 'billing', module: 'librefinance_invoicing',
      keywords: 'documentos comerciais faturas vendas compras impostos itens rascunhos' },
    { id: 'banking', icon: 'banking', module: 'librefinance_banking',
      keywords: 'bancos caixa extrato conciliacao entradas saidas saldo transferencia movimento' },
    { id: 'reports', icon: 'reports', module: 'librefinance_reports',
      keywords: 'relatorios demonstrativos fluxo de caixa resultados dre balancete' },
    { id: 'spaces', icon: 'spaces',
      keywords: 'gestoes financeiras espacos areas financas criar renomear arquivar restaurar organizacao pessoal' },
    { id: 'management', label: 'Gerenciar', icon: 'settings', children: [
      { id: 'contacts', icon: 'contacts', group: 'Cadastros',
        keywords: 'clientes fornecedores pessoas contatos' },
      { id: 'items', icon: 'contacts', group: 'Cadastros',
        keywords: 'produtos servicos itens preco catalogo' },
      { id: 'categories', icon: 'categories', group: 'Cadastros',
        keywords: 'categorias classificacao receitas despesas' },

      { id: 'companies', icon: 'companies', group: 'Financeiro e fiscal',
        keywords: 'empresa cnpj dados fiscais empresa emissora identidade legal cadastro juridico' },
      { id: 'accounts', icon: 'accounts', group: 'Financeiro e fiscal',
        keywords: 'plano de contas contabilidade patrimonio ativo passivo' },
      { id: 'transactions', icon: 'transactions', group: 'Financeiro e fiscal',
        keywords: 'lancamentos contabeis diario debito credito movimentacao' },
      { id: 'paymentMethods', icon: 'banking', group: 'Financeiro e fiscal', managerOnly: true,
        keywords: 'formas pagamento dinheiro pix boleto cartao metodos' },
      { id: 'taxes', icon: 'fiscal', group: 'Financeiro e fiscal', module: 'librefinance_invoicing',
        keywords: 'impostos jurisdicao tributacao aliquotas retencao' },
      { id: 'nfse', icon: 'fiscal', group: 'Financeiro e fiscal', module: 'librefinance_nfse',
        keywords: 'nota fiscal servico rascunho nacional' },

      { id: 'access', icon: 'contacts', group: 'Equipe e personalização', managerOnly: true,
        keywords: 'acessos equipe permissoes membros usuarios grupos compartilhar pessoas e grupos' },
      { id: 'fields', icon: 'categories', group: 'Equipe e personalização', managerOnly: true,
        keywords: 'campos personalizados customizados formularios entidades' },

      { id: 'modules', icon: 'settings', group: 'Recursos e dados', managerOnly: true,
        keywords: 'recursos instalados modulos ativar desativar extensoes configuracoes' },
      { id: 'data', icon: 'import', group: 'Recursos e dados', managerOnly: true,
        keywords: 'dados migracao importar exportar origem arquivos conversao akaunting' },
      { id: 'ai', icon: 'assistant', group: 'Recursos e dados', managerOnly: true,
        keywords: 'assistente inteligencia artificial resumo analise' },
    ] },
  ] },
])

/** Context changes presentation labels, never server rights or stored kinds. */
export function pageNameForSpace(id, kind) {
  if (kind === 'personal') {
    if (id === 'contacts') return 'Pessoas e estabelecimentos'
    if (id === 'companies') return 'Dados fiscais (opcional)'
  }
  return PAGE_NAMES[id] || id
}

/** Only the four entity types already supported by the PHP Field Studio. */
export function fieldEntityKind(value) {
  return ['company', 'contact', 'item', 'journal'].includes(value) ? value : 'company'
}

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
const LEGACY_SETTINGS = Object.freeze({
  access: 'access', fields: 'fields', payments: 'paymentMethods',
  modules: 'modules', data: 'data',
})
export function canonicalView(id, section = null) {
  if (id === 'settings') return LEGACY_SETTINGS[section] || 'access'
  if (id === 'import') return 'data'
  if (['invoicing', 'sales', 'purchases'].includes(id)) return 'documents'
  if (['obligations', 'receivables', 'payables'].includes(id)) return 'finance'
  return id
}

/** Existing links preserve their intended entry/exit direction as a filter. */
export function financeDirectionForView(id) {
  if (['invoicing', 'sales', 'receivables'].includes(id)) return 'receivable'
  if (['purchases', 'payables'].includes(id)) return 'payable'
  return 'all'
}

export function canOpenView(sections, id, { manager = false, migrationEnabled = false } = {}) {
  if (id === 'import') return manager && migrationEnabled
  return menuEntries(sections).some(item => item.id === canonicalView(id))
}

export function navigationSearch(sections, query,
  { manager = false, migrationEnabled = false, spaceKind = null } = {}) {
  const search = normalizeNavigationText(query).slice(0, 90)
  if (!search) return []
  // A direct screen-name match is more useful than a keyword hit on a
  // different screen (e.g. "clientes" must prioritize customer records).
  const rank = item => {
    const title = normalizeNavigationText(pageNameForSpace(item.id, spaceKind))
    const parent = normalizeNavigationText(item.parent)
    const keywords = normalizeNavigationText(item.keywords)
    if (title === search) return 0
    if (title.startsWith(search)) return 1
    if (title.includes(search)) return 2
    if (parent === search || parent.includes(search)) return 3
    if (keywords.includes(search)) return 4
    return -1
  }
  return menuEntries(sections).map((item, order) => ({
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
