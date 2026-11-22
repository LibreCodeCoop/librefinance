<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import {
  mdiViewDashboardOutline, mdiBankOutline, mdiSwapHorizontal,
  mdiReceiptTextOutline, mdiCashClock, mdiChartLine, mdiAccountMultipleOutline,
  mdiFileUploadOutline, mdiCogOutline, mdiShapeOutline, mdiRobotOutline,
  mdiDomain, mdiFileDocumentOutline, mdiBookOpenPageVariantOutline,
  mdiPlus, mdiRefresh, mdiMagnify,
} from '@mdi/js'
import {
  PAGE_NAMES, availableSections, canOpenView, navigationSearch,
  menuEntries, parentOfView, defaultGroupOpen, canonicalView,
} from './navigation.js'
import { useFinanceWorkspace } from './state/useFinanceWorkspace.js'
import LfSelect from './components/LfSelect.vue'
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
import BankingView from './views/BankingView.vue'
import ObligationsView from './views/ObligationsView.vue'
import InvoicesView from './views/InvoicesView.vue'
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
  manager, writable, busy, error, message,
  spaceName, kind, createSpace, refreshCurrent,
} = workspace
const showSpaceDialog = ref(false)
const quickEntry = ref(false)
provide('lfEntryDialog', quickEntry)
// The navigation follows user tasks, not the internal extension layout.
// Server-authorized module discovery is still the only source of availability.
const labels = PAGE_NAMES
const screens = {
  dashboard: DashboardView,
  transactions: TransactionsView,
  accounts: AccountsView,
  banking: BankingView,
  obligations: ObligationsView,
  sales: InvoicesView,
  purchases: InvoicesView,
  invoicing: InvoicesView, // existing bookmarks
  taxes: TaxRulesView,
  contacts: ContactsView,
  items: ItemsView,
  reports: ReportsView,
  companies: CompaniesView,
  categories: CategoriesView,
  import: ImportView,
  nfse: NfseView,
  ai: AiView,
  settings: SettingsView,
}
const icons = {
  home: mdiViewDashboardOutline,
  transactions: mdiSwapHorizontal,
  banking: mdiBankOutline,
  obligations: mdiCashClock,
  reports: mdiChartLine,
  billing: mdiReceiptTextOutline,
  fiscal: mdiFileDocumentOutline,
  contacts: mdiAccountMultipleOutline,
  categories: mdiShapeOutline,
  accounts: mdiBookOpenPageVariantOutline,
  companies: mdiDomain,
  assistant: mdiRobotOutline,
  settings: mdiCogOutline,
  import: mdiFileUploadOutline,
}
const searchQuery = ref('')
const openGroups = ref({})
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
  if (view !== 'settings') url.searchParams.delete('section')
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
const roleNames = { owner: 'Proprietário', manager: 'Gestor', operator: 'Operador',
  auditor: 'Auditor', viewer: 'Visualizador' }
const spaceKind = computed(() => ({ personal: 'Pessoal', collective: 'Coletivo',
  organization: 'Organização' })[space.value?.kind] || 'Espaço financeiro')
const spaceRole = computed(() => roleNames[space.value?.role] || 'Participante')
const selectedSpace = computed(() => spaces.value.find(s =>
  Number(s.id) === Number(activeId.value))?.name ?? 'Selecione um espaço')
const spaceOptions = computed(() => spaces.value.map(s => ({
  value: Number(s.id), label: s.name,
})))
const readyView = computed(() => (
  visibleView(activeTab.value)
    ? canonicalView(activeTab.value)
    : 'dashboard'
))
const currentScreen = computed(() => screens[readyView.value] || DashboardView)
// Shared commercial screen receives its context from the authorized route,
// never from a self-supplied document ID or an untrusted URL.
const invoiceKind = computed(() => readyView.value === 'purchases' ? 'bill' : 'invoice')
provide('lfInvoiceKind', invoiceKind)
const focusedInvoice = ref(null)
provide('lfFocusedInvoice', focusedInvoice)
const focusedObligation = ref(null)
provide('lfFocusedObligation', focusedObligation)
const activeLocation = computed(() => {
  if (readyView.value === 'settings' || readyView.value === 'import') return 'Administração'
  const match = menuEntries(availableNav.value).find(item => item.id === readyView.value)
  return match?.parent || match?.section || 'LibreFinance'
})

