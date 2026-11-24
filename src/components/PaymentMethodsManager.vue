<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline, mdiDeleteOutline } from '@mdi/js'
import LfSortableHeader from './LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
const w = inject('lfWorkspace')
const { activeId, accounts, manager } = w
const kinds = [
  { value:'cash', label:t('librefinance', 'Cash') },
  { value:'bank_transfer', label:t('librefinance', 'Bank transfer') },
  { value:'credit_card', label:t('librefinance', 'Credit card') },
  { value:'debit_card', label:t('librefinance', 'Debit card') },
  { value:'pix', label:t('librefinance', 'Instant payment (e.g. Pix)') },
  { value:'digital_wallet', label:t('librefinance', 'Digital wallet or intermediary') },
  { value:'other', label:t('librefinance', 'Other') },
]
const bankAccounts = computed(() => [
  { value:0, label:t('librefinance', 'No mandatory link') },
  ...accounts.value.filter(a => a.active && a.is_bank)
    .map(a => ({ value:a.id, label:a.name })),
])
const methods = ref([])
const sort = useLoadedSort('name')
const orderedMethods = computed(() => sort.order(methods.value, {
  kind: method => kinds.find(kind => kind.value === method.kind)?.label || method.kind,
  bank: method => accounts.value.find(account => account.id === method.bank_account_id)?.name || '',
}))
const afterId = ref(null)
const loading = ref(false)
const problem = ref('')
const dialog = ref(false)
const removeTarget = ref(null)
const draft = ref({ id:0, name:'', kind:'cash', bankAccountId:0, active:true })
let sequence = 0
async function load(after = 0) {
  const token = ++sequence
  loading.value = true
  problem.value = ''
  try {
    const result = await financeApi('librefinance', Number(activeId.value),
      '/payment-methods' + (after ? '?afterId=' + after : ''))
    if (token !== sequence) return
    methods.value = after ? [...methods.value, ...result.methods] : result.methods
    afterId.value = result.nextAfterId
  } catch (e) { if (token === sequence) problem.value = financeError(e) }
  finally { if (token === sequence) loading.value = false }
}
watch(activeId, () => load())
onMounted(() => load())
function begin(method = null) {
  problem.value = ''
  draft.value = method ? { id:method.id, name:method.name, kind:method.kind,
    bankAccountId:method.bank_account_id || 0, active:method.active }
    : { id:0,name:'',kind:'cash',bankAccountId:0,active:true }
  dialog.value = true
}
async function save() {
  if (loading.value) return
  loading.value = true
  problem.value = ''
  try {
    const d = draft.value
    await financeApi('librefinance', Number(activeId.value),
      '/payment-methods' + (d.id ? '/' + d.id : ''), d.id ? 'PUT' : 'POST', {
        name:d.name, ...(d.id ? {} : { kind:d.kind }),
        bankAccountId:d.bankAccountId || null, active:d.active,
      })
    dialog.value = false
    loading.value = false
    await load()
  } catch(e) { problem.value = financeError(e); loading.value = false }
}
async function remove() {
  if (!removeTarget.value || loading.value) return
  loading.value = true
  problem.value = ''
  try {
    await financeApi('librefinance', Number(activeId.value),
      '/payment-methods/' + removeTarget.value.id, 'DELETE')
    removeTarget.value = null
    loading.value = false
    await load()
  } catch(e) { problem.value = financeError(e); loading.value = false }
}
</script>
<template>
  <div class="lf-payment-settings">
    <div class="lf-section-heading lf-payment-toolbar">
      <p v-if="methods.length" class="lf-muted">
        {{ t('librefinance', 'Methods used to classify entries. They do not represent provider integrations.') }}
      </p>
      <NcButton v-if="manager" variant="primary" :disabled="loading" @click="begin()">
        <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
        {{ t('librefinance', 'New payment method') }}
      </NcButton>
    </div>
    <NcNoteCard v-if="methods.length" type="info">
      {{ t('librefinance', 'Registered payment methods do not move money or enable automatic integration with banks, cards or providers.') }}
    </NcNoteCard>
    <NcNoteCard v-if="problem && !dialog" type="error" role="alert">{{ problem }}</NcNoteCard>
    <div v-if="loading && !methods.length" class="lf-loading" role="status">
      <NcLoadingIcon :size="36" /> {{ t('librefinance', 'Loading payment methods…') }}
    </div>
    <NcEmptyContent v-else-if="!methods.length" :name="t('librefinance', 'No payment methods registered')"
      :description="t('librefinance', 'Use New payment method to register a payment or receipt method.')" />
    <div v-else class="lf-table-scroll">
      <table class="lf-data-table">
        <thead><tr>
          <th v-for="col in [
            {key:'name',label:t('librefinance', 'Name')}, {key:'kind',label:t('librefinance', 'Type')},
            {key:'bank',label:t('librefinance', 'Associated account')}, {key:'active',label:t('librefinance', 'Status')}]"
            :key="col.key" :aria-sort="sort.ariaSort(col.key)">
            <LfSortableHeader :label="col.label" :column="col.key"
              :active-column="sort.column.value" :direction="sort.direction.value"
              scope="loaded" @sort="sort.sort" />
          </th>
          <th v-if="manager">{{ t('librefinance', 'Actions') }}</th></tr></thead>
        <tbody><tr v-for="method in orderedMethods" :key="method.id">
          <td><strong>{{ method.name }}</strong></td>
          <td>{{ kinds.find(k => k.value === method.kind)?.label }}</td>
          <td>{{ accounts.find(a => a.id === method.bank_account_id)?.name || t('librefinance', 'None') }}</td>
          <td>{{ method.active ? t('librefinance', 'Enabled payment method') : t('librefinance', 'Disabled payment method') }}</td>
          <td v-if="manager">
            <NcActions :aria-label="t('librefinance', 'Actions for payment method {name}', { name: method.name }, { escape: false, sanitize: false })" :force-menu="true">
              <NcActionButton close-after-click @click="begin(method)">
                <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
                {{ t('librefinance', 'Edit') }}
              </NcActionButton>
              <NcActionButton close-after-click @click="problem=''; removeTarget=method">
                <template #icon><NcIconSvgWrapper :path="mdiDeleteOutline" :size="20" /></template>
                {{ t('librefinance', 'Delete') }}
              </NcActionButton>
            </NcActions>
          </td>
        </tr></tbody>
      </table>
    </div>
    <p v-if="methods.length && afterId && sort.column.value" class="lf-muted">
      {{ t('librefinance', 'Sorting applies only to loaded records.') }}
    </p>
    <NcButton v-if="methods.length && afterId" variant="tertiary" :disabled="loading"
      @click="load(afterId)">{{ t('librefinance', 'Load more payment methods') }}</NcButton>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="draft.id ? t('librefinance', 'Edit payment method') : t('librefinance', 'Create payment method')"
      @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="draft.name" :label="t('librefinance', 'Payment method name')"
          maxlength="120" required :placeholder="t('librefinance', 'E.g. company card')" />
        <LfSelect id="lf-payment-kind" v-model="draft.kind" :label="t('librefinance', 'Payment type')"
          :options="kinds" :disabled="Boolean(draft.id)" />
        <LfSelect id="lf-payment-bank" v-model="draft.bankAccountId"
          :label="t('librefinance', 'Linked financial account (optional)')" :options="bankAccounts" />
        <NcCheckboxRadioSwitch v-if="draft.id" v-model="draft.active" type="checkbox">
          {{ t('librefinance', 'Allow use in new entries') }}
        </NcCheckboxRadioSwitch>
        <NcNoteCard type="info">
          {{ t('librefinance', 'Existing method types and links are not changed after use. Deactivate a method with history to preserve entries.') }}
        </NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="dialog=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading">{{ t('librefinance', 'Save payment method') }}</NcButton></div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content"
      :open="Boolean(removeTarget)" :name="t('librefinance', 'Delete payment method')"
      @update:open="!$event && (removeTarget=null)">
      <div class="lf-dialog-form">
        <NcNoteCard type="warning">
          {{ t('librefinance', 'Only unused methods can be deleted. If entries exist, deletion is blocked; you can deactivate the method.') }}
        </NcNoteCard>
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <p>{{ t('librefinance', 'Delete payment method {name}?', { name: removeTarget?.name }, { escape: false, sanitize: false }) }}</p>
        <div class="lf-form-actions"><NcButton variant="tertiary"
          @click="removeTarget=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="error" :disabled="loading" @click="remove">{{ t('librefinance', 'Remove payment method') }}</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
