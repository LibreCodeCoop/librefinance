// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Finance workspace state and authorized API actions. No view markup.
import { ref, computed, onMounted, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { toMinorUnits } from '../money.js'

export function useFinanceWorkspace() {

const spaces = ref([])
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
let preferredSpaceHandled = false
const space = computed(() => spaces.value.find((s) => Number(s.id) === Number(activeId.value)))
const selectedModule = computed(() => modules.value.find((module) => module.id === selectedModuleId.value && module.enabled))
const writable = computed(() => ['owner', 'manager', 'operator'].includes(space.value?.role))
const manager = computed(() => ['owner', 'manager'].includes(space.value?.role))
const banks = computed(() => accounts.value.filter((a) => a.is_bank && a.active))
const categories = computed(() => accounts.value.filter((a) => a.active && a.class === (direction.value === 'expense' ? 'expense' : 'revenue')))
const money = (minor) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: space.value?.currency || 'BRL' }).format(Number(minor) / 100)
const naturalBalance = (account) => money((['liability', 'equity', 'revenue'].includes(account.class) ? -1 : 1) * account.balance_minor)
const accountNameById = (id) => accounts.value.find((a) => a.id === Number(id))?.name || ('#' + id)

function unwrapError(e) {
  return e.response?.data?.error || e.message || 'Falha desconhecida'
}
async function call(method, path, data) {
  const url = generateUrl('/apps/librefinance' + path)
  const response = await axios({ method, url, data, headers: { Accept: 'application/json' } })
  return response.data
}
async function refreshSpaces() {
  const result = await call('GET', '/api/spaces')
  spaces.value = result.spaces
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
    activeId.value = spaces.value.length ? Number(spaces.value[0].id) : 0
  }
}
async function refreshCurrent(options = {}) {
  const current = Number(activeId.value)
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
  if (generation !== loadSequence || current !== Number(activeId.value)) return
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
  if (data.members) members.value = data.members.members
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
watch(activeId, () => {
  // Changing the active workspace invalidates financial screens. Keep the
  // workspace-management screen open when archiving/restoring the selected
  // workspace, otherwise a late activeId reset hides the restore action.
  const managingSpaces = activeTab.value === 'spaces'
  resetAi()
  modulesLoadedFor.value = 0
  modules.value = []
  accounts.value = []
  journals.value = []
  contacts.value = []
  items.value = []
  companies.value = []
  companyFields.value = []
  categoryRecords.value = []
  members.value = []
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
async function doAction(action, success) {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    await action()
    message.value = success
  } catch (e) {
    error.value = unwrapError(e)
  } finally {
    busy.value = false
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
      customFields: { ...companyCustomValues.value } }
    if (companyEditId.value) await call('PUT', path + '/' + companyEditId.value, payload)
    else await call('POST', path, payload)
    resetCompanyForm()
    await refreshCurrent()
  }, 'Cadastro jurídico salvo.')
}
async function loadMoreCompanies() {
  const current = Number(activeId.value)
  const after = companyNextAfterId.value
  if (!current || !after) return
  const generation = loadSequence
  try {
    const data = await call('GET', '/api/spaces/' + current + '/companies?afterId=' + after)
    if (generation !== loadSequence || current !== Number(activeId.value)) return
    companies.value = [...companies.value, ...data.companies]
    companyNextAfterId.value = data.nextAfterId
  } catch (e) { error.value = unwrapError(e) }
}
async function createSpace() {
  await doAction(async () => {
    const created = await call('POST', '/api/spaces', { name: spaceName.value, kind: kind.value, currency: 'BRL' })
    spaceName.value = ''
    await refreshSpaces()
    // The activeId watcher owns loading; a second call caused duplicate
    // simultaneous requests when the newly created space was selected.
    activeId.value = created.id
  }, 'Gestão financeira criada.')
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
    const price = itemPrice.value.trim() === '0' || itemPrice.value.trim() === '0,00'
      ? 0 : toMinorUnits(itemPrice.value)
    await call('POST', '/api/spaces/' + selected + '/items', {
      sku: itemSku.value, name: itemName.value, kind: itemKind.value,
      description: itemDescription.value || null, unitPriceMinor: price,
    })
    itemSku.value = ''
    itemName.value = ''
    itemPrice.value = ''
    itemDescription.value = ''
    await refreshCurrent()
  }, 'Produto ou serviço cadastrado.')
}
async function loadMoreCatalog(which) {
  const selected = Number(activeId.value)
  const gen = loadSequence
  const before = which === 'contacts' ? contactNextAfterId.value : itemNextAfterId.value
  if (!before || !selected) return
  try {
    const result = await call('GET', '/api/spaces/' + selected + '/' + which + '?afterId=' + before)
    if (gen !== loadSequence || selected !== Number(activeId.value)) return
    if (which === 'contacts') {
      contacts.value = [...contacts.value, ...result.contacts]
      contactNextAfterId.value = result.nextAfterId
    } else {
      items.value = [...items.value, ...result.items]
      itemNextAfterId.value = result.nextAfterId
    }
  } catch (e) {
    if (gen === loadSequence) error.value = unwrapError(e)
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
  }, 'Categoria hierárquica cadastrada.')
}
async function loadMoreCategories() {
  const current = Number(activeId.value)
  const generation = loadSequence
  const after = categoryNextAfterId.value
  if (!current || !after) return
  try {
    const response = await call('GET', '/api/spaces/' + current + '/categories?afterId=' + after)
    if (loadSequence !== generation || current !== Number(activeId.value)) return
    categoryRecords.value = [...categoryRecords.value, ...response.categories]
    categoryNextAfterId.value = response.nextAfterId
  } catch(e) { if (loadSequence === generation) error.value = unwrapError(e) }
}
function resetAi() {
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
  }, 'Configuração do módulo atualizada.')
}
async function shareUser() {
  await doAction(async () => {
    await call('POST', '/api/spaces/' + activeId.value + '/members', { uid: shareUid.value, role: shareRole.value })
    shareUid.value = ''
    await refreshCurrent()
  }, 'Acesso individual configurado.')
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
    await call('DELETE', '/api/spaces/' + activeId.value + route + encodeURIComponent(identifier))
    await refreshCurrent()
  }, 'Acesso revogado.')
}
async function createEntry(options = {}) {
  await doAction(async () => {
    const minor = toMinorUnits(amount.value)
    if (minor <= 0 || !bankId.value || !categoryId.value) throw new Error('Selecione as contas e informe o valor.')
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
  }, 'Lançamento contabilizado.')
}
async function loadMore() {
  if (!nextBeforeId.value) return
  const selected = Number(activeId.value)
  const token = loadSequence
  try {
    const data = await call('GET', '/api/spaces/' + selected + '/journals?beforeId=' + nextBeforeId.value)
    if (token !== loadSequence || selected !== Number(activeId.value)) return
    journals.value = [...journals.value, ...data.journals]
    nextBeforeId.value = data.nextBeforeId
  } catch (e) {
    error.value = unwrapError(e)
  }
}
onMounted(async () => {
  try { await refreshSpaces() } catch (e) { error.value = unwrapError(e) }
})
  return {
    spaces, activeId, accounts, contacts, items, companies, companyNextAfterId, companyEditId, companyName, companyTradeName, companyTaxId, companyCountry, companyFields, companyCustomValues, companyMunicipal, companyState, companyActive, modules, modulesLoadedFor, categoryRecords, categoryNextAfterId, categoryCode, categoryName, categoryColor, categoryKind, categoryParentId, categoryAccountId, selectedModuleId, aiCapability, aiPreview, aiTask, aiConsent, aiBusy, aiError, aiThrough, aiFrom, contactNextAfterId, itemNextAfterId, activeTab, journals, members, groupGrants, nextBeforeId, error, message, busy, spaceName, kind, accountName, accountCode, contactName, contactKind, contactEmail, contactTaxId, itemSku, itemName, itemKind, itemPrice, itemDescription, shareUid, shareRole, groupGid, groupRole, direction, bankId, categoryId, transactionCategoryId, amount, memo, date, loadSequence, preferredSpaceHandled, space, selectedModule, writable, manager, banks, categories, money, naturalBalance, accountNameById, unwrapError, call, refreshSpaces, refreshCurrent, doAction, resetCompanyForm, editCompany, saveCompany, loadMoreCompanies, createSpace, createAccount, createContact, createItem, loadMoreCatalog, createCategory, loadMoreCategories, resetAi, openAi, previewAi, requestAi, checkAiStatus, openModule, toggleModule, shareUser, shareGroup, removeAccess, createEntry, loadMore
  }
}
