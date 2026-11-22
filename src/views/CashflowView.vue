<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, onBeforeUnmount, provide, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowDownLeft, mdiArrowUpRight, mdiFileDocumentOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import ObligationsView from './ObligationsView.vue'
import InvoicesView from './InvoicesView.vue'

const props = defineProps({ section: { type: String, default: 'finance' } })
const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const routeHint = inject('lfFinanceDirectionHint', ref('all'))
const focusedInvoice = inject('lfFocusedInvoice')
const focusedObligation = inject('lfFocusedObligation')
const newDetailed = inject('lfNewDetailed', ref(false))
const isDocuments = computed(() => props.section === 'documents')
const direction = ref(['receivable','payable'].includes(routeHint.value) ? routeHint.value : 'all')
const chooseOperation = ref('')
const newSimple = ref(false)
const invoiceKind = computed(() => direction.value === 'payable' ? 'bill' : 'invoice')
provide('lfInvoiceKind', invoiceKind)
const canSeeInvoices = computed(() =>
  w.modulesLoadedFor.value === Number(w.activeId.value)
  && w.modules.value.some(m => m.id === 'librefinance_invoicing' && m.enabled))
const canSeeObligations = computed(() =>
  w.modulesLoadedFor.value === Number(w.activeId.value)
  && w.modules.value.some(m => m.id === 'librefinance_obligations' && m.enabled))
const canSeeBanking = computed(() =>
  w.modulesLoadedFor.value === Number(w.activeId.value)
  && w.modules.value.some(m => m.id === 'librefinance_banking' && m.enabled))
const isReceivable = computed(() => chooseOperation.value === 'receivable')
const flow = {
  newSimple, newDetailed,
  showDocument(row) {
    routeHint.value = row?.kind === 'payable' ? 'payable' : 'receivable'
    navigate('documents', { preserveDirection: true })
  },
  showAccounts() { navigate('finance') },
  setDocumentDirection(kind) {
    if (['receivable','payable'].includes(kind)) direction.value = kind
  },
}
provide('lfFlowContext', flow)
async function begin(kind) {
  if (!['payable','receivable'].includes(kind)) return
  if (isDocuments.value) {
    direction.value = kind
    // Wait for the keyed sales/purchases editor to mount before opening it.
    await nextTick()
    newDetailed.value = true
  } else {
    chooseOperation.value = kind
  }
}
function newOperation(detail) {
  const kind = chooseOperation.value
  chooseOperation.value = ''
  if (!['payable','receivable'].includes(kind)) return
  if (detail === 'simple' && canSeeObligations.value) {
    direction.value = kind
    newSimple.value = true
  } else if (detail === 'detailed' && canSeeInvoices.value) {
    routeHint.value = kind
    newDetailed.value = true
    navigate('documents', { preserveDirection: true })
  }
}
watch(routeHint, kind => {
  direction.value = ['receivable','payable'].includes(kind) ? kind : 'all'
})
watch(w.activeId, () => {
  chooseOperation.value = ''
  newSimple.value = false
  newDetailed.value = false
  direction.value = 'all'
  if (focusedInvoice) focusedInvoice.value = null
  if (focusedObligation) focusedObligation.value = null
})
onBeforeUnmount(() => { chooseOperation.value = '' })
</script>

<template>
  <div class="lf-view lf-flow-view">
    <LfPageHeader :title="isDocuments ? 'Documentos comerciais' : 'A pagar e receber'"
      :eyebrow="isDocuments ? 'VENDAS E COMPRAS' : 'AGENDA FINANCEIRA'"
      :description="isDocuments
        ? 'Crie vendas ou compras com itens e impostos. Ao confirmar o documento, o título correspondente aparece na agenda financeira.'
        : 'Acompanhe compromissos a pagar e receber. Os valores são previstos até serem liquidados.'">
      <template #actions>
        <NcButton v-if="w.writable.value && (!isDocuments || canSeeInvoices)"
          variant="primary" class="lf-new-entry" @click="begin('receivable')">
          <template #icon><NcIconSvgWrapper :path="isDocuments ? mdiFileDocumentOutline : mdiArrowDownLeft" :size="20" /></template>
          {{isDocuments ? 'Nova venda' : 'Nova entrada'}}
        </NcButton>
        <NcButton v-if="w.writable.value && (!isDocuments || canSeeInvoices)"
          variant="secondary" class="lf-new-exit" @click="begin('payable')">
          <template #icon><NcIconSvgWrapper :path="isDocuments ? mdiFileDocumentOutline : mdiArrowUpRight" :size="20" /></template>
          {{isDocuments ? 'Nova compra' : 'Nova saída'}}
        </NcButton>
      </template>
    </LfPageHeader>

    <p v-if="!isDocuments" class="lf-muted lf-flow-explainer">
      Títulos e vencimentos; não é o extrato bancário.
      <NcButton v-if="canSeeBanking" variant="tertiary" size="small"
        @click="navigate('banking')">Ver Bancos e caixa</NcButton>
    </p>
    <ObligationsView v-if="!isDocuments && canSeeObligations"
      :kind="direction" :embedded="true" />
    <InvoicesView v-else-if="isDocuments && canSeeInvoices"
      :key="direction" :embedded="true" />
    <NcNoteCard v-else type="warning">
      O recurso necessário não está habilitado nesta área financeira.
    </NcNoteCard>

    <NcDialog :open="Boolean(chooseOperation)" size="normal"
      content-classes="lf-finance-dialog-content"
      :name="isReceivable ? 'Nova entrada (a receber)' : 'Nova saída (a pagar)'"
      @update:open="chooseOperation=$event?chooseOperation:''">
      <div class="lf-dialog-form">
        <h3>{{isReceivable ? 'Como deseja registrar a entrada?' : 'Como deseja registrar a saída?'}}</h3>
        <p>Uma cobrança ou conta simples cria um título nesta agenda.
          Um documento com itens e impostos é criado na página de documentos comerciais
          e gera o título automaticamente ao ser confirmado.</p>
        <NcButton v-if="canSeeObligations" variant="secondary" block
          @click="newOperation('simple')">
          {{isReceivable?'Cobrança simples':'Conta simples'}}
        </NcButton>
        <NcButton v-if="canSeeInvoices" variant="secondary" block
          @click="newOperation('detailed')">
          {{isReceivable?'Venda com itens e impostos':'Compra com itens e impostos'}}
        </NcButton>
        <NcButton variant="tertiary" @click="chooseOperation=''">Cancelar</NcButton>
      </div>
    </NcDialog>
  </div>
</template>
