<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { getLocale, t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { financeError } from '../services/api.js'
import {
  initialPolicyDraft, policyInputValue, formatPolicyValue,
} from '../services/saasPolicyDraft.js'

const props = defineProps({
  scope: { type: String, required: true },
  environmentId: { type: String, default: '' },
  spaceId: { type: Number, default: 0 },
  hideUnsupported: { type: Boolean, default: false },
})
const policies = ref([])
const inputs = ref({})
const loading = ref(false)
const busy = ref('')
const error = ref('')
const notice = ref('')
const unsupported = ref(false)
const historyOpen = ref(false)
const historyEvents = ref([])
const historyBeforeId = ref(null)
const historyLoading = ref(false)
const historyError = ref('')
let historySeq = 0
let requestSequence = 0
let mounted = true

const root = '/apps/librefinance/api/saas/v1'
const resource = computed(() => {
  if (props.scope === 'instance') return generateUrl(root + '/admin/policies')
  if (props.scope === 'environment' && props.environmentId) {
    return generateUrl(root + '/environments/' + encodeURIComponent(props.environmentId) + '/policies')
  }
  if (props.scope === 'workspace' && Number.isSafeInteger(props.spaceId) && props.spaceId > 0) {
    return generateUrl(root + '/workspaces/' + props.spaceId + '/policies')
  }
  if (props.scope === 'user' && Number.isSafeInteger(props.spaceId) && props.spaceId > 0) {
    return generateUrl(root + '/workspaces/' + props.spaceId + '/policies/me')
  }
  return ''
})
const auditResource = computed(() => {
  if (props.scope === 'instance') return generateUrl(root + '/policy-audit/instance')
  if (props.scope === 'environment' && props.environmentId) {
    return generateUrl(root + '/policy-audit/environments/'
      + encodeURIComponent(props.environmentId))
  }
  if (props.scope === 'workspace' && Number.isSafeInteger(props.spaceId)
      && props.spaceId > 0) {
    return generateUrl(root + '/policy-audit/workspaces/' + props.spaceId)
  }
  if (props.scope === 'user' && Number.isSafeInteger(props.spaceId)
      && props.spaceId > 0) {
    return generateUrl(root + '/policy-audit/workspaces/' + props.spaceId + '/me')
  }
  return ''
})
const name = key => ({
  'environments.enabled': t('librefinance', 'Enable SaaS environments'),
  'externalSharing.enabled': t('librefinance', 'External sharing'),
  'imports.enabled': t('librefinance', 'Data import'),
  'banking.allowedImportFormats': t('librefinance', 'Allowed bank statement formats (CSV or OFX)'),
  'modules.reports.enabled': t('librefinance', 'Financial reports'),
  'regional.nfseDrafts.enabled': t('librefinance', 'NFS-e drafts (optional integration)'),
  'workspaces.maxCount': t('librefinance', 'Workspace limit per environment'),
  'dashboard.kpis.enabled': t('librefinance', 'Show summary indicators on the dashboard'),
})[key] || key
const sourceLabel = scope => ({
  default: t('librefinance', 'Application default'), instance: t('librefinance', 'Nextcloud instance'),
  environment: t('librefinance', 'Environment'), workspace: t('librefinance', 'Workspace'), user: t('librefinance', 'User'),
})[scope] || t('librefinance', 'Unavailable')
const currentContext = computed(() => resource.value)
const inheritedLabel = value => value === null ? t('librefinance', 'Inherited') : value

async function load() {
  const seq = ++requestSequence
  const endpoint = resource.value
  policies.value = []
  inputs.value = {}
  error.value = ''
  notice.value = ''
  unsupported.value = false
  if (!endpoint) {
    loading.value = false
    return
  }
  loading.value = true
  try {
    const response = await axios.get(endpoint + (props.scope === 'instance' ? '' : '/effective'))
    if (!mounted || seq !== requestSequence || resource.value !== endpoint) return
    const rows = Array.isArray(response.data.policies) ? response.data.policies : []
    policies.value = rows
    inputs.value = Object.fromEntries(rows.map(row => [row.key, initialPolicyDraft(row)]))
  } catch (cause) {
    if (!mounted || seq !== requestSequence || resource.value !== endpoint) return
    unsupported.value = props.hideUnsupported
      && cause?.response?.status === 422
      && cause?.response?.data?.error === 'unsupported_scope'
    error.value = financeError(cause)
  } finally {
    if (mounted && seq === requestSequence) loading.value = false
  }
}

function closeHistory() {
  ++historySeq
  historyOpen.value = false
  historyEvents.value = []
  historyBeforeId.value = null
  historyLoading.value = false
  historyError.value = ''
}
async function fetchHistory(more = false) {
  const url = auditResource.value
  if (!url) return
  const token = ++historySeq
  historyLoading.value = true
  historyError.value = ''
  if (!more) {
    historyEvents.value = []
    historyBeforeId.value = null
  }
  try {
    const response = await axios.get(url, {
      params: more && historyBeforeId.value !== null
        ? { beforeId: historyBeforeId.value } : {},
    })
    if (!mounted || historySeq !== token || auditResource.value !== url) return
    const items = Array.isArray(response.data.events) ? response.data.events : []
    historyEvents.value = more ? [...historyEvents.value, ...items] : items
    historyBeforeId.value = Number.isSafeInteger(response.data.nextBeforeId)
      && response.data.nextBeforeId > 0 ? response.data.nextBeforeId : null
  } catch (cause) {
    if (mounted && historySeq === token && auditResource.value === url) {
      historyError.value = financeError(cause)
      if (!more) historyEvents.value = []
    }
  } finally {
    if (mounted && historySeq === token) historyLoading.value = false
  }
}
function auditTime(seconds) {
  return new Date(Number(seconds) * 1000).toLocaleString(getLocale()?.replaceAll('_', '-'))
}
async function toggleHistory() {
  if (historyOpen.value) { closeHistory(); return }
  historyOpen.value = true
  await fetchHistory(false)
}
watch(() => [props.scope, props.environmentId, props.spaceId],
  () => { closeHistory(); load() }, { immediate: true })
onBeforeUnmount(() => { mounted = false; ++requestSequence; closeHistory() })

async function update(row, clear = false) {
  const endpoint = resource.value
  if (!endpoint || busy.value || !row.editableByCurrentActor) return
  let valueJson
  if (!clear) {
    try {
      valueJson = JSON.stringify(policyInputValue(row, inputs.value[row.key]))
    } catch (cause) {
      error.value = cause.message
      return
    }
  }
  busy.value = row.key
  error.value = ''
  notice.value = ''
  try {
    const path = endpoint + '/' + encodeURIComponent(row.key)
    if (clear) {
      await axios.delete(path, { params: { expectedRevision: row.revision } })
    } else {
      await axios.put(path, { valueJson, expectedRevision: row.revision })
    }
    if (!mounted || endpoint !== resource.value) return
    await load()
    if (!mounted || endpoint !== resource.value) return
    if (historyOpen.value) await fetchHistory(false)
    notice.value = clear
      ? t('librefinance', 'Local restriction removed. The inherited policy remains in effect.')
      : t('librefinance', 'Restriction applied and audited for this scope.')
  } catch (cause) {
    if (!mounted || endpoint !== resource.value) return
    if ([403, 404, 423].includes(cause?.response?.status)) {
      policies.value = []
      inputs.value = {}
      error.value = financeError(cause)
    } else if (cause?.response?.status === 409) {
      await load()
      if (mounted && endpoint === resource.value) {
        error.value = t('librefinance', 'The revision changed. Current values were reloaded; review them before saving.')
      }
    } else {
      error.value = financeError(cause)
    }
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <section v-if="!unsupported" class="lf-saas-policy-editor"
    :aria-label="t('librefinance', 'SaaS policies for {scope}', { scope: sourceLabel(scope) }, { escape: false, sanitize: false })">
    <p class="lf-muted">{{ t('librefinance', 'These policies restrict operations but do not grant financial permissions. The server revalidates authority and revision on every change.') }}</p>
    <NcLoadingIcon v-if="loading" :size="20" />
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <div v-if="policies.length" class="lf-saas-policy-list">
      <article v-for="policy in policies" :key="policy.key" class="lf-saas-policy-row">
        <div>
          <strong>{{ name(policy.key) }}</strong>
          <p class="lf-muted">{{ policy.key }}</p>
          <p>{{ t('librefinance', 'Effective: {value}', { value: formatPolicyValue(policy) }, { escape: false, sanitize: false }) }}
            · {{ t('librefinance', 'Source: {source}', { source: sourceLabel(policy.sourceScope) }, { escape: false, sanitize: false }) }}
            · {{ t('librefinance', 'Revision: {revision}', { revision: policy.revision }, { escape: false, sanitize: false }) }}</p>
          <p v-if="policy.blockedBy" class="lf-muted">
            {{ t('librefinance', 'Mandatory restriction: {scope}', { scope: sourceLabel(policy.blockedBy) }, { escape: false, sanitize: false }) }}</p>
          <p v-if="!policy.editableByCurrentActor" class="lf-muted">
            {{ t('librefinance', 'Read-only at this scope; there is no automatic delegation.') }}
          </p>
        </div>
        <div v-if="policy.editableByCurrentActor" class="lf-saas-policy-form">
          <NcCheckboxRadioSwitch v-if="policy.type==='boolean'"
            :model-value="inputs[policy.key] === true" type="switch"
            :disabled="busy!=='' || loading"
            @update:model-value="inputs[policy.key]=Boolean($event)">
            {{ t('librefinance', 'Allow {policy}', { policy: name(policy.key).toLocaleLowerCase(getLocale()?.replaceAll('_', '-')) }, { escape: false, sanitize: false }) }}
          </NcCheckboxRadioSwitch>
          <NcTextField v-else-if="policy.type==='ceiling'"
            :model-value="String(inputs[policy.key] ?? '')"
            :label="t('librefinance', 'Limit for {policy}', { policy: name(policy.key).toLocaleLowerCase(getLocale()?.replaceAll('_', '-')) }, { escape: false, sanitize: false })"
            inputmode="numeric" :disabled="busy!=='' || loading"
            @update:model-value="inputs[policy.key]=$event" />
          <NcTextField v-else-if="policy.type==='allowed_set'"
            :model-value="String(inputs[policy.key] ?? '')"
            :label="t('librefinance', 'Allowed values for {policy}', { policy: name(policy.key) }, { escape: false, sanitize: false })"
            :disabled="busy!=='' || loading"
            @update:model-value="inputs[policy.key]=$event" />
          <div class="lf-saas-policy-buttons">
            <NcButton variant="primary" :disabled="busy!=='' || loading"
              :aria-label="t('librefinance', 'Save restriction {policy}', { policy: name(policy.key) }, { escape: false, sanitize: false })"
              @click="update(policy)">{{ t('librefinance', 'Save restriction') }}</NcButton>
            <NcButton variant="tertiary" :disabled="busy!=='' || loading || policy.inherited"
              :aria-label="t('librefinance', 'Inherit policy {policy}', { policy: name(policy.key) }, { escape: false, sanitize: false })"
              @click="update(policy, true)">{{ t('librefinance', 'Inherit value') }}</NcButton>
          </div>
        </div>
      </article>
    </div>
    <p v-else-if="!loading && !error" class="lf-muted">{{ t('librefinance', 'No policies available.') }}</p>
    <NcButton v-if="policies.length" variant="secondary"
      :aria-expanded="historyOpen" @click="toggleHistory">
      {{ historyOpen ? t('librefinance', 'Hide policy history') : t('librefinance', 'View policy history') }}
    </NcButton>
    <section v-if="historyOpen" :aria-label="t('librefinance', 'SaaS policy change history')">
      <NcNoteCard v-if="historyError" type="error" role="alert">{{ historyError }}</NcNoteCard>
      <NcLoadingIcon v-if="historyLoading" :size="20" />
      <ol v-if="historyEvents.length" class="lf-policy-audit-list">
        <li v-for="item in historyEvents" :key="item.id">
          <strong>{{ name(item.policyKey) }}</strong>
          · {{ item.action }}
          <p class="lf-muted">
            {{ t('librefinance', '{time} · By {actor} · Revision {previous} → {next}', { time: auditTime(item.occurredAt), actor: item.actorUid, previous: item.previousRevision, next: item.newRevision }, { escape: false, sanitize: false }) }}
          </p>
          <p>{{ t('librefinance', 'Before: {value}', { value: inheritedLabel(item.oldValueJson) }, { escape: false, sanitize: false }) }}
            · {{ t('librefinance', 'After: {value}', { value: inheritedLabel(item.newValueJson) }, { escape: false, sanitize: false }) }}</p>
        </li>
      </ol>
      <p v-else-if="!historyLoading && !historyError" class="lf-muted">
        {{ t('librefinance', 'No changes recorded for this scope.') }}
      </p>
      <NcButton v-if="historyBeforeId !== null" variant="tertiary"
        :disabled="historyLoading" @click="fetchHistory(true)">
        {{ t('librefinance', 'Load earlier events') }}
      </NcButton>
    </section>
  </section>
</template>

<style scoped>
.lf-saas-policy-editor{display:grid;gap:12px}
.lf-saas-policy-list{display:grid;gap:12px}
.lf-saas-policy-row{border-bottom:1px solid var(--color-border);padding:12px 0;
  display:grid;gap:10px}
.lf-saas-policy-row p{margin:4px 0}
.lf-saas-policy-form{max-width:510px;display:grid;gap:10px}
.lf-saas-policy-buttons{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.lf-policy-audit-list{display:grid;gap:10px;padding-inline-start:24px}
.lf-policy-audit-list li{border-bottom:1px solid var(--color-border);padding-bottom:8px}
.lf-policy-audit-list p{margin:5px 0}
</style>
