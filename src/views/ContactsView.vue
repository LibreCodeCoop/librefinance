<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import FieldStudioShortcut from '../components/FieldStudioShortcut.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'

const props = defineProps({ section: { type: String, default: 'contacts' } })
const w = inject('lfWorkspace')
const { activeId, manager, busy, contacts, items, contactNextAfterId,
  itemNextAfterId, money, refreshCurrent, loadMoreCatalog } = w
const active = computed(() => props.section === 'items' ? 'items' : 'contacts')
const personal = computed(() => w.space.value?.kind === 'personal')
const pageTitle = computed(() => active.value === 'items' ? 'Produtos e serviços'
  : personal.value ? 'Pessoas e estabelecimentos' : 'Clientes e fornecedores')
const editId = ref(null)
const dialog = ref('')
const working = ref(false)
const problem = ref('')
const form = ref({
  name:'', kind:'customer', email:'', taxId:'', active:true,
  sku:'', description:'', price:'', customFields:{},
})
// Existing customer/vendor/both enum is unchanged; only the labels change.
const contactKinds = computed(() => personal.value ? [
  {value:'customer',label:'De quem recebo'},
  {value:'vendor',label:'A quem pago'},
  {value:'both',label:'Pago e recebo'},
] : [
  {value:'customer',label:'Cliente'},
  {value:'vendor',label:'Fornecedor'},
  {value:'both',label:'Cliente e fornecedor'},
])
const itemKinds = [{value:'service',label:'Serviço'},{value:'product',label:'Produto'}]
const search = ref('')
const shownContacts = computed(() => contacts.value.filter(c =>
  (c.name || '').toLowerCase().includes(search.value.trim().toLowerCase())))
const shownItems = computed(() => items.value.filter(i =>
  (i.name || '').toLowerCase().includes(search.value.trim().toLowerCase())
  || (i.sku || '').toLowerCase().includes(search.value.trim().toLowerCase())))
