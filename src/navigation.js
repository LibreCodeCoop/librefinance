// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// User-task navigation for a personal, collective or business finance space.
// These descriptors expose no server permissions: PHP remains authoritative.

export const PAGE_NAMES = Object.freeze({
  dashboard: 'Visão geral',
  transactions: 'Lançamentos',
  banking: 'Bancos e conciliação',
  obligations: 'A pagar e receber',
  reports: 'Relatórios',
  invoicing: 'Faturas e compras',
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
  { id: 'daily', label: 'FINANÇAS', items: [
    { id: 'dashboard', icon: 'home', keywords: 'inicio inicio painel home resumo' },
    { id: 'transactions', icon: 'transactions', keywords: 'movimentos movimentacoes despesas receitas dinheiro diario lancamento' },
    { id: 'banking', icon: 'banking', module: 'librefinance_banking', keywords: 'extrato conta bancaria conciliacao transferencias csv' },
    { id: 'obligations', icon: 'obligations', module: 'librefinance_obligations', keywords: 'titulos vencimentos pagamentos recebimentos contas cobrar pagar' },
    { id: 'reports', icon: 'reports', module: 'librefinance_reports', keywords: 'analise balancete resultado demonstrativo caixa' },
  ] },
  { id: 'resources', label: 'OUTROS RECURSOS', items: [
    { id: 'billing', label: 'Vendas e compras', icon: 'billing', children: [
      { id: 'invoicing', icon: 'billing', module: 'librefinance_invoicing', keywords: 'faturas vendas compras documentos comerciais' },
    ] },
    { id: 'fiscal', label: 'Tributação', icon: 'fiscal', children: [
      { id: 'taxes', icon: 'fiscal', module: 'librefinance_invoicing', keywords: 'impostos jurisdicao tributacao regras aliquotas vat retencao' },
      { id: 'nfse', icon: 'fiscal', module: 'librefinance_nfse', keywords: 'nota fiscal servico rascunho nacional' },
    ] },
    { id: 'registers', label: 'Cadastros', icon: 'contacts', children: [
      { id: 'contacts', icon: 'contacts', keywords: 'clientes fornecedores pessoas contatos' },
      { id: 'items', icon: 'contacts', keywords: 'produtos servicos itens preco catalogo' },
      { id: 'categories', icon: 'categories', keywords: 'classificacao receitas despesas' },
      { id: 'accounts', icon: 'accounts', keywords: 'plano contas contabilidade patrimonio ativo passivo' },
      { id: 'companies', icon: 'companies', keywords: 'empresa cnpj organizacao identificacao legal' },
    ] },
    { id: 'ai', icon: 'assistant', managerOnly: true, keywords: 'inteligencia artificial resumo analise' },
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

export function canOpenView(sections, id, { manager = false, migrationEnabled = false } = {}) {
  if (id === 'settings') return manager
  if (id === 'import') return manager && migrationEnabled
  return menuEntries(sections).some(item => item.id === id)
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
  return [...menuEntries(sections), ...extras].filter(item => normalizeNavigationText(
    [PAGE_NAMES[item.id], item.parent, item.keywords].filter(Boolean).join(' ')).includes(search))
}

export function parentOfView(sections, id) {
  return menuEntries(sections).find(item => item.id === id)?.parentId ?? null
}

// Enterprise users see billing immediately; personal users see a calmer menu.
// No role or data is changed, and either user can expand the same authorized group.
export function defaultGroupOpen(groupId, spaceKind = 'personal') {
  return groupId === 'billing' && spaceKind === 'organization'
}
