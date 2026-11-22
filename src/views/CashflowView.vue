<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowDownLeft, mdiArrowUpRight } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import ObligationsView from './ObligationsView.vue'
import InvoicesView from './InvoicesView.vue'

const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const routeHint = inject('lfFinanceDirectionHint', ref('all'))
const focusedInvoice = inject('lfFocusedInvoice')
const focusedObligation = inject('lfFocusedObligation')

const direction = ref(routeHint.value)
const mode = ref(['sales', 'invoicing', 'purchases'].includes(w.activeTab.value)
  ? 'documents' : 'accounts')
const chooseOperation = ref('')
const newSimple = ref(false)
const newDetailed = ref(false)
const directionOptions = [
  {value:'all',label:'Entradas e saídas'},
  {value:'receivable',label:'Somente a receber'},
  {value:'payable',label:'Somente a pagar'},
]
const documentOptions = [
  {value:'receivable',label:'Vendas'},
  {value:'payable',label:'Compras'},
]
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
  mode, kind: direction, newSimple, newDetailed,
  showDocument(row) {
    if (row?.kind === 'payable' || row?.kind === 'receivable') {
      direction.value = row.kind
    }
    mode.value = 'documents'
  },
  showAccounts() { mode.value = 'accounts' },
}
provide('lfFlowContext', flow)

function begin(kind) {
  chooseOperation.value = kind
}
function newOperation(detail) {
  const kind = chooseOperation.value
  chooseOperation.value = ''
  if (!['payable','receivable'].includes(kind)) return
  direction.value = kind
  if (detail === 'simple' && canSeeObligations.value) {
    mode.value = 'accounts'
    newSimple.value = true
  } else if (detail === 'detailed' && canSeeInvoices.value) {
    mode.value = 'documents'
    newDetailed.value = true
  }
}
function showDocuments() {
  if (direction.value === 'all') direction.value = 'receivable'
  mode.value = 'documents'
}
watch(routeHint, kind => {
  direction.value = ['receivable', 'payable'].includes(kind) ? kind : 'all'
})
watch(w.activeId, () => {
  chooseOperation.value = ''
  newSimple.value = false
  newDetailed.value = false
  mode.value = 'accounts'
  direction.value = routeHint.value
  if (focusedInvoice) focusedInvoice.value = null
  if (focusedObligation) focusedObligation.value = null
})
onBeforeUnmount(() => { chooseOperation.value = '' })
</script>

<template>
  <div class="lf-view lf-flow-view">
    <LfPageHeader title="A pagar e receber" eyebrow="AGENDA FINANCEIRA"
      description="Entradas e saídas previstas na mesma lista, com filtros por direção e situação.">
      <template #actions>
        <NcButton v-if="w.writable.value" variant="primary" class="lf-new-entry"
          title="Entrada prevista: um valor a receber, sem movimentar o banco"
          @click="begin('receivable')">
          <template #icon><NcIconSvgWrapper :path="mdiArrowDownLeft" :size="20" aria-hidden="true" /></template>
          Nova entrada
        </NcButton>
        <NcButton v-if="w.writable.value" variant="secondary" class="lf-new-exit"
          title="Saída prevista: um valor a pagar, sem movimentar o banco"
          @click="begin('payable')">
          <template #icon><NcIconSvgWrapper :path="mdiArrowUpRight" :size="20" aria-hidden="true" /></template>
          Nova saída
        </NcButton>
      </template>
    </LfPageHeader>

    <div class="lf-flow-toolbar">
      <div class="lf-flow-tabs" role="group" aria-label="Visualização financeira">
        <NcButton :variant="mode === 'accounts' ? 'primary' : 'tertiary'"
          :aria-pressed="mode === 'accounts'" @click="mode='accounts'">Agenda</NcButton>
        <NcButton v-if="canSeeInvoices"
          :variant="mode === 'documents' ? 'primary' : 'tertiary'"
          :aria-pressed="mode === 'documents'" @click="showDocuments">
          Documentos comerciais
        </NcButton>
      </div>
      <LfSelect v-if="mode === 'accounts'" id="lf-finance-direction"
        v-model="direction" label="Direção" :options="directionOptions" />
      <LfSelect v-else id="lf-commercial-direction"
        v-model="direction" label="Documentos" :options="documentOptions" />
    </div>

    <p class="lf-muted lf-flow-explainer">
      A agenda mostra compromissos previstos e suas baixas. Não equivale ao
      extrato de dinheiro efetivamente movimentado.
      <NcButton v-if="canSeeBanking" variant="tertiary" size="small"
        @click="navigate('banking')">Ver extrato de Bancos e caixa</NcButton>
    </p>
    <ObligationsView v-if="mode === 'accounts' && canSeeObligations"
      :kind="direction" :embedded="true" />
    <InvoicesView v-else-if="mode === 'documents' && canSeeInvoices"
      :key="direction" :embedded="true" />
    <NcNoteCard v-else type="warning">
      O recurso necessário não está habilitado neste espaço.
    </NcNoteCard>

    <NcDialog :open="Boolean(chooseOperation)" size="normal"
      content-classes="lf-finance-dialog-content"
      :name="isReceivable ? 'Nova entrada (a receber)' : 'Nova saída (a pagar)'"
      @update:open="chooseOperation=$event?chooseOperation:''">
      <div class="lf-dialog-form">
        <h3>{{isReceivable ? 'Como deseja registrar a entrada?' : 'Como deseja registrar a saída?'}}</h3>
        <p>Este registro é um valor {{isReceivable ? 'a receber' : 'a pagar'}}.
          Não movimenta dinheiro no banco. Se houver documento comercial detalhado,
          ele gera o compromisso automaticamente na confirmação.</p>
        <NcButton v-if="canSeeObligations" variant="secondary" block
          @click="newOperation('simple')">
          {{isReceivable?'Cobrança simples':'Conta simples'}}
        </NcButton>
        <p class="lf-muted">Descrição, valor e vencimento, sem documento comercial.</p>
        <NcButton v-if="canSeeInvoices" variant="secondary" block
          @click="newOperation('detailed')">
          {{isReceivable?'Venda com itens e impostos':'Compra com itens e impostos'}}
        </NcButton>
        <p class="lf-muted">Documento comercial que gera o compromisso quando confirmado.</p>
        <NcButton variant="tertiary" @click="chooseOperation=''">Cancelar</NcButton>
      </div>
    </NcDialog>
  </div>
</template>
