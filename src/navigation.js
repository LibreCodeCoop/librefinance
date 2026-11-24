// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// User-task navigation for a personal, collective or business finance space.
// These descriptors expose no server permissions: PHP remains authoritative.

import { t } from '@nextcloud/l10n'

export const PAGE_NAMES = Object.freeze({
  dashboard: t('librefinance', 'Overview'),
  transactions: t('librefinance', 'Accounting history'),
  banking: t('librefinance', 'Account statement'),
  bankAccounts: t('librefinance', 'Accounts and banks'),
  obligations: t('librefinance', 'Financial movements'), // historic bookmark
  reports: t('librefinance', 'Reports'),
  invoicing: t('librefinance', 'Invoices'), // legacy deep-link alias
  sales: t('librefinance', 'Invoices'),
  receivables: t('librefinance', 'Financial movements'),
  finance: t('librefinance', 'Financial movements'),
  documents: t('librefinance', 'Invoices'),
  spaces: t('librefinance', 'Financial workspaces'),
  payables: t('librefinance', 'Financial movements'),
  purchases: t('librefinance', 'Invoices'),
  taxes: t('librefinance', 'Tax rules'),
  exchangeRates: t('librefinance', 'Exchange rates'),
  nfse: t('librefinance', 'Brazilian NFS-e'),
  contacts: t('librefinance', 'Customers and suppliers'),
  items: t('librefinance', 'Products and services'),
  categories: t('librefinance', 'Categories'),
  accounts: t('librefinance', 'Chart of accounts'),
  companies: t('librefinance', 'Organization details'),
  ai: t('librefinance', 'Financial assistant'),
  settings: t('librefinance', 'Access and sharing'), // legacy route aliases access control
  access: t('librefinance', 'Access and sharing'),
  fields: t('librefinance', 'Custom fields'),
  paymentMethods: t('librefinance', 'Payment methods'),
  modules: t('librefinance', 'Installed features'),
  data: t('librefinance', 'Data and migration'),
  import: t('librefinance', 'Data migration'),
})

