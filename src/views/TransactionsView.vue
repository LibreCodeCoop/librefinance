<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiArrowUpRight, mdiArrowDownLeft } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import FieldStudioShortcut from '../components/FieldStudioShortcut.vue'
import LfMarkdownEditor from '../components/LfMarkdownEditor.vue'
import { formatLedgerDate } from '../date.js'
import { financeApi, financeError } from '../services/api.js'

const w = inject('lfWorkspace')
const dialog = inject('lfEntryDialog')
const journalOpenRequest = inject('lfJournalOpenRequest', ref(0))
const { writable, activeId, journals, accounts, categoryRecords,
  direction, bankId, categoryId, transactionCategoryId, amount, memo,
  date, busy, banks, categories, nextBeforeId, createEntry, loadMore,
  money, accountNameById, space } = w
const search = ref('')
const amountPlaceholder = computed(() => w.amountInput(0))
const filtered = computed(() => journals.value.filter(row => (
  (row.memo || '').toLowerCase().includes(search.value.trim().toLowerCase())
  || (row.effective_on || '').includes(search.value.trim())
  || formatLedgerDate(row.effective_on).includes(search.value.trim())
)))
const bankChoices = computed(() => banks.value.map(a => ({ value: a.id, label: a.name })))
const counterpartChoices = computed(() => categories.value.map(a =>
  ({ value: a.id, label: a.name + ' · ' + a.code })))
const categoryChoices = computed(() => [
  { value: 0, label: t('librefinance', 'No category') },
  ...categoryRecords.value.filter(c => c.kind === direction.value && c.active && c.effective_account_id)
    .map(c => ({ value: c.id, label: c.code + ' · ' + c.name })),
])
const entryError = ref('')
const entryFields = ref({})
const notesMarkdown = ref('')
const paymentMethods = ref([])
const paymentMethodId = ref(0)
const paymentError = ref('')
let paymentRequest = 0
async function loadPaymentMethods() {
  const token = ++paymentRequest
  paymentMethods.value = []
  paymentError.value = ''
  try {
    let afterId = 0
    for (let page = 0; page < 10; page++) {
      const response = await financeApi('librefinance', Number(activeId.value),
        '/payment-methods' + (afterId ? '?afterId=' + afterId : ''))
      if (token !== paymentRequest) return
      paymentMethods.value = [...paymentMethods.value, ...response.methods]
      afterId = response.nextAfterId
      if (!afterId) break
    }
  } catch (e) { if (token === paymentRequest) paymentError.value = financeError(e) }
}
onMounted(loadPaymentMethods)
watch(activeId, loadPaymentMethods)
const paymentChoices = computed(() => [
  { value:0, label:t('librefinance', 'Not specified') },
  ...paymentMethods.value.filter(m => m.active && (
    m.bank_account_id === null || Number(m.bank_account_id) === Number(bankId.value)))
    .map(m => ({ value:m.id, label:m.name })),
])
watch(bankId, () => {
  if (!paymentChoices.value.some(m => Number(m.value) === Number(paymentMethodId.value))) {
    paymentMethodId.value = 0
  }
})
const paymentLabel = id => paymentMethods.value.find(m => m.id === id)?.name || ''

