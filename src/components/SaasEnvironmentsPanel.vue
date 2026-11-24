<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import LfSelect from './LfSelect.vue'
import SaasPolicyEditor from './SaasPolicyEditor.vue'
import { financeError } from '../services/api.js'
import {
  chooseSaasEnvironment, createWorkspaceClaimKeys, eligibleSaasEnvironments,
} from '../services/saasProvisioning.js'

const environments = ref([])
const loading = ref(false)
const loaded = ref(false)
const activating = ref(null)
const problem = ref('')
const notice = ref('')
const selectedId = ref('')
const workspaces = ref([])
const workspacesNextAfterId = ref(null)
const workspacesLoading = ref(false)
const workspacesError = ref('')
const name = ref('')
const ownerUid = ref('')
const kind = ref('organization')
const withCompany = ref(false)
const legalName = ref('')
const companyCountry = ref('BR')
const companyTaxId = ref('')
const pending = ref(null)
const creating = ref(false)
const claims = createWorkspaceClaimKeys()
const kindOptions = [
  { value: 'organization', label: t('librefinance', 'Business or organization') },
  { value: 'collective', label: t('librefinance', 'Group or collective') },
  { value: 'personal', label: t('librefinance', 'Personal or household') },
]
const manageable = computed(() => eligibleSaasEnvironments(environments.value))
const selected = computed(() => manageable.value.find(env => env.id === selectedId.value) ?? null)
const environmentOptions = computed(() => manageable.value.map(env => ({
  value: env.id, label: env.name,
})))
let requestSeq = 0
let workspacesSeq = 0
let mounted = true

const endpoint = () => generateUrl('/apps/librefinance/api/saas/v1/environments')
const workspacesEndpoint = id => endpoint() + '/' + encodeURIComponent(id) + '/workspaces'
const stateText = state => ({
  enabled_pending_admin: t('librefinance', 'Awaiting administrator'),
  active: t('librefinance', 'Active'),
  suspended: t('librefinance', 'Suspended'),
})[state] || t('librefinance', 'Unavailable')
const kindText = value => kindOptions.find(item => item.value === value)?.label || value

async function loadWorkspaces(more = false) {
  if (more && (workspacesLoading.value || workspacesNextAfterId.value === null)) return
  const id = selectedId.value
  const seq = ++workspacesSeq
  const afterId = more ? workspacesNextAfterId.value : null
  if (!more) {
    workspaces.value = []
    workspacesNextAfterId.value = null
  }
  workspacesError.value = ''
  if (!id) {
    workspacesLoading.value = false
    return
  }
  workspacesLoading.value = true
  try {
    const response = await axios.get(workspacesEndpoint(id), {
      params: afterId === null ? {} : { afterId },
    })
    if (seq !== workspacesSeq || selectedId.value !== id || !mounted) return
    const rows = Array.isArray(response.data.workspaces)
      ? response.data.workspaces : []
    workspaces.value = more ? [...workspaces.value, ...rows] : rows
    workspacesNextAfterId.value = Number.isSafeInteger(response.data.nextAfterId)
      && response.data.nextAfterId > 0 ? response.data.nextAfterId : null
  } catch (error) {
    if (seq !== workspacesSeq || selectedId.value !== id || !mounted) return
    if ([403, 404, 423].includes(error?.response?.status)) {
      // A revoked or suspended context must not retain stale UI data.
      selectedId.value = ''
      workspaces.value = []
      workspacesNextAfterId.value = null
      problem.value = financeError(error)
    } else {
      workspacesError.value = financeError(error)
    }
  } finally {
    if (seq === workspacesSeq && mounted) workspacesLoading.value = false
  }
}

watch(selectedId, () => {
  workspaces.value = []
  workspacesNextAfterId.value = null
  workspacesError.value = ''
  pending.value = null
  notice.value = ''
  name.value = ''
  ownerUid.value = ''
  kind.value = 'organization'
  withCompany.value = false
  legalName.value = ''
  companyCountry.value = 'BR'
  companyTaxId.value = ''
  loadWorkspaces()
})
watch(kind, value => {
  if (value !== 'organization') withCompany.value = false
})

