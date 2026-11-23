<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, provide, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import InvoicesView from './InvoicesView.vue'

const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const hint = inject('lfFinanceDirectionHint', ref('receivable'))
const newDetailed = inject('lfNewDetailed', ref(false))
const kind = computed(() => hint.value === 'payable' ? 'bill' : 'invoice')
const isPurchase = computed(() => kind.value === 'bill')
provide('lfInvoiceKind', kind)
provide('lfFlowContext', {
  newDetailed,
  showAccounts() { navigate('finance') },
  setDocumentDirection(direction) {
    if (direction === 'payable' || direction === 'receivable') hint.value = direction
  },
})
function choose(value) {
  if (newDetailed.value) newDetailed.value = false
  hint.value = value
}
async function start() {
  // A form may have been cancelled; a second click should create a fresh
  // request without navigating to a different CRUD screen.
  newDetailed.value = false
  await nextTick()
  newDetailed.value = true
}
</script>
<template>
  <div class="lf-view lf-documents-view">
    <LfPageHeader title="Faturas" eyebrow="FINANCEIRO"
      description="Se precisar discriminar produtos ou serviços, registre uma venda ou compra aqui. Os valores a receber ou pagar aparecem automaticamente em Movimentações.">
      <template #actions>
        <NcButton v-if="w.writable.value" variant="primary" @click="start">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{isPurchase?'Nova compra':'Nova venda'}}
        </NcButton>
      </template>
    </LfPageHeader>
    <div class="lf-document-direction" role="group" aria-label="Ver faturas de venda ou compra">
      <NcButton variant="tertiary" :aria-pressed="!isPurchase"
        @click="choose('receivable')">Vendas</NcButton>
      <NcButton variant="tertiary" :aria-pressed="isPurchase"
        @click="choose('payable')">Compras</NcButton>
    </div>
    <InvoicesView :key="'documents-'+kind" :embedded="true" />
  </div>
</template>
