<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { inject, nextTick, provide, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
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
  {value:'all', label:t('librefinance', 'All')}, {value:'invoice', label:t('librefinance', 'Sales')},
  {value:'bill', label:t('librefinance', 'Purchases')},
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
    <LfPageHeader :title="t('librefinance', 'Invoices')" :eyebrow="t('librefinance', 'FINANCE')"
      :description="t('librefinance', 'To itemize products or services, record a sale or purchase here. Amounts receivable or payable automatically appear in Financial movements.')">
      <template #actions>
        <NcPopover v-if="filterAvailable || filterKind !== 'all'"
          v-model:shown="filterOpen" popup-role="dialog">
          <template #trigger>
            <NcButton id="lf-invoices-type-filter" variant="tertiary"
              :aria-pressed="filterKind !== 'all'">
              <template #icon>
                <NcIconSvgWrapper :path="mdiFilterVariant" :size="20" />
              </template>
              {{ t('librefinance', 'Type: {type}', { type: filterOptions.find(option => option.value === filterKind)?.label }, { escape: false, sanitize: false }) }}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter invoices by type')">
            <NcButton v-for="option in filterOptions" :key="option.value"
              variant="tertiary" :aria-pressed="filterKind === option.value"
              @click="setFilter(option.value)">{{option.label}}</NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="w.writable.value" variant="primary" @click="start">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New invoice') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <InvoicesView :embedded="true" />
  </div>
</template>
