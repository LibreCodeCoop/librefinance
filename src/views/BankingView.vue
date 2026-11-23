<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFileUploadOutline, mdiRefresh, mdiLinkVariant, mdiFilterVariant } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import LfSelect from '../components/LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
const props=defineProps({ embedded:{type:Boolean,default:false} })
const w=inject('lfWorkspace')
const navigate=inject('lfNavigate')
const importRequest=inject('lfBankImportRequest',ref(0))
const focusedBank=inject('lfBankFocus',ref(null))
const { activeId, accounts, journals, nextBeforeId,
  loadMore: loadMoreJournals, money, writable } = w
const chosenAccount=ref(0)
const filter=ref('all')
const transfers=ref([])
const transferCursor=ref(null)
const rows=ref([])
const localSort=useLoadedSort('')
const orderedRows=computed(()=>localSort.order(rows.value,{
  bank_account_id:row=>bankName(row.bank_account_id),
}))
const transferSort=useLoadedSort('')
const orderedTransfers=computed(()=>transferSort.order(transfers.value,{
  booking_date:row=>row.bookingDate,
  fromAccountId:row=>bankName(row.fromAccountId),
  toAccountId:row=>bankName(row.toAccountId),
  amount_minor:row=>Number(row.amountMinor),
}))
const next=ref(null)
const providers=ref([])
const busy=ref(false)
const error=ref('')
const success=ref('')
const importDialog=ref(false)
const importError=ref('')
const importAccount=ref(0)
const csvFile=ref(null)
const csvInput=ref(null)
const reconcile=ref(null)
const journalId=ref(0)
const reconciliationError=ref('')
let generation=0
const banks=computed(()=>accounts.value.filter(a=>a.is_bank && a.active))
const journalCandidates=computed(()=>{
  if (!reconcile.value) return []
  const target = reconcile.value
  return journals.value.filter(entry => entry.postings?.some(post =>
    Number(post.accountId) === Number(target.bank_account_id)
      && Number(post.amountMinor) === Number(target.amount_minor)))
    .map(entry => ({ value: entry.id,
      label: entry.effective_on + ' · ' + entry.memo + ' (#' + entry.id + ')' }))
})
const bankChoices=computed(()=>[{value:0,label:'Todas as contas'},
  ...banks.value.map(a=>({value:a.id,label:a.name}))])
const importChoices=computed(()=>banks.value.map(a=>({value:a.id,label:a.name})))
const statuses=[
  {value:'all',label:'Todas as situações'},
  {value:'pending',label:'Pendentes'},
  {value:'matched',label:'Conciliadas'},
]
function bankName(id) {return banks.value.find(a=>a.id===Number(id))?.name||('Conta #'+id)}
function chooseReconciliation(row) {
  reconcile.value = row
  journalId.value = 0
  reconciliationError.value = ''
}
function closeReconciliation() {
  reconcile.value = null
  journalId.value = 0
  reconciliationError.value = ''
}

