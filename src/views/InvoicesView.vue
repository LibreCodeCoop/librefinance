<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFileDocumentOutline, mdiFilterVariant } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import CashPaymentDialog from '../components/CashPaymentDialog.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'
const props = defineProps({
  section: { type: String, default: 'invoices' },
  embedded: { type: Boolean, default: false },
})
const w=inject('lfWorkspace')
const flow=inject('lfFlowContext',null)
const documentKind=inject('lfInvoiceKind',ref('invoice'))
const navigate=inject('lfNavigate',()=>{})
const focusedInvoice=inject('lfFocusedInvoice',ref(null))
const focusedObligation=inject('lfFocusedObligation',ref(null))
const isPurchase=computed(()=>documentKind.value==='bill')
function commercialStatus(status) {
  return ({
    draft: 'Rascunho',
    issued: isPurchase.value?'Compra confirmada':'Venda confirmada',
    partially_paid: isPurchase.value?'Pagamento parcial':'Recebimento parcial',
    paid: 'Liquidado por lançamento',
  })[status] || status
}
const canReadObligations=computed(()=>w.modulesLoadedFor.value===Number(w.activeId.value)
  &&w.modules.value.some(item=>item.id==='librefinance_obligations'&&item.enabled))
const { activeId, contacts, accounts, journals, nextBeforeId, loadMore, money, writable, manager }=w
const current='librefinance_invoicing'
const today=new Date().toLocaleDateString('sv-SE')
const tab=computed(() => props.section === 'taxes' ? 'taxes' : 'invoices')
const invoices=ref([])
const hasOtherDocs=ref(false)
const hasListedInvoices=ref(false)
const showInvoiceFilters=computed(() => invoices.value.length > 0 || !!invoiceNext.value
  || hasOtherDocs.value || hasListedInvoices.value)
const localSort=useLoadedSort('')
const orderedInvoices=computed(()=>localSort.order(invoices.value))
const taxes=ref([])
const invoiceNext=ref(null)
const taxNext=ref(null)
const busy=ref(false)
const error=ref('')
const success=ref('')
const newInvoice=ref(false)
const newRule=ref(false)
const chosen=ref(null)
const cashTarget=ref(null)
function beginCashPayment() {
  if (!chosen.value || !chosenObligationId.value) return
  cashTarget.value=chosen.value
  chosen.value=null
  allocated.value=false
}
async function onCashPaid(result) {
  const target=cashTarget.value
  cashTarget.value=null
  success.value='Recebimento ou pagamento registrado sem duplicidade. Lançamento #'
    +result.journalId+'.'
  await load()
  if(target)await showInvoice(target.id)
}
const chosenObligationId=ref(null)
const allocated=ref(false)
const form=ref({
  number:'',kind:documentKind.value,contactId:0,country:'',region:'',
  issueOn:today,dueOn:today,
  lines:[{description:'',quantity:1,price:'',taxRuleId:0}],
})
const rule=ref({
  code:'',label:'',country:'',region:'',kind:'vat',rate:'0,00',
  inclusive:false,from:today,through:'',sourceRef:'',
})
const allocation=ref({bankAccountId:0,journalId:0,amount:''})
const allocationMinor=computed(()=>{
  try { return toMinorUnits(allocation.value.amount) } catch { return 0 }
})
const journalChoices=computed(()=>{
  const bankId=Number(allocation.value.bankAccountId)
  const expected=isPurchase.value?-allocationMinor.value:allocationMinor.value
  if(!bankId||!expected)return []
  return journals.value.filter(row=>row.postings?.some(posting=>
    Number(posting.accountId)===bankId&&Number(posting.amountMinor)===expected))
    .map(row=>({value:Number(row.id),
      label:'#'+row.id+' · '+(row.effective_on||'')+' · '+(row.memo||'Lançamento')
        +' · '+money(Math.abs(expected))}))
})
function minorInput(value) {
  const minor=Math.max(0,Number(value)||0)
  return Math.floor(minor/100)+','+String(minor%100).padStart(2,'0')
}
watch([()=>allocation.value.bankAccountId,()=>allocation.value.amount],()=>{
  if(!journalChoices.value.some(row=>row.value===Number(allocation.value.journalId))){
    allocation.value.journalId=0
  }
})
const bankChoices=computed(()=>accounts.value.filter(a=>a.is_bank&&a.active)
  .map(a=>({value:a.id,label:a.name})))
