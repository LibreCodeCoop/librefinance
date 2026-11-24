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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import LfSelect from './LfSelect.vue'
import { financeError } from '../services/api.js'

const props = defineProps({
  mode: { type: String, required: true, validator: v => ['owner', 'inbox'].includes(v) },
  spaceId: { type: Number, default: 0 },
})
const emit = defineEmits(['accepted'])
const invitations = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const nextAfterId = ref(null)
const recipientUid = ref('')
const role = ref('viewer')
const days = ref('7')
const roles = [
  { value: 'viewer', label: t('librefinance', 'Financial read access') },
  { value: 'auditor', label: t('librefinance', 'Audit and export (read-only)') },
]
const endpoint = computed(() => {
  const root = '/apps/librefinance/api/saas/v1'
  return props.mode === 'owner'
    ? (props.spaceId > 0 ? generateUrl(root + '/workspaces/' + props.spaceId + '/invitations') : '')
    : generateUrl(root + '/invitations')
})
const isOwner = computed(() => props.mode === 'owner')
let seq = 0
let mounted = true

function stateName(item) {
  if (item.expiresAt <= Math.floor(Date.now() / 1000)) return t('librefinance', 'Expired')
  return { invited: t('librefinance', 'Awaiting acceptance'), accepted: t('librefinance', 'Accepted'), revoked: t('librefinance', 'Revoked') }[item.status]
    || t('librefinance', 'Unavailable')
}
function deadline(value) {
  return new Date(Number(value) * 1000).toLocaleString(getLocale()?.replaceAll('_', '-'))
}
async function load(more = false) {
  const url = endpoint.value
  const token = ++seq
  if (!more) {
    invitations.value = []
    nextAfterId.value = null
  }
  error.value = ''
  notice.value = ''
  if (!url) {
    loading.value = false
    return
  }
  loading.value = true
  try {
    const response = await axios.get(url, {
      params: more && nextAfterId.value ? { afterId: nextAfterId.value } : {},
    })
    if (!mounted || token !== seq || endpoint.value !== url) return
    const rows = Array.isArray(response.data.invitations) ? response.data.invitations : []
    invitations.value = more ? [...invitations.value, ...rows] : rows
    nextAfterId.value = Number.isSafeInteger(response.data.nextAfterId)
      ? response.data.nextAfterId : null
  } catch (cause) {
    if (!mounted || token !== seq || endpoint.value !== url) return
    error.value = financeError(cause)
  } finally {
    if (mounted && token === seq) loading.value = false
  }
}
watch(endpoint, () => { ++seq; load() }, { immediate: true })
onBeforeUnmount(() => { mounted = false; ++seq })

function expiresAt() {
  const n = Number(days.value)
  if (!Number.isSafeInteger(n) || n < 1 || n > 30) {
    throw new Error(t('librefinance', 'Enter a validity period of 1 to 30 days.'))
  }
  return Math.floor(Date.now() / 1000) + n * 86400
}
async function mutate(method, uri, data, message) {
  const current = endpoint.value
  if (!current || busy.value) return false
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    if (method === 'POST') await axios.post(uri, data)
    else await axios.delete(uri, { params: data })
    if (!mounted || endpoint.value !== current) return false
    await load()
    if (!mounted || endpoint.value !== current) return false
    notice.value = message
    return true
  } catch (cause) {
    if (!mounted || endpoint.value !== current) return false
    if (cause?.response?.status === 409) {
      await load()
      if (mounted && endpoint.value === current) {
        error.value = t('librefinance', 'The invitation was updated in another session. Review the current state.')
      }
    } else {
      error.value = financeError(cause)
    }
    return false
  } finally {
    busy.value = false
  }
}
async function create() {
  const uid = recipientUid.value.trim()
  if (!uid) {
    error.value = t('librefinance', 'Enter a Nextcloud user identifier.')
    return
  }
  let exp
  try { exp = expiresAt() } catch (cause) { error.value = cause.message; return }
  const created = await mutate('POST', endpoint.value, {
    recipientUid: uid, role: role.value, expiresAt: exp, expectedRevision: 0,
  }, t('librefinance', 'Invitation created. The recipient must accept it in their account.'))
  if (created) recipientUid.value = ''
}
async function reinvite(item) {
  let exp
  try { exp = expiresAt() } catch (cause) { error.value = cause.message; return }
  await mutate('POST', endpoint.value, {
    recipientUid: item.recipientUid, role: item.role,
    expiresAt: exp, expectedRevision: item.revision,
  }, t('librefinance', 'New invitation issued. The recipient must accept it again.'))
}
async function revoke(item) {
  await mutate('DELETE', endpoint.value + '/' + item.id, {
    expectedRevision: item.revision,
  }, t('librefinance', 'External permission has been revoked for the next request.'))
}
async function accept(item) {
  const ok = await mutate('POST', generateUrl('/apps/librefinance/api/saas/v1/workspaces/'
    + item.spaceId + '/invitations/' + item.id + '/accept'), {
    expectedRevision: item.revision,
  }, t('librefinance', 'Invitation accepted. The workspace can now appear among your workspaces.'))
  if (ok) emit('accepted')
}
</script>

