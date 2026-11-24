<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiAccountPlusOutline, mdiAccountRemoveOutline, mdiPuzzleOutline } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import FieldStudioManager from '../components/FieldStudioManager.vue'
import PaymentMethodsManager from '../components/PaymentMethodsManager.vue'
import { financeApi, financeError } from '../services/api.js'
const w=inject('lfWorkspace')
const {activeId, manager, members, membersGrantRevision, canManageMembers, groupGrants, modules,
  shareUid, shareRole, groupGid, groupRole, shareUser, shareGroup,
  toggleModule, refreshCurrent, busy}=w
const props = defineProps({
  sectionKey: { type: String, default: 'access' },
})
const sections = {
  access: {
    panel: 'access', title: t('librefinance', 'Access and sharing'),
    description: t('librefinance', 'Define who can view and operate this financial workspace.'),
  },
  fields: {
    panel: 'fields', title: t('librefinance', 'Custom fields'),
    description: t('librefinance', 'Customize records according to the data used by the organization.'),
  },
  paymentMethods: {
    panel: 'payments', title: t('librefinance', 'Payment methods'),
    description: t('librefinance', 'Manage payment methods available for entries.'),
  },
  modules: {
    panel: 'modules', title: t('librefinance', 'Installed features'),
    description: t('librefinance', 'Activate or deactivate the features available in this financial workspace.'),
  },
}
const coreModuleLabels = {
  librefinance_banking: { name: t('librefinance', 'Banking and reconciliation'), description: t('librefinance', 'Bank statements, transfers and reconciliation') },
  librefinance_obligations: { name: t('librefinance', 'Payables and receivables'), description: t('librefinance', 'Bills, obligations, due dates and settlements') },
  librefinance_invoicing: { name: t('librefinance', 'Sales, purchases and taxes'), description: t('librefinance', 'Commercial invoices and jurisdiction-specific tax rules') },
  librefinance_reports: { name: t('librefinance', 'Financial reports'), description: t('librefinance', 'Profit and loss, cash flow, trial balance and saved reports') },
  librefinance_import: { name: t('librefinance', 'Data import and migration'), description: t('librefinance', 'Approved imports, source identity mapping and reconciliation') },
}
const current = computed(() => sections[props.sectionKey] || sections.access)
const section = computed(() => current.value.panel)
const shareDialog=ref('')
const removeTarget=ref(null)
const governanceGroup=ref(null)
const canChangeGroupGrants=ref(false)
const canGrantManager=ref(false)
let governanceRequest=0
async function loadGovernance() {
  const token=++governanceRequest
  governanceGroup.value=null
  canChangeGroupGrants.value=false
  canGrantManager.value=false
  if (!activeId.value) return
  const target=Number(activeId.value)
  try {
    const data=await financeApi('librefinance',target,'/governance')
    if (token!==governanceRequest || target!==Number(activeId.value)) return
    governanceGroup.value=data.governanceGroup
    canChangeGroupGrants.value=Boolean(data.canChangeGroupGrants)
    canGrantManager.value=Boolean(data.canGrantManager)
  } catch {}
}
onMounted(loadGovernance)
watch(activeId,loadGovernance)
onBeforeUnmount(()=>{governanceRequest++})
const problem=ref('')
const removing=ref(false)
const selectedRole=computed({
  get:()=>shareDialog.value==='member'?shareRole.value:groupRole.value,
  set:(value)=>{if(shareDialog.value==='member')shareRole.value=value;else groupRole.value=value},
})
const roleLabels = {
  owner: t('librefinance', 'Owner'),
  manager: t('librefinance', 'Manager'),
  operator: t('librefinance', 'Operator'),
  auditor: t('librefinance', 'Auditor'),
  viewer: t('librefinance', 'Viewer'),
}
const roles=computed(()=>[
  {value:'viewer',label:t('librefinance', 'Viewer')},{value:'auditor',label:t('librefinance', 'Auditor')},
  {value:'operator',label:t('librefinance', 'Operator')},
  ...(canGrantManager.value?[{value:'manager',label:t('librefinance', 'Manager')}]:[]),
])
async function share() {
  if (!shareDialog.value) return
  if (shareDialog.value==='member' && !shareUid.value.trim()) return
  if (shareDialog.value==='group' && !groupGid.value.trim()) return
  if (shareDialog.value==='member') await shareUser()
  else await shareGroup()
  if (!w.error.value) shareDialog.value=''
}
async function confirmRemoval() {
  if (!removeTarget.value || removing.value) return
  removing.value=true
  problem.value=''
  try {
    const target=removeTarget.value
    const expected = target.type === 'member' && Number.isSafeInteger(membersGrantRevision.value)
      ? '?expectedRevision=' + membersGrantRevision.value : ''
    await financeApi('librefinance',activeId.value,
      '/' + (target.type==='member'?'members/':'groups/') + encodeURIComponent(target.id) + expected,'DELETE')
    removeTarget.value=null
    await refreshCurrent()
  } catch(e) {
    if (e.response?.data?.error === 'revision_conflict') {
      try { await refreshCurrent() } catch { /* Keep the original failure visible. */ }
      problem.value = t('librefinance', 'Access grants changed. Check the refreshed list before revoking again.')
    } else {
      problem.value = financeError(e)
    }
  } finally {removing.value=false}
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="current.title" :eyebrow="t('librefinance', 'MANAGE')"
      :description="current.description" />
    <section v-if="section==='access'" class="lf-surface">
      <div class="lf-section-heading"><div><h2>{{ t('librefinance', 'Workspace access') }}</h2>
        <p>{{ t('librefinance', 'Permissions are checked by the server for each operation. Nextcloud accounts and groups are not customizable financial records: custom fields belong to companies, contacts, items and entries.') }}</p></div>
        <div class="lf-header-actions">
          <NcButton v-if="canManageMembers" variant="secondary" @click="shareDialog='member'">
            <template #icon><NcIconSvgWrapper :path="mdiAccountPlusOutline" :size="20" /></template>
            {{ t('librefinance', 'User') }}
          </NcButton>
          <NcButton v-if="canChangeGroupGrants" variant="secondary" @click="shareDialog='group'">{{ t('librefinance', 'Group') }}</NcButton>
        </div>
      </div>
      <NcNoteCard v-if="!canManageMembers" type="info">
        {{ t('librefinance', 'Only users with financial role management permission can grant or revoke individual access. Environment administrators do not automatically receive this permission.') }}
      </NcNoteCard>
      <NcNoteCard v-if="governanceGroup" type="info">
        {{ t('librefinance', 'This context is administered by Nextcloud group {name}. Delegated administrators can grant access only to users belonging to the authorized group.', { name: governanceGroup }, { escape: false, sanitize: false }) }}
      </NcNoteCard>
      <div v-if="members.length || groupGrants.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>{{ t('librefinance', 'Type') }}</th>
            <th>{{ t('librefinance', 'Identifier') }}</th>
            <th>{{ t('librefinance', 'Permission') }}</th>
            <th>{{ t('librefinance', 'Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="member in members" :key="'user-'+member.uid">
              <td>{{ t('librefinance', 'User') }}</td><td><strong>{{ member.uid }}</strong></td><td :data-role="member.role">{{ roleLabels[member.role] || member.role }}</td>
              <td><NcButton v-if="canManageMembers && member.role!=='owner'" variant="tertiary"
                :aria-label="t('librefinance', 'Revoke access for {name}', { name: member.uid }, { escape: false, sanitize: false })"
                @click="removeTarget={type:'member',id:member.uid}">
                <template #icon><NcIconSvgWrapper :path="mdiAccountRemoveOutline" :size="20" /></template>
              </NcButton></td>
            </tr>
            <tr v-for="group in groupGrants" :key="'group-'+group.group_gid">
              <td>{{ t('librefinance', 'Group') }}</td><td><strong>{{group.group_gid}}</strong></td><td :data-role="group.role">{{ roleLabels[group.role] || group.role }}</td>
              <td><NcButton v-if="canChangeGroupGrants" variant="tertiary"
                :aria-label="t('librefinance', 'Revoke access for group {name}', { name: group.group_gid }, { escape: false, sanitize: false })"
                @click="removeTarget={type:'group',id:group.group_gid}">
                <template #icon><NcIconSvgWrapper :path="mdiAccountRemoveOutline" :size="20" /></template>
              </NcButton></td>
            </tr>
          </tbody>
        </table>
      </div>
      <NcEmptyContent v-else :name="t('librefinance', 'No other access grants')"
        :description="t('librefinance', 'Invite people or groups when you need to collaborate.')" />
    </section>
    <section v-if="section==='fields'" class="lf-surface">
      <FieldStudioManager />
    </section>
    <section v-if="section==='payments'" class="lf-surface">
      <PaymentMethodsManager />
    </section>
    <section v-if="section==='modules'" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Available features') }}</h2>
        <p>{{ t('librefinance', 'LibreFinance capabilities installed by the Nextcloud administrator.') }}</p></div>
      <NcNoteCard type="info">
        {{ t('librefinance', 'Activating a feature in this workspace does not install new code or grant additional permission. Regional apps such as NFS-e remain optional.') }}
      </NcNoteCard>
      <div v-for="module in modules" :key="module.id" class="lf-settings-module" :data-module-id="module.id">
        <div class="lf-module-descriptor">
          <NcIconSvgWrapper :path="mdiPuzzleOutline" :size="22" />
          <div><strong>{{ coreModuleLabels[module.id]?.name || module.name }}</strong><small>{{ coreModuleLabels[module.id]?.description || module.description }}</small>
            <small v-if="module.blockedByPolicy">
              {{ t('librefinance', 'Blocked by administrative policy. Local activation does not remove the restriction.') }}
            </small>
            <small v-else>{{module.enabled?t('librefinance', 'Available in this workspace'):t('librefinance', 'Deactivated in this workspace')}}</small></div>
        </div>
        <NcButton :disabled="busy || !manager || module.blockedByPolicy"
          :variant="module.enabled?'tertiary':'primary'"
          @click="toggleModule(module)">{{ module.blockedByPolicy ? t('librefinance', 'Blocked by policy') : (module.enabled ? t('librefinance', 'Deactivate') : t('librefinance', 'Activate')) }}</NcButton>
      </div>
      <NcEmptyContent v-if="!modules.length" :name="t('librefinance', 'No additional features available')" />
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(shareDialog)"
      :name="shareDialog==='member'?t('librefinance', 'Grant access to user'):t('librefinance', 'Grant access to group')"
      @update:open="shareDialog=$event?shareDialog:''">
      <form class="lf-dialog-form" @submit.prevent="share">
        <NcTextField v-if="shareDialog==='member'" v-model="shareUid"
          :label="t('librefinance', 'Nextcloud user')" required />
        <NcTextField v-else v-model="groupGid" :label="t('librefinance', 'Nextcloud group')" required />
        <LfSelect id="lf-sharing-role" v-model="selectedRole"
          :options="roles" :label="t('librefinance', 'Workspace role')" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="shareDialog=''">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">{{ t('librefinance', 'Grant access') }}</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog content-classes="lf-finance-dialog-content" :open="Boolean(removeTarget)" :name="t('librefinance', 'Revoke access')"
      @update:open="removeTarget = $event ? removeTarget : null">
      <div class="lf-dialog-form">
        <p>{{ t('librefinance', 'Revoke access for {name} to this financial workspace?', { name: removeTarget?.id }, { escape: false, sanitize: false }) }}</p>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="removeTarget=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="error" :disabled="removing" @click="confirmRemoval">
            {{ t('librefinance', 'Revoke access') }}
          </NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
