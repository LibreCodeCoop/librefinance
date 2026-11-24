<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref } from 'vue'
import { t } from '@nextcloud/l10n'
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
  { id:'catalog', title:t('librefinance', 'Registrations and accounts'), detail:t('librefinance', 'Company, people, categories and financial accounts'),
    preview:'/preview', apply:'/import', size:131072 },
  { id:'movements', title:t('librefinance', 'Historical statements'), detail:t('librefinance', 'Inflows and outflows awaiting reconciliation'),
    preview:'/movement-preview', apply:'/movement-import', size:131072 },
  { id:'transfers', title:t('librefinance', 'Transfers'), detail:t('librefinance', 'Account pairs without duplicating movements'),
    preview:'/transfer-preview', apply:'/transfer-import', size:131072 },
  { id:'inventory', title:t('librefinance', 'Coverage audit'), detail:t('librefinance', 'Compare counts with mappings and identify gaps'),
    preview:'/inventory-preview', apply:null, size:32768 },
]
const statusLabels = {
  new: t('librefinance', 'New record'),
  unchanged: t('librefinance', 'Already present'),
  conflict: t('librefinance', 'Conflict'),
  not_required: t('librefinance', 'Not required'),
  not_implemented: t('librefinance', 'Not implemented'),
  gap: t('librefinance', 'Coverage gap'),
  counts_match: t('librefinance', 'Counts match'),
}
const kindLabels = {
  company: t('librefinance', 'Company'),
  account: t('librefinance', 'Account'),
  contact: t('librefinance', 'Contact'),
  category: t('librefinance', 'Category'),
}
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
    error.value = t('librefinance', 'The file exceeds the limit for this import type.')
    return
  }
  try {
    const text = await file.text()
    if (token !== generation) return
    sourceText.value = text
    fileName.value = file.name
  } catch {
    if (token === generation) error.value = t('librefinance', 'Failed to read the selected file.')
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
          t('librefinance', 'Batch imported; failed to refresh the interface records: {error}', { error: financeError(refreshFailure) }, { escape: false, sanitize: false })
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
        t('librefinance', 'Import completed, but coverage could not be retrieved: {error}', { error: financeError(cause) }, { escape: false, sanitize: false })
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
    <LfPageHeader :title="t('librefinance', 'Migration and review')" :eyebrow="t('librefinance', 'SETTINGS / DATA')"
      :description="t('librefinance', 'Import in stages and compare the source with records stored in Nextcloud.')">
      <template #actions><NcButton variant="secondary" @click="backToSettings">{{ t('librefinance', 'Back to features') }}</NcButton></template>
    </LfPageHeader>
    <NcNoteCard type="warning">
      {{ t('librefinance', 'Only import normalized data from authorized companies and review each preview before confirming. Source balances are kept for reference; no import posts journal entries automatically.') }}
    </NcNoteCard>
    <div class="lf-import-progress" :aria-label="t('librefinance', 'Migration workflows')">
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
        <span class="lf-state">{{ t('librefinance', 'Normalized file') }}</span>
      </div>
      <p class="lf-muted">
        {{isInventory?t('librefinance', 'Coverage manifest, up to 32 KiB. Does not write data.'):
          t('librefinance', 'Batch of records, up to 128 KiB. Review conflicts before confirming.')}}
      </p>
      <input ref="input" class="lf-visually-hidden"
        type="file" accept=".json,application/json" :disabled="busy" @change="pickFile" />
      <div class="lf-import-actions">
        <NcButton variant="secondary" :disabled="busy" @click="input?.click()">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          {{ t('librefinance', 'Select JSON file') }}
        </NcButton>
        <span class="lf-muted">{{fileName||t('librefinance', 'No file selected')}}</span>
      </div>
      <NcTextArea :model-value="sourceText" :label="t('librefinance', 'Or paste the normalized JSON')"
        :placeholder="t('librefinance', 'Paste a batch with the format, source company and authorized data')"
        :disabled="busy" @update:model-value="changed" />
      <div class="lf-form-actions">
        <NcButton variant="primary" :disabled="busy||!sourceText.trim()"
          @click="review">{{ t('librefinance', 'Validate and preview') }}</NcButton>
      </div>
    </section>
    <section v-if="preview" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Preview result') }}</h2>
        <span :class="preview.ready || isInventory ? 'lf-state lf-state-good' : 'lf-state lf-state-warning'">
          {{isInventory?t('librefinance', 'Read-only'):preview.ready?t('librefinance', 'Ready to confirm'):t('librefinance', 'Conflicts found')}}
        </span>
      </div>
      <p v-if="preview.source" class="lf-muted">
        {{ t('librefinance', 'Source: {system} · Company {company}', { system: preview.source.system, company: preview.source.companyId }, { escape: false, sanitize: false }) }}
      </p>
      <div v-if="!isInventory" class="lf-kpis">
        <div class="lf-kpi"><span>{{ t('librefinance', 'New records') }}</span>
          <strong>{{count.new||0}}</strong></div>
        <div class="lf-kpi"><span>{{ t('librefinance', 'Existing records') }}</span>
          <strong>{{count.unchanged||0}}</strong></div>
        <div class="lf-kpi"><span>{{ t('librefinance', 'Conflicts') }}</span>
          <strong>{{count.conflict||0}}</strong></div>
      </div>
      <div v-else class="lf-kpis">
        <div class="lf-kpi"><span>{{ t('librefinance', 'Resources examined') }}</span>
          <strong>{{count.resourcesExamined||0}}</strong></div>
        <div class="lf-kpi"><span>{{ t('librefinance', 'Unimplemented resources') }}</span>
          <strong>{{count.resourcesWithUnimplementedImports||0}}</strong></div>
        <div class="lf-kpi"><span>{{ t('librefinance', 'Broken references') }}</span>
          <strong>{{count.brokenSourceMappings||0}}</strong></div>
      </div>
      <NcNoteCard v-if="preview.issues?.length" type="warning" role="alert">
        {{ t('librefinance', 'Conflicts: {count}. Import is blocked until they are resolved.', { count: preview.issues.length }, { escape: false, sanitize: false }) }}
      </NcNoteCard>
      <div class="lf-table-scroll"><table class="lf-data-table">
        <thead v-if="isInventory"><tr><th>{{ t('librefinance', 'Resource') }}</th>
            <th>{{ t('librefinance', 'Source origin') }}</th>
          <th>{{ t('librefinance', 'Present') }}</th>
            <th>{{ t('librefinance', 'Missing') }}</th>
            <th>{{ t('librefinance', 'State') }}</th></tr></thead>
        <thead v-else><tr><th>{{ t('librefinance', 'Type') }}</th>
            <th>{{ t('librefinance', 'Source ID') }}</th>
            <th>{{ t('librefinance', 'State') }}</th></tr></thead>
        <tbody v-if="isInventory"><tr v-for="item in rows" :key="item.resource">
          <td>{{item.resource}}</td><td>{{item.sourceCount}}</td>
          <td>{{item.targetPresent}}</td><td>{{item.missing}}</td>
          <td :data-status="item.status">{{ statusLabels[item.status] || item.status }}</td>
        </tr></tbody>
        <tbody v-else><tr v-for="row in rows" :key="row.kind+':'+row.sourceId">
          <td :data-kind="row.kind">{{ kindLabels[row.kind] || row.kind || (step === 'movements' ? t('librefinance', 'Transaction') : t('librefinance', 'Transfer')) }}</td>
          <td class="lf-code">{{row.sourceId}}</td><td :data-status="row.status">{{ statusLabels[row.status] || row.status }}</td>
        </tr></tbody>
      </table></div>
      <NcNoteCard v-if="isInventory" type="info">
        {{ t('librefinance', 'Matching counts do not demonstrate equality of amounts, accounting links, fiscal issuance or complete historical coverage.') }}
      </NcNoteCard>
      <template v-else-if="preview.ready">
        <NcCheckboxRadioSwitch v-model="confirmed" type="checkbox">
          {{ t('librefinance', 'I confirm the destination company and authorize only the records validated in this preview, leaving journal entries and reconciliations pending.') }}
        </NcCheckboxRadioSwitch>
        <div class="lf-form-actions">
          <NcButton variant="primary" :disabled="!confirmed||busy" @click="apply">
            {{ t('librefinance', 'Import validated batch') }}
          </NcButton>
        </div>
      </template>
    </section>
    <section v-if="result" class="lf-surface">
      <h2>{{ t('librefinance', 'Import completed') }}</h2>
      <NcNoteCard type="success" role="status">
        {{ t('librefinance', 'New: {created}. Existing: {skipped}. No movement was posted automatically.', { created: result.created, skipped: result.skipped }, { escape: false, sanitize: false }) }}
      </NcNoteCard>
      <div v-if="coverage" class="lf-table-scroll">
        <h3>{{ t('librefinance', 'Destination coverage') }}</h3>
        <pre class="lf-technical-report">{{JSON.stringify(coverage,null,2)}}</pre>
        <p class="lf-muted">
          {{ t('librefinance', 'Persisted mappings do not prove functional equivalence or reconciliation of the source balance.') }}
        </p>
      </div>
    </section>
  </div>
</template>
