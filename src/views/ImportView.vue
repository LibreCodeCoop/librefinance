<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFileUploadOutline, mdiCheckCircleOutline, mdiAlertCircleOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import { financeApi, financeError } from '../services/api.js'

const workspace = inject('lfWorkspace')
const { activeId, manager } = workspace
const navigate = inject('lfNavigate')
function backToSettings() {
  navigate('modules')
}
const kinds = [
  { id:'catalog', title:'Cadastros e contas', detail:'Empresa, pessoas, categorias e contas financeiras',
    preview:'/preview', apply:'/import', size:131072 },
  { id:'movements', title:'Extratos históricos', detail:'Entradas e saídas aguardando conciliação',
    preview:'/movement-preview', apply:'/movement-import', size:131072 },
  { id:'transfers', title:'Transferências', detail:'Pares de contas sem duplicar movimentos',
    preview:'/transfer-preview', apply:'/transfer-import', size:131072 },
  { id:'inventory', title:'Auditoria de cobertura', detail:'Compare contagens com mapeamentos e identifique lacunas',
    preview:'/inventory-preview', apply:null, size:32768 },
]
const step = ref('catalog')
const selected = computed(() => kinds.find(item => item.id === step.value))
const sourceText = ref('')
const fileName = ref('')
const preview = ref(null)
const confirmed = ref(false)
const result = ref(null)
const coverage = ref(null)
const error = ref('')
const busy = ref(false)
const input = ref(null)
let generation = 0
function clearInput() {
  ++generation
  fileName.value = ''
  sourceText.value = ''
  preview.value = null
  result.value = null
  coverage.value = null
  confirmed.value = false
  error.value = ''
}
function switchStep(kind) {
  // Navigation while a request is pending would invalidate its generation
  // and leave busy=true forever in the abandoned completion handler.
  if (busy.value || !kinds.some(item => item.id === kind)) return
  clearInput()
  step.value = kind
}
async function pickFile(event) {
  const file = event.target.files?.[0]
  if (event.target) event.target.value = ''
  if (!file || busy.value) return
  const token = ++generation
  preview.value = null
  result.value = null
  coverage.value = null
  confirmed.value = false
  error.value = ''
  if (file.size > selected.value.size) {
    error.value = 'O arquivo excede o limite deste tipo de importação.'
    return
  }
  try {
    const text = await file.text()
    if (token !== generation) return
    sourceText.value = text
    fileName.value = file.name
  } catch {
    if (token === generation) error.value = 'Falha ao ler o arquivo selecionado.'
  }
}
async function review() {
  if (!sourceText.value.trim() || busy.value) return
  const token = ++generation
  busy.value = true
  preview.value = null
  result.value = null
  coverage.value = null
  confirmed.value = false
  error.value = ''
  try {
    const body = step.value === 'inventory'
      ? { inventoryJson: sourceText.value }
      : { exportJson: sourceText.value }
    const data = await financeApi('librefinance', activeId.value,
      selected.value.preview, 'POST', body)
    if (token === generation) preview.value = data
  } catch (cause) {
    if (token === generation) error.value = financeError(cause)
  } finally {
    if (token === generation) busy.value = false
  }
}
async function apply() {
  if (!preview.value?.ready || !confirmed.value || !selected.value.apply || busy.value) return
  const token = ++generation
  busy.value = true
  error.value = ''
  try {
    const response = await financeApi('librefinance', activeId.value,
      selected.value.apply, 'POST', {
        exportJson: sourceText.value,
        previewHash: preview.value.previewHash,
        confirmed: true,
      })
    if (token !== generation) return
    result.value = response
    const origin = response.source || preview.value.source
    preview.value = null
    confirmed.value = false
    sourceText.value = ''
    fileName.value = ''
    if (step.value === 'catalog') {
      try {
        // Catalog imports update accounts, contacts, companies and categories.
        // The shared state must be refreshed before opening another view.
        // Keep the import page mounted so the success evidence remains visible.
        await workspace.refreshCurrent({ preserveView: true })
      } catch (refreshFailure) {
        if (token === generation) error.value =
          'Lote importado; falha ao atualizar os cadastros da interface: '
          + financeError(refreshFailure)
      }
      if (token !== generation) return
    }
    const route = step.value === 'catalog' ? '/coverage'
      : step.value === 'movements' ? '/movement-coverage' : '/transfer-coverage'
    try {
      const query = '?sourceSystem=' + encodeURIComponent(origin.system)
        + '&sourceCompanyId=' + encodeURIComponent(origin.companyId)
      const report = await financeApi('librefinance', activeId.value,
        route + query)
      if (token === generation) coverage.value = report
    } catch (cause) {
      if (token === generation) error.value =
        'Importação concluída, mas não foi possível consultar a cobertura: ' + financeError(cause)
    }
  } catch (cause) {
    if (token === generation) error.value = financeError(cause)
  } finally {
    if (token === generation) busy.value = false
  }
}
function changed(text) {
  if (busy.value) return
  ++generation
  sourceText.value = text
  preview.value = null
  result.value = null
  coverage.value = null
  confirmed.value = false
  error.value = ''
  fileName.value = ''
}
const isInventory = computed(() => step.value === 'inventory')
const rows = computed(() => isInventory.value
  ? preview.value?.resources || []
  : preview.value?.records || [])
