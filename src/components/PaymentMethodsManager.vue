<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue'
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
  { value:'cash', label:'Dinheiro' },
  { value:'bank_transfer', label:'Transferência bancária' },
  { value:'credit_card', label:'Cartão de crédito' },
  { value:'debit_card', label:'Cartão de débito' },
  { value:'pix', label:'Pagamento instantâneo (ex.: Pix)' },
  { value:'digital_wallet', label:'Carteira digital ou intermediador' },
  { value:'other', label:'Outro' },
]
const bankAccounts = computed(() => [
  { value:0, label:'Sem vínculo obrigatório' },
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
        Modalidades usadas na classificação de lançamentos. Não representam integração com provedores.
      </p>
      <NcButton v-if="manager" variant="primary" :disabled="loading" @click="begin()">
        <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
        Nova forma de pagamento
      </NcButton>
    </div>
    <NcNoteCard v-if="methods.length" type="info">
      Formas de pagamento cadastradas não movimentam dinheiro nem habilitam integração
      automática com bancos, cartões ou provedores.
    </NcNoteCard>
    <NcNoteCard v-if="problem && !dialog" type="error" role="alert">{{ problem }}</NcNoteCard>
    <div v-if="loading && !methods.length" class="lf-loading" role="status">
      <NcLoadingIcon :size="36" /> Carregando formas de pagamento…
    </div>
    <NcEmptyContent v-else-if="!methods.length" name="Nenhuma forma de pagamento cadastrada"
      description="Use Nova forma de pagamento para registrar uma modalidade de pagamento ou recebimento." />
    <div v-else class="lf-table-scroll">
      <table class="lf-data-table">
        <thead><tr>
          <th v-for="col in [
            {key:'name',label:'Nome'}, {key:'kind',label:'Tipo'},
            {key:'bank',label:'Conta associada'}, {key:'active',label:'Situação'}]"
            :key="col.key" :aria-sort="sort.ariaSort(col.key)">
            <LfSortableHeader :label="col.label" :column="col.key"
              :active-column="sort.column.value" :direction="sort.direction.value"
              scope="loaded" @sort="sort.sort" />
          </th>
          <th v-if="manager">Ações</th></tr></thead>
        <tbody><tr v-for="method in orderedMethods" :key="method.id">
          <td><strong>{{ method.name }}</strong></td>
          <td>{{ kinds.find(k => k.value === method.kind)?.label }}</td>
          <td>{{ accounts.find(a => a.id === method.bank_account_id)?.name || 'Nenhuma' }}</td>
          <td>{{ method.active ? 'Ativa' : 'Desativada' }}</td>
          <td v-if="manager">
            <NcActions :aria-label="'Ações da forma ' + method.name" :force-menu="true">
              <NcActionButton close-after-click @click="begin(method)">
                <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
                Editar
              </NcActionButton>
              <NcActionButton close-after-click @click="problem=''; removeTarget=method">
                <template #icon><NcIconSvgWrapper :path="mdiDeleteOutline" :size="20" /></template>
                Excluir
              </NcActionButton>
            </NcActions>
          </td>
        </tr></tbody>
      </table>
    </div>
    <p v-if="methods.length && afterId && sort.column.value" class="lf-muted">
      A ordenação considera apenas os registros carregados.
    </p>
    <NcButton v-if="methods.length && afterId" variant="tertiary" :disabled="loading"
      @click="load(afterId)">Carregar mais formas</NcButton>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="draft.id ? 'Editar forma de pagamento' : 'Criar forma de pagamento'"
      @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="draft.name" label="Nome da forma de pagamento"
          maxlength="120" required placeholder="Ex.: cartão da empresa" />
        <LfSelect id="lf-payment-kind" v-model="draft.kind" label="Tipo de pagamento"
          :options="kinds" :disabled="Boolean(draft.id)" />
        <LfSelect id="lf-payment-bank" v-model="draft.bankAccountId"
          label="Conta financeira vinculada (opcional)" :options="bankAccounts" />
        <NcCheckboxRadioSwitch v-if="draft.id" v-model="draft.active" type="checkbox">
          Permitir utilizar em novos lançamentos
        </NcCheckboxRadioSwitch>
        <NcNoteCard type="info">
          Não alteramos o tipo de formas já existentes nem seus vínculos após serem usadas.
          Desative uma forma com histórico para preservar os lançamentos.
        </NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="dialog=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading">Salvar forma</NcButton></div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content"
      :open="Boolean(removeTarget)" name="Excluir forma de pagamento"
      @update:open="!$event && (removeTarget=null)">
      <div class="lf-dialog-form">
        <NcNoteCard type="warning">
          Só é possível excluir uma forma nunca usada. Se houver lançamentos,
          a exclusão será bloqueada; você pode desativá-la.
        </NcNoteCard>
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <p>Excluir <strong>{{ removeTarget?.name }}</strong>?</p>
        <div class="lf-form-actions"><NcButton variant="tertiary"
          @click="removeTarget=null">Cancelar</NcButton>
          <NcButton variant="error" :disabled="loading" @click="remove">Excluir forma</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
