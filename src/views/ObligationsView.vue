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
import { mdiPlus } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'
const w=inject('lfWorkspace')
const { activeId, money, writable, manager, contacts, contactNextAfterId, loadMoreCatalog }=w
const today=new Date().toLocaleDateString('sv-SE')
const list=ref([])
const next=ref(null)
const filter=ref('all')
const loading=ref(false)
const error=ref('')
const success=ref('')
const formOpen=ref(false)
const settlement=ref(null)
const cancelTarget=ref(null)
const draft=ref({kind:'payable',reference:'',description:'',amount:'',
  issuedOn:today,dueOn:today,contactId:0})
const settleForm=ref({amount:'',paidOn:today,note:''})
const choices=[
  {value:'all',label:'Todas'},
  {value:'open',label:'Em aberto'},
  {value:'partial',label:'Parcialmente baixadas'},
  {value:'settled',label:'Liquidadas'},
  {value:'cancelled',label:'Canceladas'},
]
const kinds=[{value:'payable',label:'A pagar'},{value:'receivable',label:'A receber'}]
const contactChoices=computed(()=>[
  {value:0,label:'Sem contato'},
  ...contacts.value.map(c=>({value:c.id,label:c.name})),
])
const outstanding=row=>Number(row.amount_minor)-Number(row.paid_minor)
const overdue=row=>['open','partial'].includes(row.status)&&row.due_on<today
let generation=0
async function load() {
  const token=++generation
  loading.value=true;error.value=''
  try{
    const data=await financeApi('librefinance_obligations',activeId.value,
      '/obligations?status='+filter.value)
    if(token!==generation)return
    list.value=data.obligations
    next.value=data.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)loading.value=false}
}
async function more() {
  if(!next.value||loading.value)return
  const token=generation
  loading.value=true
  try{
    const data=await financeApi('librefinance_obligations',activeId.value,
      '/obligations?status='+filter.value+'&afterId='+next.value)
    if(token!==generation)return
    list.value=[...list.value,...data.obligations];next.value=data.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)loading.value=false}
}
async function perform(task,notice) {
  if(loading.value)return false
  const token=++generation
  loading.value=true;error.value='';success.value=''
  try{
    await task()
    if(token!==generation)return false
    success.value=notice
    await load()
    return true
  }catch(e){if(token===generation)error.value=financeError(e);return false}
  finally{if(token===generation)loading.value=false}
}
async function create() {
  if(!draft.value.description.trim()||!draft.value.amount||!draft.value.dueOn)return
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations','POST',{
      kind:draft.value.kind,reference:draft.value.reference.trim(),
      description:draft.value.description.trim(),amountMinor:toMinorUnits(draft.value.amount),
      issuedOn:draft.value.issuedOn,dueOn:draft.value.dueOn,
      contactId:Number(draft.value.contactId)||null,
    }),'Título registrado.')
  if(result){
    formOpen.value=false
    draft.value={kind:'payable',reference:'',description:'',amount:'',
      issuedOn:today,dueOn:today,contactId:0}
  }
}
function beginSettlement(row) {
  settlement.value=row
  settleForm.value={amount:(Math.floor(outstanding(row)/100)+','
    +String(outstanding(row)%100).padStart(2,'0')),paidOn:today,note:''}
}
async function settle() {
  if(!settlement.value)return
  const target=settlement.value
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations/'+target.id+'/settlements','POST',{
      amountMinor:toMinorUnits(settleForm.value.amount),
      paidOn:settleForm.value.paidOn,operationKey:'web:'+crypto.randomUUID(),
      note:settleForm.value.note.trim()||null,
    }),'Baixa operacional registrada. Nenhum lançamento contábil foi feito.')
  if(result)settlement.value=null
}
async function cancel() {
  if(!cancelTarget.value)return
  const id=cancelTarget.value.id
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations/'+id+'/cancel','POST',{}),'Título cancelado.')
  if(result)cancelTarget.value=null
}
watch(filter,load)
onMounted(load)
onBeforeUnmount(()=>++generation)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="A pagar e receber" eyebrow="COMPROMISSOS"
      description="Controle operacional de vencimentos, liquidações e valores em aberto.">
      <template #actions>
        <NcButton v-if="writable" variant="primary" @click="formOpen=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Novo título
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard type="info">
      Baixas operacionais não criam lançamentos contábeis nem movimentações bancárias.
      Registre a contrapartida por meio da contabilidade quando aplicável.
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading"><h2>Títulos financeiros</h2>
        <LfSelect id="lf-obligations-filter" v-model="filter" label="Situação"
          :options="choices" /></div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Referência</th><th>Descrição</th><th>Tipo</th>
            <th>Vencimento</th><th class="lf-numeric">Valor</th>
            <th class="lf-numeric">Em aberto</th><th>Situação</th><th v-if="writable">Ações</th></tr></thead>
          <tbody><tr v-for="row in list" :key="row.id">
            <td class="lf-code">{{row.reference}}</td>
            <td><strong>{{row.description}}</strong></td>
            <td>{{row.kind==='payable'?'A pagar':'A receber'}}</td>
            <td>{{row.due_on}}</td>
            <td class="lf-numeric">{{money(row.amount_minor)}}</td>
            <td class="lf-numeric"><strong>{{money(outstanding(row))}}</strong></td>
            <td><span :class="overdue(row)?'lf-state lf-state-warning':'lf-state'">
              {{overdue(row)?'Vencido':({
                open:'Aberto',partial:'Parcial',settled:'Liquidado',cancelled:'Cancelado'
              }[row.status]||row.status)}}</span></td>
            <td v-if="writable" class="lf-row-actions">
              <NcButton v-if="['open','partial'].includes(row.status)&&!row.linkedInvoiceId"
                size="small" @click="beginSettlement(row)">Registrar baixa</NcButton>
              <span v-else-if="row.linkedInvoiceId" class="lf-state">
                Vinculado à fatura #{{ row.linkedInvoiceId }} · baixa via lançamento
              </span>
              <NcButton v-if="manager&&row.status==='open'&&!row.linkedInvoiceId"
                size="small" variant="tertiary" @click="cancelTarget=row">Cancelar</NcButton>
            </td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="!list.length" name="Nenhum título neste filtro" />
      </div>
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="loading" @click="more">Carregar mais títulos</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="formOpen" name="Novo título financeiro"
      @update:open="formOpen=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <LfSelect id="lf-title-type" v-model="draft.kind" :options="kinds" label="Tipo" />
        <NcTextField v-model="draft.description" label="Descrição" maxlength="255" required />
        <NcTextField v-model="draft.reference" label="Referência (opcional)"
          maxlength="80" />
        <NcTextField v-model="draft.amount" label="Valor" inputmode="decimal"
          placeholder="0,00" required />
        <div class="lf-form-grid">
          <LfDateField id="lf-title-issued" v-model="draft.issuedOn" label="Emissão" />
          <LfDateField id="lf-title-due" v-model="draft.dueOn" label="Vencimento" />
        </div>
        <LfSelect id="lf-title-contact" v-model="draft.contactId" :options="contactChoices"
          label="Contato (opcional)" />
        <NcButton v-if="contactNextAfterId" variant="tertiary"
          @click="loadMoreCatalog('contacts')">Carregar mais contatos</NcButton>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="formOpen=false">Cancelar</NcButton>
          <NcButton type="submit" variant="primary" :disabled="loading">Cadastrar título</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(settlement)" name="Registrar baixa operacional"
      @update:open="settlement = $event ? settlement : null">
      <form class="lf-dialog-form" @submit.prevent="settle">
        <p v-if="settlement"><strong>{{settlement.description}}</strong>
          · Em aberto: {{money(outstanding(settlement))}}</p>
        <NcTextField v-model="settleForm.amount" label="Valor da baixa"
          inputmode="decimal" placeholder="0,00" required />
        <LfDateField id="lf-settle-date" v-model="settleForm.paidOn" label="Data" />
        <NcTextField v-model="settleForm.note" label="Observação" maxlength="255" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="settlement=null">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading">
            Registrar baixa
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog content-classes="lf-finance-dialog-content" :open="Boolean(cancelTarget)" name="Cancelar título"
      @update:open="cancelTarget = $event ? cancelTarget : null">
      <div class="lf-dialog-form">
        <p>Cancelar <strong>{{cancelTarget?.description}}</strong>? O histórico será preservado.</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="cancelTarget=null">Manter título</NcButton>
          <NcButton variant="error" :disabled="loading" @click="cancel">Confirmar cancelamento</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
