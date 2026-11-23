<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
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
  {value:'text',label:'Texto curto'}, {value:'textarea',label:'Texto longo'},
  {value:'email',label:'E-mail'}, {value:'url',label:'Endereço web'},
  {value:'integer',label:'Número inteiro'}, {value:'number',label:'Decimal exato'},
  {value:'date',label:'Data'}, {value:'boolean',label:'Sim / Não'},
  {value:'select',label:'Lista de opções'},
]
const entities = computed(() => [
  {value:'company',label:w.space.value?.kind==='personal'?'Organização (opcional)':'Empresa / organização',
    description:'Dados da organização, endereço e informações administrativas'},
  {value:'contact',label:w.space.value?.kind==='personal'?'Pessoas e estabelecimentos':'Clientes e fornecedores',
    description:'Informações adicionais dos contatos cadastrados'},
  {value:'item',label:'Produtos e serviços',description:'Dados do catálogo de itens'},
  {value:'invoice',label:'Faturas de venda',description:'Informações associadas a cada venda detalhada'},
  {value:'bill',label:'Faturas de compra',description:'Informações associadas a cada compra detalhada'},
  {value:'journal',label:'Movimentações realizadas',
    description:'Entradas e saídas já registradas no livro financeiro'},
])
const fields = ref([])
const loading = ref(false)
const saving = ref(false)
const problem = ref('')
const dialog = ref(false)
const step = ref('choose')
const draft = ref({})
const totals = computed(() => entities.value.map(entry => ({
  ...entry,
  fields:fields.value.filter(field => field.entityKind === entry.value),
})))
let generation = 0
async function load() {
  const token = ++generation
  if (!w.activeId.value) return
  const spaceId=Number(w.activeId.value)
  loading.value = true
  problem.value = ''
  try {
    const results = await Promise.all(entities.value.map(entry =>
      financeApi('librefinance', spaceId, '/custom-fields/' + entry.value)))
    if (token !== generation || spaceId !== Number(w.activeId.value)) return
    fields.value = results.flatMap(response => response.fields)
  } catch (e) {
    if (token === generation) {
      problem.value = financeError(e)
      fields.value = []
    }
  } finally {
    if (token === generation) loading.value = false
  }
}
watch(() => w.activeId.value, () => {
  dialog.value=false
  load()
}, {immediate:true})
onBeforeUnmount(() => { generation++ })
function newDraft() {
  return {id:0,entityKind:'',key:'',label:'',type:'text',
    required:false,visible:true,apiVisible:true,active:true,
    help:'',sortOrder:0,columnSpan:12,options:''}
}
function begin(field = null) {
  problem.value = ''
  draft.value = field ? {
    id:field.id,entityKind:field.entityKind,key:field.key,label:field.label,type:field.type,
    required:field.required,visible:field.visible,apiVisible:field.apiVisible,
    active:field.active,help:field.help||'',sortOrder:field.sortOrder,
    columnSpan:field.columnSpan,options:(field.options||[]).join('\n'),
  } : newDraft()
  if (!draft.value.visible || !draft.value.apiVisible) draft.value.required=false
  step.value=field?'details':'choose'
  dialog.value=true
}
function chooseEntity(entityKind) {
  const group=totals.value.find(entry => entry.value===entityKind)
  if (!group || group.fields.length>=30) return
  draft.value.entityKind=entityKind
  draft.value.sortOrder=group.fields.length*10
  step.value='details'
}
const currentEntity = computed(() =>
  entities.value.find(entry => entry.value===draft.value.entityKind))
function formVisibilityChanged(value) {
  if (!value) {
    // Conservative privacy default: hiding a field should not accidentally
    // make the historical private data available to API consumers.
    // Re-enabling API access remains an explicit independent choice.
    draft.value.required=false
    draft.value.apiVisible=false
  }
}
function autoKey(label) {
  return String(label).normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')
    .replace(/^[^a-z]+/,'').slice(0,40)
}
async function save() {
  if (saving.value || !draft.value.entityKind) return
  problem.value=''
  saving.value=true
  const spaceId=Number(w.activeId.value)
  try {
    const f=draft.value
    const payload={
      label:f.label.trim(),required:Boolean(f.visible && f.apiVisible && f.required),
      visible:Boolean(f.visible),apiVisible:Boolean(f.apiVisible),
      active:Boolean(f.active),help:f.help,sortOrder:Number(f.sortOrder)||0,
      columnSpan:Number(f.columnSpan),
    }
    if(!f.id)Object.assign(payload,{
      key:f.key.trim() || autoKey(f.label),type:f.type,
      options:f.type==='select'
        ? f.options.split('\n').map(value=>value.trim()).filter(Boolean) : [],
    })
    await financeApi('librefinance',spaceId,
      '/custom-fields/'+f.entityKind+(f.id?'/'+f.id:''),
      f.id?'PUT':'POST',payload)
    if (spaceId !== Number(w.activeId.value)) return
    dialog.value=false
    await load()
  } catch (e) {
    if(spaceId===Number(w.activeId.value))problem.value=financeError(e)
  } finally {
    saving.value=false
  }
}
</script>

