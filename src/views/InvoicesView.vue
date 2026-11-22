<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFileDocumentOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'
const props = defineProps({ section: { type: String, default: 'invoices' } })
const w=inject('lfWorkspace')
const { activeId, contacts, accounts, money, writable, manager }=w
const current='librefinance_invoicing'
const today=new Date().toLocaleDateString('sv-SE')
const tab=computed(() => props.section === 'taxes' ? 'taxes' : 'invoices')
const invoices=ref([])
const taxes=ref([])
const invoiceNext=ref(null)
const taxNext=ref(null)
const busy=ref(false)
const error=ref('')
const success=ref('')
const newInvoice=ref(false)
const newRule=ref(false)
const chosen=ref(null)
const chosenObligationId=ref(null)
const allocated=ref(false)
const form=ref({
  number:'',kind:'invoice',contactId:0,country:'',region:'',
  issueOn:today,dueOn:today,
  lines:[{description:'',quantity:1,price:'',taxRuleId:0}],
})
const rule=ref({
  code:'',label:'',country:'',region:'',kind:'vat',rate:'0,00',
  inclusive:false,from:today,through:'',sourceRef:'',
})
const allocation=ref({bankAccountId:0,journalId:'',amount:''})
const bankChoices=computed(()=>accounts.value.filter(a=>a.is_bank&&a.active)
  .map(a=>({value:a.id,label:a.name})))
const contactsChoices=computed(()=>contacts.value.filter(c=>c.active)
  .map(c=>({value:c.id,label:c.name})))
const taxChoices=computed(()=>[{value:0,label:'Sem imposto'},
  ...taxes.value.map(t=>({value:t.id,label:t.label+' ('+(t.rate_bps/100).toFixed(2)+'%)'}))])
