<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFileUploadOutline, mdiRefresh, mdiLinkVariant } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
const w=inject('lfWorkspace')
const navigate=inject('lfNavigate')
const { activeId, accounts, journals, nextBeforeId,
  loadMore: loadMoreJournals, money, writable } = w
const tab=ref('statements')
const chosenAccount=ref(0)
const filter=ref('all')
const transfers=ref([])
const transferCursor=ref(null)
const rows=ref([])
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
    const api='librefinance_banking'
    if (tab.value==='statements') {
      const query='/transactions?status='+filter.value+'&bankAccountId='+Number(chosenAccount.value)
      const [entries,known]=await Promise.all([
        financeApi(api,activeId.value,query),
        financeApi(api,activeId.value,'/providers'),
      ])
      if(token!==generation)return
      rows.value=entries.transactions;next.value=entries.nextAfterId
      providers.value=known.providers
    } else {
      const data=await financeApi(api,activeId.value,'/transfers')
      if(token!==generation)return
      transfers.value=data.transfers;transferCursor.value=data.nextAfterId
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function more() {
  if(busy.value)return
  const token=generation
  busy.value=true
  try {
    if(tab.value==='statements' && next.value) {
      const r=await financeApi('librefinance_banking',activeId.value,
        '/transactions?status='+filter.value+'&bankAccountId='+Number(chosenAccount.value)
        +'&afterId='+next.value)
      if(token!==generation)return
      rows.value=[...rows.value,...r.transactions];next.value=r.nextAfterId
    } else if(tab.value==='transfers' && transferCursor.value) {
      const r=await financeApi('librefinance_banking',activeId.value,
        '/transfers?afterId='+transferCursor.value)
      if(token!==generation)return
      transfers.value=[...transfers.value,...r.transfers];transferCursor.value=r.nextAfterId
    }
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
watch([tab,chosenAccount,filter],load)
onMounted(load)
onBeforeUnmount(()=>++generation)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="Bancos e conciliação" eyebrow="FINANCEIRO"
      description="Confira movimentações importadas, vínculos contábeis e transferências pendentes.">
      <template #actions>
        <NcButton v-if="writable" variant="primary" :disabled="busy || !banks.length"
          @click="openImportDialog">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          Importar extrato CSV
        </NcButton>
        <NcButton variant="tertiary" aria-label="Atualizar extratos" :disabled="busy" @click="load">
          <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard type="info">
      O extrato é uma lista para conferência: movimentos importados não alteram o saldo contábil
      até serem vinculados a um lançamento existente.
    </NcNoteCard>
    <details class="lf-finance-explanation">
      <summary>Como funciona a conciliação?</summary>
      <p>1. Importe um extrato CSV para revisão. 2. Localize uma movimentação pendente.
        3. Vincule-a a um lançamento contábil da mesma conta e valor.
        Transferências históricas exigem a conferência das duas pontas.
        Não existe sincronização bancária em tempo real homologada aqui.</p>
    </details>
    <NcNoteCard v-if="writable && !banks.length" type="info">
      Antes de importar um extrato, cadastre uma conta financeira no Plano de contas.
      <NcButton variant="tertiary" @click="navigate('accounts')">Abrir plano de contas</NcButton>
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-filter-row">
        <NcButton :variant="tab==='statements'?'primary':'tertiary'"
          :pressed="tab==='statements'" @click="tab='statements'">Movimentações do extrato</NcButton>
        <NcButton :variant="tab==='transfers'?'primary':'tertiary'"
          :pressed="tab==='transfers'" @click="tab='transfers'">Transferências entre contas</NcButton>
      </div>
      <div v-if="tab==='statements'" class="lf-filter-row">
        <LfSelect id="lf-bank-filter" v-model="chosenAccount" label="Conta financeira"
          :options="bankChoices" />
        <LfSelect id="lf-bank-status" v-model="filter" label="Situação"
          :options="statuses" />
      </div>
      <p v-if="tab==='statements' && rows.length" class="lf-dialog-help">
        {{rows.length}} movimentação(ões) carregada(s) neste filtro{{next ? '; há outras páginas disponíveis' : ''}}.
        Os totais de um extrato completo não são estimados a partir desta lista.
      </p>
      <div class="lf-table-scroll">
        <table v-if="tab==='statements'" class="lf-data-table">
          <thead><tr><th>Data</th><th>Conta</th><th>Descrição</th>
            <th class="lf-numeric">Valor</th><th>Estado</th><th v-if="writable">Ações</th></tr></thead>
          <tbody><tr v-for="row in rows" :key="row.id">
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
        <table v-else class="lf-data-table">
          <thead><tr><th>Data</th><th>Conta de origem</th><th>Conta de destino</th>
            <th class="lf-numeric">Valor</th><th>Situação</th></tr></thead>
          <tbody><tr v-for="item in transfers" :key="item.id">
            <td>{{item.bookingDate}}</td><td>{{bankName(item.fromAccountId)}}</td>
            <td>{{bankName(item.toAccountId)}}</td>
            <td class="lf-numeric">{{money(item.amountMinor)}}</td>
            <td><span class="lf-state lf-state-warning">Aguardando conferência</span></td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="tab==='statements'&&!rows.length"
          name="Nenhuma movimentação para o filtro selecionado"
          description="Ajuste os filtros ou importe um CSV para conferir movimentos. Nenhum saldo contábil muda durante a importação." />
        <NcEmptyContent v-if="tab==='transfers'&&!transfers.length"
          name="Nenhuma transferência histórica registrada" />
      </div>
      <div v-if="(tab==='statements'&&next)||(tab==='transfers'&&transferCursor)"
        class="lf-table-footer"><NcButton :disabled="busy" @click="more">
          Carregar mais
        </NcButton></div>
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