<template>
  <div class="lf-field-studio">
    <div class="lf-section-heading">
      <div>
        <p>Informações adicionais da gestão atual, sem alterar valores, saldos ou cálculos.</p>
      </div>
      <NcButton variant="primary" :disabled="loading||saving||totals.every(group=>group.fields.length>=30)"
        @click="begin()">Novo campo</NcButton>
    </div>
    <NcNoteCard v-if="problem&&!dialog" type="error" role="alert">{{problem}}</NcNoteCard>
    <p v-if="loading" role="status" class="lf-muted">Carregando campos…</p>
    <template v-else-if="fields.length">
      <section v-for="group in totals.filter(group=>group.fields.length)" :key="group.value"
        class="lf-custom-field-group">
        <h3>{{group.label}} <small class="lf-muted">({{group.fields.length}}/30)</small></h3>
        <div v-for="field in group.fields" :key="field.id" class="lf-custom-field-row">
          <div>
            <strong>{{field.label}}</strong>
            <small>{{types.find(type=>type.value===field.type)?.label}}
              · {{field.visible?'No formulário':'Interno'}}
              · {{field.apiVisible?'API comum':'API restrita a gestores'}}
              · {{field.active?'Ativo':'Inativo'}}</small>
            <small v-if="field.help">{{field.help}}</small>
          </div>
          <NcButton variant="tertiary" :aria-label="'Editar '+field.label"
            @click="begin(field)">Editar</NcButton>
        </div>
      </section>
    </template>
    <NcEmptyContent v-else-if="!problem" name="Nenhum campo personalizado"
      description="Ao criar um campo, escolha primeiro onde ele será utilizado." />
    <NcDialog :open="dialog" size="normal" content-classes="lf-finance-dialog-content"
      :name="draft.id?'Editar campo personalizado':'Novo campo personalizado'"
      @update:open="!$event&&!saving&&(dialog=false)">
      <div v-if="step==='choose'" class="lf-dialog-form">
        <h3>Onde este campo será utilizado?</h3>
        <div class="lf-field-targets" role="group" aria-label="Escolher local do campo">
          <NcButton v-for="group in totals" :key="group.value" variant="secondary"
            :disabled="group.fields.length>=30" @click="chooseEntity(group.value)">
            {{group.label}}
          </NcButton>
        </div>
        <p class="lf-muted">Cada campo pertence à área escolhida, dentro desta gestão financeira.
          Cadastre os valores ao criar ou editar os registros dessa área.</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=false">Cancelar</NcButton>
        </div>
      </div>
      <form v-else class="lf-dialog-form" @submit.prevent="save">
        <NcButton v-if="!draft.id" variant="tertiary" @click="step='choose'">
          {{currentEntity?.label}} · Alterar local
        </NcButton>
        <p v-else class="lf-muted">Utilizado em: <strong>{{currentEntity?.label}}</strong></p>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <NcTextField v-model="draft.label" label="Nome do campo" maxlength="100" required />
        <details class="lf-finance-explanation">
          <summary>Identificador técnico</summary>
          <NcTextField v-model="draft.key" label="Chave para integrações"
            :disabled="Boolean(draft.id)" placeholder="Gerado automaticamente pelo nome"
            maxlength="40" />
        </details>
        <LfSelect id="lf-field-studio-type" v-model="draft.type" label="Tipo de informação"
          :disabled="Boolean(draft.id)" :options="types" />
        <NcTextArea v-if="draft.type==='select'" v-model="draft.options"
          label="Opções (uma por linha)" :disabled="Boolean(draft.id)" />
        <NcTextField v-model="draft.help" label="Ajuda para quem preencher (opcional)"
          maxlength="255" />
        <div class="lf-form-grid">
          <LfSelect id="lf-field-studio-width" v-model="draft.columnSpan"
            label="Largura no formulário"
            :options="[{value:12,label:'Linha inteira'},{value:6,label:'Meia linha'}]" />
          <NcTextField v-model="draft.sortOrder" label="Posição na tela" inputmode="numeric" />
        </div>
        <NcCheckboxRadioSwitch v-model="draft.visible" type="checkbox"
          @update:model-value="formVisibilityChanged">
          Mostrar no formulário
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-model="draft.apiVisible" type="checkbox"
          @update:model-value="!$event&&(draft.required=false)">
          Disponível na API comum
        </NcCheckboxRadioSwitch>
        <NcNoteCard v-if="!draft.visible && !draft.apiVisible" type="info">
          Este campo será um dado interno. Apenas gestores poderão consultá-lo e
          preenchê-lo nos formulários de {{currentEntity?.label}}. Não é necessário
          importar uma planilha para cadastrar seu valor.
        </NcNoteCard>
        <NcNoteCard v-else-if="!draft.visible && draft.apiVisible" type="warning">
          Oculto no formulário, mas disponível pela API para usuários autorizados
          desta gestão. Desative a opção de API caso o dado deva ser interno.
        </NcNoteCard>
        <NcNoteCard v-if="!draft.apiVisible" type="info">
          O valor não será entregue a visualizadores e operadores pela API comum.
          Gestores continuam tendo acesso administrativo para consultar e editar o dado.
        </NcNoteCard>
        <NcCheckboxRadioSwitch v-model="draft.required" type="checkbox"
          :disabled="!draft.visible||!draft.apiVisible">
          Obrigatório ao salvar
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-if="draft.id" v-model="draft.active" type="checkbox">
          Campo ativo (desativar preserva valores existentes)
        </NcCheckboxRadioSwitch>
        <p v-if="draft.id" class="lf-muted">Tipo e opções são preservados para que os
          valores existentes não mudem de significado.</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="saving" @click="dialog=false">
            Cancelar
          </NcButton>
          <NcButton variant="primary" type="submit" :disabled="saving">
            {{saving?'Salvando…':'Salvar campo'}}
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>
