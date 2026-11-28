<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfDateField from './LfDateField.vue'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
import { formatLedgerDate } from '../date.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  source: { type: String, required: true }, // invoice or obligation
  existing: { type: Boolean, default: false },
  record: { type: Object, default: null },
  kind: { type: String, required: true }, // receivable or payable
})
const emit = defineEmits(['update:open', 'paid'])
const w = inject('lfWorkspace')
const busy = ref(false)
const error = ref('')
const bankId = ref(0)
const counterId = ref(0)
const journalId = ref(0)
const amount = ref('')
const date = ref('')
const operationKey = ref('')
const bankOptions = computed(() => w.accounts.value
  .filter(account => account.is_bank && account.active)
  .map(account => ({ value: account.id, label: account.name })))
const expectedClass = computed(() => props.kind === 'receivable' ? 'revenue' : 'expense')
const counterOptions = computed(() => w.accounts.value.filter(account =>
  account.active && !account.is_bank
  && (props.source !== 'invoice' || account.class === expectedClass.value))
  .map(account => ({ value: account.id, label: account.name })))
const journalOptions = computed(() => {
  const id=Number(bankId.value)
  const expected=props.kind==='receivable'?minor.value:-minor.value
  if (!props.existing || !id || !expected) return []
  return w.journals.value.filter(row => row.postings?.some(posting =>
    Number(posting.accountId)===id && Number(posting.amountMinor)===expected))
    .map(row=>({value:Number(row.id),
      label:'#'+row.id+' · '+formatLedgerDate(row.effective_on)+' · '+(row.memo || t('librefinance', 'Recorded financial movement'))}))
})
watch(journalId, () => {
  const selected=w.journals.value.find(row=>Number(row.id)===Number(journalId.value))
  if (props.existing && selected) date.value=selected.effective_on
})
const outstanding = computed(() => {
  const record = props.record ?? {}
  const total = Number(record.amount_minor ?? record.total_minor ?? 0)
  return total - Number(record.paid_minor ?? 0)
})
const minor = computed(() => {
  try { return w.parseMoney(amount.value, props.record, w.accountById(bankId.value), props.existing ? undefined : w.accountById(counterId.value)) } catch { return 0 }
})
const taxed=computed(()=>props.source==='invoice' && !props.existing
  && (Number(props.record?.tax_minor)||Number(props.record?.withheld_minor)))
const valid = computed(() => !busy.value && !taxed.value && props.record?.id && bankId.value
  && (props.existing
    ? journalOptions.value.some(row=>row.value===Number(journalId.value))
    : counterId.value && Number(bankId.value)!==Number(counterId.value))
  && minor.value>0 && minor.value<=outstanding.value
  && /^\d{4}-\d{2}-\d{2}$/.test(date.value))