function newRecord() {
  editId.value = null
  form.value = { name:'',kind:active.value==='contacts'?'customer':'service',
    email:'',taxId:'',active:true,sku:'',description:'',price:'',customFields:{} }
  problem.value = ''
  dialog.value = active.value
}
function editContact(row) {
  editId.value = row.id
  form.value = { ...form.value,name:row.name,kind:row.kind,
    email:row.email||'',taxId:row.tax_id||'',active:row.active,
    customFields:{...row.custom_fields} }
  problem.value = ''
  dialog.value = 'contacts'
}
function editItem(row) {
  editId.value = row.id
  form.value = { ...form.value,name:row.name,kind:row.kind,sku:row.sku,
    description:row.description || '',price:Math.floor(row.unit_price_minor/100)
      + ',' + String(row.unit_price_minor%100).padStart(2,'0'),active:row.active,
    customFields:{...row.custom_fields} }
  problem.value = ''
  dialog.value = 'items'
}
async function save() {
  if (working.value || !form.value.name.trim()) return
  working.value = true
  problem.value = ''
  try {
    if (dialog.value === 'contacts') {
      const data = {name:form.value.name.trim(),kind:form.value.kind,
        email:form.value.email.trim()||null,taxId:form.value.taxId.trim()||null,
        active:form.value.active,customFields:form.value.customFields}
      await financeApi('librefinance',activeId.value,
        '/contacts' + (editId.value ? '/' + editId.value : ''),
        editId.value ? 'PUT' : 'POST',data)
    } else {
      const data = {sku:form.value.sku.trim(),name:form.value.name.trim(),
        kind:form.value.kind,description:form.value.description.trim()||null,
        unitPriceMinor:toMinorUnits(form.value.price),active:form.value.active,customFields:form.value.customFields}
      await financeApi('librefinance',activeId.value,
        '/items' + (editId.value ? '/' + editId.value : ''),
        editId.value ? 'PUT' : 'POST',data)
    }
    await refreshCurrent()
    dialog.value = ''
  } catch (e) { problem.value = financeError(e) }
  finally { working.value = false }
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="pageTitle"
      eyebrow="CADASTROS"
      :description="active === 'contacts'
        ? (personal
          ? 'Registre pessoas e estabelecimentos de quem você recebe ou a quem paga na vida pessoal.'
          : 'Organize quem compra de você, de quem você compra e seus dados de contato.')
        : (personal
          ? 'Itens opcionais para detalhar compras e serviços usados em casa.'
          : 'Gerencie os produtos e serviços utilizados em vendas e compras.')">
      <template #actions>
        <FieldStudioShortcut :entity-kind="active==='contacts'?'contact':'item'" />
        <NcButton v-if="manager" variant="primary" @click="newRecord">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ active === 'contacts' ? 'Novo contato' : 'Novo produto ou serviço' }}
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>{{ active === 'contacts'
          ? (personal ? 'Contatos pessoais' : 'Pessoas e organizações')
          : 'Catálogo de itens' }}</h2>
        <NcTextField v-model="search" label="Filtrar registros carregados"
          type="search" placeholder="Pesquisar por nome" class="lf-search-field" />
      </div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead v-if="active==='contacts'"><tr><th>Contato</th><th>Tipo</th><th>E-mail</th>
            <th>Identificação</th><th>Situação</th><th v-if="manager">Ações</th></tr></thead>
          <thead v-else><tr><th>Código</th><th>Nome</th><th>Tipo</th>
            <th class="lf-numeric">Preço</th><th>Situação</th><th v-if="manager">Ações</th></tr></thead>
          <tbody v-if="active==='contacts'"><tr v-for="c in shownContacts" :key="c.id">
            <td><strong>{{ c.name }}</strong></td>
            <td>{{ contactKinds.find(k=>k.value===c.kind)?.label || c.kind }}</td>
            <td>{{ c.email||'—' }}</td><td>{{ c.tax_id||'—' }}</td>
            <td>{{ c.active ? 'Ativo' : 'Inativo' }}</td>
            <td v-if="manager"><NcButton variant="tertiary" :aria-label="'Editar ' + c.name"
              @click="editContact(c)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
          <tbody v-else><tr v-for="i in shownItems" :key="i.id">
            <td class="lf-code">{{ i.sku }}</td><td><strong>{{ i.name }}</strong></td>
            <td>{{ i.kind==='service'?'Serviço':'Produto' }}</td>
            <td class="lf-numeric">{{ money(i.unit_price_minor) }}</td>
            <td>{{ i.active?'Ativo':'Inativo' }}</td>
            <td v-if="manager"><NcButton variant="tertiary" :aria-label="'Editar ' + i.name"
              @click="editItem(i)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="active==='contacts' && !shownContacts.length"
          name="Nenhum contato nesta página" />
        <NcEmptyContent v-if="active==='items' && !shownItems.length"
          name="Nenhum produto ou serviço nesta página" />
      </div>
      <div class="lf-table-footer">
        <NcButton v-if="active==='contacts'&&contactNextAfterId"
          variant="secondary" @click="loadMoreCatalog('contacts')">Mais contatos</NcButton>
        <NcButton v-if="active==='items'&&itemNextAfterId"
          variant="secondary" @click="loadMoreCatalog('items')">Mais produtos e serviços</NcButton>
      </div>
    </section>

    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(dialog)"
      :name="(editId ? 'Editar ' : 'Novo ') + (dialog==='items'?'produto ou serviço':'contato')"
      @update:open="dialog = $event ? dialog : ''">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="form.name" label="Nome" required maxlength="160" />
        <LfSelect v-if="dialog==='contacts'" id="lf-contact-kind" v-model="form.kind"
          :options="contactKinds" label="Tipo de contato" />
        <template v-if="dialog==='contacts'">
          <NcTextField v-model="form.email" label="E-mail (opcional)" type="email" maxlength="190" />
          <NcTextField v-model="form.taxId" label="CPF/CNPJ ou identificação (opcional)" maxlength="40" />
        </template>
        <template v-else>
          <NcTextField v-model="form.sku" label="Código/SKU" maxlength="48" required />
          <LfSelect id="lf-item-kind" v-model="form.kind" :options="itemKinds" label="Tipo" />
          <NcTextField v-model="form.price" label="Preço unitário" placeholder="0,00"
            inputmode="decimal" required />
          <NcTextArea v-model="form.description" label="Descrição adicional" />
        </template>
        <CustomFieldsPanel :key="activeId + ':' + dialog" v-model="form.customFields"
          :entity-kind="dialog === 'contacts' ? 'contact' : 'item'" />
        <NcCheckboxRadioSwitch v-if="editId" v-model="form.active" type="checkbox">
          Registro ativo
        </NcCheckboxRadioSwitch>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=''">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="working">
            Salvar cadastro
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>
