<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import {
  mdiPlus, mdiFileUploadOutline, mdiCashClock, mdiBankOutline, mdiReceiptTextOutline,
} from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import ObligationsView from './ObligationsView.vue'
import InvoicesView from './InvoicesView.vue'
import BankingView from './BankingView.vue'

const props = defineProps({
  // Preserve existing deep links: finance, documents and banking remain valid URLs.
  section: { type: String, default: 'finance' },
})
const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const routeHint = inject('lfFinanceDirectionHint', ref('all'))
const focusedInvoice = inject('lfFocusedInvoice')
const focusedObligation = inject('lfFocusedObligation')
const newDetailed = inject('lfNewDetailed', ref(false))
const importRequest = inject('lfBankImportRequest', ref(0))
const newMenuShown = ref(false)
const newSimple = inject('lfNewSimple', ref(false))
const direction = ref(['payable', 'receivable'].includes(routeHint.value) ? routeHint.value : 'all')
const invoiceKind = computed(() => direction.value === 'payable' ? 'bill' : 'invoice')
provide('lfInvoiceKind', invoiceKind)

function hasModule(id) {
  return w.modulesLoadedFor.value === Number(w.activeId.value)
    && w.modules.value.some(module => module.id === id && module.enabled)
}
const canSeeObligations = computed(() => hasModule('librefinance_obligations'))
const canSeeInvoices = computed(() => hasModule('librefinance_invoicing'))
const canSeeBanking = computed(() => hasModule('librefinance_banking'))
const panels = computed(() => [
  { id: 'finance', label: 'A pagar e receber', hint: 'Compromissos', icon: mdiCashClock, enabled: canSeeObligations.value },
  { id: 'banking', label: 'Conferir extrato', hint: 'Importação e conciliação', icon: mdiBankOutline, enabled: canSeeBanking.value },
  { id: 'documents', label: 'Faturas', hint: 'Vendas e compras', icon: mdiReceiptTextOutline, enabled: canSeeInvoices.value },
].filter(item => item.enabled))
const selectedPanel = computed(() => panels.value.some(item => item.id === props.section)
  ? props.section : (panels.value[0]?.id ?? 'finance'))

const flow = {
  newSimple,
  newDetailed,
  showDocument(row) {
    routeHint.value = row?.kind === 'payable' ? 'payable' : 'receivable'
    navigate('documents', { preserveDirection: true })
  },
  showAccounts() {
    navigate('finance')
  },
  setDocumentDirection(kind) {
    if (['payable', 'receivable'].includes(kind)) {
      routeHint.value = kind
      direction.value = kind
    }
  },
}
provide('lfFlowContext', flow)

function selectPanel(id) {
  if (!panels.value.some(item => item.id === id)) return
  newMenuShown.value = false
  if (id !== props.section) navigate(id)
}
function startSimple(kind) {
  if (!w.writable.value || !canSeeObligations.value) return
  newMenuShown.value = false
  routeHint.value = kind
  direction.value = kind
  newSimple.value = true
  if (props.section !== 'finance') navigate('finance', { preserveDirection: true })
}
function startInvoice(kind) {
  if (!w.writable.value || !canSeeInvoices.value) return
  newMenuShown.value = false
  routeHint.value = kind === 'bill' ? 'payable' : 'receivable'
  direction.value = routeHint.value
  newDetailed.value = true
  if (props.section !== 'documents') navigate('documents', { preserveDirection: true })
}
function startImport() {
  if (!w.writable.value || !canSeeBanking.value) return
  newMenuShown.value = false
  // BankingView consumes this request once. The shell owns the token so it
  // survives the route remount, and no import is attempted without an account.
  importRequest.value += 1
  if (props.section !== 'banking') navigate('banking')
}

watch(routeHint, kind => {
  direction.value = ['receivable', 'payable'].includes(kind) ? kind : 'all'
})
watch(w.activeId, () => {
  newMenuShown.value = false
  newSimple.value = false
  newDetailed.value = false
  direction.value = 'all'
  if (focusedInvoice) focusedInvoice.value = null
  if (focusedObligation) focusedObligation.value = null
})
onBeforeUnmount(() => { newMenuShown.value = false })
</script>

<template>
  <div class="lf-view lf-unified-finance">
    <LfPageHeader title="Financeiro" eyebrow="OPERAÇÕES"
      description="Acompanhe compromissos, registre faturas e confira movimentações bancárias em um só lugar.">
      <template #actions>
        <NcPopover v-if="w.writable.value && (canSeeObligations || canSeeInvoices)"
          v-model:shown="newMenuShown" popup-role="dialog">
          <template #trigger>
            <NcButton variant="primary" class="lf-finance-create">
              <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
              Novo
            </NcButton>
          </template>
          <div class="lf-finance-action-menu" role="group" aria-label="Registrar no Financeiro">
            <p v-if="canSeeObligations" class="lf-muted">Sem fatura</p>
            <NcButton v-if="canSeeObligations" variant="tertiary" block
              @click="startSimple('payable')">Conta a pagar</NcButton>
            <NcButton v-if="canSeeObligations" variant="tertiary" block
              @click="startSimple('receivable')">Cobrança a receber</NcButton>
            <p v-if="canSeeInvoices" class="lf-muted">Com itens e tributos</p>
            <NcButton v-if="canSeeInvoices" variant="tertiary" block
              @click="startInvoice('invoice')">Fatura de venda</NcButton>
            <NcButton v-if="canSeeInvoices" variant="tertiary" block
              @click="startInvoice('bill')">Fatura de compra</NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="w.writable.value && canSeeBanking" variant="secondary"
          class="lf-finance-import" @click="startImport">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          Importar extrato
        </NcButton>
      </template>
    </LfPageHeader>

    <div v-if="panels.length" class="lf-finance-panel-nav" role="tablist"
      aria-label="Etapas do financeiro">
      <NcButton v-for="panel in panels" :key="panel.id"
        role="tab" :aria-selected="selectedPanel === panel.id"
        :variant="selectedPanel === panel.id ? 'secondary' : 'tertiary'"
        :aria-controls="'lf-finance-panel-' + panel.id"
        @click="selectPanel(panel.id)">
        <template #icon><NcIconSvgWrapper :path="panel.icon" :size="20" /></template>
        {{ panel.label }}
      </NcButton>
    </div>
    <section v-if="panels.length" :id="'lf-finance-panel-' + selectedPanel"
      class="lf-finance-panel" role="tabpanel">
      <ObligationsView v-if="selectedPanel === 'finance'" :key="'pending-'+direction"
        :kind="direction" :embedded="true" />
      <InvoicesView v-else-if="selectedPanel === 'documents'"
        :key="'invoices-'+direction" :embedded="true" />
      <BankingView v-else-if="selectedPanel === 'banking'" :embedded="true" />
    </section>
    <NcEmptyContent v-else name="Nenhuma operação financeira disponível"
      description="Ative pelo menos um recurso financeiro em Gerenciar → Recursos instalados." />
  </div>
</template>
