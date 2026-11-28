// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Finance workspace state and authorized API actions. No view markup.
import { ref, shallowRef, computed, onMounted, onScopeDispose, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatContextInput, formatContextMoney, parseContextMoney } from '../money.js'
import { authorizedInitialWorkspace, contextKey } from '../financialContexts.js'
import { bindFinanceSelection, requestFinancialWorkspace } from '../services/api.js'

export function useFinanceWorkspace() {

const spaces = ref([])
const spacesStatus = ref('loading')
const activeId = ref(0)
const accounts = ref([])
const contacts = ref([])
const items = ref([])
const companies = ref([])
const companyNextAfterId = ref(null)
const companyEditId = ref(0)
const companyName = ref('')
const companyTradeName = ref('')
const companyTaxId = ref('')
const companyCountry = ref('')
const companyFields = ref([])
const companyCustomValues = ref({})
const companyMunicipal = ref('')
const companyState = ref('')
const blankCompanyProfile = () => ({
  contactEmail: '', contactPhone: '', website: '', addressLine1: '',
  addressLine2: '', city: '', region: '', postalCode: '', brandColor: '#64748B',
})
const companyProfile = ref(blankCompanyProfile())
const companyActive = ref(true)
const modules = ref([])
const modulesLoadedFor = ref(0)
const categoryRecords = ref([])
const categoryNextAfterId = ref(null)
const categoryCode = ref('')
const categoryName = ref('')
const categoryColor = ref('#64748B')
const categoryKind = ref('expense')
const categoryParentId = ref(0)
const categoryAccountId = ref(0)
const selectedModuleId = ref('')
const aiCapability = ref(null)
const aiPreview = ref(null)
const aiTask = ref(null)
const aiConsent = ref(false)
const aiBusy = ref(false)
const aiError = ref('')
const aiThrough = ref(new Date().toLocaleDateString('sv-SE'))
const aiFrom = ref(new Date().getFullYear() + '-01-01')
const contactNextAfterId = ref(null)
const itemNextAfterId = ref(null)
const activeTab = ref('dashboard')
const journals = ref([])
const members = ref([])
const membersGrantRevision = ref(null)
const canManageMembers = ref(false)
const groupGrants = ref([])
const nextBeforeId = ref(null)
const error = ref('')
const message = ref('')
const busy = ref(false)
const spaceName = ref('')
const kind = ref('personal')
const accountName = ref('')
const accountCode = ref('')
const contactName = ref('')
const contactKind = ref('customer')
const contactEmail = ref('')
const contactTaxId = ref('')
const itemSku = ref('')
const itemName = ref('')
const itemKind = ref('service')
const itemPrice = ref('')
const itemDescription = ref('')
const shareUid = ref('')
const shareRole = ref('viewer')
const groupGid = ref('')
const groupRole = ref('viewer')
const direction = ref('expense')
const bankId = ref(0)
const categoryId = ref(0)
const transactionCategoryId = ref(0)
const amount = ref('')
const memo = ref('')
const date = ref(new Date().toLocaleDateString('sv-SE'))
let loadSequence = 0
let spacesLoadSequence = 0
let actionSequence = 0
let preferredSpaceHandled = false
const space = computed(() => spaces.value.find((s) => Number(s.id) === Number(activeId.value)))
const selectedContext = computed(() => contextKey(space.value))
const requestSelection = shallowRef()
watch([activeId, selectedContext], ([id, key]) => {
  requestSelection.value = {
    spaceId: Number(id),
    // Listings without a public ID can only pass the server's legacy check.
    context: key.startsWith('environment:') ? key.slice('environment:'.length)
      : key === 'external' ? key : key ? 'legacy' : '',
  }
}, { immediate: true, flush: 'sync' })
onScopeDispose(bindFinanceSelection(() => requestSelection.value))
const selectedModule = computed(() => modules.value.find((module) => module.id === selectedModuleId.value && module.enabled))
const writable = computed(() => ['owner', 'manager', 'operator'].includes(space.value?.role))
const manager = computed(() => ['owner', 'manager'].includes(space.value?.role))
const banks = computed(() => accounts.value.filter((a) => a.is_bank && a.active))
const categories = computed(() => accounts.value.filter((a) => a.active && a.class === (direction.value === 'expense' ? 'expense' : 'revenue')))
const money = (minor, record) => formatContextMoney(minor, space.value, record ?? space.value)
const amountInput = (minor, record) => formatContextInput(minor, space.value, record ?? space.value)
const parseMoney = (value, ...records) => parseContextMoney(value, space.value, records)
const accountById = id => accounts.value.find(account => Number(account.id) === Number(id))
const naturalBalance = (account) => money((['liability', 'equity', 'revenue'].includes(account.class) ? -1 : 1) * account.balance_minor, account)
const accountNameById = (id) => accounts.value.find((a) => a.id === Number(id))?.name || ('#' + id)

function unwrapError(e) {
  const code = e.response?.data?.error
  if (code === 'revision_conflict') return t('librefinance', 'Permissions changed. Refresh the workspace before trying again.')
  if (code === 'grant_revision_required') return t('librefinance', 'Load the current permissions before changing access.')
  return code || e.message || t('librefinance', 'Unknown failure')
}
async function call(method, path, data) {
  const url = generateUrl('/apps/librefinance' + path)
  // Lifecycle actions on the management list use their own target and ACL.
  // Only workspace subresources are tied to the active financial selection.
  const scoped = /^\/api\/spaces\/(\d+)\/([^/?]+)/.exec(path)
  if (scoped && !['archive', 'restore'].includes(scoped[2])) {
    return requestFinancialWorkspace(scoped[1], url, method, data)
  }
  const response = await axios({ method, url, data, headers: { Accept: 'application/json' } })
  return response.data
}
async function refreshSpaces() {
  // Invitations and lifecycle actions may refresh the authorized listing
  // concurrently. Only the latest request may change the list or selection.
  const generation = ++spacesLoadSequence
  if (spacesStatus.value !== 'ready') spacesStatus.value = 'loading'
  let result
  try {
    result = await call('GET', '/api/spaces')
  } catch (e) {
    if (generation !== spacesLoadSequence) return
    if (spacesStatus.value !== 'ready') spacesStatus.value = 'failed'
    throw e
  }
  if (generation !== spacesLoadSequence) return
  spaces.value = result.spaces
  spacesStatus.value = 'ready'
  if (!preferredSpaceHandled) {
    preferredSpaceHandled = true
    const desired = Number(new URLSearchParams(window.location.search).get('spaceId'))
    if (Number.isSafeInteger(desired) && desired > 0
        && spaces.value.some((s) => Number(s.id) === desired)) {
      activeId.value = desired
      return
    }
  }
  if (!spaces.value.some((s) => Number(s.id) === Number(activeId.value))) {
    activeId.value = authorizedInitialWorkspace(spaces.value)
  }
}
async function refreshCurrent(options = {}) {
  const selection = requestSelection.value
  const current = selection.spaceId
  const generation = ++loadSequence
  // A successful import must refresh shared finance data without unmounting
  // the import page or discarding its confirmation and coverage report.
  if (!options.preserveView) modulesLoadedFor.value = 0
  if (!current) {
    accounts.value = []
    contacts.value = []
    items.value = []
    companies.value = []
    companyFields.value = []
    companyNextAfterId.value = null
    companyEditId.value = 0
    modules.value = []
    categoryRecords.value = []
    categoryNextAfterId.value = null
    selectedModuleId.value = ''
    contactNextAfterId.value = null
    itemNextAfterId.value = null
    journals.value = []
    members.value = []
    membersGrantRevision.value = null
    canManageMembers.value = false
    groupGrants.value = []
    return
  }
  // A transient failure in a secondary endpoint must not hide the chart of
  // accounts (or carry stale links from another selected finance space).
  const paths = [
    'accounts', 'journals', 'members', 'groups',
    'contacts', 'items', 'modules', 'categories', 'companies', 'company-fields',
  ]
  const requests = paths.map((path) => (
    (path === 'members' || path === 'groups') && !manager.value
      ? Promise.resolve({ [path]: [] })
      : call('GET', '/api/spaces/' + current + '/' + path)
  ))
  const results = await Promise.allSettled(requests)
  if (generation !== loadSequence || selection !== requestSelection.value) return
  const failures = []
  const data = {}
  results.forEach((result, index) => {
    const key = paths[index]
    if (result.status === 'fulfilled') {
      data[key] = result.value
    } else {
      failures.push(key)
    }
  })
  if (data.accounts) {
    accounts.value = data.accounts.accounts
    const firstBank = data.accounts.accounts.find((account) => account.is_bank)
    if (!data.accounts.accounts.some((account) =>
      account.id === Number(bankId.value) && account.is_bank)) {
      bankId.value = firstBank?.id || 0
    }
  }
  if (data.journals) {
    journals.value = data.journals.journals
    nextBeforeId.value = data.journals.nextBeforeId
  }
  if (data.members) {
    members.value = data.members.members
    membersGrantRevision.value = Number.isSafeInteger(data.members.grantRevision)
      ? data.members.grantRevision : null
    canManageMembers.value = data.members.canManageMembers === true
  } else {
    membersGrantRevision.value = null
    canManageMembers.value = false
  }
  if (data.groups) groupGrants.value = data.groups.groups
  if (data.contacts) {
    contacts.value = data.contacts.contacts
    contactNextAfterId.value = data.contacts.nextAfterId
  }
  if (data.items) {
    items.value = data.items.items
    itemNextAfterId.value = data.items.nextAfterId
  }
  if (data.categories) {
    categoryRecords.value = data.categories.categories
    categoryNextAfterId.value = data.categories.nextAfterId
  }
  if (data['company-fields']) companyFields.value = data['company-fields'].fields
  if (data.companies) {
    companies.value = data.companies.companies
    companyNextAfterId.value = data.companies.nextAfterId
  }
  if (data.modules) {
    modules.value = data.modules.modules
    modulesLoadedFor.value = current
    if (!data.modules.modules.some((module) =>
      module.id === selectedModuleId.value && module.enabled)) {
      selectedModuleId.value = ''
    }
  }
  error.value = failures.length
    ? 'Falha ao carregar: ' + failures.join(', ') + '. Atualize para tentar novamente.'
    : ''
}
watch(requestSelection, () => {
  // Changing the active workspace invalidates financial screens. Keep the
  // workspace-management screen open when archiving/restoring the selected
  // workspace, otherwise a late activeId reset hides the restore action.
  const managingSpaces = activeTab.value === 'spaces'
  // Invalidate old UI ownership immediately when this selection is reset.
  ++actionSequence
  busy.value = false
  resetAi()
  // An unfinished amount belongs to the previous financial context.
  amount.value = ''
  itemPrice.value = ''
  memo.value = ''
  bankId.value = 0
  categoryId.value = 0
  transactionCategoryId.value = 0
  modulesLoadedFor.value = 0
  modules.value = []
  accounts.value = []
  journals.value = []
  nextBeforeId.value = null
  contacts.value = []
  contactNextAfterId.value = null
  items.value = []
  itemNextAfterId.value = null
  companies.value = []
  companyNextAfterId.value = null
  categoryNextAfterId.value = null
  companyFields.value = []
  categoryRecords.value = []
  members.value = []
  membersGrantRevision.value = null
  canManageMembers.value = false
  groupGrants.value = []
  selectedModuleId.value = ''
  activeTab.value = managingSpaces ? 'spaces' : 'dashboard'
  companyEditId.value = 0
  error.value = ''
  message.value = ''
  refreshCurrent()
})
watch(direction, () => { categoryId.value = 0; transactionCategoryId.value = 0 })
watch(categoryKind, () => { categoryParentId.value = 0; categoryAccountId.value = 0 })
watch(transactionCategoryId, (id) => {
  const classification = categoryRecords.value.find(c => c.id === Number(id))
  if (classification?.effective_account_id) categoryId.value = classification.effective_account_id
})
async function doAction(action, success, refreshOnRevisionConflict = false) {
  const selection = requestSelection.value
  const token = ++actionSequence
  const isCurrent = () => selection === requestSelection.value && token === actionSequence
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    await action(isCurrent)
    if (isCurrent()) message.value = success
  } catch (e) {
    if (!isCurrent()) return
    if (refreshOnRevisionConflict && e.response?.data?.error === 'revision_conflict') {
      // The server rejected the stale write. Reload its authoritative
      // revision; do not retry or silently replace a concurrent change.
      try { await refreshCurrent() } catch { /* Retain the original conflict. */ }
    }
    if (isCurrent()) error.value = unwrapError(e)
  } finally {
    if (isCurrent()) busy.value = false
  }
}
function resetCompanyForm() {
  companyEditId.value = 0
  companyName.value = ''
  companyTradeName.value = ''
  companyTaxId.value = ''
  companyCountry.value = ''
  companyCustomValues.value = {}
  companyMunicipal.value = ''
  companyState.value = ''
  companyProfile.value = blankCompanyProfile()
  companyActive.value = true
}
function editCompany(company) {
  companyEditId.value = company.id
  companyName.value = company.legal_name
  companyTradeName.value = company.trade_name || ''
  companyTaxId.value = company.tax_id || ''
  companyCountry.value = company.country || ''
  companyCustomValues.value = { ...company.custom_fields }
  companyMunicipal.value = company.municipal_registration || ''
  companyState.value = company.state_registration || ''
  companyProfile.value = {
    contactEmail: company.contact_email || '',
    contactPhone: company.contact_phone || '',
    website: company.website || '',
    addressLine1: company.address_line1 || '',
    addressLine2: company.address_line2 || '',
    city: company.city || '',
    region: company.region || '',
    postalCode: company.postal_code || '',
    brandColor: company.brand_color || '#64748B',
  }
  companyActive.value = company.active
  activeTab.value = 'companies'
}
async function saveCompany() {
  await doAction(async () => {
    const current = Number(activeId.value)
    const path = '/api/spaces/' + current + '/companies'
    const payload = { legalName: companyName.value, country: companyCountry.value.trim().toUpperCase(),
      taxId: companyTaxId.value.trim() || null,
      tradeName: companyTradeName.value, municipalRegistration: companyMunicipal.value,
      stateRegistration: companyState.value, active: companyActive.value,
      customFields: { ...companyCustomValues.value },
      profile: { ...companyProfile.value } }
    if (companyEditId.value) await call('PUT', path + '/' + companyEditId.value, payload)
    else await call('POST', path, payload)
    resetCompanyForm()
    await refreshCurrent()
  }, t('librefinance', 'Legal registration saved.'))
}
async function loadMoreCompanies() {
  const selection = requestSelection.value
  const current = selection.spaceId
  const after = companyNextAfterId.value
  if (!current || !after) return
  const generation = loadSequence
  try {
    const data = await call('GET', '/api/spaces/' + current + '/companies?afterId=' + after)
    if (generation !== loadSequence || selection !== requestSelection.value) return
    companies.value = [...companies.value, ...data.companies]
    companyNextAfterId.value = data.nextAfterId
  } catch (e) {
    if (generation === loadSequence && selection === requestSelection.value) error.value = unwrapError(e)
  }
}
async function createSpace() {
  await doAction(async (isCurrent) => {
    const created = await call('POST', '/api/spaces', { name: spaceName.value, kind: kind.value, currency: 'BRL' })
    if (!isCurrent()) return
    spaceName.value = ''
    await refreshSpaces()
    if (!isCurrent()) return
    // The activeId watcher owns loading; a second call caused duplicate
    // simultaneous requests when the newly created space was selected.
    activeId.value = created.id
  }, t('librefinance', 'Financial workspace created.'))
}
async function createAccount() {
  await doAction(async () => {
    await call('POST', '/api/spaces/' + activeId.value + '/accounts', {
      code: accountCode.value, name: accountName.value, class: 'asset', isBank: true,
    })
    accountCode.value = ''
    accountName.value = ''
    await refreshCurrent()
  }, 'Conta cadastrada.')
}
async function createContact() {
  await doAction(async () => {
    const selected = Number(activeId.value)
    await call('POST', '/api/spaces/' + selected + '/contacts', {
      name: contactName.value, kind: contactKind.value,
      email: contactEmail.value || null, taxId: contactTaxId.value || null,
    })
    contactName.value = ''
    contactEmail.value = ''
    contactTaxId.value = ''
    await refreshCurrent()
  }, 'Contato cadastrado.')
}
async function createItem() {
  await doAction(async () => {
    const selected = Number(activeId.value)
    const price = parseContextMoney(itemPrice.value, space.value, [], { allowZero: true })
    await call('POST', '/api/spaces/' + selected + '/items', {
      sku: itemSku.value, name: itemName.value, kind: itemKind.value,
      description: itemDescription.value || null, unitPriceMinor: price,
    })
    itemSku.value = ''
    itemName.value = ''
    itemPrice.value = ''
    itemDescription.value = ''
    await refreshCurrent()
  }, t('librefinance', 'Product or service registered.'))
}
async function loadMoreCatalog(which) {
  const selection = requestSelection.value
  const selected = selection.spaceId
  const gen = loadSequence
  const before = which === 'contacts' ? contactNextAfterId.value : itemNextAfterId.value
  if (!before || !selected) return
  try {
    const result = await call('GET', '/api/spaces/' + selected + '/' + which + '?afterId=' + before)
    if (gen !== loadSequence || selection !== requestSelection.value) return
    if (which === 'contacts') {
      contacts.value = [...contacts.value, ...result.contacts]
      contactNextAfterId.value = result.nextAfterId
    } else {
      items.value = [...items.value, ...result.items]
      itemNextAfterId.value = result.nextAfterId
    }
  } catch (e) {
    if (gen === loadSequence && selection === requestSelection.value) error.value = unwrapError(e)
  }
}
async function createCategory() {
  await doAction(async () => {
    await call('POST', '/api/spaces/' + activeId.value + '/categories', {
      code: categoryCode.value,
      name: categoryName.value,
      color: categoryColor.value,
      kind: categoryKind.value,
      parentId: categoryParentId.value || null,
      accountId: categoryAccountId.value || null,
    })
    categoryCode.value = ''
    categoryName.value = ''
    categoryColor.value = '#64748B'
    categoryParentId.value = 0
    categoryAccountId.value = 0
    await refreshCurrent()
  }, t('librefinance', 'Hierarchical category registered.'))
}
async function loadMoreCategories() {
  const selection = requestSelection.value
  const current = selection.spaceId
  const generation = loadSequence
  const after = categoryNextAfterId.value
  if (!current || !after) return
  try {
    const response = await call('GET', '/api/spaces/' + current + '/categories?afterId=' + after)
    if (loadSequence !== generation || selection !== requestSelection.value) return
    categoryRecords.value = [...categoryRecords.value, ...response.categories]
    categoryNextAfterId.value = response.nextAfterId
  } catch(e) { if (loadSequence === generation && selection === requestSelection.value) error.value = unwrapError(e) }
}
function resetAi() {
  aiBusy.value = false
  aiCapability.value = null
  aiPreview.value = null
  aiTask.value = null
  aiConsent.value = false
  aiError.value = ''
}
watch([aiFrom, aiThrough], () => {
  aiPreview.value = null
  aiTask.value = null
  aiConsent.value = false
})
async function openAi() {
  if (!manager.value || !activeId.value) return
  resetAi()
  activeTab.value = 'ai'
  aiBusy.value = true
  const current = Number(activeId.value)
  const generation = loadSequence
  try {
    const value = await call('GET', '/api/spaces/' + current + '/ai/capabilities')
    if (generation === loadSequence && current === Number(activeId.value)) {
      aiCapability.value = value
    }
  } catch (e) {
    if (generation === loadSequence) aiError.value = unwrapError(e)
  } finally {
    if (generation === loadSequence) aiBusy.value = false
  }
}
async function previewAi() {
  if (!manager.value || !activeId.value || aiBusy.value) return
  aiBusy.value = true
  aiError.value = ''
  aiPreview.value = null
  aiConsent.value = false
  const current = Number(activeId.value)
  const generation = loadSequence
  try {
    const query = '?from=' + encodeURIComponent(aiFrom.value)
      + '&through=' + encodeURIComponent(aiThrough.value)
    const value = await call('GET', '/api/spaces/' + current + '/ai/preview' + query)
    if (generation === loadSequence && current === Number(activeId.value)) {
      aiPreview.value = value
    }
  } catch (e) {
    if (generation === loadSequence) aiError.value = unwrapError(e)
  } finally {
    if (generation === loadSequence) aiBusy.value = false
  }
}
async function requestAi() {
  if (!manager.value || !activeId.value || aiBusy.value || !aiPreview.value
      || !aiConsent.value || !aiCapability.value?.available) return
  const current = Number(activeId.value)
  const generation = loadSequence
  aiBusy.value = true
  aiError.value = ''
  try {
    const created = await call('POST', '/api/spaces/' + current + '/ai/analyze', {
      from: aiFrom.value, through: aiThrough.value, consent: true,
      previewHash: aiPreview.value.previewHash,
    })
    if (generation === loadSequence && current === Number(activeId.value)) {
      aiTask.value = created
      aiConsent.value = false
    }
  } catch (e) {
    if (generation === loadSequence) aiError.value = unwrapError(e)
  } finally {
    if (generation === loadSequence) aiBusy.value = false
  }
}
async function checkAiStatus() {
  if (!manager.value || !aiTask.value?.taskId || aiBusy.value) return
  aiBusy.value = true
  aiError.value = ''
  const current = Number(activeId.value)
  const generation = loadSequence
  try {
    const value = await call('GET', '/api/spaces/' + current
      + '/ai/tasks/' + aiTask.value.taskId)
    if (generation === loadSequence && current === Number(activeId.value)) {
      aiTask.value = value
    }
  } catch (e) {
    if (generation === loadSequence) aiError.value = unwrapError(e)
  } finally {
    if (generation === loadSequence) aiBusy.value = false
  }
}
function openModule(moduleId) {
  if (modulesLoadedFor.value !== Number(activeId.value)) return
  const module = modules.value.find(m => m.id === moduleId && m.enabled)
  if (!module) return
  const views = {
    librefinance_reports: 'reports',
    librefinance_obligations: 'obligations',
    librefinance_invoicing: 'invoicing',
    librefinance_banking: 'banking',
    librefinance_import: 'import',
    librefinance_nfse: 'nfse',
  }
  const view = views[moduleId]
  if (!view) return
  selectedModuleId.value = moduleId
  activeTab.value = view
}
async function toggleModule(module) {
  if (!manager.value) return
  await doAction(async () => {
    await call('PUT', '/api/spaces/' + activeId.value + '/modules/' + encodeURIComponent(module.id), { enabled: !module.enabled })
    await refreshCurrent()
  }, t('librefinance', 'Module configuration updated.'))
}
async function shareUser() {
  await doAction(async () => {
    const data = { uid: shareUid.value, role: shareRole.value }
    if (Number.isSafeInteger(membersGrantRevision.value)) {
      data.expectedRevision = membersGrantRevision.value
    }
    await call('POST', '/api/spaces/' + activeId.value + '/members', data)
    shareUid.value = ''
    await refreshCurrent()
  }, 'Acesso individual configurado.', true)
}
async function shareGroup() {
  await doAction(async () => {
    await call('POST', '/api/spaces/' + activeId.value + '/groups', { gid: groupGid.value, role: groupRole.value })
    groupGid.value = ''
    await refreshCurrent()
  }, 'Acesso do grupo configurado.')
}
async function removeAccess(type, identifier) {
  if (!confirm('Revogar o acesso selecionado?')) return
  await doAction(async () => {
    const route = type === 'member' ? '/members/' : '/groups/'
    const revision = type === 'member' && Number.isSafeInteger(membersGrantRevision.value)
      ? '?expectedRevision=' + membersGrantRevision.value : ''
    await call('DELETE', '/api/spaces/' + activeId.value + route + encodeURIComponent(identifier) + revision)
    await refreshCurrent()
  }, 'Acesso revogado.', type === 'member')
}
async function createEntry(options = {}) {
  await doAction(async () => {
    const minor = parseMoney(amount.value, accountById(bankId.value), accountById(categoryId.value))
    if (minor <= 0 || !bankId.value || !categoryId.value) throw new Error(t('librefinance', 'Select the accounts and enter the amount.'))
    const debitId = direction.value === 'income' ? Number(bankId.value) : Number(categoryId.value)
    const creditId = direction.value === 'income' ? Number(categoryId.value) : Number(bankId.value)
    await call('POST', '/api/spaces/' + activeId.value + '/journals', {
      debitId, creditId, amountMinor: minor,
      memo: memo.value, date: date.value,
      externalKey: 'web:' + crypto.randomUUID(),
      categoryId: transactionCategoryId.value || null,
      customFields: options.customFields || {},
      notesMarkdown: options.notesMarkdown || null,
      paymentMethodId: options.paymentMethodId || null,
    })
    amount.value = ''
    memo.value = ''
    transactionCategoryId.value = 0
    await refreshCurrent()
  }, t('librefinance', 'Journal entry posted.'))
}
async function loadMore() {
  if (!nextBeforeId.value) return
  const selection = requestSelection.value
  const selected = selection.spaceId
  const token = loadSequence
  try {
    const data = await call('GET', '/api/spaces/' + selected + '/journals?beforeId=' + nextBeforeId.value)
    if (token !== loadSequence || selection !== requestSelection.value) return
    journals.value = [...journals.value, ...data.journals]
    nextBeforeId.value = data.nextBeforeId
  } catch (e) {
    if (token === loadSequence && selection === requestSelection.value) error.value = unwrapError(e)
  }
}
async function loadInitialSpaces() {
  error.value = ''
  try { await refreshSpaces() } catch (e) { error.value = unwrapError(e) }
}
onMounted(loadInitialSpaces)
  return {
    spaces, spacesStatus, loadInitialSpaces, activeId, accounts, contacts, items, companies, companyNextAfterId, companyEditId, companyName, companyTradeName, companyTaxId, companyCountry, companyFields, companyCustomValues, companyMunicipal, companyState, companyProfile, companyActive, modules, modulesLoadedFor, categoryRecords, categoryNextAfterId, categoryCode, categoryName, categoryColor, categoryKind, categoryParentId, categoryAccountId, selectedModuleId, aiCapability, aiPreview, aiTask, aiConsent, aiBusy, aiError, aiThrough, aiFrom, contactNextAfterId, itemNextAfterId, activeTab, journals, members, membersGrantRevision, canManageMembers, groupGrants, nextBeforeId, error, message, busy, spaceName, kind, accountName, accountCode, contactName, contactKind, contactEmail, contactTaxId, itemSku, itemName, itemKind, itemPrice, itemDescription, shareUid, shareRole, groupGid, groupRole, direction, bankId, categoryId, transactionCategoryId, amount, memo, date, loadSequence, preferredSpaceHandled, space, selectedModule, writable, manager, banks, categories, money, amountInput, parseMoney, accountById, naturalBalance, accountNameById, unwrapError, call, refreshSpaces, refreshCurrent, doAction, resetCompanyForm, editCompany, saveCompany, loadMoreCompanies, createSpace, createAccount, createContact, createItem, loadMoreCatalog, createCategory, loadMoreCategories, resetAi, openAi, previewAi, requestAi, checkAiStatus, openModule, toggleModule, shareUser, shareGroup, removeAccess, createEntry, loadMore
  }
}
