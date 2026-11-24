<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import {
  mdiViewDashboardOutline, mdiBankOutline, mdiSwapHorizontal,
  mdiReceiptTextOutline, mdiCashClock, mdiChartLine, mdiAccountMultipleOutline,
  mdiFileUploadOutline, mdiCogOutline, mdiShapeOutline, mdiRobotOutline,
  mdiDomain, mdiFileDocumentOutline, mdiBookOpenPageVariantOutline,
  mdiPlus, mdiRefresh, mdiMagnify, mdiArrowDownLeft, mdiArrowUpRight,
  mdiFolderAccountOutline, mdiCartOutline,
} from '@mdi/js'
import {
  PAGE_NAMES, availableSections, canOpenView, navigationSearch,
  menuEntries, parentOfView, defaultGroupOpen, canonicalView, financeDirectionForView,
} from './navigation.js'
import { useFinanceWorkspace } from './state/useFinanceWorkspace.js'
import LfSelect from './components/LfSelect.vue'
import { pageNameForSpace, fieldEntityKind } from './navigation.js'
import DashboardView from './views/DashboardView.vue'
import TransactionsView from './views/TransactionsView.vue'
import AccountsView from './views/AccountsView.vue'
import ContactsView from './views/ContactsView.vue'
import ItemsView from './views/ItemsView.vue'
import CompaniesView from './views/CompaniesView.vue'
import CategoriesView from './views/CategoriesView.vue'
import SettingsView from './views/SettingsView.vue'
import AiView from './views/AiView.vue'
import ReportsView from './views/ReportsView.vue'
import ObligationsView from './views/ObligationsView.vue'
import InvoicesView from './views/InvoicesView.vue'
import CashflowView from './views/CashflowView.vue'
import MovementsView from './views/MovementsView.vue'
import DocumentsView from './views/DocumentsView.vue'
import SpacesView from './views/SpacesView.vue'
import TaxRulesView from './views/TaxRulesView.vue'
import NfseView from './views/NfseView.vue'
import ImportView from './views/ImportView.vue'

/**
 * ONE application shell. Back-end modules expose authorized REST capabilities,
 * not iframe pages. This view map is closed and does not accept external URLs.
 */
const workspace = useFinanceWorkspace()
provide('lfWorkspace', workspace)
const {
  spaces, activeId, activeTab, space, modules, modulesLoadedFor,
  manager, writable, busy, error, message, refreshCurrent,
} = workspace
const quickEntry = ref(false)
provide('lfEntryDialog', quickEntry)
// Route handoff token: the destination consumes this only after mount, avoiding
// a modal open before NcDialog has initialized in a newly rendered screen.
const journalOpenRequest = ref(0)
provide('lfJournalOpenRequest', journalOpenRequest)
// One-shot editor requests must outlive the finance sub-panel route remount.
const newSimple = ref(false)
provide('lfNewSimple', newSimple)
const bankImportRequest = ref(0)
provide('lfBankImportRequest', bankImportRequest)
const focusedBank = ref(null)
provide('lfBankFocus', focusedBank)
const bankContext = ref(0)
provide('lfBankContext', bankContext)
// The navigation follows user tasks, not the internal extension layout.
// Server-authorized module discovery is still the only source of availability.
const labels = computed(() => Object.fromEntries(
  Object.keys(PAGE_NAMES).map(id => [id, pageNameForSpace(id, space.value?.kind)])))
const fieldStudioEntity = ref(fieldEntityKind(
  new URLSearchParams(window.location.search).get('entity')))
