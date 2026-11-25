<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFolderAccountOutline, mdiHomeOutline, mdiDomain,
  mdiAccountGroupOutline, mdiPencilOutline, mdiArchiveArrowDownOutline,
  mdiBackupRestore, mdiFolderOpenOutline } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import SaasEnvironmentsPanel from '../components/SaasEnvironmentsPanel.vue'
import SaasPolicyEditor from '../components/SaasPolicyEditor.vue'
import SaasInvitationsPanel from '../components/SaasInvitationsPanel.vue'
import { financeError } from '../services/api.js'
import { ledgerLocale } from '../money.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const onboarding = inject('lfCompanyOnboarding', ref(false))
const archived = ref([])
const loading = ref(false)
const creating = ref(false)
const editing = ref(null)
const archiving = ref(null)
const problem = ref('')
const showWorkspacePolicies = ref(false)
const notice = ref('')
const name = ref('')
const newName = ref('')
const newKind = ref('personal')
const canCreate = ref(false)
async function loadCreationPolicy() {
  try {
    const result = await axios.get(generateUrl('/apps/librefinance/api/governance/capabilities'))
    canCreate.value = Boolean(result.data.canCreate)
  } catch { canCreate.value = false }
}
const newColor = ref('#64748B')
const editColor = ref('#64748B')
const kinds = [
  { value: 'personal', label: t('librefinance', 'Personal or household') },
  { value: 'collective', label: t('librefinance', 'Group or collective') },
  { value: 'organization', label: t('librefinance', 'Business or organization') },
]
const roleLabels = {
  owner: t('librefinance', 'Owner'),
  manager: t('librefinance', 'Manager'),
  operator: t('librefinance', 'Operator'),
  auditor: t('librefinance', 'Auditor'),
  viewer: t('librefinance', 'Viewer'),
}
const search = ref('')
const needle = computed(() => search.value.toLocaleLowerCase(ledgerLocale()).trim())
const matches = item => !needle.value
  || item.name.toLocaleLowerCase(ledgerLocale()).includes(needle.value)
  || (kinds.find(k => k.value === item.kind)?.label || '').toLocaleLowerCase(ledgerLocale()).includes(needle.value)
const owned = computed(() => w.spaces.value.filter(space => space.role === 'owner' && matches(space)))
const other = computed(() => w.spaces.value.filter(space => space.role !== 'owner' && matches(space)))
const archivedFiltered = computed(() => archived.value.filter(matches))
const typeIcon = type => ({ personal: mdiHomeOutline, collective: mdiAccountGroupOutline,
  organization: mdiDomain })[type] || mdiFolderAccountOutline
