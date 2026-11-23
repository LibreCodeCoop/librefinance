<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { inject, nextTick, provide, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFilterVariant } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import InvoicesView from './InvoicesView.vue'

const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const hint = inject('lfFinanceDirectionHint', ref('all'))
const newDetailed = inject('lfNewDetailed', ref(false))
// Filter and creation kind are independent: selecting a purchase in the list
// must not silently turn the next new document into a purchase.
const filterKind = ref(hint.value === 'payable' ? 'bill'
  : hint.value === 'receivable' ? 'invoice' : 'all')
const filterAvailable = ref(false)
const filterOpen = ref(false)
const filterOptions = [
  {value:'all', label:'Todas'}, {value:'invoice', label:'Vendas'},
  {value:'bill', label:'Compras'},
]
provide('lfInvoiceFilter', filterKind)
provide('lfInvoiceFilterAvailable', filterAvailable)
provide('lfFlowContext', {
  newDetailed,
  showAccounts() { navigate('finance') },
})
function setFilter(value) {
  if (!filterOptions.some(option => option.value === value)) return
  filterKind.value = value
  filterOpen.value = false
}
watch(w.activeId, () => {
  filterKind.value = 'all'
  filterAvailable.value = false
  filterOpen.value = false
  newDetailed.value = false
})
async function start() {
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
        <NcPopover v-if="filterAvailable || filterKind !== 'all'"
          v-model:shown="filterOpen" popup-role="dialog">
          <template #trigger>
            <NcButton id="lf-invoices-type-filter" variant="tertiary"
              :aria-pressed="filterKind !== 'all'">
              <template #icon>
                <NcIconSvgWrapper :path="mdiFilterVariant" :size="20" />
              </template>
              Tipo: {{filterOptions.find(option => option.value === filterKind)?.label}}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar faturas por tipo">
            <NcButton v-for="option in filterOptions" :key="option.value"
              variant="tertiary" :aria-pressed="filterKind === option.value"
              @click="setFilter(option.value)">{{option.label}}</NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="w.writable.value" variant="primary" @click="start">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Nova fatura
        </NcButton>
      </template>
    </LfPageHeader>
    <InvoicesView :embedded="true" />
  </div>
</template>