async function reload() {
  const seq = ++requestSeq
  loading.value = true
  problem.value = ''
  try {
    const response = await axios.get(endpoint())
    if (seq !== requestSeq || !mounted) return
    // This is management eligibility, never a financial workspace grant.
    environments.value = Array.isArray(response.data.environments)
      ? response.data.environments : []
    const previousId = selectedId.value
    selectedId.value = chooseSaasEnvironment(environments.value, previousId)
    // The watcher handles a changed selection. Refresh only an unchanged one.
    if (selectedId.value && previousId === selectedId.value) await loadWorkspaces()
  } catch (error) {
    if (seq === requestSeq && mounted) {
      environments.value = []
      selectedId.value = ''
      workspaces.value = []
      workspacesNextAfterId.value = null
      problem.value = financeError(error)
    }
  } finally {
    if (seq === requestSeq && mounted) {
      loading.value = false
      loaded.value = true
    }
  }
}

async function activate(environment) {
  if (activating.value !== null || environment.state !== 'enabled_pending_admin'
    || !environment.canManage) return
  activating.value = environment.id
  problem.value = ''
  notice.value = ''
  try {
    await axios.post(endpoint() + '/' + encodeURIComponent(environment.id) + '/provision', {})
    if (!mounted) return
    await reload()
    notice.value = t('librefinance', 'Environment activated. Financial access is defined separately.')
  } catch (error) {
    if (mounted) problem.value = financeError(error)
  } finally {
    activating.value = null
  }
}

function reviewNewWorkspace() {
  if (!selected.value || !name.value.trim() || !ownerUid.value.trim() || creating.value) return
  if (withCompany.value && (!legalName.value.trim()
    || !/^[A-Za-z]{2}$/.test(companyCountry.value.trim()))) {
    workspacesError.value = t('librefinance', 'Enter the legal name and the two-letter ISO country code.')
    return
  }
  pending.value = {
    environmentId: selected.value.id,
    environmentName: selected.value.name,
    name: name.value.trim(),
    ownerUid: ownerUid.value.trim(),
    kind: kind.value,
    currency: 'BRL',
    color: '#64748B',
    ...(withCompany.value && kind.value === 'organization' ? {
      legalName: legalName.value.trim(),
      companyCountry: companyCountry.value.trim().toUpperCase(),
      companyTaxId: companyTaxId.value.trim() || null,
    } : {}),
  }
  workspacesError.value = ''
}

async function createWorkspace() {
  const request = pending.value
  if (!request || creating.value || request.environmentId !== selectedId.value) return
  let idempotencyKey
  try {
    idempotencyKey = claims.get(request.environmentId, request)
  } catch (error) {
    workspacesError.value = financeError(error)
    return
  }
  creating.value = true
  workspacesError.value = ''
  try {
    await axios.post(workspacesEndpoint(request.environmentId), {
      name: request.name,
      ownerUid: request.ownerUid,
      kind: request.kind,
      currency: request.currency,
      color: request.color,
      ...(request.legalName ? {
        legalName: request.legalName,
        companyCountry: request.companyCountry,
        companyTaxId: request.companyTaxId,
      } : {}),
      idempotencyKey,
    })
    claims.clear(request.environmentId, request)
    if (!mounted || selectedId.value !== request.environmentId) return
    pending.value = null
    name.value = ''
    ownerUid.value = ''
    kind.value = 'organization'
    withCompany.value = false
    legalName.value = ''
    companyCountry.value = 'BR'
    companyTaxId.value = ''
    await loadWorkspaces()
    notice.value = request.legalName
      ? t('librefinance', 'Workspace and company created together, with independent ledger and accounts.')
      : t('librefinance', 'Independent financial workspace created for the specified owner.')
  } catch (error) {
    if (mounted && selectedId.value === request.environmentId) {
      if ([403, 404, 423].includes(error?.response?.status)) {
        selectedId.value = ''
        problem.value = financeError(error)
      } else {
        workspacesError.value = financeError(error)
      }
    }
    // Keep the same idempotency key for a possible lost HTTP response.
  } finally {
    creating.value = false
  }
}

onMounted(reload)
onBeforeUnmount(() => {
  mounted = false
  ++requestSeq
  ++workspacesSeq
})
</script>