// Icon names are mapped to local @mdi/js paths in the shell. No extension is
// allowed to supply remote JS, HTML, arbitrary URL or user-controlled markup.
export const MENU_STRUCTURE = Object.freeze([
  { id: 'operations', label: 'LIBREFINANCE', items: [
    { id: 'dashboard', icon: 'home', keywords: t('librefinance', 'home overview summary dashboard') },
    // Financial activity always works, even with optional extensions disabled.
    // Statement review is a contextual route reached from an account, not a
    // competing menu area or another accounting screen.
    { id: 'financeMenu', label: t('librefinance', 'Finance'), icon: 'obligations', children: [
      { id: 'finance', icon: 'transactions',
        keywords: t('librefinance', 'movements payables receivables inflows outflows payments collections balance statement') },
      { id: 'documents', icon: 'billing', module: 'librefinance_invoicing',
        keywords: t('librefinance', 'invoices sales purchases customers suppliers products services items taxes') },
      { id: 'banking', icon: 'banking', hidden: true, module: 'librefinance_banking',
        keywords: t('librefinance', 'statements import review reconciliation by account') },
    ] },
    { id: 'reports', icon: 'reports', module: 'librefinance_reports',
      keywords: t('librefinance', 'reports statements cash flow results profit and loss trial balance') },
    { id: 'management', label: t('librefinance', 'Manage'), icon: 'settings', children: [
      { id: 'spaces', icon: 'spaces', group: 'workspaces', groupLabel: t('librefinance', 'Financial workspaces'),
        keywords: t('librefinance', 'workspaces finance areas create rename archive restore organization personal') },
      { id: 'companies', icon: 'companies', group: 'records', groupLabel: t('librefinance', 'Records'),
        keywords: t('librefinance', 'company tax ID fiscal details issuer legal identity registration logo color') },
      { id: 'contacts', icon: 'contacts', group: 'records', groupLabel: t('librefinance', 'Records'),
        keywords: t('librefinance', 'customers suppliers people contacts') },
      { id: 'items', icon: 'contacts', group: 'records', groupLabel: t('librefinance', 'Records'),
        keywords: t('librefinance', 'products services items price catalog') },
      { id: 'categories', icon: 'categories', group: 'records', groupLabel: t('librefinance', 'Records'),
        keywords: t('librefinance', 'categories classification income expenses') },

      { id: 'bankAccounts', icon: 'banking', group: 'records', groupLabel: t('librefinance', 'Records'),
        keywords: t('librefinance', 'accounts banks cash register bank details finance') },
      { id: 'accounts', icon: 'accounts', group: 'financeTax', groupLabel: t('librefinance', 'Finance and tax'),
        keywords: t('librefinance', 'chart of accounts accounting equity assets liabilities') },
      { id: 'transactions', icon: 'transactions', group: 'financeTax', groupLabel: t('librefinance', 'Finance and tax'),
        keywords: t('librefinance', 'accounting journal debit credit actual inflows outflows history') },
      { id: 'paymentMethods', icon: 'banking', group: 'financeTax', groupLabel: t('librefinance', 'Finance and tax'), managerOnly: true,
        keywords: t('librefinance', 'payment methods cash Pix bank slip card') },
      { id: 'exchangeRates', icon: 'transactions', group: 'financeTax', groupLabel: t('librefinance', 'Finance and tax'),
        keywords: t('librefinance', 'exchange rates currency foreign exchange history') },
      { id: 'taxes', icon: 'fiscal', group: 'financeTax', groupLabel: t('librefinance', 'Finance and tax'), module: 'librefinance_invoicing',
        keywords: t('librefinance', 'taxes jurisdiction rates withholding') },
      { id: 'nfse', icon: 'fiscal', group: 'financeTax', groupLabel: t('librefinance', 'Finance and tax'), module: 'librefinance_nfse',
        keywords: t('librefinance', 'service invoice draft national') },

      { id: 'access', icon: 'contacts', group: 'team', groupLabel: t('librefinance', 'Team and customization'), managerOnly: true,
        keywords: t('librefinance', 'access team permissions members users groups sharing') },
      { id: 'fields', icon: 'categories', group: 'team', groupLabel: t('librefinance', 'Team and customization'), managerOnly: true,
        keywords: t('librefinance', 'custom fields forms entities') },

      { id: 'modules', icon: 'settings', group: 'features', groupLabel: t('librefinance', 'Features and data'), managerOnly: true,
        keywords: t('librefinance', 'installed features modules enable disable extensions settings') },
      { id: 'data', icon: 'import', group: 'features', groupLabel: t('librefinance', 'Features and data'), managerOnly: true,
        keywords: t('librefinance', 'data migration import export source files conversion') },
      { id: 'ai', icon: 'assistant', group: 'features', groupLabel: t('librefinance', 'Features and data'), managerOnly: true,
        keywords: t('librefinance', 'assistant artificial intelligence summary analysis') },
    ] },
  ] },
])

/** Context changes presentation labels, never server rights or stored kinds. */
export function pageNameForSpace(id, kind) {
  if (kind === 'personal') {
    if (id === 'contacts') return t('librefinance', 'People and businesses')
    if (id === 'companies') return t('librefinance', 'Organizations (optional)')
  }
  return PAGE_NAMES[id] || id
}

/** Recognized destinations for the six safely scoped Field Studio entities. */
export function fieldEntityKind(value) {
  return ['company', 'contact', 'item', 'journal', 'invoice', 'bill'].includes(value)
    ? value : 'company'
}

export function normalizeNavigationText(value) {
  return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().trim().replace(/\s+/g, ' ')
}

/** Feature flags hide unavailable screens, but the always-available journal
 * and finance overview do not depend on optional modules. */
export function availableSections({ manager = false, modules = [], modulesLoaded = false } = {}) {
  const enabled = new Set(modulesLoaded ? modules.filter(m => m.enabled).map(m => m.id) : [])
  const allowed = item => (!item.managerOnly || manager)
    && (!item.module || enabled.has(item.module))
  return MENU_STRUCTURE.map(section => ({
    ...section,
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
  if (['obligations','payables','receivables'].includes(id)) return 'finance'
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
  const canonical = canonicalView(id)
  // The URL is only a closed screen key; permissions and optional module
  // availability must be checked against the authorized navigation.
  return menuEntries(sections).some(item => item.id === canonical)
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
  return menuEntries(sections).filter(item => !item.hidden).map((item, order) => ({
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
export function defaultGroupOpen(id) {
  return id === 'financeMenu'
}