function href(space) {
  const url = new URL(window.location.href)
  url.searchParams.set('spaceId', String(Number(space.id)))
  url.searchParams.set('view', 'dashboard')
  url.searchParams.delete('section')
  return url.pathname + url.search
}
function openSpace(event, space) {
  if (event?.button !== 0 || event.metaKey || event.altKey || event.ctrlKey || event.shiftKey) return
  event.preventDefault()
  w.activeId.value = Number(space.id)
  navigate('dashboard')
}
let requestSeq = 0
let viewMounted = true
let creationDialogRevision = 0
watch(creating, () => { ++creationDialogRevision }, { flush: 'sync' })
async function refreshArchived() {
  const token = ++requestSeq
  loading.value = true
  try {
    const data = await w.call('GET', '/api/spaces/archived')
    if (token === requestSeq) archived.value = data.spaces
  } catch (error) {
    if (token === requestSeq) problem.value = financeError(error)
  } finally {
    if (token === requestSeq) loading.value = false
  }
}
onMounted(() => { refreshArchived(); loadCreationPolicy() })
onBeforeUnmount(() => { ++requestSeq; viewMounted = false })
function startEdit(space) {
  problem.value = ''
  editing.value = space
  name.value = space.name
  editColor.value = space.accent_color || '#64748B'
}
async function rename() {
  if (!editing.value || !name.value.trim() || loading.value) return
  const target = editing.value
  loading.value = true
  problem.value = ''
  try {
    await w.call('PUT', '/api/spaces/' + Number(target.id), {
      name: name.value.trim(),
      ...(target.kind === 'organization' ? {} : { color: editColor.value }),
    })
    editing.value = null
    await w.refreshSpaces()
    notice.value = t('librefinance', 'Workspace name updated.')
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
async function archive() {
  if (!archiving.value || loading.value) return
  const target = archiving.value
  loading.value = true
  problem.value = ''
  try {
    await w.call('POST', '/api/spaces/' + Number(target.id) + '/archive', {})
    archiving.value = null
    await w.refreshSpaces()
    await refreshArchived()
    notice.value = t('librefinance', 'Workspace archived without deleting entries or documents.')
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
async function restore(space) {
  if (loading.value) return
  loading.value = true
  problem.value = ''
  try {
    await w.call('POST', '/api/spaces/' + Number(space.id) + '/restore', {})
    await w.refreshSpaces()
    await refreshArchived()
    notice.value = t('librefinance', 'Workspace restored with its history.')
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
async function create() {
  if (!newName.value.trim() || loading.value) return
  const originId = Number(w.activeId.value)
  const submittedKind = newKind.value
  let dialogRevision = creationDialogRevision
  const ownsView = () => viewMounted && creationDialogRevision === dialogRevision
    && Number(w.activeId.value) === originId && w.activeTab.value === 'spaces'
  loading.value = true
  problem.value = ''
  try {
    const created = await w.call('POST', '/api/spaces', {
      name: newName.value.trim(), kind: submittedKind, currency: 'BRL',
      color: submittedKind === 'organization' ? '#64748B' : newColor.value,
    })
    const ownsCompletion = ownsView() && creating.value
    if (ownsCompletion) {
      creating.value = false
      dialogRevision = creationDialogRevision
      newName.value = ''
      newColor.value = '#64748B'
    }

    // The server may have committed the workspace after the user left or
    // dismissed this dialog. Refresh the authorized list, but do not let
    // that obsolete UI action take over another screen or workspace.
    await w.refreshSpaces()
    const createdId = Number(created.id)
    // On the first run, refreshing the list can select the new workspace
    // and remount this view. That is part of this still-owned creation.
    const initialSelection = originId === 0
      && Number(w.activeId.value) === createdId
      && w.activeTab.value === 'spaces'
      && creationDialogRevision === dialogRevision
    if (!ownsCompletion || (!ownsView() && !initialSelection)
        || !w.spaces.value.some(s => Number(s.id) === createdId)) return

    w.activeId.value = createdId
    // Wait until activeId watchers have reset navigation before opening onboarding.
    await nextTick()
    if (Number(w.activeId.value) !== createdId || w.activeTab.value !== 'spaces') return
    notice.value = t('librefinance', 'Independent workspace created.')
    if (submittedKind === 'organization') {
      onboarding.value = true
      navigate('companies')
    } else {
      navigate('dashboard')
    }
  } catch (error) {
    if (ownsView()) problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="lf-view">
    <LfPageHeader :title="t('librefinance', 'Financial workspaces')" :eyebrow="t('librefinance', 'MANAGE')"
      :description="t('librefinance', 'Each financial area contains an independent ledger, cash and access rights. Registering companies within an area does not create separate accounting.')" >
      <template #actions>
        <NcButton v-if="canCreate" variant="primary" @click="creating=true">{{ t('librefinance', 'New workspace') }}</NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{notice}}</NcNoteCard>
    <div class="lf-area-guidance">
      <p>{{ t('librefinance', 'A financial workspace has its own cash and accounting ledger, with independent accounts, entries, documents and access rights. It is not a department or a company or branch registration.') }}</p>
      <p>{{ t('librefinance', 'To register or edit companies and branches within the current finances, use Organization details in Records, without creating another area. For separate accounting and balances by tax ID, use independent workspaces: companies registered in the same workspace do not have segregated ledgers.') }}</p>
      <NcButton v-if="w.manager.value && w.activeId.value" variant="secondary"
        @click="navigate('companies')">{{ t('librefinance', 'Register or edit companies') }}</NcButton>
    </div>
    <SaasEnvironmentsPanel />
    <SaasInvitationsPanel mode="inbox" @accepted="w.refreshSpaces()" />
    <section v-if="w.activeId.value && w.spaces.value.some(s =>
      Number(s.id) === Number(w.activeId.value)
      && s.saasManaged === true && s.role === 'owner')" class="lf-surface">
      <h2>{{ t('librefinance', 'External financial sharing') }}</h2>
      <SaasInvitationsPanel mode="owner" :space-id="Number(w.activeId.value)" />
    </section>
    <section v-if="w.activeId.value && w.manager.value
      && w.spaces.value.some(s => Number(s.id) === Number(w.activeId.value)
        && s.saasManaged === true)" class="lf-surface">
      <div class="lf-section-heading">
        <div>
          <h2>{{ t('librefinance', 'Policies of the current workspace') }}</h2>
          <p>{{ t('librefinance', 'Owners and financial managers can restrict features of their SaaS workspace without modifying other ledgers in the environment.') }}</p>
        </div>
        <NcButton variant="secondary"
          :aria-expanded="showWorkspacePolicies"
          @click="showWorkspacePolicies=!showWorkspacePolicies">
          {{ showWorkspacePolicies ? t('librefinance', 'Hide policies') : t('librefinance', 'Configure policies') }}
        </NcButton>
      </div>
      <SaasPolicyEditor v-if="showWorkspacePolicies"
        scope="workspace" :space-id="Number(w.activeId.value)" hide-unsupported />
    </section>
    <section v-if="w.activeId.value && w.spaces.value.some(s =>
      Number(s.id) === Number(w.activeId.value) && s.saasManaged === true)"
      class="lf-surface">
      <h2>{{ t('librefinance', 'Individual dashboard preferences') }}</h2>
      <p>{{ t('librefinance', 'These user restrictions are independent of other ledgers and never replace group financial permission.') }}</p>
      <SaasPolicyEditor scope="user"
        :space-id="Number(w.activeId.value)" hide-unsupported />
    </section>
    <div v-if="w.spaces.value.length || archived.length" class="lf-native-workspace-filter" role="search">
      <NcTextField v-model="search" :label="t('librefinance', 'Filter financial workspaces')"
        type="search" :placeholder="t('librefinance', 'Area name or type')" />
    </div>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>{{ t('librefinance', 'My workspaces') }}</h2>
          <p>{{ t('librefinance', 'Open accesses the financial ledger. Renaming or archiving does not change entries.') }}</p></div>
      </div>
      <ul v-if="owned.length" class="lf-native-space-list" :aria-label="t('librefinance', 'Workspaces I own')">
        <NcListItem v-for="item in owned" :key="item.id"
          :name="item.name" :details="item.currency"
          :href="href(item)" :actions-aria-label="t('librefinance', 'Actions for workspace {name}', { name: item.name }, { escape: false, sanitize: false })"
          force-menu force-display-actions @click="openSpace($event, item)">
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>
            <span v-if="item.kind!=='organization'" class="lf-category-dot" :style="{backgroundColor:item.accent_color||'#64748B'}" aria-hidden="true" />
            {{ kinds.find(k => k.value===item.kind)?.label || item.kind }}
            · {{ t('librefinance', 'Owner') }}
          </template>
          <template #actions>
            <NcActionButton close-after-click @click="openSpace({button:0,preventDefault(){}},item)">
              <template #icon><NcIconSvgWrapper :path="mdiFolderOpenOutline" :size="20" /></template>
              {{ t('librefinance', 'Open') }}
            </NcActionButton>
            <NcActionButton close-after-click @click="startEdit(item)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
              {{ t('librefinance', 'Edit name and color') }}
            </NcActionButton>
            <NcActionButton close-after-click @click="problem=''; archiving=item">
              <template #icon><NcIconSvgWrapper :path="mdiArchiveArrowDownOutline" :size="20" /></template>
              {{ t('librefinance', 'Archive') }}
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
      <NcEmptyContent v-else :name="t('librefinance', 'No workspaces match this search')"
        :description="t('librefinance', 'Create a workspace, remove the filter or restore an archived workspace.')" />
    </section>
    <section v-if="archivedFiltered.length" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Archived workspaces') }}</h2></div>
      <ul class="lf-native-space-list" :aria-label="t('librefinance', 'Archived financial workspaces')">
        <NcListItem v-for="item in archivedFiltered" :key="item.id" :name="item.name"
          :details="item.currency" :actions-aria-label="t('librefinance', 'Actions for archived workspace {name}', { name: item.name }, { escape: false, sanitize: false })" force-menu force-display-actions>
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>
            {{ kinds.find(k => k.value===item.kind)?.label || item.kind }} · {{ t('librefinance', 'Archived workspace') }}
          </template>
          <template #actions>
            <NcActionButton :disabled="loading" close-after-click @click="restore(item)">
              <template #icon><NcIconSvgWrapper :path="mdiBackupRestore" :size="20" /></template>
              {{ t('librefinance', 'Restore') }}
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
    </section>
    <section v-if="other.length" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Shared with me') }}</h2></div>
      <ul class="lf-native-space-list" :aria-label="t('librefinance', 'Shared financial workspaces')">
        <NcListItem v-for="item in other" :key="item.id" :name="item.name"
          :href="href(item)" :actions-aria-label="t('librefinance', 'Actions for workspace {name}', { name: item.name }, { escape: false, sanitize: false })"
          :details="item.currency" force-menu force-display-actions @click="openSpace($event,item)">
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>{{ roleLabels[item.role] || item.role }} · {{ t('librefinance', 'Shared workspace') }}</template>
          <template #actions>
            <NcActionButton close-after-click @click="openSpace({button:0,preventDefault(){}},item)">
              <template #icon><NcIconSvgWrapper :path="mdiFolderOpenOutline" :size="20" /></template>
              {{ t('librefinance', 'Open') }}
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
    </section>
    <p class="lf-muted lf-area-retention-note">{{ t('librefinance', 'Permanent deletion of workspaces is unavailable in this version. Archiving is reversible and preserves records and audit history; safe deletion must also protect data and references in linked modules.') }}</p>

    <NcDialog :open="creating" :name="t('librefinance', 'Create financial workspace')"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="creating=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <NcNoteCard type="warning">
          <strong>{{ t('librefinance', 'This option creates an independent financial workspace.') }}</strong>
          {{ t('librefinance', 'Accounts, cash, obligations, balances and access rights will be separate. If you only need to register another company or branch within the same finances, cancel and use Manage → Organization details.') }}
        </NcNoteCard>
        <p>{{ t('librefinance', 'Use a separate area only for truly independent finances, such as your household, another business or a group that does not share cash.') }}</p>
        <NcButton v-if="w.manager.value && w.activeId.value" variant="tertiary"
          @click="creating=false; navigate('companies')">
          {{ t('librefinance', 'I want to register a company within the current finances') }}
        </NcButton>
        <NcTextField v-model="newName" :label="t('librefinance', 'Workspace name')" maxlength="150" required />
        <LfSelect id="lf-space-kind-new" v-model="newKind"
          :label="t('librefinance', 'Financial workspace type')" :options="kinds" />
        <div v-if="newKind!=='organization'" class="lf-field"><span class="lf-field-label">{{ t('librefinance', 'Workspace identification color') }}</span>
          <NcColorPicker v-model="newColor">
            <NcButton variant="secondary" :aria-label="t('librefinance', 'Choose financial workspace color')">
              <span class="lf-category-color-swatch" :style="{backgroundColor:newColor}" aria-hidden="true" />
              {{ t('librefinance', 'Select color') }}
            </NcButton>
          </NcColorPicker>
        </div>
        <p v-if="newKind==='organization'" class="lf-muted">
          {{ t('librefinance', 'Color, logo and visual identity belong to the company, not the workspace. Next, configure legal registration, visual identity, logo and additional fields in Organization details.') }}</p>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="creating=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading || !newName.trim()">{{ t('librefinance', 'Create independent workspace') }}</NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(editing)" :name="t('librefinance', 'Edit financial workspace')"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="editing=$event?editing:null">
      <form class="lf-dialog-form" @submit.prevent="rename">
        <NcTextField v-model="name" :label="t('librefinance', 'Workspace name')" required maxlength="150" />
        <div v-if="editing?.kind!=='organization'" class="lf-field"><span class="lf-field-label">{{ t('librefinance', 'Workspace identification color') }}</span>
          <NcColorPicker v-model="editColor">
            <NcButton variant="secondary" :aria-label="t('librefinance', 'Change financial workspace color')">
              <span class="lf-category-color-swatch" :style="{backgroundColor:editColor}" aria-hidden="true" />
              {{ t('librefinance', 'Select color') }}
            </NcButton>
          </NcColorPicker>
        </div>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="editing=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton type="submit" variant="primary"
            :disabled="loading || !name.trim()">{{ t('librefinance', 'Save name') }}</NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(archiving)" :name="t('librefinance', 'Archive financial workspace')"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="archiving=$event?archiving:null">
      <div class="lf-dialog-form">
        <p>{{ t('librefinance', 'Archive {name}?', { name: archiving?.name }, { escape: false, sanitize: false }) }}</p>
        <NcNoteCard type="warning">
          {{ t('librefinance', 'The workspace will no longer appear in lists and access will be suspended. Accounts, documents, entries and audit history will be preserved. The owner can restore it on this screen.') }}
        </NcNoteCard>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="archiving=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="error" :disabled="loading" @click="archive">{{ t('librefinance', 'Archive workspace') }}</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