<template>
  <section v-if="environments.length || loading || problem || loaded"
    class="lf-surface" aria-labelledby="lf-saas-environments-heading">
    <div class="lf-section-heading">
      <div>
        <h2 id="lf-saas-environments-heading">{{ t('librefinance', 'LibreFinance environments') }}</h2>
        <p>{{ t('librefinance', 'Nextcloud groups enabled for LibreFinance SaaS. Each environment has independent administration. Being a member or administrator of the group does not grant access to financial ledgers.') }}</p>
      </div>
    </div>
    <NcLoadingIcon v-if="loading" :size="20" />
    <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
    <NcNoteCard v-if="loaded && !loading && !problem && !environments.length"
      type="info" role="status">
      {{ t('librefinance', 'No SaaS environment is available for this account. An instance administrator must enable the Nextcloud group; a group administrator or delegate can then activate it. Existing personal workspaces remain independent.') }}
    </NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <ul v-if="environments.length" class="lf-saas-environments"
      :aria-label="t('librefinance', 'Available SaaS environments')">
      <li v-for="environment in environments" :key="environment.id"
        class="lf-saas-environment-item">
        <div>
          <strong>{{ environment.name }}</strong>
          <p class="lf-muted">{{ t('librefinance', 'State: {state}', { state: stateText(environment.state) }, { escape: false, sanitize: false }) }}</p>
        </div>
        <NcButton v-if="environment.canManage && environment.state==='enabled_pending_admin'"
          variant="primary" :disabled="activating!==null"
          :aria-label="t('librefinance', 'Activate environment {name}', { name: environment.name }, { escape: false, sanitize: false })"
          @click="activate(environment)">
          {{ t('librefinance', 'Activate environment') }}
        </NcButton>
        <span v-else-if="environment.state==='suspended'" class="lf-muted">
          {{ t('librefinance', 'Reactivation requires administrative recovery.') }}
        </span>
        <span v-else-if="environment.state==='enabled_pending_admin'" class="lf-muted">
          {{ t('librefinance', 'Awaiting an enabled group administrator or delegate.') }}
        </span>
      </li>
    </ul>

    <div v-if="manageable.length" class="lf-saas-manager"
      aria-labelledby="lf-saas-manager-heading">
      <h3 id="lf-saas-manager-heading">{{ t('librefinance', 'Manage workspaces by environment') }}</h3>
      <p>{{ t('librefinance', 'Choose one environment. Workspaces and permissions are never combined across groups.') }}</p>
      <LfSelect id="lf-saas-selected-environment" v-model="selectedId"
        :label="t('librefinance', 'Administrative environment')" :options="environmentOptions"
        :placeholder="t('librefinance', 'Select the environment')" />
      <p v-if="!selected" class="lf-muted">
        {{ t('librefinance', 'If you manage multiple groups, explicitly select the environment you want to manage.') }}
      </p>
      <div v-else class="lf-saas-workspaces">
        <h4>{{ t('librefinance', 'Policies for this environment') }}</h4>
        <p>{{ t('librefinance', 'Restrict the environment without extending instance limits or gaining access to accounting ledgers.') }}</p>
        <SaasPolicyEditor scope="environment" :environment-id="selected.id" />
        <h4>{{ t('librefinance', 'Workspaces of environment {name}', { name: selected.name }, { escape: false, sanitize: false }) }}</h4>
        <NcLoadingIcon v-if="workspacesLoading" :size="20" />
        <NcNoteCard v-if="workspacesError" type="error" role="alert">{{ workspacesError }}</NcNoteCard>
        <ul v-if="workspaces.length" :aria-label="t('librefinance', 'Administrative workspaces in the environment')">
          <li v-for="workspace in workspaces" :key="workspace.id">
            <strong>{{ workspace.name }}</strong>
            <span class="lf-muted">{{ t('librefinance', '{kind} · Owner: {owner}', { kind: kindText(workspace.kind), owner: workspace.ownerUid }, { escape: false, sanitize: false }) }}</span>
          </li>
        </ul>
        <NcButton v-if="workspacesNextAfterId !== null"
          variant="secondary" :disabled="workspacesLoading"
          @click="loadWorkspaces(true)">{{ t('librefinance', 'Load more workspaces') }}</NcButton>
        <p v-else-if="!workspaces.length && !workspacesLoading" class="lf-muted">
          {{ t('librefinance', 'No independent financial workspace has been created in this environment.') }}
        </p>
        <form class="lf-saas-create" @submit.prevent="reviewNewWorkspace">
          <h4>{{ t('librefinance', 'New independent workspace') }}</h4>
          <NcNoteCard type="info">{{ t('librefinance', 'The workspace will have separate accounts and an accounting ledger. The group administrator does not receive financial access. The owner must be another member of the Nextcloud group.') }}</NcNoteCard>
          <NcTextField v-model="name" :label="t('librefinance', 'Workspace name')" required maxlength="150" />
          <NcTextField v-model="ownerUid" :label="t('librefinance', 'Nextcloud username of the owner')"
            required maxlength="64" />
          <LfSelect id="lf-saas-new-kind" v-model="kind"
            :label="t('librefinance', 'Workspace type')" :options="kindOptions" />
          <NcCheckboxRadioSwitch v-if="kind==='organization'"
            v-model="withCompany" type="checkbox">
            {{ t('librefinance', 'Register a legal entity together with the new ledger') }}
          </NcCheckboxRadioSwitch>
          <template v-if="kind==='organization' && withCompany">
            <NcTextField v-model="legalName" :label="t('librefinance', 'Legal name')" required maxlength="180" />
            <NcTextField v-model="companyCountry" :label="t('librefinance', 'Company country (ISO 3166-1, two letters)')"
              required maxlength="2" />
            <NcTextField v-model="companyTaxId" :label="t('librefinance', 'Tax identifier (optional)')"
              maxlength="40" />
            <NcNoteCard type="info">
              {{ t('librefinance', 'The company and ledger will be created in the same transaction. No external tax registration will be issued or validated automatically.') }}
            </NcNoteCard>
          </template>
          <NcButton variant="primary" type="submit"
            :disabled="creating || workspacesLoading || !name.trim() || !ownerUid.trim()">
            {{ t('librefinance', 'Review creation') }}
          </NcButton>
        </form>
      </div>
    </div>

    <NcDialog :open="Boolean(pending)" :name="t('librefinance', 'Confirm independent financial workspace')"
      size="normal" @update:open="!$event && (pending=null)">
      <div class="lf-saas-confirm">
        <NcNoteCard type="warning">
          {{ t('librefinance', 'Creating a new workspace separates the accounting ledger and does not grant you any financial role. Confirm the environment and owner.') }}
        </NcNoteCard>
        <p><strong>{{ t('librefinance', 'Environment:') }}</strong> {{ pending?.environmentName }}</p>
        <p><strong>{{ t('librefinance', 'Workspace:') }}</strong> {{ pending?.name }}</p>
        <p><strong>{{ t('librefinance', 'Owner:') }}</strong> {{ pending?.ownerUid }}</p>
        <p v-if="pending?.legalName"><strong>{{ t('librefinance', 'Company:') }}</strong>
          {{ pending.legalName }} ({{ pending.companyCountry }})</p>
        <NcNoteCard v-if="workspacesError" type="error" role="alert">
          {{ workspacesError }}
        </NcNoteCard>
        <div class="lf-saas-actions">
          <NcButton variant="tertiary" :disabled="creating" @click="pending=null">
            {{ t('librefinance', 'Cancel') }}
          </NcButton>
          <NcButton variant="primary" :disabled="creating" @click="createWorkspace">
            {{ pending?.legalName ? t('librefinance', 'Create company and workspace') : t('librefinance', 'Create independent workspace') }}
          </NcButton>
        </div>
      </div>
    </NcDialog>
  </section>
</template>

<style scoped>
.lf-saas-environments{list-style:none;display:grid;gap:12px;margin:0;padding:0}
.lf-saas-environment-item{border-bottom:1px solid var(--color-border);padding:12px 0;
  display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
.lf-saas-environment-item p{margin:4px 0 0}
.lf-saas-manager{border-top:1px solid var(--color-border);padding-top:16px;
  display:grid;gap:12px}
.lf-saas-workspaces{display:grid;gap:12px}
.lf-saas-workspaces ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.lf-saas-workspaces li{display:grid;gap:4px;border-bottom:1px solid var(--color-border);padding:8px 0}
.lf-saas-create{display:grid;gap:12px;max-width:510px}
.lf-saas-confirm{display:grid;gap:12px;padding:12px}
.lf-saas-actions{display:flex;justify-content:flex-end;gap:12px}
</style>
