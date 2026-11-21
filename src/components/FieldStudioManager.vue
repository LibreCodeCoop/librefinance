<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { inject, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
const w = inject('lfWorkspace')
const types = [
  { value:'text', label:'Texto curto' }, { value:'textarea', label:'Texto longo' },
  { value:'email', label:'E-mail' }, { value:'url', label:'Endereço web' },
  { value:'integer', label:'Número inteiro' }, { value:'number', label:'Decimal exato' },
  { value:'date', label:'Data' }, { value:'boolean', label:'Sim / Não' },
  { value:'select', label:'Lista de opções' },
]
const entities = [
  { value:'company', label:'Empresa' }, { value:'contact', label:'Cliente ou fornecedor' },
  { value:'item', label:'Produto ou serviço' }, { value:'journal', label:'Receita ou despesa' },
]
const entity = ref('company')
const fields = ref([])
const loading = ref(false)
const problem = ref('')
const dialog = ref(false)
const draft = ref({})
let generation = 0
async function load() {
  const token = ++generation
  loading.value = true
  problem.value = ''
  fields.value = []
  try {
    const response = await financeApi('librefinance', Number(w.activeId.value),
      '/custom-fields/' + entity.value)
    if (token === generation) fields.value = response.fields
  } catch (e) { if (token === generation) problem.value = financeError(e) }
  finally { if (token === generation) loading.value = false }
}
watch([entity, () => w.activeId.value], load)
onMounted(load)
function begin(field = null) {
  problem.value = ''
  draft.value = field ? {
    id: field.id, key: field.key, label: field.label, type: field.type,
    required: field.required, visible: field.visible, active: field.active,
    help: field.help || '', sortOrder: field.sortOrder,
    columnSpan: field.columnSpan, options: (field.options || []).join('\n'),
  } : { id: 0, key: '', label: '', type: 'text', required: false,
    visible: true, active: true, help: '', sortOrder: fields.value.length * 10,
    columnSpan: 12, options: '' }
  if (!draft.value.visible) draft.value.required = false
  dialog.value = true
}
async function save() {
  if (loading.value) return
  problem.value = ''
  loading.value = true
  try {
    const f = draft.value
    const payload = {
      label: f.label, required: f.visible && f.required, visible: f.visible,
      active: f.active, help: f.help, sortOrder: Number(f.sortOrder) || 0,
      columnSpan: Number(f.columnSpan),
    }
    if (!f.id) Object.assign(payload, { key: f.key, type: f.type,
      options: f.type === 'select' ? f.options.split('\n').map(s => s.trim()).filter(Boolean) : [] })
    await financeApi('librefinance', Number(w.activeId.value),
      '/custom-fields/' + entity.value + (f.id ? '/' + f.id : ''), f.id ? 'PUT' : 'POST', payload)
    dialog.value = false
    loading.value = false
    await load()
  } catch (e) { problem.value = financeError(e); loading.value = false }
}
</script>
<template>
  <div class="lf-field-studio">
    <div class="lf-section-heading"><div>
      <h2>Campos personalizados</h2>
      <p>Configure quais informações adicionais aparecem em cada formulário. Essas definições pertencem somente à gestão financeira atual.</p>
    </div><NcButton variant="primary" :disabled="fields.length >= 30" @click="begin()">Novo campo</NcButton></div>
    <LfSelect id="lf-field-studio-entity" v-model="entity" label="Onde este campo será utilizado?"
      :options="entities" />
    <NcNoteCard type="info">Campos ocultos não aparecem nos formulários comuns e não são entregues
      pela API a visualizadores. São metadados técnicos para gestores e integrações autorizadas.
      Um campo oculto não pode ser obrigatório. Desativar não apaga valores anteriores.</NcNoteCard>
    <NcNoteCard v-if="problem && !dialog" type="error" role="alert">{{ problem }}</NcNoteCard>
    <div v-for="field in fields" :key="field.id" class="lf-custom-field-row">
      <div><strong>{{ field.label }}</strong><small>
        {{ field.key }} · {{ types.find(t=>t.value===field.type)?.label }} ·
        {{ field.visible ? 'Visível' : 'Oculto' }} ·
        {{ field.required ? 'Obrigatório' : 'Opcional' }} ·
        {{ field.active ? 'Ativo' : 'Desativado' }}
      </small><small v-if="field.help">{{ field.help }}</small></div>
      <NcButton variant="tertiary" :aria-label="'Configurar ' + field.label"
        @click="begin(field)">Editar</NcButton>
    </div>
    <NcEmptyContent v-if="!fields.length && !loading" name="Nenhum campo nesta entidade"
      description="Crie um campo apenas quando precisar registrar informações além das existentes." />
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="draft.id ? 'Editar campo adicional' : 'Criar campo adicional'" @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="draft.label" label="Nome do campo" maxlength="100" required />
        <NcTextField v-model="draft.key" label="Identificador técnico"
          :disabled="Boolean(draft.id)" placeholder="ex.: centro_custo" maxlength="40" required />
        <LfSelect id="lf-field-studio-type" v-model="draft.type" label="Tipo de dado"
          :disabled="Boolean(draft.id)" :options="types" />
        <NcTextArea v-if="draft.type === 'select'" v-model="draft.options"
          label="Opções (uma por linha)" :disabled="Boolean(draft.id)" />
        <NcTextField v-model="draft.help" label="Texto de ajuda (opcional)" maxlength="255" />
        <div class="lf-form-grid">
          <LfSelect id="lf-field-studio-width" v-model="draft.columnSpan"
            label="Largura no formulário" :options="[{value:12,label:'Linha inteira'}, {value:6,label:'Meia linha'}]" />
          <NcTextField v-model="draft.sortOrder" label="Ordem de exibição" inputmode="numeric" />
        </div>
        <NcCheckboxRadioSwitch v-model="draft.visible" type="checkbox"
          @update:model-value="!$event && (draft.required=false)">Exibir no formulário comum</NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-model="draft.required" type="checkbox" :disabled="!draft.visible">Obrigatório ao salvar</NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-if="draft.id" v-model="draft.active" type="checkbox">Campo ativo</NcCheckboxRadioSwitch>
        <NcNoteCard type="info">Chave, tipo e opções de um campo existente são preservados para
          evitar que valores anteriores mudem de significado.</NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="dialog=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading">Salvar campo</NcButton></div>
      </form>
    </NcDialog>
  </div>
</template>