async function load() {
  const token=++generation
  busy.value=true
  error.value=''
  try {
    const query='/transactions?status='+filter.value+'&bankAccountId='+Number(chosenAccount.value)
    const [entryResult,providerResult,transferResult]=await Promise.allSettled([
      financeApi('librefinance_banking',activeId.value,query),
      financeApi('librefinance_banking',activeId.value,'/providers'),
      financeApi('librefinance_banking',activeId.value,'/transfers'),
    ])
    if(token!==generation)return
    if (entryResult.status==='rejected') throw entryResult.reason
    rows.value=entryResult.value.transactions
    next.value=entryResult.value.nextAfterId
    providers.value=providerResult.status==='fulfilled' ? providerResult.value.providers : []
    transfers.value=transferResult.status==='fulfilled' ? transferResult.value.transfers : []
    transferCursor.value=transferResult.status==='fulfilled' ? transferResult.value.nextAfterId : null
    if (providerResult.status==='rejected' || transferResult.status==='rejected') {
      error.value='Algumas consultas bancárias estão indisponíveis. Os extratos carregados permanecem visíveis.'
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function more() {
  if (!next.value || busy.value) return
  const token=generation
  busy.value=true
  try {
    const result=await financeApi('librefinance_banking',activeId.value,
      '/transactions?status='+filter.value+'&bankAccountId='+Number(chosenAccount.value)
      +'&afterId='+next.value)
    if(token!==generation)return
    rows.value=[...rows.value,...result.transactions];next.value=result.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function moreTransfers() {
  if (!transferCursor.value || busy.value) return
  const token=generation
  busy.value=true
  try {
    const result=await financeApi('librefinance_banking',activeId.value,
      '/transfers?afterId='+transferCursor.value)
    if(token!==generation)return
    transfers.value=[...transfers.value,...result.transfers]
    transferCursor.value=result.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
function openImportDialog() {
  importError.value = ''
  csvFile.value = null
  importAccount.value = banks.value[0]?.id || 0
  importDialog.value = true
}
function closeImportDialog() {
  importDialog.value = false
  csvFile.value = null
  importError.value = ''
}
function pickFile(event) {
  csvFile.value=event.target.files?.[0]||null
  importError.value = ''
  if(event.target) event.target.value=''
}
async function importCsv() {
  if(!csvFile.value||!importAccount.value||busy.value)return
  const token=++generation
  busy.value=true;error.value='';success.value='';importError.value=''
  try{
    if(csvFile.value.size>262144)throw new Error('Arquivo acima de 256 KiB')
    const csv=await csvFile.value.text()
    const out=await financeApi('librefinance_banking',activeId.value,
      '/imports','POST',{bankAccountId:Number(importAccount.value),csv})
    if(token!==generation)return
    success.value=out.imported+' movimentações importadas; '+out.skipped+' já existentes.'
    closeImportDialog()
    await load()
  }catch(e){if(token===generation)importError.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function match() {
  if(!reconcile.value||busy.value)return
  const id=Number(journalId.value)
  if(!Number.isSafeInteger(id)||id<1||!journalCandidates.value.some(j=>j.value===id)){
    reconciliationError.value='Selecione um lançamento compatível para concluir a conciliação.';return
  }
  const token=++generation
  busy.value=true;error.value='';success.value='';reconciliationError.value=''
  try{
    await financeApi('librefinance_banking',activeId.value,
      '/transactions/'+reconcile.value.id+'/match','POST',{journalId:id})
    if(token!==generation)return
    closeReconciliation()
    success.value='Movimentação vinculada ao lançamento existente.'
    await load()
  }catch(e){if(token===generation)reconciliationError.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
watch([chosenAccount,filter],load)
// Opening from a pending evidence row lands on that exact authorized
// statement transaction; no payment or ledger entry is created here.
watch(focusedBank, focus => {
  if (!props.embedded || !focus
    || Number(focus.spaceId) !== Number(activeId.value)) return
  const row=focus.transaction
  focusedBank.value=null
  if (!row || !Number.isSafeInteger(Number(row.id)) || Number(row.id)<1
    || !banks.value.some(bank=>Number(bank.id)===Number(row.bank_account_id))
    || row.status!=='pending') return
  chosenAccount.value=Number(row.bank_account_id)
  filter.value='pending'
  if(writable.value) chooseReconciliation(row)
}, { immediate:true })
// A toolbar outside this component may request an import while the banking
// panel is mounting. Consume exactly once, even if the user revisits the tab.
watch(importRequest, requested => {
  if (!requested || !props.embedded) return
  importRequest.value=0
  if (writable.value && banks.value.length) openImportDialog()
}, { immediate:true })
onMounted(load)
onBeforeUnmount(()=>++generation)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader v-if="!embedded" title="Bancos e caixa" eyebrow="FINANCEIRO"
      description="Confira extratos e concilie movimentações sem contar transferências internas duas vezes.">
      <template #actions>
        <NcButton v-if="writable && banks.length && rows.length" variant="primary" :disabled="busy"
          @click="openImportDialog">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          Importar extrato CSV
        </NcButton>
        <NcButton variant="tertiary" aria-label="Atualizar extratos" :disabled="busy" @click="load">
          <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
        </NcButton>
      </template>
    </LfPageHeader>
    <p v-if="embedded" class="lf-muted lf-bank-intro">
      O extrato confirma movimentos do banco; conciliar vincula a um lançamento já contabilizado.
      Importar não gera receita, despesa ou quitação de fatura automaticamente.
    </p>
    <details v-if="rows.length || transfers.length" class="lf-finance-explanation">
      <summary>Como funciona a conciliação?</summary>
      <p>Movimentos importados não alteram o saldo contábil até serem conciliados.
        1. Importe um extrato CSV para revisão. 2. Localize uma movimentação pendente.
        3. Vincule-a a um lançamento contábil da mesma conta e valor.
        Transferências históricas exigem a conferência das duas pontas.
        Não existe sincronização bancária em tempo real homologada aqui.</p>
    </details>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section class="lf-surface">
      <div v-if="rows.length || next || chosenAccount || filter!=='all'"
        class="lf-section-heading lf-banking-toolbar">
        <div
          class="lf-native-agenda-filters">
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-bank-filter" variant="tertiary" :pressed="chosenAccount !== 0">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                Conta: {{ bankChoices.find(option=>Number(option.value)===Number(chosenAccount))?.label }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar por conta financeira">
              <NcButton v-for="choice in bankChoices" :key="choice.value"
                variant="tertiary" :aria-pressed="Number(chosenAccount)===Number(choice.value)"
                @click="chosenAccount=Number(choice.value)">{{choice.label}}</NcButton>
            </div>
          </NcPopover>
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-bank-status" variant="tertiary" :pressed="filter!=='all'">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                Situação: {{ statuses.find(option=>option.value===filter)?.label }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar movimentações por situação">
              <NcButton v-for="choice in statuses" :key="choice.value"
                variant="tertiary" :aria-pressed="filter===choice.value"
                @click="filter=choice.value">{{choice.label}}</NcButton>
            </div>
          </NcPopover>
        </div>
      </div>
      <p v-if="rows.length" class="lf-dialog-help">
        {{rows.length}} movimentação(ões) carregada(s) neste filtro{{next ? '; há outras páginas disponíveis' : ''}}.
        Os totais de um extrato completo não são estimados a partir desta lista.
      </p>
      <div v-if="busy && !rows.length"
        class="lf-loading" role="status">
        <NcLoadingIcon :size="44" /> Carregando dados bancários…
      </div>
      <NcEmptyContent v-else-if="!banks.length"
        name="Nenhuma conta financeira disponível"
        description="Cadastre uma conta de banco ou caixa no Plano de contas para poder importar extratos.">
        <template v-if="writable" #action>
          <NcButton variant="primary" @click="navigate('accounts')">Abrir plano de contas</NcButton>
        </template>
      </NcEmptyContent>
      <NcEmptyContent v-else-if="!rows.length"
        :name="chosenAccount || filter!=='all' ? 'Nenhuma movimentação neste filtro' : 'Nenhuma movimentação importada'"
        description="Importe um extrato CSV para conferir movimentos, sem alterar o saldo contábil.">
        <template #action>
          <NcButton v-if="chosenAccount || filter!=='all'" @click="chosenAccount=0;filter='all'">Limpar filtros</NcButton>
          <NcButton v-else-if="writable && !embedded" variant="primary" @click="openImportDialog">Importar extrato CSV</NcButton>
        </template>
      </NcEmptyContent>
      <div v-else class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'booking_date',label:'Data'}, {key:'bank_account_id',label:'Conta'},
              {key:'description',label:'Descrição'}, {key:'amount_minor',label:'Valor'},
              {key:'status',label:'Estado'}]" :key="col.key"
              :class="{'lf-numeric':col.key==='amount_minor'}"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th><th v-if="writable">Ações</th>
          </tr></thead>
          <tbody><tr v-for="row in orderedRows" :key="row.id">
            <td>{{row.booking_date}}</td>
            <td>{{bankName(row.bank_account_id)}}</td>
            <td><strong>{{row.description}}</strong></td>
            <td class="lf-numeric">{{money(row.amount_minor)}}</td>
            <td><span :class="row.status==='matched'?'lf-state lf-state-good':'lf-state lf-state-warning'">
              {{row.status==='matched'?'Conciliada':'Pendente'}}</span></td>
            <td v-if="writable"><NcButton v-if="row.status==='pending'"
              variant="tertiary" :aria-label="'Conciliar movimento '+row.id"
              @click="chooseReconciliation(row)">
              <template #icon><NcIconSvgWrapper :path="mdiLinkVariant" :size="20" /></template>
              Conciliar
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <details v-if="transfers.length" class="lf-finance-explanation lf-transfer-history">
        <summary>Transferências entre contas ({{transfers.length}}{{transferCursor ? '+' : ''}})</summary>
        <p class="lf-muted">Confira as duas pontas nos extratos. Transferências entre
          contas próprias não representam receita ou despesa.</p>
        <div class="lf-table-scroll"><table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'booking_date',label:'Data'}, {key:'fromAccountId',label:'Conta de origem'},
              {key:'toAccountId',label:'Conta de destino'}, {key:'amount_minor',label:'Valor'}]"
              :key="col.key" :class="{'lf-numeric':col.key==='amount_minor'}"
              :aria-sort="transferSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="transferSort.column.value" :direction="transferSort.direction.value"
                scope="loaded" @sort="transferSort.sort" />
            </th><th>Situação</th>
          </tr></thead>
          <tbody><tr v-for="item in orderedTransfers" :key="item.id">
            <td>{{item.bookingDate}}</td><td>{{bankName(item.fromAccountId)}}</td>
            <td>{{bankName(item.toAccountId)}}</td>
            <td class="lf-numeric">{{money(item.amountMinor)}}</td>
            <td><span class="lf-state lf-state-warning">Aguardando conferência</span></td>
          </tr></tbody>
        </table></div>
        <NcButton v-if="transferCursor" :disabled="busy" @click="moreTransfers">
          Carregar mais transferências
        </NcButton>
      </details>
      <p v-if="localSort.column.value && next" class="lf-muted">
        Ordenação aplicada somente às movimentações carregadas.</p>
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more">Carregar mais movimentações</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="importDialog" name="Importar extrato CSV"
      @update:open="!$event && closeImportDialog()">
      <form class="lf-dialog-form" @submit.prevent="importCsv">
        <p>Importe os movimentos para revisão. O arquivo será analisado e
          nenhum lançamento contábil será criado automaticamente.</p>
        <NcNoteCard v-if="importError" type="error" role="alert">{{ importError }}</NcNoteCard>
        <LfSelect id="lf-import-bank" v-model="importAccount" label="Conta de destino"
          :options="importChoices" />
        <input ref="csvInput" class="lf-visually-hidden" type="file"
          accept=".csv,text/csv" @change="pickFile" />
        <NcButton variant="secondary" @click="csvInput?.click()">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          Selecionar CSV
        </NcButton>
        <p class="lf-muted">{{csvFile?.name||'Nenhum arquivo selecionado'}}</p>
        <details class="lf-finance-explanation">
          <summary>Formato esperado do CSV</summary>
          <p>Use as colunas <code>id,date,amount,description</code>, nesta ordem.
            Exemplo: <code>mov-001,2026-11-20,-25.90,Pagamento de teste</code>.
            Utilize ponto como separador decimal e sinal negativo para saídas.
            O limite é 256 KiB por arquivo.</p>
        </details>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeImportDialog">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy||!csvFile||!importAccount">
            Importar para revisão
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(reconcile)" name="Conciliar movimentação"
      @update:open="!$event && closeReconciliation()">
      <form class="lf-dialog-form" @submit.prevent="match">
        <p>Confira a movimentação selecionada antes de vinculá-la a um registro contábil.
          Esta etapa não registra nova receita nem nova despesa.</p>
        <NcNoteCard v-if="reconciliationError" type="error" role="alert">{{ reconciliationError }}</NcNoteCard>
        <div v-if="reconcile" class="lf-dialog-summary">
          <strong>{{ reconcile.description }}</strong>
          <div class="lf-reconciliation-details">
            <span>Data</span><strong>{{ reconcile.booking_date }}</strong>
            <span>Conta financeira</span><strong>{{ bankName(reconcile.bank_account_id) }}</strong>
            <span>Movimento</span><strong>{{ Number(reconcile.amount_minor) >= 0 ? 'Entrada' : 'Saída' }}</strong>
            <span>Valor no extrato</span><strong>{{ money(reconcile.amount_minor) }}</strong>
          </div>
          <small>Em revisão. O lançamento correspondente deve usar a mesma conta e valor.</small>
        </div>
        <LfSelect id="lf-bank-journal" v-model="journalId"
          label="Selecionar lançamento já contabilizado" :options="journalCandidates"
          placeholder="Escolha pela data e descrição" />
        <NcNoteCard v-if="journalCandidates.length===0" type="info">
          Nenhum lançamento compatível foi encontrado nos registros carregados.
          Confira a conta e o valor. Não crie um lançamento duplicado para forçar a conciliação.
        </NcNoteCard>
        <NcButton v-if="nextBeforeId" variant="tertiary" :disabled="busy"
          @click="loadMoreJournals">Procurar em lançamentos mais antigos</NcButton>
        <p v-else-if="journalCandidates.length===0" class="lf-dialog-help">
          Se o lançamento ainda não foi contabilizado, registre-o em Lançamentos contábeis e volte para conciliar.
        </p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeReconciliation">Cancelar</NcButton>
          <NcButton variant="primary" type="submit"
            :disabled="busy||!journalCandidates.some(j=>j.value===Number(journalId))">
            Confirmar conciliação
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>