const entryMinor = computed(() => {
  try { return w.parseMoney(amount.value, w.accountById(bankId.value), w.accountById(categoryId.value)) } catch { return null }
})
const selectedBank = computed(() => banks.value.find(item => Number(item.id) === Number(bankId.value)))
const selectedCounterpart = computed(() => counterpartChoices.value.find(item => Number(item.value) === Number(categoryId.value)))
const entrySummary = computed(() => {
  if (!entryMinor.value || !selectedBank.value || !selectedCounterpart.value) return ''
  return direction.value === 'expense'
    ? t('librefinance', 'Outflow of {amount} from account {account}. Classification: {classification}.', { amount: money(entryMinor.value), account: selectedBank.value.name, classification: selectedCounterpart.value.label }, { escape: false, sanitize: false })
    : t('librefinance', 'Inflow of {amount} into account {account}. Classification: {classification}.', { amount: money(entryMinor.value), account: selectedBank.value.name, classification: selectedCounterpart.value.label }, { escape: false, sanitize: false })
})
// Picking the only valid account is safe; guessing when there are multiple is not.
watch([dialog, counterpartChoices], ([opened, choices]) => {
  if (!opened) return
  entryError.value = ''
  if (!choices.some(item => Number(item.value) === Number(categoryId.value))) {
    categoryId.value = choices.length === 1 ? Number(choices[0].value) : 0
  } else if (!categoryId.value && choices.length === 1) {
    categoryId.value = Number(choices[0].value)
  }
})
watch([journalOpenRequest, w.activeTab], ([request, view]) => {
  if (!request || view !== 'transactions') return
  journalOpenRequest.value = 0
  if (!writable.value) return
  nextTick(() => {
    if (w.activeTab.value === 'transactions' && writable.value) {
      dialog.value = true
    }
  })
}, { immediate: true })
function closeDialog() { entryError.value = ''; dialog.value = false }
async function submit() {
  entryError.value = ''
  try { w.parseMoney(amount.value, w.accountById(bankId.value), w.accountById(categoryId.value)) } catch (cause) {
    entryError.value = cause.message
    return
  }
  if (!bankId.value || !categoryId.value || !date.value || !memo.value.trim()) {
    entryError.value = t('librefinance', 'Enter the financial account, income or expense account, date and description before continuing.')
    return
  }
  await createEntry({ customFields: entryFields.value, notesMarkdown: notesMarkdown.value,
    paymentMethodId: paymentMethodId.value || null })
  if (w.error.value) {
    entryError.value = w.error.value
  } else {
    entryFields.value = {}
    notesMarkdown.value = ''
    paymentMethodId.value = 0
    closeDialog()
  }
}
const formatted = row => money(Math.abs(row.postings?.[0]?.amountMinor || 0))
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="t('librefinance', 'Inflows and outflows')" :eyebrow="t('librefinance', 'FINANCE')"
      :description="t('librefinance', 'Record money already moved without a registered pending bill. For an existing payable or receivable, use its Pay or Receive action; to review bank activity, use Statements and reconciliation.')">
      <template #actions>
        <FieldStudioShortcut entity-kind="journal" />
        <NcButton v-if="writable" variant="primary" @click="dialog = true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'Record inflow or outflow') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>{{ t('librefinance', 'Posted transactions') }}</h2>
        <NcTextField v-model="search" :label="t('librefinance', 'Filter loaded journal entries')"
          :placeholder="t('librefinance', 'Description or date')" type="search"
          class="lf-search-field" />
      </div>
      <details class="lf-finance-explanation">
        <summary>{{ t('librefinance', 'How should journal entries be interpreted?') }}</summary>
        <p>{{ t('librefinance', 'Debit and credit are the two sides of an accounting entry. A posted entry cannot be edited: differences must be corrected with another entry. Search filters only loaded pages. Imported, pending statements are reviewed in Statements and reconciliation.') }}</p>
      </details>
      <div v-if="filtered.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th>{{ t('librefinance', 'Date') }}</th>
            <th>{{ t('librefinance', 'Description') }}</th>
            <th>{{ t('librefinance', 'Classification') }}</th>
            <th>{{ t('librefinance', 'Debit') }}</th>
            <th>{{ t('librefinance', 'Credit') }}</th>
            <th class="lf-numeric">{{ t('librefinance', 'Amount') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="entry in filtered" :key="entry.id">
              <td><time :datetime="entry.effective_on">{{ formatLedgerDate(entry.effective_on) }}</time></td>
              <td><strong>{{ entry.memo }}</strong>
                <small v-if="entry.notes_md" class="lf-muted">{{ t('librefinance', 'Markdown notes attached') }}</small><small v-if="entry.payment_method_id && paymentLabel(entry.payment_method_id)"
                  class="lf-muted"> · {{ paymentLabel(entry.payment_method_id) }}</small></td>
              <td>{{ entry.category_id
                ? (categoryRecords.find(c => c.id === entry.category_id)?.name || '#' + entry.category_id)
                : t('librefinance', 'Unclassified') }}</td>
              <td>{{ accountNameById(entry.postings?.find(p => p.amountMinor > 0)?.accountId) }}</td>
              <td>{{ accountNameById(entry.postings?.find(p => p.amountMinor < 0)?.accountId) }}</td>
              <td class="lf-numeric lf-nowrap"><strong>{{ formatted(entry) }}</strong></td>
            </tr>
          </tbody>
        </table>
      </div>
      <NcEmptyContent v-else
        :name="search ? t('librefinance', 'No journal entries found on this page') : t('librefinance', 'No posted journal entries')"
        :description="t('librefinance', 'Use Record inflow or outflow, or load more history.')" />
      <div v-if="nextBeforeId" class="lf-table-footer">
        <NcButton variant="secondary" :disabled="busy" @click="loadMore">{{ t('librefinance', 'Load more journal entries') }}</NcButton>
      </div>
    </section>

    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog" :name="t('librefinance', 'Record inflow or outflow')"
      @update:open="dialog = $event">
      <form class="lf-dialog-form" @submit.prevent="submit">
        <p><strong>{{ direction === 'expense' ? t('librefinance', 'Record a paid expense.') : t('librefinance', 'Record received income.') }}</strong>
          {{ t('librefinance', 'The financial account ledger balance will be updated immediately. To pay or receive a registered bill, use its Pay or Receive action to avoid duplicates. To review a bank statement, use Statements and reconciliation.') }}</p>
        <NcNoteCard v-if="entryError" type="error" role="alert">{{ entryError }}</NcNoteCard>
        <fieldset class="lf-entry-direction">
          <legend>{{ t('librefinance', 'What happened?') }}</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{ selected: direction === 'expense' }"
              :aria-pressed="direction === 'expense'" @click="direction = 'expense'">
              <NcIconSvgWrapper :path="mdiArrowUpRight" :size="22" aria-hidden="true" />
              <span><strong>{{ t('librefinance', 'Expense') }}</strong>
                <small>{{ t('librefinance', 'Money went out') }}</small></span>
            </button>
            <button type="button" :class="{ selected: direction === 'income' }"
              :aria-pressed="direction === 'income'" @click="direction = 'income'">
              <NcIconSvgWrapper :path="mdiArrowDownLeft" :size="22" aria-hidden="true" />
              <span><strong>{{ t('librefinance', 'Revenue') }}</strong>
                <small>{{ t('librefinance', 'Money came in') }}</small></span>
            </button>
          </div>
        </fieldset>
        <div class="lf-form-grid">
          <LfSelect id="lf-journal-bank" v-model="bankId"
            :label="direction === 'expense' ? t('librefinance', 'Where did the money come from?') : t('librefinance', 'Where did the money go?')"
            :options="bankChoices" :placeholder="t('librefinance', 'Select an account')" />
          <LfSelect id="lf-journal-counterpart" v-model="categoryId"
            :label="direction === 'expense' ? t('librefinance', 'Expense account') : t('librefinance', 'Revenue account')"
            :options="counterpartChoices" :placeholder="t('librefinance', 'Choose the accounting classification')" />
          <LfSelect v-if="categoryChoices.length > 1" id="lf-journal-category"
            v-model="transactionCategoryId" :label="t('librefinance', 'Category (optional)')"
            :options="categoryChoices" />
          <NcTextField v-model="amount" :label="t('librefinance', 'Amount')" inputmode="decimal"
            :placeholder="amountPlaceholder" />
          <LfSelect id="lf-journal-payment-method" v-model="paymentMethodId"
            :label="t('librefinance', 'Payment method (optional)')" :options="paymentChoices" />
          <LfDateField id="lf-journal-date" v-model="date" :label="t('librefinance', 'Transaction date')" />
          <NcTextField v-model="memo" :label="t('librefinance', 'Short description')" maxlength="255"
            :placeholder="t('librefinance', 'E.g. payment for office supplies')" class="lf-form-full" />
        </div>
        <NcNoteCard v-if="paymentError" type="warning">
          {{ t('librefinance', 'Payment methods unavailable: {error}', { error: paymentError }, { escape: false, sanitize: false }) }}
        </NcNoteCard>
        <LfMarkdownEditor v-model="notesMarkdown" :label="t('librefinance', 'Detailed description')" />
        <CustomFieldsPanel v-model="entryFields" entity-kind="journal" />
        <p class="lf-dialog-help">{{ t('librefinance', 'The income or expense account determines how the transaction appears in reports. A category, if available, provides a more detailed classification.') }}</p>
        <div v-if="entrySummary" class="lf-dialog-summary" role="status" aria-live="polite">
          <strong>{{ t('librefinance', 'Review before posting') }}</strong>
          <p>{{ entrySummary }}</p>
          <small>{{ t('librefinance', 'The entry is final. Correct errors with a new entry, never by changing the history.') }}</small>
        </div>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeDialog">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">{{ t('librefinance', 'Post transaction') }}</NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>