provide('lfFieldStudioEntity', fieldStudioEntity)
const screens = {
  dashboard: DashboardView,
  transactions: TransactionsView,
  accounts: AccountsView,
  banking: CashflowView,
  finance: MovementsView,
  bankAccounts: AccountsView,
  documents: DocumentsView,
  spaces: SpacesView,
  receivables: CashflowView,
  payables: CashflowView,
  // Historic links map to the same two user-facing financial screens.
  obligations: MovementsView,
  sales: DocumentsView,
  purchases: DocumentsView,
  invoicing: DocumentsView,
  taxes: TaxRulesView,
  contacts: ContactsView,
  items: ItemsView,
  reports: ReportsView,
  companies: CompaniesView,
  categories: CategoriesView,
  import: ImportView,
  nfse: NfseView,
  ai: AiView,
  settings: SettingsView, // compatibility for historic links
  access: SettingsView,
  fields: SettingsView,
  paymentMethods: SettingsView,
  modules: SettingsView,
  data: ImportView,
}
const icons = {
  home: mdiViewDashboardOutline,
  transactions: mdiSwapHorizontal,
  banking: mdiBankOutline,
  finance: mdiCashClock,
  spaces: mdiFolderAccountOutline,
  receivables: mdiArrowDownLeft,
  payables: mdiArrowUpRight,
  obligations: mdiCashClock,
  reports: mdiChartLine,
  billing: mdiReceiptTextOutline,
  purchases: mdiCartOutline,
  bankAccounts: mdiBankOutline,
  fiscal: mdiFileDocumentOutline,
  contacts: mdiAccountMultipleOutline,
  categories: mdiShapeOutline,
  accounts: mdiBookOpenPageVariantOutline,
  companies: mdiDomain,
  assistant: mdiRobotOutline,
  settings: mdiCogOutline,
  access: mdiAccountMultipleOutline,
  fields: mdiShapeOutline,
  paymentMethods: mdiBankOutline,
  modules: mdiCogOutline,
  data: mdiFileUploadOutline,
  import: mdiFileUploadOutline,
}
const searchQuery = ref('')
// Browser back/forward can change a search term without changing the view.
// Remount only when history navigation supplies a different record search.
const urlSearchTerm = ref(new URLSearchParams(window.location.search).get('search') || '')
const openGroups = ref({})
const managementGroupIcons = {
  'workspaces': mdiFolderAccountOutline,
  'records': mdiAccountMultipleOutline,
  'financeTax': mdiBookOpenPageVariantOutline,
  'team': mdiCogOutline,
  'features': mdiFileUploadOutline,
}
const companyOnboarding = ref(false)
provide('lfCompanyOnboarding', companyOnboarding)
const availableNav = computed(() => availableSections({
  manager: manager.value,
  modules: modules.value,
  modulesLoaded: modulesLoadedFor.value === Number(activeId.value),
}))
const migrationAvailable = computed(() => manager.value
  && modulesLoadedFor.value === Number(activeId.value)
  && modules.value.some(m => m.id === 'librefinance_import' && m.enabled))
const searchResults = computed(() => navigationSearch(availableNav.value, searchQuery.value, {
  manager: manager.value, migrationEnabled: migrationAvailable.value,
  spaceKind: space.value?.kind,
}))
const isSearching = computed(() => searchQuery.value.trim().length > 0)
function visibleView(view) {
  return canOpenView(availableNav.value, view, {
    manager: manager.value, migrationEnabled: migrationAvailable.value,
  })
}
function groupHasActive(item) {
  return item.children?.some(child => child.id === readyView.value) || false
}
function subgroupOpen(groupId, item) {
  if (item.children?.some(child => child.id === readyView.value && child.group === groupId)) return true
  return openGroups.value['sub:' + groupId] ?? false
}
function changeSubgroup(groupId, value) {
  setGroupOpen('sub:' + groupId, value)
}
function groupedChildren(item) {
  const grouped = new Map()
  for (const child of item.children || []) {
    if (!grouped.has(child.group)) grouped.set(child.group, [])
    grouped.get(child.group).push(child)
  }
  return [...grouped].map(([id, children]) => ({ id, name: children[0].groupLabel, children }))
}
function isGroupOpen(item) {
  // The active view must remain reachable in the navigation after a reload.
  // Initial NcAppNavigation collapse events must not override this invariant.
  if (groupHasActive(item)) return true
  return openGroups.value[item.id] ?? defaultGroupOpen(item.id, space.value?.kind)
}
function setGroupOpen(id, value) {
  openGroups.value = { ...openGroups.value, [id]: value }
}
function clearSearch() {
  searchQuery.value = ''
}
function searchKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    clearSearch()
    event.currentTarget?.blur()
  } else if (event.key === 'Enter' && searchResults.value.length === 1) {
    event.preventDefault()
    navigate(searchResults.value[0].id)
  }
}
// A native Nextcloud link remains a valid URL for new tabs, copy-link and
// browser history. The core only accepts known, currently authorized views.
function viewHref(view) {
  const url = new URL(window.location.href)
  url.searchParams.set('view', view)
  url.searchParams.delete('section')
  if (view !== readyView.value) url.searchParams.delete('search')
  if (view === 'fields') url.searchParams.set('entity', fieldStudioEntity.value)
  else url.searchParams.delete('entity')
  if (activeId.value) url.searchParams.set('spaceId', String(activeId.value))
  return url.pathname + url.search
}
function onNavClick(event, view) {
  if (event.button !== 0 || event.metaKey || event.altKey || event.ctrlKey || event.shiftKey) return
  event.preventDefault()
  navigate(view)
}
function onNavGroupClick(event, groupId) {
  event.preventDefault()
  const group = availableNav.value.flatMap(section => section.items)
    .find(item => item.id === groupId)
  if (group) setGroupOpen(groupId, !isGroupOpen(group))
}
const roleNames = { owner: t('librefinance', 'Owner'), manager: t('librefinance', 'Manager'), operator: t('librefinance', 'Operator'),
  auditor: t('librefinance', 'Auditor'), viewer: t('librefinance', 'Viewer') }