function generateKey() {
  // Only one financial action is submitted for each verified form state.
  operationKey.value = 'workflow:' + crypto.randomUUID()
}
watch(() => props.open, opened => {
  if (!opened) return
  error.value = ''
  busy.value = false
  bankId.value = bankOptions.value.length === 1 ? Number(bankOptions.value[0].value) : 0
  counterId.value = counterOptions.value.length === 1
    ? Number(counterOptions.value[0].value) : 0
  journalId.value=0
  amount.value = w.amountInput(Math.max(0, outstanding.value), props.record)
  date.value = new Date().toLocaleDateString('sv-SE')
  generateKey()
}, { immediate: true })
watch([bankId, counterId, journalId, amount, date], () => {
  if (!busy.value) generateKey()
})
watch(w.activeId, () => {
  if (props.open) emit('update:open', false)
  error.value = ''
})
function close() {
  if (!busy.value) emit('update:open', false)
}
async function recordPayment() {
  if (!valid.value || !w.writable.value) return
  busy.value = true
  error.value = ''
  const currentSpace = Number(w.activeId.value)
  const targetId = Number(props.record.id)
  const path=props.existing
    ? '/obligations/' + targetId + '/existing-journal-payments'
    : props.source==='invoice'
      ? '/invoices/' + targetId + '/cash-payments'
      : '/obligations/' + targetId + '/cash-payments'
  try {
    const result = await financeApi('librefinance', currentSpace, path, 'POST', {
      bankAccountId: Number(bankId.value),
      ...(props.existing
        ? {journalId:Number(journalId.value)}
        : {counterAccountId:Number(counterId.value)}),
      amountMinor: minor.value,
      paidOn: date.value,
      operationKey: operationKey.value,
    })
    if (currentSpace !== Number(w.activeId.value)) return
    emit('paid', result)
    emit('update:open', false)
  } catch (cause) {
    if (currentSpace === Number(w.activeId.value)) {
      error.value = financeError(cause)
    }
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <NcDialog :open="open" content-classes="lf-finance-dialog-content" size="normal"
    :name="existing ? t('librefinance', 'Link existing movement')
      : kind === 'receivable' ? t('librefinance', 'Record receipt') : t('librefinance', 'Record payment')"
    @update:open="close">
    <form class="lf-dialog-form" @submit.prevent="recordPayment">
      <NcNoteCard type="info">
        {{ existing
          ? t('librefinance', 'Link a payment or receipt that has already been recorded. The money will not be counted twice.')
          : t('librefinance', 'Record the payment or receipt for this bill. The balance will be updated automatically. No transfer will be sent to the bank.') }}
      </NcNoteCard>
      <NcNoteCard v-if="taxed" type="error">
        {{ t('librefinance', 'This invoice has taxes or withholdings that require review. It is not safe to record the amount automatically. Link an already reviewed movement.') }}
      </NcNoteCard>
      <NcNoteCard v-if="source === 'invoice' && !existing" type="warning">
        {{ t('librefinance', 'Confirming an invoice does not mean receiving or paying. This action records the money now. If it was already recorded, link an existing movement to avoid duplication.') }}
      </NcNoteCard>
      <NcNoteCard v-if="source !== 'invoice' && !existing && counterOptions.length>1"
        type="info">
        {{ t('librefinance', 'Choose the movement type corresponding to the payment or receipt.') }}
      </NcNoteCard>
      <p v-if="record"><strong>{{record.description || record.number}}</strong> ·
        {{ t('librefinance', 'Outstanding: {amount}', { amount: w.money(outstanding, record) }, { escape: false, sanitize: false }) }}</p>
      <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
      <LfSelect id="lf-cash-bank" v-model="bankId"
        :label="t('librefinance', 'Bank or cash account')" :options="bankOptions"
        :disabled="busy" />
      <LfSelect v-if="!existing && counterOptions.length>1"
        id="lf-cash-counter" v-model="counterId" :label="t('librefinance', 'Movement type')"
        :options="counterOptions" :disabled="busy" />
      <LfSelect v-else-if="existing" id="lf-existing-journal" v-model="journalId"
        :label="t('librefinance', 'Previously recorded movement')" :options="journalOptions"
        :disabled="busy" />
      <div v-if="existing && !journalOptions.length">
        <NcNoteCard type="warning">{{ t('librefinance', 'No compatible movement among the loaded entries. Amount, account and direction must match.') }}</NcNoteCard>
        <NcButton v-if="w.nextBeforeId.value" variant="tertiary" :disabled="busy"
          @click="w.loadMore">{{ t('librefinance', 'Load earlier entries') }}</NcButton>
      </div>
      <NcNoteCard v-if="!bankOptions.length || (!existing && !counterOptions.length)" type="warning">
        {{ t('librefinance', 'An active financial account and compatible classification account must be registered in More tools → Chart of accounts.') }}
      </NcNoteCard>
      <NcTextField v-model="amount" :label="t('librefinance', 'Amount received or paid')"
        inputmode="decimal" required :disabled="busy" />
      <LfDateField id="lf-cash-date" v-model="date" :label="t('librefinance', 'Movement date')" />
      <div class="lf-form-actions">
        <NcButton variant="tertiary" :disabled="busy" @click="close">{{ t('librefinance', 'Cancel') }}</NcButton>
        <NcButton variant="primary" type="submit" :disabled="!valid">
          {{busy ? t('librefinance', 'Recording…') : existing
            ? t('librefinance', 'Link without duplication') : t('librefinance', 'Record without duplication')}}
        </NcButton>
      </div>
    </form>
  </NcDialog>
</template>