const kinds=[{value:'invoice',label:'Fatura de venda'},{value:'bill',label:'Conta de compra'}]
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
      financeApi(current,activeId.value,'/invoices'),
      financeApi(current,activeId.value,'/tax-rules'),
    ])
    if(token!==generation)return
    invoices.value=docs.invoices;invoiceNext.value=docs.nextAfterId
    taxes.value=tax.rules;taxNext.value=tax.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function more(kind) {
  const cursor=kind==='invoice'?invoiceNext.value:taxNext.value
  if(!cursor||busy.value)return
  const token=generation;busy.value=true
  try{
    const response=await financeApi(current,activeId.value,
      (kind==='invoice'?'/invoices':'/tax-rules')+'?afterId='+cursor)
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
      number:form.value.number.trim(),kind:form.value.kind,
      contactId:Number(form.value.contactId),issueOn:form.value.issueOn,
      dueOn:form.value.dueOn,country:form.value.country.trim(),
      region:form.value.region.trim()||null,lines,
    })
  },'Fatura comercial criada como rascunho, sem emissão fiscal.')
  if(successResult){
    newInvoice.value=false
    form.value={number:'',kind:'invoice',contactId:0,country:'',region:'',
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
      chosen.value=response.invoice
      chosenObligationId.value=response.obligationId
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
}
async function issue() {
  if(!chosen.value)return
  const id=chosen.value.id
  const done=await perform(async()=>financeApi(current,activeId.value,
    '/invoices/'+id+'/issue','POST',{}),
    'Fatura comercial emitida internamente. Não é uma NFS-e.')
  if(done){chosen.value=null;await showInvoice(id)}
}
async function allocate() {
  if(!chosen.value)return
  const id=chosen.value.id
  const done=await perform(async()=>financeApi(current,activeId.value,
    '/invoices/'+id+'/allocations','POST',{
      bankAccountId:Number(allocation.value.bankAccountId),
      journalId:Number(allocation.value.journalId),
      amountMinor:toMinorUnits(allocation.value.amount),
    }),'Lançamento existente associado; nenhuma transferência criada.')
  if(done){allocated.value=false;chosen.value=null;await showInvoice(id)}
}
onMounted(load)
onBeforeUnmount(()=>++generation)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="tab === 'taxes' ? 'Regras tributárias' : 'Faturas e compras'"
      :eyebrow="tab === 'taxes' ? 'CONFIGURAÇÃO FISCAL' : 'DOCUMENTOS COMERCIAIS'"
      :description="tab === 'taxes'
        ? 'Configure regras de cálculo, jurisdição e vigência. Esta seção não emite documentos fiscais.'
        : 'Crie e acompanhe faturas de venda e documentos de compra, separados da configuração de impostos.'">
      <template #actions>
        <NcButton v-if="tab === 'taxes' ? manager : writable" variant="primary"
          @click="tab==='taxes'?newRule=true:newInvoice=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{tab==='taxes'?'Nova regra':'Nova fatura'}}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard type="info">
      {{ tab === 'taxes'
        ? 'As regras configuradas são parâmetros para documentos comerciais; não representam homologação tributária.'
        : 'Faturas comerciais não são notas fiscais. A emissão de NFS-e permanece em recurso separado.' }}
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section v-if="tab==='invoices'" class="lf-surface">
      <div class="lf-section-heading"><h2>Documentos comerciais</h2></div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Número</th><th>Tipo</th><th>Situação</th>
            <th>Vencimento</th><th class="lf-numeric">Total</th>
            <th class="lf-numeric">Liquidado</th><th>Ações</th></tr></thead>
          <tbody><tr v-for="invoice in invoices" :key="invoice.id">
            <td class="lf-code">{{invoice.number}}</td>
            <td>{{invoice.kind==='invoice'?'Venda':'Compra'}}</td>
            <td><span class="lf-state">{{invoice.status}}</span></td>
            <td>{{invoice.due_on}}</td>
            <td class="lf-numeric">{{money(invoice.total_minor)}}</td>
            <td class="lf-numeric">{{money(invoice.paid_minor)}}</td>
            <td><NcButton size="small" variant="tertiary" @click="showInvoice(invoice.id)">
              Detalhes
            </NcButton></td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="!invoices.length" name="Nenhum documento comercial"
          description="Crie uma fatura de venda ou conta de compra." />
      </div>
      <div v-if="invoiceNext" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more('invoice')">Carregar mais</NcButton>
      </div>
    </section>
    <section v-else class="lf-surface">
      <div class="lf-section-heading"><h2>Regras de imposto</h2></div>
      <div class="lf-table-scroll"><table class="lf-data-table">
        <thead><tr><th>Código</th><th>Jurisdição</th><th>Tipo</th>
          <th>Alíquota</th><th>Vigência</th><th>Fonte</th></tr></thead>
        <tbody><tr v-for="tax in taxes" :key="tax.id">
          <td><strong>{{tax.code}} · {{tax.label}}</strong></td>
          <td>{{tax.country}} {{tax.region||''}}</td><td>{{tax.kind}}</td>
          <td>{{(tax.rate_bps/100).toFixed(2)}}% {{tax.inclusive?'(incluso)':''}}</td>
          <td>{{tax.valid_from}} a {{tax.valid_through||'indeterminado'}}</td>
          <td>{{tax.source_ref}}</td>
        </tr></tbody>
      </table><NcEmptyContent v-if="!taxes.length" name="Nenhuma regra cadastrada" /></div>
      <div v-if="taxNext" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more('tax')">Carregar mais regras</NcButton>
      </div>
    </section>

    <NcDialog content-classes="lf-finance-dialog-content" :open="newInvoice" name="Nova fatura comercial" size="large"
      @update:open="newInvoice=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <div class="lf-form-grid">
          <NcTextField v-model="form.number" label="Número" maxlength="80" required />
          <LfSelect id="lf-invoice-kind" v-model="form.kind" :options="kinds" label="Tipo" />
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

    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(chosen)" name="Detalhes da fatura"
      @update:open="closeDetails($event)">
      <div v-if="chosen" class="lf-dialog-form">
        <h3>{{chosen.number}}</h3>
        <p>Estado: {{chosen.status}}</p>
        <p v-if="chosenObligationId">Título financeiro vinculado #{{chosenObligationId}}.
          A baixa é realizada por um lançamento contábil válido.</p>
        <NcNoteCard v-else-if="chosen.status!=='draft'" type="warning">
          Documento histórico sem obrigação reconciliada. Associações de
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
          <NcButton v-if="!allocated" @click="allocated=true">Associar lançamento</NcButton>
          <form v-else class="lf-dialog-form" @submit.prevent="allocate">
            <LfSelect id="lf-invoice-bank" v-model="allocation.bankAccountId"
              label="Conta financeira" :options="bankChoices" />
            <NcTextField v-model="allocation.journalId" label="ID do lançamento existente"
              type="number" required />
            <NcTextField v-model="allocation.amount" label="Valor da associação"
              inputmode="decimal" required />
            <NcButton variant="primary" type="submit" :disabled="busy">
              Vincular lançamento existente
            </NcButton>
          </form>
        </template>
        <NcButton variant="tertiary" @click="chosen=null;allocated=false">
          Fechar detalhes
        </NcButton>
      </div>
    </NcDialog>
  </div>
</template>
