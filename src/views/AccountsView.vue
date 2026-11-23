<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { computed, inject, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline, mdiDeleteOutline, mdiFilterVariant } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import { financeApi, financeError } from '../services/api.js'
const props = defineProps({ bankOnly: { type: Boolean, default: false } })
const w = inject('lfWorkspace')
const { activeId, accounts, naturalBalance, manager, refreshCurrent } = w
const navigate = inject('lfNavigate')
function openPaymentSettings() {
  navigate('paymentMethods')
}
const dialog = ref(false)
const deleteTarget = ref(null)
const deletePreview = ref(null)
const deleteBusy = ref(false)
const deleteError = ref('')
const deleteReasons = {
  journal_entries: 'Existem lançamentos históricos, mesmo que o saldo atual seja zero',
  category_links: 'Existem categorias vinculadas a esta conta',
  bank_transactions: 'Existem movimentações de extrato associadas',
  payment_methods: 'Há formas de pagamento vinculadas a esta conta',
  bank_transfers: 'Existem transferências bancárias associadas',
  invoice_allocations: 'Existem pagamentos ou recebimentos de faturas associados',
  import_snapshots: 'Existem saldos e identificadores de origem importados',
  import_mappings: 'Existe um vínculo com registros de origem importados',
}
async function confirmDelete(account) {
  deleteTarget.value = account
  deletePreview.value = null
  deleteError.value = ''
  deleteBusy.value = true
  try {
    deletePreview.value = await financeApi('librefinance', Number(activeId.value),
      '/accounts/' + account.id + '/deletion', 'GET')
  } catch (e) { deleteError.value = financeError(e) }
  finally { deleteBusy.value = false }
}
async function deleteAccount() {
  if (!deleteTarget.value || !deletePreview.value?.canDelete || deleteBusy.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await financeApi('librefinance', Number(activeId.value),
      '/accounts/' + deleteTarget.value.id, 'DELETE')
    await refreshCurrent()
    deleteTarget.value = null
    deletePreview.value = null
  } catch (e) {
    // A reference could have been written after preview; the server is final.
    deleteError.value = financeError(e)
    deletePreview.value = null
  } finally { deleteBusy.value = false }
}
const working = ref(false)
const problem = ref('')
const filter = ref('all')
const search = ref('')
const localSort = useLoadedSort('code')
const form = ref({ id: 0, code: '', name: '', class: 'asset', isBank: true, active: true })
const filters = [
  { value: 'all', label: 'Todas as classes' },
  { value: 'asset', label: 'Ativo' }, { value: 'liability', label: 'Passivo' },
  { value: 'equity', label: 'Patrimônio' }, { value: 'revenue', label: 'Receita' },
  { value: 'expense', label: 'Despesa' },
]
const explanations = [
  ['Ativo', 'o que a organização possui ou tem a receber (ex.: caixa e bancos)'],
  ['Passivo', 'obrigações a pagar (ex.: empréstimos e fornecedores)'],
  ['Patrimônio', 'recursos próprios e resultados acumulados'],
  ['Receita', 'o que foi ganho com vendas, serviços ou outras atividades'],
  ['Despesa', 'gastos com atividades e manutenção'],
]
const listed = computed(() => localSort.order(accounts.value.filter(a =>
  (!props.bankOnly || (a.is_bank && a.class === 'asset'))
  && (props.bankOnly || filter.value === 'all' || a.class === filter.value)
  && (!search.value.trim() || (a.code + ' ' + a.name).toLocaleLowerCase('pt-BR')
    .includes(search.value.trim().toLocaleLowerCase('pt-BR')))), {
      balance: account => (['liability','equity','revenue'].includes(account.class)
        ? -1 : 1) * Number(account.balance_minor),
    }))
