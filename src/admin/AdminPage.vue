<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import LfSelect from '../components/LfSelect.vue'

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
  {value: 'delegation_reviewed', label: 'Delegação revisada'},
  {value: 'group_admin_restored', label: 'Administrador do grupo restaurado'},
  {value: 'security_incident_resolved', label: 'Incidente de segurança resolvido'},
  {value: 'membership_corrected', label: 'Participação no grupo corrigida'},
]
const saasGroupsPath = () => generateUrl('/apps/librefinance/api/saas/v1/admin/groups')
const groupStatusLabel = state => ({
  disabled: 'Não habilitado',
  enabled_pending_admin: 'Aguardando administrador',
  active: 'Ambiente ativo',
  suspended: 'Suspenso',
})[state] || 'Estado indisponível'
let groupRequestSeq = 0
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
const message = cause => cause?.response?.data?.error || cause.message || 'Erro inesperado'

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
    notice.value = 'Configuração atualizada.'
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
</script>

<template>
  <section class="lf-admin-page">
    <h2>LibreFinance — Administração da instância</h2>
    <p>Administração global é exclusiva do administrador Nextcloud.
      Administradores delegados de grupos gerenciam apenas seus contextos financeiros.</p>
    <NcLoadingIcon v-if="!loaded" :size="24" />
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <NcCheckboxRadioSwitch :model-value="managed" :disabled="busy || !loaded"
      type="switch" @update:model-value="setManaged">
      Provisionamento gerenciado pelo administrador da instância
    </NcCheckboxRadioSwitch>
    <p>Quando ativado, usuários comuns não podem criar contextos financeiros legados.
      Os vínculos e delegações dos contextos legados permanecem preservados.
      Em ambientes SaaS novos, administrar um grupo ou a instância não concede,
      por si só, acesso aos dados financeiros.</p>

    <div v-if="managed" class="lf-admin-card">
      <h3>Grupos Nextcloud disponíveis</h3>
      <p>Habilite individualmente grupos Nextcloud para o LibreFinance SaaS.
        Sem subadministrador do grupo ou delegado válido, o ambiente aguarda
        autorização para ser provisionado. Nenhuma dessas ações concede
        acesso ao livro contábil.</p>
      <form @submit.prevent="searchGroups()">
        <NcTextField v-model="groupSearch" label="Pesquisar grupos" maxlength="100" />
        <NcButton type="submit" :disabled="busy || groupsLoading">Buscar grupos</NcButton>
      </form>
      <NcLoadingIcon v-if="groupsLoading" :size="20" />
      <NcNoteCard v-if="groupSearchError" type="error" role="alert">{{ groupSearchError }}</NcNoteCard>
      <p v-if="!groupsLoading && groups.length===0">Nenhum grupo encontrado.</p>
      <ul v-if="groups.length" aria-label="Estado SaaS dos grupos Nextcloud" class="lf-saas-groups">
        <li v-for="group in groups" :key="group.id" class="lf-saas-group-row">
          <div>
            <strong>{{ group.name }}</strong>
            <span class="lf-admin-hint">({{ group.id }})</span>
            <p class="lf-admin-hint">{{ groupStatusLabel(group.state) }}</p>
          </div>
          <NcButton v-if="group.state==='disabled'" variant="primary"
            :disabled="busy || groupsLoading"
            :aria-label="'Habilitar LibreFinance para ' + group.name"
            @click="setGroupEnabled(group, true)">Habilitar</NcButton>
          <NcButton v-else-if="group.state==='active' || group.state==='enabled_pending_admin'"
            variant="tertiary" :disabled="busy || groupsLoading"
            :aria-label="'Suspender LibreFinance para ' + group.name"
            @click="suspendingGroup=group">Suspender</NcButton>
          <NcButton v-else-if="group.state==='suspended'"
            variant="secondary" :disabled="busy || groupsLoading"
            :aria-label="'Recuperar ambiente de ' + group.name"
            @click="recoveringGroup=group">Recuperar</NcButton>
          <span v-else class="lf-admin-hint">Estado indisponível</span>
        </li>
      </ul>
      <NcButton v-if="groupsNextOffset!==null" variant="tertiary"
        :disabled="busy || groupsLoading" @click="searchGroups(groupsNextOffset)">
        Carregar mais grupos
      </NcButton>
    </div>
    <div v-if="managed" class="lf-admin-card">
      <h3>Administradores delegados do LibreFinance</h3>
      <p>A delegação permite administrar o ambiente LibreFinance daquele grupo.
        Nos novos ambientes SaaS, ela não concede papel financeiro nem leitura
        dos lançamentos. A concessão só vale no LibreFinance e não muda
        permissões em outros aplicativos Nextcloud.</p>
      <label for="lf-delegate-group">Grupo governante</label>
      <select id="lf-delegate-group" v-model="delegateGroup" @change="loadDelegates">
        <option value="">Selecione um grupo</option>
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }} ({{ group.id }})
        </option>
      </select>
      <NcLoadingIcon v-if="delegatesLoading" :size="20" />
      <ul v-if="delegateGroup && delegates.length">
        <li v-for="delegate in delegates" :key="delegate.uid">
          {{ delegate.uid }}
          <NcButton variant="tertiary" :disabled="busy"
            :aria-label="'Revogar delegação de '+delegate.uid"
            @click="revokeDelegate(delegate.uid)">Revogar</NcButton>
        </li>
      </ul>
      <p v-else-if="delegateGroup && !delegatesLoading">Nenhum gestor delegado pelo LibreFinance.</p>
      <form v-if="delegateGroup" @submit.prevent="grantDelegate">
        <NcTextField v-model="delegateUid" label="Usuário Nextcloud do grupo" required />
        <NcButton type="submit" :disabled="busy || !delegateUid.trim()">Delegar administração</NcButton>
      </form>
    </div>
    <div v-if="managed" class="lf-admin-card">
      <h3>Novo contexto financeiro</h3>
      <form @submit.prevent="provision">
        <NcTextField v-model="name" label="Nome do contexto" required maxlength="150" />
        <label for="lf-governance-kind">Tipo</label>
        <select id="lf-governance-kind" v-model="kind">
          <option value="organization">Organização</option>
          <option value="collective">Grupo</option>
          <option value="personal">Pessoal</option>
        </select>
        <label for="lf-governance-group">Grupo Nextcloud</label>
        <select id="lf-governance-group" v-model="gid" required>
          <option value="" disabled>Selecione um grupo</option>
          <option v-for="group in groups" :key="group.id" :value="group.id">
            {{ group.name }} ({{ group.id }})
          </option>
        </select>
        <NcButton variant="primary" type="submit" :disabled="busy || !name.trim() || !gid.trim()">
          Criar contexto
        </NcButton>
      </form>
    </div>
    <div v-if="managed" class="lf-admin-card">
      <h3>Contextos existentes</h3>
      <ul>
        <li v-for="context in contexts" :key="context.id">
          {{ context.name }} ({{ context.kind }}) —
          {{ context.governanceGroup || 'Sem grupo governante' }}
          <span v-if="context.archived"> · Arquivado
            <NcButton variant="tertiary" :disabled="busy"
              @click="restoreContext(context)">Restaurar</NcButton>
          </span>
        </li>
      </ul>
      <form @submit.prevent="bind">
        <label for="lf-governance-existing">Vincular contexto existente</label>
        <select id="lf-governance-existing" v-model.number="bindingId" required>
          <option :value="0" disabled>Escolha um contexto sem grupo</option>
          <option v-for="context in contexts.filter(c=>!c.governanceGroup)"
            :key="context.id" :value="context.id">{{ context.name }}</option>
        </select>
        <label for="lf-governance-existing-group">Grupo Nextcloud</label>
        <select id="lf-governance-existing-group" v-model="bindingGid" required>
          <option value="" disabled>Selecione um grupo</option>
          <option v-for="group in groups" :key="group.id" :value="group.id">
            {{ group.name }} ({{ group.id }})
          </option>
        </select>
        <NcButton type="submit" :disabled="busy || !bindingId || !bindingGid.trim()">Vincular</NcButton>
      </form>
    </div>
    <NcDialog :open="Boolean(suspendingGroup)" size="normal"
      name="Suspender grupo do LibreFinance"
      @update:open="!$event && (suspendingGroup=null)">
      <div class="lf-admin-confirm">
        <NcNoteCard type="warning">Suspender impede novos acessos ao ambiente,
          mas preserva os lançamentos, os documentos e o vínculo histórico.
          A reativação exige recuperação administrativa auditada.</NcNoteCard>
        <p>Suspender <strong>{{ suspendingGroup?.name }}</strong>?</p>
        <div class="lf-admin-actions">
          <NcButton variant="tertiary" @click="suspendingGroup=null">Cancelar</NcButton>
          <NcButton variant="error" :disabled="busy"
            @click="setGroupEnabled(suspendingGroup, false)">Suspender grupo</NcButton>
        </div>
      </div>
    </NcDialog>
    <NcDialog :open="Boolean(recoveringGroup)" size="normal"
      name="Recuperar ambiente do LibreFinance"
      @update:open="!$event && (recoveringGroup=null)">
      <div class="lf-admin-confirm">
        <NcNoteCard type="warning">
          A recuperação é exclusiva do administrador da instância.
          O motivo fica registrado na auditoria. A operação mantém os dados
          financeiros intactos e retorna o ambiente a
          "Aguardando administrador", sem permitir acesso financeiro automático.
        </NcNoteCard>
        <p>Recuperar <strong>{{ recoveringGroup?.name }}</strong>?</p>
        <LfSelect id="lf-saas-recovery-reason" v-model="recoveryReason"
          label="Motivo obrigatório da recuperação"
          :options="recoveryOptions" :disabled="busy"
          placeholder="Escolha o motivo da recuperação" />
        <div class="lf-admin-actions">
          <NcButton variant="tertiary"
            @click="recoveringGroup=null; recoveryReason=''">Cancelar</NcButton>
          <NcButton variant="primary" :disabled="busy || !recoveryReason"
            @click="recoverGroup">Confirmar recuperação</NcButton>
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