const spaceKind = computed(() => ({ personal: t('librefinance', 'Home / personal'), collective: t('librefinance', 'Group / collective'),
  organization: t('librefinance', 'Business / organization') })[space.value?.kind] || t('librefinance', 'Financial workspace'))
const spaceRole = computed(() => roleNames[space.value?.role] || t('librefinance', 'Participant'))
const selectedSpace = computed(() => spaces.value.find(s =>
  Number(s.id) === Number(activeId.value))?.name ?? t('librefinance', 'Select a workspace'))
const spaceOptions = computed(() => spaces.value.map(s => ({
  value: Number(s.id), label: s.name,
})))
const readyView = computed(() => (
  visibleView(activeTab.value)
    ? canonicalView(activeTab.value)
    : 'dashboard'
))
const currentScreen = computed(() => screens[readyView.value] || DashboardView)
const financeDirectionHint = ref(financeDirectionForView(
  new URLSearchParams(window.location.search).get('view')))
provide('lfFinanceDirectionHint', financeDirectionHint)
// Shared commercial screen receives its context from the authorized route,
// never from a self-supplied document ID or an untrusted URL.
const invoiceKind = computed(() => financeDirectionHint.value === 'payable' ? 'bill' : 'invoice')
provide('lfInvoiceKind', invoiceKind)
const focusedInvoice = ref(null)
provide('lfFocusedInvoice', focusedInvoice)
const newDetailed = ref(false)
provide('lfNewDetailed', newDetailed)
const focusedObligation = ref(null)
provide('lfFocusedObligation', focusedObligation)
const activeLocation = computed(() => {
  if (['finance','payables','receivables','sales','purchases','documents','banking',
    'bankAccounts'].includes(readyView.value)) return t('librefinance', 'Finance')
  if (readyView.value === 'settings' || readyView.value === 'spaces' || readyView.value === 'import') return t('librefinance', 'Manage')
  const match = menuEntries(availableNav.value).find(item => item.id === readyView.value)
  return match?.parent || match?.section || 'LibreFinance'
})