function create() {
  problem.value = ''
  form.value = {
    id: 0, code: props.bankOnly ? 'BANK-' + crypto.randomUUID().replaceAll('-', '').slice(0, 20).toUpperCase() : '',
    name: '', class: 'asset', isBank: true, active: true,
  }
  dialog.value = true
}
function edit(account) {
  problem.value = ''
  form.value = { id: account.id, code: account.code, name: account.name,
    class: account.class, isBank: account.is_bank, active: account.active }
  dialog.value = true
}
async function submit() {
  if (working.value) return
  problem.value = ''
  working.value = true
  try {
    const selected = form.value

    if (selected.id) {
      await financeApi('librefinance', Number(activeId.value), '/accounts/' + selected.id, 'PUT',
        { name: selected.name.trim(), active: selected.active })
    } else {
      await financeApi('librefinance', Number(activeId.value), '/accounts', 'POST', {
        code: selected.code.trim(), name: selected.name.trim(),
        class: props.bankOnly ? 'asset' : selected.class,
        isBank: props.bankOnly || (selected.class === 'asset' && selected.isBank),
      })
    }
    await refreshCurrent()
    dialog.value = false
  } catch (e) { problem.value = financeError(e) }
  finally { working.value = false }
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="props.bankOnly ? 'Contas e bancos' : 'Plano de contas'"
      :eyebrow="props.bankOnly ? 'FINANCEIRO' : 'CONTABILIDADE'"
      :description="props.bankOnly
        ? 'Cadastre onde seu dinheiro fica: contas bancárias e caixa. Selecione uma conta ao registrar pagamentos ou importar extratos.'
        : 'Organize e consulte as contas que sustentam os relatórios financeiros. Não é preciso conhecer contabilidade para registrar receitas e despesas.'">
      <template #actions>
        <NcButton v-if="props.bankOnly" variant="tertiary" @click="navigate('finance')">
          Movimentações
        </NcButton>
        <NcButton v-else variant="secondary" @click="openPaymentSettings">
          Formas de pagamento
        </NcButton>
        <NcButton v-if="manager" variant="primary" @click="create">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{props.bankOnly ? 'Nova conta bancária ou caixa' : 'Criar conta'}}
        </NcButton>
      </template>
    </LfPageHeader>
    <details v-if="props.bankOnly" class="lf-finance-explanation">
      <summary>Sobre conectar o banco</summary>
      <p>Por enquanto, você pode registrar movimentações e importar extratos manualmente.
        Ainda não é possível conectar sua instituição bancária automaticamente.
        Não informe senhas neste cadastro.</p>
    </details>
    <NcNoteCard v-else type="info">
      <strong>Conta financeira x plano de contas:</strong> uma conta bancária é onde o dinheiro está;
      o plano de contas explica a natureza de cada movimento. As cinco classes são padrões
      contábeis, não categorias para personalizar. Você pode criar contas dentro delas.
    </NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>{{props.bankOnly ? 'Meus bancos e caixas' : 'Contas cadastradas'}}</h2>
        <NcTextField v-if="!props.bankOnly || accounts.some(a=>a.is_bank && a.class==='asset')"
          v-model="search" label="Buscar contas carregadas" type="search"
          placeholder="Código ou nome" class="lf-search-field" />
      </div>
      <div v-if="!props.bankOnly && listed.length" class="lf-filter-row lf-account-filter">
        <span class="lf-muted">{{ listed.length }} de {{ accounts.length }} contas</span>
        <NcPopover popup-role="dialog">
          <template #trigger>
            <NcButton id="lf-account-class-filter" variant="tertiary" :pressed="filter!=='all'">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
              Classe: {{filters.find(item=>item.value===filter)?.label}}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar plano de contas por classe">
            <NcButton v-for="choice in filters" :key="choice.value" variant="tertiary"
              :aria-pressed="filter===choice.value" @click="filter=choice.value">{{choice.label}}</NcButton>
          </div>
        </NcPopover>
      </div>
      <div v-if="listed.length" class="lf-table-scroll"><table class="lf-data-table">
        <thead><tr>
          <th v-for="col in [
            ...(!props.bankOnly ? [{key:'code',label:'Código'}] : []),
            {key:'name',label:'Conta'},
            ...(!props.bankOnly ? [{key:'class',label:'Natureza'}] : []),
            {key:'active',label:'Situação'},
            {key:'balance',label:'Saldo'}]" :key="col.key"
            :class="{ 'lf-numeric': col.key==='balance' }" :aria-sort="localSort.ariaSort(col.key)">
            <LfSortableHeader :label="col.label" :column="col.key"
              :active-column="localSort.column.value" :direction="localSort.direction.value"
              scope="loaded" @sort="localSort.sort" />
          </th>
          <th v-if="manager">Ações</th></tr></thead>
        <tbody><tr v-for="account in listed" :key="account.id">
          <td v-if="!props.bankOnly" class="lf-code">{{ account.code }}</td>
          <td><strong>{{ account.name }}</strong>
            <small v-if="account.is_bank" class="lf-muted">Conta financeira (banco ou caixa)</small></td>
          <td v-if="!props.bankOnly">{{ filters.find(f=>f.value===account.class)?.label || account.class }}</td>
          <td><span :class="account.active ? 'lf-state lf-state-good' : 'lf-state'">
            {{ account.active ? 'Ativa' : 'Desativada' }}</span></td>
          <td class="lf-numeric"><strong>{{ naturalBalance(account) }}</strong></td>
          <td v-if="manager">
            <div class="lf-row-actions">
              <NcButton variant="tertiary" :aria-label="'Editar ' + account.name"
                @click="edit(account)">
                <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
              </NcButton>
              <NcButton variant="tertiary" :aria-label="'Excluir ' + account.name"
                @click="confirmDelete(account)">
                <template #icon><NcIconSvgWrapper :path="mdiDeleteOutline" :size="20" /></template>
              </NcButton>
            </div>
          </td>
        </tr></tbody></table>
      </div>
      <NcEmptyContent v-else :name="props.bankOnly ? 'Nenhum banco ou caixa cadastrado' : 'Nenhuma conta encontrada'"
        :description="props.bankOnly
          ? 'Adicione a primeira conta bancária ou caixa para organizar pagamentos, recebimentos e extratos.'
          : 'Mude o filtro ou crie uma conta nesta gestão financeira.'">
        <template v-if="props.bankOnly && manager" #action>
          <NcButton variant="primary" @click="create">Nova conta bancária ou caixa</NcButton>
        </template>
      </NcEmptyContent>
      <details v-if="!props.bankOnly" class="lf-finance-explanation">
        <summary>O que significam Ativo, Passivo, Patrimônio, Receita e Despesa?</summary>
        <ul><li v-for="[label, description] in explanations" :key="label">
          <strong>{{ label }}:</strong> {{ description }}.</li></ul>
        <p>Essas classificações precisam permanecer estáveis para que balanços e resultados sejam coerentes.</p>
      </details>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="props.bankOnly ? (form.id ? 'Editar banco ou caixa' : 'Nova conta bancária ou caixa')
        : (form.id ? 'Editar conta financeira' : 'Criar conta financeira')"
      @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="submit">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="form.name" label="Nome da conta" maxlength="150" required />
        <NcTextField v-if="!props.bankOnly" v-model="form.code"
          label="Código contábil" maxlength="32"
          :disabled="Boolean(form.id)" required placeholder="Ex.: BANCO-001" />
        <LfSelect v-if="!props.bankOnly" id="lf-account-class"
          v-model="form.class" label="Natureza contábil"
          :disabled="Boolean(form.id)" :options="filters.slice(1)" />
        <NcCheckboxRadioSwitch v-if="!props.bankOnly && form.class === 'asset' && !form.id"
          v-model="form.isBank" type="checkbox">
          Esta conta representa dinheiro em banco ou caixa
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-if="form.id" v-model="form.active" type="checkbox">
          Permitir novos lançamentos nesta conta
        </NcCheckboxRadioSwitch>
        <NcNoteCard v-if="!props.bankOnly" type="info">O código de uma conta existente não muda porque
          identifica seus lançamentos. Contas com saldo ou histórico têm restrições
          de alteração e exclusão. Não use este cadastro para fornecer senhas bancárias.</NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="dialog=false">Cancelar</NcButton>
          <NcButton type="submit" variant="primary" :disabled="working">Salvar conta</NcButton></div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content"
      :open="Boolean(deleteTarget)" name="Excluir conta"
      @update:open="!$event && (deleteTarget = null)">
      <div class="lf-dialog-form">
        <h3>{{ deleteTarget?.name }} ({{ deleteTarget?.code }})</h3>
        <NcNoteCard v-if="deleteError" type="error" role="alert">
          {{ deleteError }}. A exclusão não foi realizada. Atualize a verificação e tente novamente.
        </NcNoteCard>
        <p v-if="deleteBusy" role="status">Conferindo histórico e vínculos desta conta…</p>
        <template v-else-if="deletePreview && !deletePreview.canDelete">
          <NcNoteCard type="warning">
            Esta conta não pode ser excluída porque possui histórico ou vínculos. A exclusão
            não apaga lançamentos, extratos nem faturas.
          </NcNoteCard>
          <ul>
            <li v-for="reason in deletePreview.reasons" :key="reason">
              {{ deleteReasons[reason] || 'Há registros vinculados que precisam ser preservados' }}.
            </li>
          </ul>
          <p>Você pode desativar contas sem saldo, mantendo seu histórico.</p>
        </template>
        <NcNoteCard v-else-if="deletePreview?.canDelete" type="warning">
          Esta conta não possui lançamentos nem vínculos conhecidos. A exclusão do cadastro
          será permanente e não poderá ser desfeita. Nenhum registro financeiro será apagado.
        </NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="deleteTarget=null">Fechar</NcButton>
          <NcButton v-if="deletePreview && !deletePreview.canDelete" variant="secondary"
            @click="edit(deleteTarget); deleteTarget=null">Editar ou desativar</NcButton>
          <NcButton v-if="deletePreview?.canDelete" variant="error" :disabled="deleteBusy"
            @click="deleteAccount">Excluir conta permanentemente</NcButton>
          <NcButton v-if="deleteError" variant="secondary" :disabled="deleteBusy"
            @click="confirmDelete(deleteTarget)">Verificar novamente</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
