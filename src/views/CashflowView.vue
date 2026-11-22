<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfPageHeader from '../components/LfPageHeader.vue'
import ObligationsView from './ObligationsView.vue'
import InvoicesView from './InvoicesView.vue'
import { canonicalView } from '../navigation.js'

const w = inject('lfWorkspace')
const focusedInvoice = inject('lfFocusedInvoice')
const focusedObligation = inject('lfFocusedObligation')
const kind = computed(() => canonicalView(w.activeTab.value) === 'payables'
  ? 'payable' : 'receivable')
const invoiceKind = computed(() => kind.value === 'payable' ? 'bill' : 'invoice')
provide('lfInvoiceKind', invoiceKind)

const mode = ref(['sales', 'invoicing', 'purchases'].includes(w.activeTab.value)
  ? 'documents' : 'accounts')
const chooseOperation = ref(false)
const newSimple = ref(false)
const newDetailed = ref(false)
const canSeeInvoices = computed(() =>
  w.modulesLoadedFor.value === Number(w.activeId.value)
  && w.modules.value.some(m => m.id === 'librefinance_invoicing' && m.enabled))
const canSeeObligations = computed(() =>
  w.modulesLoadedFor.value === Number(w.activeId.value)
  && w.modules.value.some(m => m.id === 'librefinance_obligations' && m.enabled))
const isReceivable = computed(() => kind.value === 'receivable')
const heading = computed(() => isReceivable.value ? 'A receber' : 'A pagar')

const flow = {
  mode,
  kind,
  newSimple,
  newDetailed,
  showDocument() { mode.value = 'documents' },
  showAccounts() { mode.value = 'accounts' },
}
provide('lfFlowContext', flow)

function newOperation(detail) {
  chooseOperation.value = false
  if (detail === 'simple' && canSeeObligations.value) {
    mode.value = 'accounts'
    newSimple.value = true
  } else if (detail === 'detailed' && canSeeInvoices.value) {
    mode.value = 'documents'
    newDetailed.value = true
  }
}
watch([w.activeId, kind], () => {
  chooseOperation.value = false
  newSimple.value = false
  newDetailed.value = false
  mode.value = 'accounts'
  // No dialog or linked record from the previous company may be reopened.
  if (focusedInvoice) focusedInvoice.value = null
  if (focusedObligation) focusedObligation.value = null
})
onBeforeUnmount(() => { chooseOperation.value = false })
</script>

<template>
  <div class="lf-view lf-flow-view">
    <LfPageHeader :title="heading" eyebrow="GESTÃO FINANCEIRA"
      :description="isReceivable
        ? 'Acompanhe cobranças, vendas, vencimentos e recebimentos em um só lugar.'
        : 'Acompanhe compromissos, compras, vencimentos e pagamentos em um só lugar.'">
      <template #actions>
        <NcButton v-if="w.writable.value" variant="primary" @click="chooseOperation = true">
          {{isReceivable ? 'Nova cobrança' : 'Nova conta'}}
        </NcButton>
      </template>
    </LfPageHeader>

    <div class="lf-flow-tabs" role="group" aria-label="Tipo de visualização">
      <NcButton :variant="mode === 'accounts' ? 'primary' : 'tertiary'"
        :aria-pressed="mode === 'accounts'"
        @click="mode = 'accounts'">
        {{ isReceivable ? 'Valores a receber' : 'Valores a pagar' }}
      </NcButton>
      <NcButton v-if="canSeeInvoices"
        :variant="mode === 'documents' ? 'primary' : 'tertiary'"
        :aria-pressed="mode === 'documents'"
        @click="mode = 'documents'">
        {{ isReceivable ? 'Vendas detalhadas e rascunhos' : 'Compras detalhadas e rascunhos' }}
      </NcButton>
    </div>

    <NcNoteCard type="info">
      {{ isReceivable
        ? 'Uma venda confirmada aparece automaticamente nos valores a receber. Registre diretamente uma cobrança somente quando não houver documento de venda.'
        : 'Uma compra confirmada aparece automaticamente nos valores a pagar. Registre diretamente um compromisso somente quando não houver documento de compra.' }}
    </NcNoteCard>
    <ObligationsView v-if="mode === 'accounts' && canSeeObligations"
      :kind="kind" :embedded="true" />
    <InvoicesView v-else-if="mode === 'documents' && canSeeInvoices"
      :embedded="true" />
    <NcNoteCard v-else type="warning">
      O recurso necessário não está habilitado neste espaço. Consulte o gestor.
    </NcNoteCard>

    <NcDialog :open="chooseOperation" size="normal" content-classes="lf-finance-dialog-content"
      :name="isReceivable ? 'Nova cobrança' : 'Nova conta'"
      @update:open="chooseOperation = $event">
      <div class="lf-dialog-form">
        <h3>Como deseja registrar?</h3>
        <p>Escolha apenas uma opção. O sistema não criará dois compromissos para
          representar a mesma operação.</p>
        <NcButton v-if="canSeeObligations" block variant="secondary"
          @click="newOperation('simple')">
          {{ isReceivable ? 'Cobrança simples' : 'Conta simples' }}
        </NcButton>
        <p class="lf-muted">Somente descrição, valor e vencimento. Não gera documento comercial.</p>
        <NcButton v-if="canSeeInvoices" block variant="secondary"
          @click="newOperation('detailed')">
          {{ isReceivable ? 'Venda com itens e impostos' : 'Compra com itens e impostos' }}
        </NcButton>
        <p class="lf-muted">Cria um documento comercial. Quando confirmado, o valor
          a receber ou pagar é criado automaticamente e fica vinculado.</p>
        <NcButton variant="tertiary" @click="chooseOperation = false">Cancelar</NcButton>
      </div>
    </NcDialog>
  </div>
</template>