const initialQuery = new URLSearchParams(window.location.search)
let pendingInitialView = canonicalView(initialQuery.get('view'), initialQuery.get('section'))
const settingPanels = ['access', 'fields', 'paymentMethods', 'modules']
function expandParent(view) {
  const id = parentOfView(availableNav.value, view)
  if (id !== null) setGroupOpen(id, true)
  const target = menuEntries(availableNav.value).find(item => item.id === view)
  if (target?.group) changeSubgroup(target.group, true)
}
function navigate(view, options = {}) {
  if (!screens[view] || !visibleView(view)) return
  // An explicit entity identifies which existing form definition to edit;
  // no second editor or new writable endpoint is introduced.
  if (view === 'fields') {
    fieldStudioEntity.value = fieldEntityKind(options.entityKind || 'company')
  }
  // The historical documents view has no direction in its URL. Internal
  // document actions already carry a verified purchase/sale context.
  if (['payables','receivables','sales','purchases','invoicing'].includes(view)
    && !options.preserveDirection) {
    financeDirectionHint.value = financeDirectionForView(view)
  }
  if (view === 'finance' && !options.preserveDirection) {
    financeDirectionHint.value = 'all'
  }
  view = canonicalView(view)
  expandParent(view)
  clearSearch()
  pendingInitialView = null
  // Do not keep an old action confirmation visible in another section.
  message.value = ''
  if (view === activeTab.value) return
  activeTab.value = view
  const url = new URL(window.location.href)
  url.searchParams.set('view', view)
  url.searchParams.delete('section')
  url.searchParams.delete('search')
  urlSearchTerm.value = ''
  if (view === 'fields') url.searchParams.set('entity', fieldStudioEntity.value)
  else url.searchParams.delete('entity')
  if (activeId.value) url.searchParams.set('spaceId', String(activeId.value))
  window.history.pushState(null, '', url.pathname + url.search)
}
provide('lfNavigate', navigate)
function selectSpace(id) {
  if (!spaces.value.some(s => Number(s.id) === Number(id))) return
  activeId.value = Number(id)
  focusedInvoice.value = null
  focusedObligation.value = null
  focusedBank.value = null
  bankContext.value = 0
  bankImportRequest.value = 0
  journalOpenRequest.value = 0
  quickEntry.value = false
  newDetailed.value = false
  newSimple.value = false
  clearSearch()
  openGroups.value = {}
  pendingInitialView = null
  activeTab.value = 'dashboard'
  const url = new URL(window.location.href)
  url.searchParams.set('spaceId', String(Number(id)))
  url.searchParams.set('view', 'dashboard')
  url.searchParams.delete('search')
  urlSearchTerm.value = ''
  window.history.pushState(null, '', url.pathname + url.search)
}
function openEntry() {
  if (!writable.value || !activeId.value || !visibleView('finance')) return
  navigate('finance')
}

function onHistoryChange() {
  const values = new URLSearchParams(window.location.search)
  const destinationSpace = Number(values.get('spaceId'))
  const destinationView = canonicalView(values.get('view'), values.get('section'))
  urlSearchTerm.value = values.get('search') || ''
  if (destinationView === 'fields') {
    fieldStudioEntity.value = fieldEntityKind(values.get('entity'))
  }
  if (Number.isSafeInteger(destinationSpace) && spaces.value.some(s =>
    Number(s.id) === destinationSpace)) {
    activeId.value = destinationSpace
  }
  pendingInitialView = destinationView
  financeDirectionHint.value = financeDirectionForView(values.get('view'))
  if (visibleView(destinationView)
    && modulesLoadedFor.value === Number(activeId.value)) {
    // A browser back/forward event must not create a new history entry.
    expandParent(destinationView)
    clearSearch()
    activeTab.value = destinationView
    pendingInitialView = null
    message.value = ''
  }
}
// Selection is reflected in the URL only as a safe, locally permitted ID and
// closed view key. Never persist source data or tokens in browser history.
watch([activeId, activeTab], () => {
  if (pendingInitialView) return
  const url = new URL(window.location.href)
  if (activeId.value) url.searchParams.set('spaceId', String(activeId.value))
  else url.searchParams.delete('spaceId')
  url.searchParams.set('view', readyView.value)
  window.history.replaceState(null, '', url.pathname + url.search)
})
watch([modulesLoadedFor, activeId], () => {
  if (!pendingInitialView || modulesLoadedFor.value !== Number(activeId.value)) return
  const requested = pendingInitialView
  pendingInitialView = null
  if (visibleView(requested)) {
    expandParent(requested)
    activeTab.value = requested
  }
})
onMounted(() => window.addEventListener('popstate', onHistoryChange))
onBeforeUnmount(() => window.removeEventListener('popstate', onHistoryChange))
</script>