const count = computed(() => isInventory.value
  ? preview.value?.summary || {}
  : preview.value?.counts || {})
onBeforeUnmount(() => { ++generation })
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="Migração e conferência" eyebrow="CONFIGURAÇÕES / DADOS"
      description="Importe em etapas e compare a origem com os registros persistidos no Nextcloud.">
      <template #actions><NcButton variant="secondary" @click="backToSettings">Voltar aos recursos</NcButton></template>
    </LfPageHeader>
    <NcNoteCard type="warning">
      Importe somente dados normalizados de empresas autorizadas e revise cada
      prévia antes de confirmar. Os saldos de origem são mantidos como referência;
      nenhuma importação contabiliza lançamentos automaticamente.
    </NcNoteCard>
    <div class="lf-import-progress" aria-label="Fluxos da migração">
      <NcButton v-for="item in kinds" :key="item.id"
        :variant="step===item.id?'primary':'tertiary'"
        :pressed="step===item.id" :disabled="busy" @click="switchStep(item.id)">
        {{item.title}}
      </NcButton>
    </div>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>{{selected.title}}</h2><p>{{selected.detail}}</p></div>
        <span class="lf-state">Arquivo normalizado</span>
      </div>
      <p class="lf-muted">
        {{isInventory?'Manifesto de cobertura, até 32 KiB. Não grava dados.':
          'Lote de registros, até 128 KiB. Revise conflitos antes de confirmar.'}}
      </p>
      <input ref="input" class="lf-visually-hidden"
        type="file" accept=".json,application/json" :disabled="busy" @change="pickFile" />
      <div class="lf-import-actions">
        <NcButton variant="secondary" :disabled="busy" @click="input?.click()">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          Selecionar arquivo JSON
        </NcButton>
        <span class="lf-muted">{{fileName||'Nenhum arquivo selecionado'}}</span>
      </div>
      <NcTextArea :model-value="sourceText" label="Ou cole o JSON normalizado"
        placeholder="Cole um lote com formato, empresa de origem e os dados autorizados"
        :disabled="busy" @update:model-value="changed" />
      <div class="lf-form-actions">
        <NcButton variant="primary" :disabled="busy||!sourceText.trim()"
          @click="review">Validar e pré-visualizar</NcButton>
      </div>
    </section>
    <section v-if="preview" class="lf-surface">
      <div class="lf-section-heading"><h2>Resultado da prévia</h2>
        <span :class="preview.ready || isInventory ? 'lf-state lf-state-good' : 'lf-state lf-state-warning'">
          {{isInventory?'Somente leitura':preview.ready?'Pronto para confirmar':'Conflitos encontrados'}}
        </span>
      </div>
      <p v-if="preview.source" class="lf-muted">
        Origem: {{preview.source.system}} · Empresa {{preview.source.companyId}}
      </p>
      <div v-if="!isInventory" class="lf-kpis">
        <div class="lf-kpi"><span>Novos</span><strong>{{count.new||0}}</strong></div>
        <div class="lf-kpi"><span>Já existentes</span><strong>{{count.unchanged||0}}</strong></div>
        <div class="lf-kpi"><span>Conflitos</span><strong>{{count.conflict||0}}</strong></div>
      </div>
      <div v-else class="lf-kpis">
        <div class="lf-kpi"><span>Recursos avaliados</span>
          <strong>{{count.resourcesExamined||0}}</strong></div>
        <div class="lf-kpi"><span>Recursos não implementados</span>
          <strong>{{count.resourcesWithUnimplementedImports||0}}</strong></div>
        <div class="lf-kpi"><span>Referências quebradas</span>
          <strong>{{count.brokenSourceMappings||0}}</strong></div>
      </div>
      <NcNoteCard v-if="preview.issues?.length" type="warning" role="alert">
        Há {{preview.issues.length}} conflito(s). A importação está bloqueada até resolver.
      </NcNoteCard>
      <div class="lf-table-scroll"><table class="lf-data-table">
        <thead v-if="isInventory"><tr><th>Recurso</th><th>Origem</th>
          <th>Presentes</th><th>Ausentes</th><th>Estado</th></tr></thead>
        <thead v-else><tr><th>Tipo</th><th>ID na origem</th><th>Estado</th></tr></thead>
        <tbody v-if="isInventory"><tr v-for="item in rows" :key="item.resource">
          <td>{{item.resource}}</td><td>{{item.sourceCount}}</td>
          <td>{{item.targetPresent}}</td><td>{{item.missing}}</td>
          <td>{{item.status}}</td>
        </tr></tbody>
        <tbody v-else><tr v-for="row in rows" :key="row.kind+':'+row.sourceId">
          <td>{{row.kind || (step==='movements'?'Transação':'Transferência')}}</td>
          <td class="lf-code">{{row.sourceId}}</td><td>{{row.status}}</td>
        </tr></tbody>
      </table></div>
      <NcNoteCard v-if="isInventory" type="info">
        Contagens coincidentes não demonstram igualdade de valores, vínculos contábeis,
        emissão fiscal nem cobertura histórica completa.
      </NcNoteCard>
      <template v-else-if="preview.ready">
        <NcCheckboxRadioSwitch v-model="confirmed" type="checkbox">
          Confirmo a empresa de destino e autorizo somente os registros
          validados nesta prévia, mantendo lançamentos e conciliações pendentes.
        </NcCheckboxRadioSwitch>
        <div class="lf-form-actions">
          <NcButton variant="primary" :disabled="!confirmed||busy" @click="apply">
            Importar lote validado
          </NcButton>
        </div>
      </template>
    </section>
    <section v-if="result" class="lf-surface">
      <h2>Importação concluída</h2>
      <NcNoteCard type="success" role="status">
        Novos: {{result.created}}. Existentes: {{result.skipped}}.
        Nenhuma movimentação foi contabilizada automaticamente.
      </NcNoteCard>
      <div v-if="coverage" class="lf-table-scroll">
        <h3>Cobertura no destino</h3>
        <pre class="lf-technical-report">{{JSON.stringify(coverage,null,2)}}</pre>
        <p class="lf-muted">
          Mapeamentos persistidos não comprovam equivalência funcional ou
          conciliação do saldo da origem.
        </p>
      </div>
    </section>
  </div>
</template>
