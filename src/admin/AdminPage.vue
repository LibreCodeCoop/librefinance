<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { getLocale, t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import LfSelect from '../components/LfSelect.vue'
import SaasPolicyEditor from '../components/SaasPolicyEditor.vue'

const managed = ref(false)
const contexts = ref([])
const name = ref('')
const gid = ref('')
const groupSearch = ref('')
const groups = ref([])
const groupSearchError = ref('')
const groupsLoading = ref(false)
const groupsNextOffset = ref(null)
const suspendingGroup = ref(null)
const recoveringGroup = ref(null)
const recoveryReason = ref('')
const recoveryOptions = [
  {value: 'delegation_reviewed', label: t('librefinance', 'Delegation reviewed')},
  {value: 'group_admin_restored', label: t('librefinance', 'Group administrator restored')},
  {value: 'security_incident_resolved', label: t('librefinance', 'Security incident resolved')},
  {value: 'membership_corrected', label: t('librefinance', 'Group membership corrected')},
]
const saasGroupsPath = () => generateUrl('/apps/librefinance/api/saas/v1/admin/groups')
const groupStatusLabel = state => ({
  disabled: t('librefinance', 'Not enabled'),
  enabled_pending_admin: t('librefinance', 'Awaiting administrator'),
  active: t('librefinance', 'Active environment'),
  suspended: t('librefinance', 'Suspended'),
})[state] || t('librefinance', 'State unavailable')
const contextKindLabel = kind => ({
  organization: t('librefinance', 'Organization'),
  collective: t('librefinance', 'Group'),
  personal: t('librefinance', 'Personal'),
})[kind] || kind
let groupRequestSeq = 0
const auditGroup = ref(null)
const auditRows = ref([])
const auditNextBeforeId = ref(null)
const auditLoading = ref(false)
const auditError = ref('')
let auditRequestSeq = 0
const auditTimestamp = seconds => new Date(Number(seconds) * 1000).toLocaleString(getLocale()?.replaceAll('_', '-'))
const kind = ref('organization')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const loaded = ref(false)
const bindingId = ref(0)
const bindingGid = ref('')
const delegateGroup = ref('')
const delegateUid = ref('')
const delegates = ref([])
const delegatesLoading = ref(false)
const path = route => generateUrl('/apps/librefinance/api/admin/' + route)
const message = cause => cause?.response?.data?.error || cause.message || t('librefinance', 'Unexpected error')

async function searchGroups(offset = 0) {
  const sequence = ++groupRequestSeq
  groupsLoading.value = true
  groupSearchError.value = ''
  try {
    const response = await axios.get(saasGroupsPath(), {
      params: { search: groupSearch.value.trim().slice(0, 100), offset },
    })
    if (sequence !== groupRequestSeq) return
    const rows = Array.isArray(response.data.groups) ? response.data.groups : []
    groups.value = offset > 0 ? [...groups.value, ...rows] : rows
    groupsNextOffset.value = response.data.nextOffset ?? null
  } catch (cause) {
    if (sequence === groupRequestSeq) groupSearchError.value = message(cause)
  } finally {
    if (sequence === groupRequestSeq) groupsLoading.value = false
  }
}
function closeGroupAudit() {
  ++auditRequestSeq
  auditGroup.value = null
  auditRows.value = []
  auditNextBeforeId.value = null
  auditLoading.value = false
  auditError.value = ''
}
async function openGroupAudit(group) {
  closeGroupAudit()
  if (!group?.environmentId) return
  auditGroup.value = group
  await loadGroupAudit(false)
}
async function loadGroupAudit(more = false) {
  const group = auditGroup.value
  if (!group || auditLoading.value || (more && auditNextBeforeId.value === null)) return
  const seq = ++auditRequestSeq
  const beforeId = more ? auditNextBeforeId.value : null
  auditLoading.value = true
  auditError.value = ''
  try {
    const response = await axios.get(generateUrl('/apps/librefinance/api/saas/v1/admin/groups/'
      + encodeURIComponent(group.id) + '/audit'), {
      params: beforeId === null ? {} : { beforeId },
    })
    if (seq !== auditRequestSeq || auditGroup.value?.id !== group.id) return
    if (response.data.environmentId !== group.environmentId) {
      throw new Error(t('librefinance', 'The group administrative association changed. Reopen the history.'))
    }
    const rows = Array.isArray(response.data.events) ? response.data.events : []
    auditRows.value = more ? [...auditRows.value, ...rows] : rows
    auditNextBeforeId.value = Number.isSafeInteger(response.data.nextBeforeId)
      && response.data.nextBeforeId > 0 ? response.data.nextBeforeId : null
  } catch (cause) {
    if (seq === auditRequestSeq && auditGroup.value?.id === group.id) {
      auditError.value = message(cause)
      if (!more) auditRows.value = []
    }
  } finally {
    if (seq === auditRequestSeq) auditLoading.value = false
  }
}
function setGroupEnabled(group, enabled) {
  if (!group || busy.value) return
  run(async () => {
    await axios.put(generateUrl('/apps/librefinance/api/saas/v1/admin/groups/'
      + encodeURIComponent(group.id) + '/enablement'), { enabled })
    suspendingGroup.value = null
    await searchGroups()
  })
}
function recoverGroup() {
  if (!recoveringGroup.value || !recoveryReason.value || busy.value) return
  const group = recoveringGroup.value
  const reasonCode = recoveryReason.value
  run(async () => {
    await axios.post(generateUrl('/apps/librefinance/api/saas/v1/admin/groups/'
      + encodeURIComponent(group.id) + '/recover'), { reasonCode })
    recoveringGroup.value = null
    recoveryReason.value = ''
    await searchGroups()
  })
}
async function loadDelegates() {
  delegates.value = []
  if (!delegateGroup.value) return
  delegatesLoading.value = true
  try {
    const response = await axios.get(path('groups/' + encodeURIComponent(delegateGroup.value) + '/delegates'))
    delegates.value = response.data.delegates || []
  } catch (cause) { error.value = message(cause) }
  finally { delegatesLoading.value = false }
}
function grantDelegate() {
  if (!delegateGroup.value || !delegateUid.value.trim()) return
  run(async () => {
    await axios.post(path('groups/' + encodeURIComponent(delegateGroup.value) + '/delegates'), {
      uid: delegateUid.value.trim(),
    })
    delegateUid.value = ''
    await loadDelegates()
  })
}
function revokeDelegate(uid) {
  if (!delegateGroup.value) return
  run(async () => {
    await axios.delete(path('groups/' + encodeURIComponent(delegateGroup.value)
      + '/delegates/' + encodeURIComponent(uid)))
    await loadDelegates()
  })
}
async function reload() {
  const [config, existing] = await Promise.all([
    axios.get(path('governance')), axios.get(path('contexts')),
  ])
  managed.value = Boolean(config.data.managed)
  contexts.value = existing.data.contexts || []
}

async function run(action) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await action()
    await reload()
    notice.value = t('librefinance', 'Configuration updated.')
  } catch (cause) {
    error.value = message(cause)
  } finally { busy.value = false }
}
function setManaged(value) {
  run(() => axios.put(path('governance'), {managed: Boolean(value)}))
}
function provision() {
  if (!name.value.trim() || !gid.value.trim()) return
  run(async () => {
    await axios.post(path('contexts'), {
      name: name.value.trim(), kind: kind.value, currency: 'BRL', gid: gid.value.trim(),
    })
    name.value = ''
    gid.value = ''
  })
}
function restoreContext(context) {
  run(() => axios.post(path('contexts/' + Number(context.id) + '/restore'), {}))
}
function bind() {
  if (!bindingId.value || !bindingGid.value.trim()) return
  run(async () => {
    await axios.put(path('contexts/' + Number(bindingId.value) + '/group'), {
      gid: bindingGid.value.trim(),
    })
    bindingId.value = 0
    bindingGid.value = ''
  })
}
onMounted(async () => {
  try { await reload() } catch (cause) { error.value = message(cause) }
  finally { loaded.value = true }
  await searchGroups()
})
onBeforeUnmount(() => {
  ++groupRequestSeq
  closeGroupAudit()
})
</script>

