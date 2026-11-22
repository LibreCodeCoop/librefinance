<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfDateField from './LfDateField.vue'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'

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
      label:'#'+row.id+' · '+row.effective_on+' · '+(row.memo || 'Movimentação registrada')}))
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
function amountInput(minor) {
  return Math.floor(Math.max(0,minor) / 100) + ','
    + String(Math.max(0,minor) % 100).padStart(2, '0')
}
const minor = computed(() => {
  try { return toMinorUnits(amount.value) } catch { return 0 }
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
  amount.value = amountInput(outstanding.value)
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
    :name="existing ? 'Vincular movimentação existente'
      : kind === 'receivable' ? 'Registrar recebimento' : 'Registrar pagamento'"
    @update:open="close">
    <form class="lf-dialog-form" @submit.prevent="recordPayment">
      <NcNoteCard type="info">
        {{ existing
          ? 'Associa este compromisso a um lançamento de caixa já contabilizado. Não gera outra movimentação.'
          : 'Registra uma movimentação contábil de caixa e atualiza este compromisso na mesma operação. Não realiza transferência no banco real.' }}
      </NcNoteCard>
      <NcNoteCard v-if="taxed" type="error">
        Este documento tem tributos ou retenções. A contabilização de caixa
        automática está bloqueada para impedir classificação incorreta dos
        valores. Vincule um lançamento existente com as partidas corretas.
      </NcNoteCard>
      <NcNoteCard v-if="source === 'invoice' && !existing" type="warning">
        A confirmação da fatura ainda não contabiliza receitas ou
        despesas por competência. Este registro segue o regime de caixa.
        Se o movimento já está contabilizado, utilize a opção de associar um
        lançamento existente, sem criar outro.
      </NcNoteCard>
      <NcNoteCard v-if="source !== 'invoice' && !existing" type="info">
        Escolha a classificação correta da movimentação (por exemplo, despesa,
        receita ou pagamento de dívida). Ela não é inferida automaticamente.
      </NcNoteCard>
      <p v-if="record"><strong>{{record.description || record.number}}</strong> ·
        Em aberto: {{w.money(outstanding)}}</p>
      <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
      <LfSelect id="lf-cash-bank" v-model="bankId"
        label="Conta bancária ou caixa" :options="bankOptions"
        :disabled="busy" />
      <LfSelect v-if="!existing" id="lf-cash-counter" v-model="counterId"
        :label="source === 'invoice' ? 'Classificação da receita ou despesa'
          : 'Classificação desta movimentação'"
        :options="counterOptions" :disabled="busy" />
      <LfSelect v-else id="lf-existing-journal" v-model="journalId"
        label="Movimentação já registrada" :options="journalOptions"
        :disabled="busy" />
      <div v-if="existing && !journalOptions.length">
        <NcNoteCard type="warning">Não há movimentação compatível entre os
          lançamentos carregados. Valor, conta e direção devem coincidir.</NcNoteCard>
        <NcButton v-if="w.nextBeforeId.value" variant="tertiary" :disabled="busy"
          @click="w.loadMore">Carregar lançamentos anteriores</NcButton>
      </div>
      <NcNoteCard v-if="!bankOptions.length || (!existing && !counterOptions.length)" type="warning">
        É necessário cadastrar uma conta financeira ativa e uma conta de
        classificação compatível em Mais ferramentas → Plano de contas.
      </NcNoteCard>
      <NcTextField v-model="amount" label="Valor recebido ou pago"
        inputmode="decimal" required :disabled="busy" />
      <LfDateField id="lf-cash-date" v-model="date" label="Data da movimentação" />
      <div class="lf-form-actions">
        <NcButton variant="tertiary" :disabled="busy" @click="close">Cancelar</NcButton>
        <NcButton variant="primary" type="submit" :disabled="!valid">
          {{busy ? 'Registrando…' : existing
            ? 'Vincular sem duplicar' : 'Registrar sem duplicar'}}
        </NcButton>
      </div>
    </form>
  </NcDialog>
</template>