<template>
  <NcContent app-name="librefinance">
    <NcAppNavigation :aria-label="t('librefinance', 'LibreFinance navigation')">
      <div class="lf-navigation-context">
        <div class="lf-product-lockup">
          <span class="lf-product-symbol" aria-hidden="true"><NcIconSvgWrapper :path="mdiChartLine" :size="22" /></span>
          <div><p class="lf-product-name">LibreFinance</p>
            <p class="lf-muted">{{space?.kind==='personal'
              ? t('librefinance', 'Household finances') : t('librefinance', 'Personal and organizational finances')}}</p></div>
        </div>
        <LfSelect v-if="spaceOptions.length > 1" id="lf-space-select"
          :model-value="activeId" :options="spaceOptions"
          :label="t('librefinance', 'Current financial workspace')"
          :placeholder="t('librefinance', 'Choose a workspace')"
          @update:model-value="selectSpace" />
        <p v-else-if="space" class="lf-space-current" :aria-label="t('librefinance', 'Current financial workspace')">
          <strong>{{selectedSpace}}</strong>
        </p>
        <p v-if="space" class="lf-space-meta">
          <span class="lf-category-dot" :style="{backgroundColor:space.accent_color||'#64748B'}"
            aria-hidden="true" />
          <span>{{ spaceKind }}</span><span aria-hidden="true">·</span>
          <span>{{ space.currency }}</span><span aria-hidden="true">·</span>
          <span>{{ spaceRole }}</span>
        </p>
        <div v-if="space" class="lf-menu-search" role="search">
          <NcIconSvgWrapper :path="mdiMagnify" :size="19" aria-hidden="true" />
          <label for="lf-menu-search" class="lf-visually-hidden">{{ t('librefinance', 'Find a LibreFinance feature') }}</label>
          <input id="lf-menu-search" v-model="searchQuery" type="search"
            autocomplete="off" spellcheck="false" maxlength="90"
            :aria-label="t('librefinance', 'Search the menu')"
          :placeholder="t('librefinance', 'Search for a feature…')"
            @keydown="searchKeydown" />
        </div>
      </div>
      <template #list>
        <NcAppNavigationList v-if="space" class="lf-nav-list">
          <template v-if="isSearching">
            <li class="lf-nav-section"><span>{{ t('librefinance', 'SEARCH RESULTS') }}</span></li>
            <NcAppNavigationItem v-for="entry in searchResults" :key="entry.id"
              :name="labels[entry.id]" :title="entry.parent || entry.section"
              :href="viewHref(entry.id)" :active="readyView === entry.id"
              @click="onNavClick($event, entry.id)">
              <template #icon><NcIconSvgWrapper :path="icons[entry.icon]" :size="20" /></template>
            </NcAppNavigationItem>
            <li v-if="!searchResults.length" class="lf-menu-empty" role="status">
              <strong>{{ t('librefinance', 'No features found') }}</strong>
              <span>{{ t('librefinance', 'Try another term. Only features available in this workspace are shown.') }}</span>
            </li>
          </template>
          <template v-else>
            <template v-for="group in availableNav" :key="group.id">
              <li class="lf-nav-section"><span>{{ group.label }}</span></li>
              <template v-for="item in group.items" :key="item.id">
                <NcAppNavigationItem v-if="item.children" :name="item.label"
                  :allow-collapse="true" :open="isGroupOpen(item)"
                  :active="groupHasActive(item)" @click="onNavGroupClick($event, item.id)"
                  @update:open="setGroupOpen(item.id, $event)">
                  <template #icon><NcIconSvgWrapper :path="icons[item.icon]" :size="20" /></template>
                  <template v-if="item.id === 'financeMenu'">
                    <NcAppNavigationItem v-for="child in item.children.filter(child => !child.hidden)" :key="child.id"
                      :name="labels[child.id]" :href="viewHref(child.id)"
                      :active="readyView === child.id" @click="onNavClick($event, child.id)">
                      <template #icon>
                        <NcIconSvgWrapper :path="icons[child.icon]" :size="18" />
                      </template>
                    </NcAppNavigationItem>
                  </template>
                  <template v-else>
                    <NcAppNavigationItem v-for="subgroup in groupedChildren(item)"
                      :key="subgroup.id" class="lf-native-management-group"
                      :name="subgroup.name" :allow-collapse="true"
                      :open="subgroupOpen(subgroup.id, item)"
                      :active="subgroup.children.some(child => child.id === readyView)"
                      @click.prevent="changeSubgroup(subgroup.id, !subgroupOpen(subgroup.id, item))"
                      @update:open="changeSubgroup(subgroup.id, $event)">
                      <template #icon>
                        <NcIconSvgWrapper :path="managementGroupIcons[subgroup.id]" :size="20" />
                      </template>
                      <NcAppNavigationItem v-for="child in subgroup.children" :key="child.id"
                        :name="labels[child.id]" :href="viewHref(child.id)"
                        :active="readyView === child.id" @click="onNavClick($event, child.id)">
                        <template #icon>
                          <NcIconSvgWrapper :path="icons[child.icon]" :size="18" />
                        </template>
                      </NcAppNavigationItem>
                    </NcAppNavigationItem>
                  </template>
                </NcAppNavigationItem>
                <NcAppNavigationItem v-else :name="labels[item.id]" :href="viewHref(item.id)"
                  :active="readyView === item.id"
                  @click="onNavClick($event, item.id)">
                  <template #icon><NcIconSvgWrapper :path="icons[item.icon]" :size="20" /></template>
                </NcAppNavigationItem>
              </template>
            </template>
          </template>
        </NcAppNavigationList>
      </template>

    </NcAppNavigation>

    <NcAppContent>
      <div class="lf-workspace" data-testid="librefinance-workspace"
        :data-modules-loaded="modulesLoadedFor === Number(activeId)">
        <div v-if="space" class="lf-context-bar">
          <div class="lf-context-identity">
            <span class="lf-context-label">{{ activeLocation }} <span aria-hidden="true">/</span> {{ labels[readyView] }}</span>
            <strong>{{ selectedSpace }}</strong>
          </div>
          <div class="lf-context-actions">
            <span class="lf-context-currency">{{ space.currency }}</span>
            <NcButton v-if="writable && readyView === 'dashboard'" variant="primary" @click="openEntry">
              <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
              {{ t('librefinance', 'View financial movements') }}
            </NcButton>
            <NcButton variant="tertiary" :aria-label="t('librefinance', 'Refresh data')"
              :disabled="busy" @click="refreshCurrent">
              <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
            </NcButton>
          </div>
        </div>
        <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
        <NcNoteCard v-if="message" type="success" role="status">{{ message }}</NcNoteCard>

        <component :is="SpacesView" v-if="!space && readyView==='spaces'" />
        <NcEmptyContent v-else-if="!space" :name="t('librefinance', 'Start organizing your finances')"
          :description="t('librefinance', 'Activate an authorized SaaS environment, create a personal workspace, or restore an archived workspace. Group administration does not grant financial access.')">
          <template #action>
            <NcButton variant="primary" @click="navigate('spaces')">
              {{ t('librefinance', 'Manage or create workspaces') }}
            </NcButton>
          </template>
        </NcEmptyContent>
        <div v-else-if="modulesLoadedFor !== Number(activeId)" class="lf-loading">
          <NcLoadingIcon :size="44" />
          <p>{{ t('librefinance', 'Loading financial data…') }}</p>
        </div>
        <SettingsView v-else-if="settingPanels.includes(readyView)"
          :key="activeId + ':' + readyView" :section-key="readyView" />
        <div v-else-if="readyView === 'data' && !migrationAvailable" class="lf-view">
          <NcEmptyContent :name="t('librefinance', 'Import unavailable')"
            :description="t('librefinance', 'Enable the import module in Installed features to migrate data from another solution.')">
            <template #action>
              <NcButton variant="primary" @click="navigate('modules')">{{ t('librefinance', 'View installed features') }}</NcButton>
            </template>
          </NcEmptyContent>
        </div>
        <component :is="currentScreen" v-else
          :key="activeId + ':' + readyView + ':' + urlSearchTerm"
          v-bind="readyView === 'bankAccounts' ? { bankOnly: true }
            : ['finance','sales','purchases','banking'].includes(readyView)
              ? { section: readyView } : {}" />
      </div>
    </NcAppContent>


  </NcContent>
</template>