<template>
  <section class="lf-admin-page">
    <h2>{{ t('librefinance', 'LibreFinance — Instance administration') }}</h2>
    <p>{{ t('librefinance', 'Global administration is restricted to Nextcloud administrators. Delegated group administrators manage only their own financial contexts.') }}</p>
    <NcLoadingIcon v-if="!loaded" :size="24" />
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <NcCheckboxRadioSwitch :model-value="managed" :disabled="busy || !loaded"
      type="switch" @update:model-value="setManaged">
      {{ t('librefinance', 'Provisioning managed by the instance administrator') }}
    </NcCheckboxRadioSwitch>
    <p>{{ t('librefinance', 'When enabled, ordinary users cannot create legacy financial contexts. Legacy context associations and delegations are preserved. In new SaaS environments, administering a group or the instance does not in itself grant access to financial data.') }}</p>

    <div class="lf-admin-card">
      <h3>{{ t('librefinance', 'Instance SaaS policies') }}</h3>
      <p>{{ t('librefinance', 'The limits and restrictions defined here are inherited by environments and workspaces. Editing them does not grant access to financial ledgers.') }}</p>
      <SaasPolicyEditor scope="instance" />
    </div>

    <div v-if="managed" class="lf-admin-card">
      <h3>{{ t('librefinance', 'Available Nextcloud groups') }}</h3>
      <p>{{ t('librefinance', 'Enable individual Nextcloud groups for LibreFinance SaaS. Without a qualified group subadministrator or delegate, the environment awaits authorization to be provisioned. None of these actions grants access to the accounting ledger.') }}</p>
      <form @submit.prevent="searchGroups()">
        <NcTextField v-model="groupSearch" :label="t('librefinance', 'Search groups')" maxlength="100" />
        <NcButton type="submit" :disabled="busy || groupsLoading">{{ t('librefinance', 'Find groups') }}</NcButton>
      </form>
      <NcLoadingIcon v-if="groupsLoading" :size="20" />
      <NcNoteCard v-if="groupSearchError" type="error" role="alert">{{ groupSearchError }}</NcNoteCard>
      <p v-if="!groupsLoading && groups.length===0">{{ t('librefinance', 'No groups found.') }}</p>
      <ul v-if="groups.length" :aria-label="t('librefinance', 'SaaS status of Nextcloud groups')" class="lf-saas-groups">
        <li v-for="group in groups" :key="group.id" class="lf-saas-group-row">
          <div>
            <strong>{{ group.name }}</strong>
            <span class="lf-admin-hint">({{ group.id }})</span>
            <p class="lf-admin-hint">{{ groupStatusLabel(group.state) }}</p>
          </div>
          <NcButton v-if="group.state==='disabled'" variant="primary"
            :disabled="busy || groupsLoading"
            :aria-label="t('librefinance', 'Enable LibreFinance for {name}', { name: group.name }, { escape: false, sanitize: false })"
            @click="setGroupEnabled(group, true)">{{ t('librefinance', 'Enable') }}</NcButton>
          <NcButton v-else-if="group.state==='active' || group.state==='enabled_pending_admin'"
            variant="tertiary" :disabled="busy || groupsLoading"
            :aria-label="t('librefinance', 'Suspend LibreFinance for {name}', { name: group.name }, { escape: false, sanitize: false })"
            @click="suspendingGroup=group">{{ t('librefinance', 'Suspend') }}</NcButton>
          <NcButton v-else-if="group.state==='suspended'"
            variant="secondary" :disabled="busy || groupsLoading"
            :aria-label="t('librefinance', 'Recover environment for {name}', { name: group.name }, { escape: false, sanitize: false })"
            @click="recoveringGroup=group">{{ t('librefinance', 'Recover') }}</NcButton>
          <span v-else class="lf-admin-hint">{{ t('librefinance', 'State unavailable') }}</span>
          <NcButton v-if="group.environmentId" variant="tertiary"
            :disabled="auditLoading"
            :aria-label="t('librefinance', 'View audit history for {name}', { name: group.name }, { escape: false, sanitize: false })"
            @click="openGroupAudit(group)">{{ t('librefinance', 'Audit') }}</NcButton>
        </li>
      </ul>
      <NcButton v-if="groupsNextOffset!==null" variant="tertiary"
        :disabled="busy || groupsLoading" @click="searchGroups(groupsNextOffset)">
        {{ t('librefinance', 'Load more groups') }}
      </NcButton>
    </div>
    <div v-if="managed" class="lf-admin-card">
      <h3>{{ t('librefinance', 'LibreFinance delegated administrators') }}</h3>
      <p>{{ t('librefinance', 'Delegation allows administration of the LibreFinance environment for that group. In new SaaS environments, it grants neither a financial role nor access to journal entries. The grant applies only to LibreFinance and does not change permissions in other Nextcloud apps.') }}</p>
      <label for="lf-delegate-group">{{ t('librefinance', 'Governing group') }}</label>
      <select id="lf-delegate-group" v-model="delegateGroup" @change="loadDelegates">
        <option value="">{{ t('librefinance', 'Select a group') }}</option>
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }} ({{ group.id }})
        </option>
      </select>
      <NcLoadingIcon v-if="delegatesLoading" :size="20" />
      <ul v-if="delegateGroup && delegates.length">
        <li v-for="delegate in delegates" :key="delegate.uid">
          {{ delegate.uid }}
          <NcButton variant="tertiary" :disabled="busy"
            :aria-label="t('librefinance', 'Revoke delegation for {name}', { name: delegate.uid }, { escape: false, sanitize: false })"
            @click="revokeDelegate(delegate.uid)">{{ t('librefinance', 'Revoke') }}</NcButton>
        </li>
      </ul>
      <p v-else-if="delegateGroup && !delegatesLoading">{{ t('librefinance', 'No LibreFinance delegated administrators.') }}</p>
      <form v-if="delegateGroup" @submit.prevent="grantDelegate">
        <NcTextField v-model="delegateUid" :label="t('librefinance', 'Nextcloud user in the group')" required />
        <NcButton type="submit" :disabled="busy || !delegateUid.trim()">{{ t('librefinance', 'Delegate administration') }}</NcButton>
      </form>
    </div>
    <div v-if="managed" class="lf-admin-card">
      <h3>{{ t('librefinance', 'New financial context') }}</h3>
      <form @submit.prevent="provision">
        <NcTextField v-model="name" :label="t('librefinance', 'Context name')" required maxlength="150" />
        <label for="lf-governance-kind">{{ t('librefinance', 'Type') }}</label>
        <select id="lf-governance-kind" v-model="kind">
          <option value="organization">{{ t('librefinance', 'Organization') }}</option>
          <option value="collective">{{ t('librefinance', 'Group') }}</option>
          <option value="personal">{{ t('librefinance', 'Personal') }}</option>
        </select>
        <label for="lf-governance-group">{{ t('librefinance', 'Nextcloud group') }}</label>
        <select id="lf-governance-group" v-model="gid" required>
          <option value="" disabled>{{ t('librefinance', 'Select a group') }}</option>
          <option v-for="group in groups" :key="group.id" :value="group.id">
            {{ group.name }} ({{ group.id }})
          </option>
        </select>
        <NcButton variant="primary" type="submit" :disabled="busy || !name.trim() || !gid.trim()">
          {{ t('librefinance', 'Create context') }}
        </NcButton>
      </form>
    </div>
    <div v-if="managed" class="lf-admin-card">
      <h3>{{ t('librefinance', 'Existing contexts') }}</h3>
      <ul>
        <li v-for="context in contexts" :key="context.id">
          {{ context.name }} ({{ contextKindLabel(context.kind) }}) —
          {{ context.governanceGroup || t('librefinance', 'No governing group') }}
          <span v-if="context.legacyReviewState === 'pending_review'">
            · {{ t('librefinance', 'Review pending: multiple legal identities in the same ledger.') }}
          </span>
          <span v-if="context.archived"> · {{ t('librefinance', 'Archived') }}
            <NcButton variant="tertiary" :disabled="busy"
              @click="restoreContext(context)">{{ t('librefinance', 'Restore') }}</NcButton>
          </span>
        </li>
      </ul>
      <form @submit.prevent="bind">
        <label for="lf-governance-existing">{{ t('librefinance', 'Associate existing context') }}</label>
        <select id="lf-governance-existing" v-model.number="bindingId" required>
          <option :value="0" disabled>{{ t('librefinance', 'Choose a context without a group') }}</option>
          <option v-for="context in contexts.filter(c=>!c.governanceGroup)"
            :key="context.id" :value="context.id">{{ context.name }}</option>
        </select>
        <label for="lf-governance-existing-group">{{ t('librefinance', 'Nextcloud group') }}</label>
        <select id="lf-governance-existing-group" v-model="bindingGid" required>
          <option value="" disabled>{{ t('librefinance', 'Select a group') }}</option>
          <option v-for="group in groups" :key="group.id" :value="group.id">
            {{ group.name }} ({{ group.id }})
          </option>
        </select>
        <NcButton type="submit" :disabled="busy || !bindingId || !bindingGid.trim()">{{ t('librefinance', 'Associate') }}</NcButton>
      </form>
    </div>
    <NcDialog :open="Boolean(auditGroup)" size="normal"
      :name="t('librefinance', 'LibreFinance audit history')"
      @update:open="!$event && closeGroupAudit()">
      <div class="lf-admin-confirm">
        <h3>{{ t('librefinance', 'Environment history for {name}', { name: auditGroup?.name ?? '' }, { escape: false, sanitize: false }) }}</h3>
        <p>{{ t('librefinance', 'Chronological record of environment administration actions. It does not contain balances, journal entries or financial documents.') }}</p>
        <NcNoteCard v-if="auditError" type="error" role="alert">{{ auditError }}</NcNoteCard>
        <NcLoadingIcon v-if="auditLoading" :size="20" />
        <ol v-if="auditRows.length" :aria-label="t('librefinance', 'Administrative audit events')">
          <li v-for="event in auditRows" :key="event.id">
            <strong>{{ event.action }}</strong>
            <p>{{ t('librefinance', '{time} · By {actor}', { time: auditTimestamp(event.occurredAt), actor: event.actorUid }, { escape: false, sanitize: false }) }}</p>
            <p class="lf-admin-hint">{{ event.subjectType }} · {{ event.subjectId }}</p>
          </li>
        </ol>
        <p v-else-if="!auditLoading && !auditError">{{ t('librefinance', 'No events recorded yet.') }}</p>
        <div class="lf-admin-actions">
          <NcButton v-if="auditNextBeforeId !== null" variant="secondary"
            :disabled="auditLoading" @click="loadGroupAudit(true)">
            {{ t('librefinance', 'Load earlier events') }}
          </NcButton>
          <NcButton variant="tertiary" @click="closeGroupAudit()">{{ t('librefinance', 'Close') }}</NcButton>
        </div>
      </div>
    </NcDialog>
    <NcDialog :open="Boolean(suspendingGroup)" size="normal"
      :name="t('librefinance', 'Suspend LibreFinance group')"
      @update:open="!$event && (suspendingGroup=null)">
      <div class="lf-admin-confirm">
        <NcNoteCard type="warning">{{ t('librefinance', 'Suspension prevents new access to the environment, but preserves journal entries, documents and historical associations. Reactivation requires audited administrative recovery.') }}</NcNoteCard>
        <p>{{ t('librefinance', 'Suspend {name}?', { name: suspendingGroup?.name ?? '' }, { escape: false, sanitize: false }) }}</p>
        <div class="lf-admin-actions">
          <NcButton variant="tertiary" @click="suspendingGroup=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="error" :disabled="busy"
            @click="setGroupEnabled(suspendingGroup, false)">{{ t('librefinance', 'Suspend group') }}</NcButton>
        </div>
      </div>
    </NcDialog>
    <NcDialog :open="Boolean(recoveringGroup)" size="normal"
      :name="t('librefinance', 'Recover LibreFinance environment')"
      @update:open="!$event && (recoveringGroup=null)">
      <div class="lf-admin-confirm">
        <NcNoteCard type="warning">
          {{ t('librefinance', 'Only the instance administrator can perform recovery. The reason is recorded in the audit history. The operation keeps financial data intact and returns the environment to the awaiting administrator state, without granting automatic financial access.') }}
        </NcNoteCard>
        <p>{{ t('librefinance', 'Recover {name}?', { name: recoveringGroup?.name ?? '' }, { escape: false, sanitize: false }) }}</p>
        <LfSelect id="lf-saas-recovery-reason" v-model="recoveryReason"
          :label="t('librefinance', 'Required recovery reason')"
          :options="recoveryOptions" :disabled="busy"
          :placeholder="t('librefinance', 'Choose the recovery reason')" />
        <div class="lf-admin-actions">
          <NcButton variant="tertiary"
            @click="recoveringGroup=null; recoveryReason=''">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" :disabled="busy || !recoveryReason"
            @click="recoverGroup">{{ t('librefinance', 'Confirm recovery') }}</NcButton>
        </div>
      </div>
    </NcDialog>
  </section>
</template>

<style scoped>
.lf-admin-page{max-width:860px;padding:16px 0;display:grid;gap:14px}
.lf-admin-card{border:1px solid var(--color-border);border-radius:var(--border-radius-large);padding:16px}
.lf-admin-card form{display:flex;flex-direction:column;gap:12px;max-width:480px}
.lf-admin-card ul{padding-inline-start:22px;margin:12px 0}
.lf-admin-card select{min-height:40px;border-radius:var(--border-radius)}
.lf-saas-groups{display:grid;gap:12px;list-style:none;padding:0;margin:16px 0}
.lf-saas-group-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--color-border);padding:10px 0}
.lf-admin-hint{color:var(--color-text-maxcontrast);font-size:var(--default-font-size)}
.lf-admin-confirm{display:grid;gap:16px;padding:12px}
.lf-admin-actions{display:flex;justify-content:flex-end;gap:12px}
</style>