<template>
  <section v-if="isOwner || loading || invitations.length || error"
    class="lf-invitations" :aria-label="isOwner ? t('librefinance', 'External financial grants') : t('librefinance', 'Received financial invitations')">
    <h3>{{ isOwner ? t('librefinance', 'Invitations for external access') : t('librefinance', 'Received financial invitations') }}</h3>
    <p class="lf-muted">{{ t('librefinance', 'Invitations are limited to the specified financial ledger, with expiry and revocation. They do not grant membership in Nextcloud groups or write access.') }}</p>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <form v-if="isOwner" class="lf-invite-form" @submit.prevent="create">
      <NcTextField v-model="recipientUid"
        :label="t('librefinance', 'Nextcloud user identifier')"
        :placeholder="t('librefinance', 'Existing account outside the environment group')"
        :disabled="busy" />
      <LfSelect v-model="role" id="lf-invite-role"
        :label="t('librefinance', 'External permission')" :options="roles" :disabled="busy" />
      <NcTextField v-model="days" :label="t('librefinance', 'Validity in days (1 to 30)')"
        inputmode="numeric" :disabled="busy" />
      <NcButton type="submit" variant="primary" :disabled="busy || !recipientUid.trim()">
        {{ t('librefinance', 'Invite external account') }}
      </NcButton>
    </form>
    <NcLoadingIcon v-if="loading" :size="20" />
    <ul v-if="invitations.length" class="lf-invite-list">
      <li v-for="item in invitations" :key="item.id" class="lf-invite-item">
        <div>
          <strong>{{ isOwner ? item.recipientUid : t('librefinance', 'Workspace {id}', { id: item.spaceId }, { escape: false, sanitize: false }) }}</strong>
          <p>{{ item.role === 'auditor' ? t('librefinance', 'Audit') : t('librefinance', 'Read access') }} · {{ stateName(item) }}</p>
          <p class="lf-muted">{{ t('librefinance', 'Valid until {date} · Revision {revision}', { date: deadline(item.expiresAt), revision: item.revision }, { escape: false, sanitize: false }) }}</p>
        </div>
        <div class="lf-invite-actions">
          <NcButton v-if="!isOwner && item.status === 'invited'"
            variant="primary" :disabled="busy"
            :aria-label="t('librefinance', 'Accept invitation for workspace {id}', { id: item.spaceId }, { escape: false, sanitize: false })"
            @click="accept(item)">{{ t('librefinance', 'Accept invitation') }}</NcButton>
          <NcButton v-if="isOwner && item.status !== 'revoked'"
            variant="secondary" :disabled="busy"
            :aria-label="t('librefinance', 'Revoke access for {name}', { name: item.recipientUid }, { escape: false, sanitize: false })"
            @click="revoke(item)">{{ t('librefinance', 'Revoke') }}</NcButton>
          <NcButton v-if="isOwner && (item.status === 'revoked'
              || item.expiresAt <= Math.floor(Date.now() / 1000))"
            variant="tertiary" :disabled="busy"
            :aria-label="t('librefinance', 'Renew invitation for {name}', { name: item.recipientUid }, { escape: false, sanitize: false })"
            @click="reinvite(item)">{{ t('librefinance', 'Invite again') }}</NcButton>
        </div>
      </li>
    </ul>
    <p v-else-if="isOwner && !loading" class="lf-muted">{{ t('librefinance', 'No external invitation registered.') }}</p>
    <NcButton v-if="nextAfterId !== null" variant="tertiary" :disabled="busy || loading"
      @click="load(true)">{{ t('librefinance', 'Load more invitations') }}</NcButton>
  </section>
</template>

<style scoped>
.lf-invitations{display:grid;gap:12px}
.lf-invitations h3{margin:0}
.lf-invite-form{display:grid;gap:12px;max-width:550px}
.lf-invite-list{list-style:none;display:grid;gap:10px;margin:0;padding:0}
.lf-invite-item{display:flex;justify-content:space-between;align-items:center;
  gap:12px;flex-wrap:wrap;border-bottom:1px solid var(--color-border);padding:10px 0}
.lf-invite-item p{margin:4px 0}
.lf-invite-actions{display:flex;gap:8px;flex-wrap:wrap}
</style>