let pendingInitialView = new URLSearchParams(window.location.search).get('view')
function expandParent(view) {
  const id = parentOfView(availableNav.value, view)
  if (id !== null) setGroupOpen(id, true)
}
function navigate(view) {
  if (!screens[view] || !visibleView(view)) return
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
  if (view !== 'settings') url.searchParams.delete('section')
  if (activeId.value) url.searchParams.set('spaceId', String(activeId.value))
  window.history.pushState(null, '', url.pathname + url.search)
}
provide('lfNavigate', navigate)
function selectSpace(id) {
  if (!spaces.value.some(s => Number(s.id) === Number(id))) return
  activeId.value = Number(id)
  focusedInvoice.value = null
  focusedObligation.value = null
  clearSearch()
  openGroups.value = {}
  pendingInitialView = null
  activeTab.value = 'dashboard'
  const url = new URL(window.location.href)
  url.searchParams.set('spaceId', String(Number(id)))
  url.searchParams.set('view', 'dashboard')
  window.history.pushState(null, '', url.pathname + url.search)
}
function openEntry() {
  if (!writable.value || !activeId.value) return
  navigate('transactions')
  quickEntry.value = true
}
async function saveSpace(event) {
  event?.preventDefault()
  if (!spaceName.value.trim()) return
  await createSpace()
  if (!error.value) {
    showSpaceDialog.value = false
    // The selected space and first-use guidance already confirm creation.
    // Avoid stacking a large success banner above the dashboard.
    message.value = ''
  }
}
function onHistoryChange() {
  const values = new URLSearchParams(window.location.search)
  const destinationSpace = Number(values.get('spaceId'))
  const destinationView = values.get('view')
  if (Number.isSafeInteger(destinationSpace) && spaces.value.some(s =>
    Number(s.id) === destinationSpace)) {
    activeId.value = destinationSpace
  }
  pendingInitialView = destinationView
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
    <NcAppNavigation aria-label="Navegação do LibreFinance">
      <div class="lf-navigation-context">
        <div class="lf-product-lockup">
          <span class="lf-product-symbol" aria-hidden="true"><NcIconSvgWrapper :path="mdiChartLine" :size="22" /></span>
          <div><p class="lf-product-name">LibreFinance</p>
            <p class="lf-muted">Finanças pessoais e de organizações</p></div>
        </div>
        <LfSelect id="lf-space-select" :model-value="activeId"
          :options="spaceOptions" label="Gerenciar finanças de"
          placeholder="Selecione sua gestão financeira" @update:model-value="selectSpace" />
        <p v-if="space" class="lf-space-meta">
          <span>{{ spaceKind }}</span><span aria-hidden="true">·</span>
          <span>{{ space.currency }}</span><span aria-hidden="true">·</span>
          <span>{{ spaceRole }}</span>
        </p>
        <div v-if="space" class="lf-menu-search" role="search">
          <NcIconSvgWrapper :path="mdiMagnify" :size="19" aria-hidden="true" />
          <label for="lf-menu-search" class="lf-visually-hidden">Buscar uma função do LibreFinance</label>
          <input id="lf-menu-search" v-model="searchQuery" type="search"
            autocomplete="off" spellcheck="false" maxlength="90"
            aria-label="Buscar no menu" placeholder="Buscar uma função…"
            @keydown="searchKeydown" />
        </div>
      </div>
      <template #list>
        <NcAppNavigationList v-if="space" class="lf-nav-list">
          <template v-if="isSearching">
            <li class="lf-nav-section"><span>RESULTADOS DA BUSCA</span></li>
            <NcAppNavigationItem v-for="entry in searchResults" :key="entry.id"
              :name="labels[entry.id]" :title="entry.parent || entry.section"
              :href="viewHref(entry.id)" :active="readyView === entry.id"
              @click="onNavClick($event, entry.id)">
              <template #icon><NcIconSvgWrapper :path="icons[entry.icon]" :size="20" /></template>
            </NcAppNavigationItem>
            <li v-if="!searchResults.length" class="lf-menu-empty" role="status">
              <strong>Nenhuma função encontrada</strong>
              <span>Tente outro termo. Somente recursos disponíveis neste espaço são exibidos.</span>
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
                  <NcAppNavigationItem v-for="child in item.children" :key="child.id"
                    :name="labels[child.id]" :href="viewHref(child.id)"
                    :active="readyView === child.id" @click="onNavClick($event, child.id)">
                    <template #icon><NcIconSvgWrapper :path="icons[child.icon]" :size="18" /></template>
                  </NcAppNavigationItem>
                </NcAppNavigationItem>
                <NcAppNavigationItem v-else :name="labels[item.id]" :href="viewHref(item.id)"
                  :active="readyView === item.id" @click="onNavClick($event, item.id)">
                  <template #icon><NcIconSvgWrapper :path="icons[item.icon]" :size="20" /></template>
                </NcAppNavigationItem>
              </template>
            </template>
          </template>
        </NcAppNavigationList>
      </template>
      <template #footer>
        <div class="lf-nav-footer lf-navigation-tools">
          <button v-if="manager" class="lf-footer-nav-button" type="button"
            :class="{ 'lf-footer-nav-button--active': readyView === 'settings' || readyView === 'import' }"
            :aria-current="readyView === 'settings' || readyView === 'import' ? 'page' : undefined"
            @click="navigate('settings')">
            <NcIconSvgWrapper :path="mdiCogOutline" :size="20" aria-hidden="true" />
            <span>Configurações</span>
          </button>
          <button type="button" class="lf-footer-nav-button" @click="showSpaceDialog = true">
            <NcIconSvgWrapper :path="mdiPlus" :size="20" aria-hidden="true" />
            <span>Nova gestão financeira</span>
          </button>
        </div>
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
            <NcButton v-if="writable && readyView !== 'transactions'" variant="primary" @click="openEntry">
              <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
              Novo lançamento
            </NcButton>
            <NcButton variant="tertiary" aria-label="Atualizar dados"
              :disabled="busy" @click="refreshCurrent">
              <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
            </NcButton>
          </div>
        </div>
        <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
        <NcNoteCard v-if="message" type="success" role="status">{{ message }}</NcNoteCard>

        <NcEmptyContent v-if="!space" name="Comece a organizar suas finanças"
          description="Gerencie suas finanças pessoais ou as de um coletivo ou organização, com os acessos separados.">
          <template #action>
            <NcButton variant="primary" @click="showSpaceDialog = true">
              Criar gestão financeira
            </NcButton>
          </template>
        </NcEmptyContent>
        <div v-else-if="modulesLoadedFor !== Number(activeId)" class="lf-loading">
          <NcLoadingIcon :size="44" />
          <p>Carregando dados financeiros…</p>
        </div>
        <component :is="currentScreen" v-else :key="activeId + ':' + readyView" />
      </div>
    </NcAppContent>

    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="showSpaceDialog" name="Criar gestão financeira"
      @update:open="showSpaceDialog = $event">
      <form class="lf-dialog-form" @submit.prevent="saveSpace">
        <p>Crie uma gestão para suas finanças pessoais, de um grupo ou organização. Cada gestão tem contas, lançamentos e permissões independentes.</p>
        <NcTextField v-model="spaceName" label="Nome desta gestão financeira" :required="true"
          maxlength="150" placeholder="Ex.: Minhas finanças" />
        <LfSelect id="lf-new-space-kind" v-model="kind" label="Quem vai administrar estas finanças?"
          :options="[
            { value: 'personal', label: 'Pessoal' },
            { value: 'collective', label: 'Coletivo' },
            { value: 'organization', label: 'Organização' },
          ]" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="showSpaceDialog = false">Cancelar</NcButton>
          <NcButton type="submit" variant="primary" :disabled="busy || !spaceName.trim()">
            Criar gestão
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </NcContent>
</template>