const contactsChoices=computed(()=>contacts.value.filter(c=>c.active)
  .map(c=>({value:c.id,label:c.name})))
const taxChoices=computed(()=>[{value:0,label:'Sem imposto'},
  ...taxes.value.map(t=>({value:t.id,label:t.label+' ('+(t.rate_bps/100).toFixed(2)+'%)'}))])
const ruleKinds=[
  {value:'vat',label:'IVA / VAT'},{value:'sales',label:'Imposto sobre venda'},
  {value:'withholding',label:'Retenção'},{value:'other',label:'Outro imposto'},
]
let generation=0
function closeDetails(open) {if(!open){chosen.value=null;chosenObligationId.value=null;allocated.value=false}}
function rateBps(value) {
  const m=String(value).trim().match(/^(\d{1,4})(?:[.,](\d{1,2}))?$/)
  if(!m)throw new Error('Alíquota inválida')
  const n=Number(m[1])*100+Number((m[2]||'').padEnd(2,'0'))
  if(n>100000)throw new Error('Alíquota acima do limite de 1000%')
  return n
}
async function load() {
  const token=++generation;busy.value=true;error.value=''
  try{
    const [docs,tax]=await Promise.all([
      financeApi(current,activeId.value,'/invoices?kind='+documentKind.value),
      financeApi(current,activeId.value,'/tax-rules'),
    ])
    if(token!==generation)return
    invoices.value=docs.invoices;invoiceNext.value=docs.nextAfterId
    if (docs.invoices.length) hasListedInvoices.value=true
    taxes.value=tax.rules;taxNext.value=tax.nextAfterId
    // An empty direction must not hide existing faturas of the opposite kind.
    hasOtherDocs.value=false
    if (!docs.invoices.length && tab.value==='invoices') {
      const opposite=documentKind.value==='invoice'?'bill':'invoice'
      const other=await financeApi(current,activeId.value,'/invoices?kind='+opposite)
      if(token!==generation)return
      hasOtherDocs.value=other.invoices.length>0
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function more(kind) {
  const cursor=kind==='invoice'?invoiceNext.value:taxNext.value
  if(!cursor||busy.value)return
  const token=generation;busy.value=true
  try{
    const response=await financeApi(current,activeId.value,
      (kind==='invoice'?'/invoices?kind='+documentKind.value+'&afterId='+cursor:'/tax-rules?afterId='+cursor))
    if(token!==generation)return
    if(kind==='invoice'){
      invoices.value=[...invoices.value,...response.invoices]
      invoiceNext.value=response.nextAfterId
    }else{
      taxes.value=[...taxes.value,...response.rules]
      taxNext.value=response.nextAfterId
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function perform(task,notice) {
  if(busy.value)return false
  const token=++generation;busy.value=true;error.value='';success.value=''
  try{
    await task()
    if(token!==generation)return false
    success.value=notice
    await load()
    return true
  }catch(e){if(token===generation)error.value=financeError(e);return false}
  finally{if(token===generation)busy.value=false}
}
function addLine() {
  if(form.value.lines.length>=20){error.value='Máximo de 20 linhas por fatura';return}
  form.value.lines.push({description:'',quantity:1,price:'',taxRuleId:0})
}
async function create() {
  if(form.value.lines.some(line=>!line.description.trim()
    || !Number.isSafeInteger(Number(line.quantity)) || Number(line.quantity)<1
    || Number(line.quantity)>100000)) {
    error.value='Revise descrição e quantidade das linhas.'
    return
  }
  const successResult=await perform(async()=>{
    const lines=form.value.lines.map(line=>({
      description:line.description.trim(),quantity:Number(line.quantity),
      unitPriceMinor:toMinorUnits(line.price),
      taxRuleId:Number(line.taxRuleId)||null,
    }))
    await financeApi(current,activeId.value,'/invoices','POST',{
      number:form.value.number.trim(),kind:documentKind.value,
      contactId:Number(form.value.contactId),issueOn:form.value.issueOn,
      dueOn:form.value.dueOn,country:form.value.country.trim(),
      region:form.value.region.trim()||null,lines,
    })
  },isPurchase.value?'Fatura de compra salva como rascunho, sem movimentar o banco.'
    :'Fatura de venda salva como rascunho, sem emissão fiscal.')
  if(successResult){
    newInvoice.value=false
    form.value={number:'',kind:documentKind.value,contactId:0,country:'',region:'',
      issueOn:today,dueOn:today,lines:[{description:'',quantity:1,price:'',taxRuleId:0}]}
  }
}
async function createRule() {
  const done=await perform(async()=>financeApi(current,activeId.value,'/tax-rules','POST',{
    code:rule.value.code.trim(),label:rule.value.label.trim(),
    country:rule.value.country.trim().toUpperCase(),region:rule.value.region.trim()||null,
    kind:rule.value.kind,rateBps:rateBps(rule.value.rate),inclusive:rule.value.inclusive,
    validFrom:rule.value.from,validThrough:rule.value.through||null,
    sourceRef:rule.value.sourceRef.trim(),
  }),'Regra registrada em versão imutável; conformidade legal não verificada.')
  if(done)newRule.value=false
}
async function showInvoice(id) {
  const token=generation
  error.value=''
  try{
    const response=await financeApi(current,activeId.value,'/invoices/'+id)
    if(token===generation){
      if(response.invoice.kind!==documentKind.value){
        error.value='Este documento pertence a outra área comercial.'
        return
      }
      chosen.value=response.invoice
      chosenObligationId.value=response.obligationId
      allocation.value={bankAccountId:0,journalId:0,
        amount:minorInput(Number(response.invoice.total_minor)
          -Number(response.invoice.paid_minor))}
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
}
async function issue() {
  if(!chosen.value)return
  const id=chosen.value.id
  const done=await perform(async()=>financeApi(current,activeId.value,
    '/invoices/'+id+'/issue','POST',{}),
    'Fatura confirmada internamente. Não é uma NFS-e.')
  if(done){chosen.value=null;await showInvoice(id)}
}
async function allocate() {
  if(!chosen.value)return
  if(!journalChoices.value.some(row=>row.value===Number(allocation.value.journalId))){
    error.value='Selecione um lançamento contabilizado que corresponda à conta e ao valor.'
    return
  }
  const id=chosen.value.id
  const done=await perform(async()=>financeApi(current,activeId.value,
    '/invoices/'+id+'/allocations','POST',{
      bankAccountId:Number(allocation.value.bankAccountId),
      journalId:Number(allocation.value.journalId),
      amountMinor:toMinorUnits(allocation.value.amount),
    }),'Lançamento existente associado; nenhuma transferência criada.')
  if(done){allocated.value=false;chosen.value=null;await showInvoice(id)}
}
async function openFocusedInvoice() {
  const focus=focusedInvoice.value
  if(!focus||Number(focus.spaceId)!==Number(activeId.value)
    ||focus.kind!==documentKind.value)return
  focusedInvoice.value=null
  await showInvoice(Number(focus.id))
}
watch(activeId, () => {
  // A previous workspace's documents must not expose filters in a new empty space.
  hasListedInvoices.value=false
  hasOtherDocs.value=false
})
watch([activeId,documentKind],async()=>{
  chosen.value=null
  chosenObligationId.value=null
  allocated.value=false
  newInvoice.value=false
  form.value.kind=documentKind.value
  await load()
  await openFocusedInvoice()
},{immediate:true})
// Opening a detailed document must run after the initial active-space watcher,
// which resets forms while resolving the authorized workspace data.
watch(() => flow?.newDetailed.value, requested => {
  if (!requested || !w.writable.value || !w.activeId.value) return
  newInvoice.value=true
  flow.newDetailed.value=false
}, { immediate:true })
onBeforeUnmount(()=>++generation)
function openLinkedObligation() {
  if(!canReadObligations.value||!chosenObligationId.value||!chosen.value)return
  focusedObligation.value={spaceId:Number(activeId.value),
    id:Number(chosenObligationId.value)}
  chosen.value=null
  if(flow)flow.showAccounts()
  else navigate('obligations')
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader v-if="!embedded" :title="tab === 'taxes' ? 'Regras tributárias'
        : (isPurchase ? 'Documentos de compra' : 'Faturas de venda')"
      :eyebrow="tab === 'taxes' ? 'CONFIGURAÇÃO FISCAL' : 'FATURAS'"
      :description="tab === 'taxes'
        ? 'Configure regras de cálculo, jurisdição e vigência. Esta seção não emite documentos fiscais.'
        : (isPurchase
          ? 'Registre faturas recebidas de fornecedores com itens, tributos e vencimento. Após confirmar, o valor aparece em A pagar e receber.'
          : 'Cadastre faturas para clientes. Após confirmar, o valor aparece automaticamente em A pagar e receber.')">
      <template #actions>
        <NcButton v-if="tab === 'taxes' ? manager : writable" variant="primary"
          @click="tab==='taxes'?newRule=true:newInvoice=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{tab==='taxes'?'Nova regra':(isPurchase?'Nova fatura de compra':'Nova fatura de venda')}}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="tab==='taxes'" type="info">
      As regras configuradas são parâmetros tributários; não representam homologação fiscal.
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section v-if="tab==='invoices'" class="lf-surface">
      <div v-if="showInvoiceFilters" class="lf-section-heading">
        <h2>Faturas {{ isPurchase ? 'de compra' : 'de venda' }}</h2>
        <NcPopover v-if="flow" popup-role="dialog">
          <template #trigger>
            <NcButton id="lf-commercial-direction" variant="tertiary">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
              Tipo: {{ isPurchase ? 'Compras' : 'Vendas' }}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar documentos por tipo">
            <NcButton variant="tertiary" :aria-pressed="!isPurchase"
              @click="flow.setDocumentDirection('receivable')">Vendas</NcButton>
            <NcButton variant="tertiary" :aria-pressed="isPurchase"
              @click="flow.setDocumentDirection('payable')">Compras</NcButton>
          </div>
        </NcPopover>
      </div>
      <div v-if="busy && !invoices.length" class="lf-loading" role="status">
        Carregando faturas…
      </div>
      <div v-else-if="invoices.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'number',label:'Número'}, {key:'kind',label:'Tipo'},
              {key:'status',label:'Situação'}, {key:'due_on',label:'Vencimento'},
              {key:'total_minor',label:'Total'}, {key:'paid_minor',label:'Liquidado'}]"
              :key="col.key" :class="{'lf-numeric':['total_minor','paid_minor'].includes(col.key)}"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th><th>Ações</th>
          </tr></thead>
          <tbody><tr v-for="invoice in orderedInvoices" :key="invoice.id">
            <td class="lf-code">{{invoice.number}}</td>
            <td>{{invoice.kind==='invoice'?'Venda':'Compra'}}</td>
            <td><span class="lf-state">{{commercialStatus(invoice.status)}}</span></td>
            <td>{{invoice.due_on}}</td>
            <td class="lf-numeric">{{money(invoice.total_minor)}}</td>
            <td class="lf-numeric">{{money(invoice.paid_minor)}}</td>
            <td><NcButton size="small" variant="tertiary" @click="showInvoice(invoice.id)">
              Detalhes
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <NcEmptyContent v-else-if="!error"
        :name="hasOtherDocs ? 'Nenhuma fatura nesta categoria' : 'Nenhuma fatura cadastrada'"
        :description="hasOtherDocs
          ? 'Selecione a outra categoria para ver as faturas cadastradas.'
          : 'Cadastre uma fatura de compra ou de venda. Confirme-a para gerar o valor em A pagar e receber.'" />
      <p v-if="localSort.column.value && invoiceNext" class="lf-muted">
        Ordenação aplicada somente aos documentos carregados.</p>
      <div v-if="invoiceNext" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more('invoice')">Carregar mais</NcButton>
      </div>
    </section>
    <section v-else class="lf-surface">
      <div class="lf-section-heading"><h2>Regras de imposto</h2></div>
      <div v-if="taxes.length" class="lf-table-scroll"><table class="lf-data-table">
        <thead><tr><th>Código</th><th>Jurisdição</th><th>Tipo</th>
          <th>Alíquota</th><th>Vigência</th><th>Fonte</th></tr></thead>
        <tbody><tr v-for="tax in taxes" :key="tax.id">
          <td><strong>{{tax.code}} · {{tax.label}}</strong></td>
          <td>{{tax.country}} {{tax.region||''}}</td><td>{{tax.kind}}</td>
          <td>{{(tax.rate_bps/100).toFixed(2)}}% {{tax.inclusive?'(incluso)':''}}</td>
          <td>{{tax.valid_from}} a {{tax.valid_through||'indeterminado'}}</td>
          <td>{{tax.source_ref}}</td>
        </tr></tbody>
      </table></div>
      <NcEmptyContent v-else name="Nenhuma regra cadastrada" />
      <div v-if="taxNext" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more('tax')">Carregar mais regras</NcButton>
      </div>
    </section>

    <NcDialog content-classes="lf-finance-dialog-content" :open="newInvoice"
      :name="isPurchase ? 'Nova fatura de compra' : 'Nova fatura de venda'" size="large"
      @update:open="newInvoice=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <div class="lf-form-grid">
          <NcTextField v-model="form.number" label="Número" maxlength="80" required />
          <p class="lf-muted">Tipo: {{isPurchase ? 'Compra de fornecedor' : 'Venda a cliente'}}</p>
          <LfSelect id="lf-invoice-contact" v-model="form.contactId" :options="contactsChoices"
            label="Cliente ou fornecedor" />
          <NcTextField v-model="form.country" label="País (ISO 2, obrigatório)" maxlength="2" required />
          <NcTextField v-model="form.region" label="Região (opcional)" maxlength="32" />
          <LfDateField id="lf-invoice-date" v-model="form.issueOn" label="Emissão" />
          <LfDateField id="lf-invoice-due" v-model="form.dueOn" label="Vencimento" />
        </div>
        <h3>Itens da fatura</h3>
        <div v-for="(line,index) in form.lines" :key="index" class="lf-invoice-line">
          <NcTextField v-model="line.description" :label="'Descrição da linha '+(index+1)"
            maxlength="255" required />
          <NcTextField v-model="line.quantity" label="Quantidade" type="number" required />
          <NcTextField v-model="line.price" label="Preço unitário" inputmode="decimal"
            placeholder="0,00" required />
          <LfSelect :id="'lf-invoice-tax-'+index" v-model="line.taxRuleId"
            label="Imposto" :options="taxChoices" />
          <NcButton variant="tertiary" :disabled="form.lines.length===1"
            @click="form.lines.splice(index,1)">Retirar</NcButton>
        </div>
        <NcButton variant="secondary" :disabled="form.lines.length>=20" @click="addLine">
          Adicionar linha
        </NcButton>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="newInvoice=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">Salvar rascunho</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="newRule" name="Nova regra tributária"
      @update:open="newRule=$event">
      <form class="lf-dialog-form" @submit.prevent="createRule">
        <NcTextField v-model="rule.code" label="Código" required maxlength="40" />
        <NcTextField v-model="rule.label" label="Nome" required maxlength="150" />
        <NcTextField v-model="rule.country" label="País (ISO 2)" maxlength="2" required />
        <NcTextField v-model="rule.region" label="Região opcional" maxlength="32" />
        <LfSelect id="lf-tax-kind" v-model="rule.kind" :options="ruleKinds" label="Natureza" />
        <NcTextField v-model="rule.rate" label="Alíquota (%)" inputmode="decimal"
          placeholder="0,00" required />
        <NcCheckboxRadioSwitch v-model="rule.inclusive" type="checkbox"
          :disabled="rule.kind==='withholding'">Incluso no preço</NcCheckboxRadioSwitch>
        <div class="lf-form-grid">
          <LfDateField id="lf-tax-from" v-model="rule.from" label="Início" />
          <LfDateField id="lf-tax-through" v-model="rule.through" label="Fim (opcional)" />
        </div>
        <NcTextField v-model="rule.sourceRef" label="Fonte normativa"
          maxlength="255" required />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="newRule=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">Registrar versão</NcButton>
        </div>
      </form>
    </NcDialog>

    <CashPaymentDialog :open="Boolean(cashTarget)" source="invoice"
      :record="cashTarget" :kind="isPurchase?'payable':'receivable'"
      @update:open="cashTarget=$event?cashTarget:null"
      @paid="onCashPaid" />
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(chosen)" name="Detalhes da fatura"
      @update:open="closeDetails($event)">
      <div v-if="chosen" class="lf-dialog-form">
        <h3>{{chosen.number}}</h3>
        <p>Situação: {{commercialStatus(chosen.status)}}</p>
        <p v-if="chosenObligationId">Conta {{isPurchase?'a pagar':'a receber'}}
          vinculada #{{chosenObligationId}}. A associação do pagamento exige um
          lançamento contábil válido; ela não gera um segundo movimento.</p>
        <NcButton v-if="chosenObligationId && canReadObligations" variant="tertiary"
          @click="openLinkedObligation">Ver conta vinculada</NcButton>
        <NcNoteCard v-else-if="chosen.status!=='draft'" type="warning">
          Fatura histórica sem obrigação reconciliada. Associações de
          pagamento exigem conciliação explícita antes de continuar.
        </NcNoteCard>
        <div class="lf-kpis">
          <div class="lf-kpi"><span>Total</span>
            <strong>{{money(chosen.total_minor)}}</strong></div>
          <div class="lf-kpi"><span>Em aberto</span>
            <strong>{{money(chosen.total_minor-chosen.paid_minor)}}</strong></div>
        </div>
        <NcButton v-if="manager&&chosen.status==='draft'" variant="primary"
          :disabled="busy" @click="issue">Confirmar emissão comercial</NcButton>
        <template v-if="writable&&chosenObligationId&&['issued','partially_paid'].includes(chosen.status)">
          <NcNoteCard v-if="Number(chosen.tax_minor)||Number(chosen.withheld_minor)"
            type="warning">
            Este documento contém tributos ou retenções. Não é seguro
            contabilizar o total bruto como receita ou despesa.
            Registre as partidas tributárias corretas em Lançamentos contábeis
            e utilize Vincular movimentação já registrada.
          </NcNoteCard>
          <NcButton v-else variant="primary" @click="beginCashPayment">
            {{isPurchase?'Registrar pagamento':'Registrar recebimento'}}
          </NcButton>
          <NcButton v-if="!allocated" variant="secondary" @click="allocated=true">
            Vincular movimentação já registrada
          </NcButton>
          <form v-else class="lf-dialog-form" @submit.prevent="allocate">
            <NcNoteCard type="info">
              Selecione uma movimentação já contabilizada, com o mesmo valor e a
              mesma conta financeira. A associação não movimenta dinheiro novamente.
            </NcNoteCard>
            <LfSelect id="lf-invoice-bank" v-model="allocation.bankAccountId"
              label="Conta financeira" :options="bankChoices" />
            <NcTextField v-model="allocation.amount" label="Valor movimentado"
              inputmode="decimal" required />
            <LfSelect id="lf-invoice-journal" v-model="allocation.journalId"
              label="Lançamento contabilizado" :options="journalChoices" />
            <NcNoteCard v-if="!journalChoices.length" type="warning">
              Nenhum lançamento correspondente nas páginas carregadas.
              Se o pagamento ainda não foi contabilizado, registre-o em
              Lançamentos contábeis e retorne a este documento.
            </NcNoteCard>
            <div class="lf-form-actions">
              <NcButton v-if="nextBeforeId" variant="tertiary"
                :disabled="busy" @click="loadMore">Carregar lançamentos anteriores</NcButton>
              <NcButton variant="tertiary" @click="chosen=null;navigate('transactions')">
                Abrir lançamentos contábeis
              </NcButton>
              <NcButton variant="primary" type="submit"
                :disabled="busy||!journalChoices.some(row=>row.value===Number(allocation.journalId))">
                Associar sem duplicar movimento
              </NcButton>
            </div>
          </form>
        </template>
        <NcButton variant="tertiary" @click="chosen=null;allocated=false">
          Fechar detalhes
        </NcButton>
      </div>
    </NcDialog>
  </div>
</template>
