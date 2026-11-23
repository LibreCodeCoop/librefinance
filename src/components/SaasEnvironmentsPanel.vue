<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
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
const activating = ref(null)
const problem = ref('')
const notice = ref('')
const selectedId = ref('')
const workspaces = ref([])
const workspacesLoading = ref(false)
const workspacesError = ref('')
const name = ref('')
const ownerUid = ref('')
const kind = ref('organization')
const pending = ref(null)
const creating = ref(false)
const claims = createWorkspaceClaimKeys()
const kindOptions = [
  { value: 'organization', label: 'Negócio ou organização' },
  { value: 'collective', label: 'Grupo ou coletivo' },
  { value: 'personal', label: 'Pessoal ou da casa' },
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
  enabled_pending_admin: 'Aguardando administrador',
  active: 'Ativo',
  suspended: 'Suspenso',
})[state] || 'Indisponível'
const kindText = value => kindOptions.find(item => item.value === value)?.label || value

async function loadWorkspaces() {
  const id = selectedId.value
  const seq = ++workspacesSeq
  workspaces.value = []
  workspacesError.value = ''
  if (!id) {
    workspacesLoading.value = false
    return
  }
  workspacesLoading.value = true
  try {
    const response = await axios.get(workspacesEndpoint(id))
    if (seq !== workspacesSeq || selectedId.value !== id || !mounted) return
    workspaces.value = Array.isArray(response.data.workspaces)
      ? response.data.workspaces : []
  } catch (error) {
    if (seq !== workspacesSeq || selectedId.value !== id || !mounted) return
    if ([403, 404, 423].includes(error?.response?.status)) {
      // A revoked or suspended context must not retain stale UI data.
      selectedId.value = ''
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
  workspacesError.value = ''
  pending.value = null
  notice.value = ''
  name.value = ''
  ownerUid.value = ''
  kind.value = 'organization'
  loadWorkspaces()
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
      problem.value = financeError(error)
    }
  } finally {
    if (seq === requestSeq && mounted) loading.value = false
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
    notice.value = 'Ambiente ativado. Os acessos financeiros são definidos separadamente.'
  } catch (error) {
    if (mounted) problem.value = financeError(error)
  } finally {
    activating.value = null
  }
}

function reviewNewWorkspace() {
  if (!selected.value || !name.value.trim() || !ownerUid.value.trim() || creating.value) return
  pending.value = {
    environmentId: selected.value.id,
    environmentName: selected.value.name,
    name: name.value.trim(),
    ownerUid: ownerUid.value.trim(),
    kind: kind.value,
    currency: 'BRL',
    color: '#64748B',
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
      idempotencyKey,
    })
    claims.clear(request.environmentId, request)
    if (!mounted || selectedId.value !== request.environmentId) return
    pending.value = null
    name.value = ''
    ownerUid.value = ''
    kind.value = 'organization'
    await loadWorkspaces()
    notice.value = 'Gestão financeira independente criada para o proprietário informado.'
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
  <section v-if="environments.length || loading || problem"
    class="lf-surface" aria-labelledby="lf-saas-environments-heading">
    <div class="lf-section-heading">
      <div>
        <h2 id="lf-saas-environments-heading">Ambientes LibreFinance</h2>
        <p>Grupos Nextcloud habilitados para o LibreFinance SaaS.
          Cada ambiente tem administração independente. Ser membro ou administrador
          do grupo não concede acesso aos livros financeiros.</p>
      </div>
    </div>
    <NcLoadingIcon v-if="loading" :size="20" />
    <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <ul v-if="environments.length" class="lf-saas-environments"
      aria-label="Ambientes SaaS disponíveis">
      <li v-for="environment in environments" :key="environment.id"
        class="lf-saas-environment-item">
        <div>
          <strong>{{ environment.name }}</strong>
          <p class="lf-muted">Estado: {{ stateText(environment.state) }}</p>
        </div>
        <NcButton v-if="environment.canManage && environment.state==='enabled_pending_admin'"
          variant="primary" :disabled="activating!==null"
          :aria-label="'Ativar ambiente ' + environment.name"
          @click="activate(environment)">
          Ativar ambiente
        </NcButton>
        <span v-else-if="environment.state==='suspended'" class="lf-muted">
          Reativação somente após recuperação administrativa.
        </span>
        <span v-else-if="environment.state==='enabled_pending_admin'" class="lf-muted">
          Aguarda subadministrador ou delegado habilitado.
        </span>
      </li>
    </ul>

    <div v-if="manageable.length" class="lf-saas-manager"
      aria-labelledby="lf-saas-manager-heading">
      <h3 id="lf-saas-manager-heading">Gerenciar gestões por ambiente</h3>
      <p>Escolha um único ambiente. Gestões e permissões nunca são combinadas entre grupos.</p>
      <LfSelect id="lf-saas-selected-environment" v-model="selectedId"
        label="Ambiente administrativo" :options="environmentOptions"
        placeholder="Selecione o ambiente" />
      <p v-if="!selected" class="lf-muted">
        Se você administra vários grupos, selecione explicitamente qual ambiente deseja gerenciar.
      </p>
      <div v-else class="lf-saas-workspaces">
        <h4>Políticas deste ambiente</h4>
        <p>Restrinja o ambiente sem ampliar os limites da instância
          nem adquirir acesso a livros contábeis.</p>
        <SaasPolicyEditor scope="environment" :environment-id="selected.id" />
        <h4>Gestões do ambiente {{ selected.name }}</h4>
        <NcLoadingIcon v-if="workspacesLoading" :size="20" />
        <NcNoteCard v-if="workspacesError" type="error" role="alert">{{ workspacesError }}</NcNoteCard>
        <ul v-if="workspaces.length" aria-label="Gestões administrativas do ambiente">
          <li v-for="workspace in workspaces" :key="workspace.id">
            <strong>{{ workspace.name }}</strong>
            <span class="lf-muted">{{ kindText(workspace.kind) }} · Proprietário: {{ workspace.ownerUid }}</span>
          </li>
        </ul>
        <p v-else-if="!workspacesLoading" class="lf-muted">
          Nenhuma gestão financeira independente foi criada neste ambiente.
        </p>
        <form class="lf-saas-create" @submit.prevent="reviewNewWorkspace">
          <h4>Nova gestão independente</h4>
          <NcNoteCard type="info">A gestão terá contas e livro contábil separados.
            O administrador do grupo não recebe acesso financeiro.
            O proprietário precisa ser outro participante do grupo Nextcloud.</NcNoteCard>
          <NcTextField v-model="name" label="Nome da gestão" required maxlength="150" />
          <NcTextField v-model="ownerUid" label="Usuário Nextcloud do proprietário"
            required maxlength="64" />
          <LfSelect id="lf-saas-new-kind" v-model="kind"
            label="Tipo da gestão" :options="kindOptions" />
          <NcButton variant="primary" type="submit"
            :disabled="creating || workspacesLoading || !name.trim() || !ownerUid.trim()">
            Revisar criação
          </NcButton>
        </form>
      </div>
    </div>

    <NcDialog :open="Boolean(pending)" name="Confirmar gestão financeira independente"
      size="normal" @update:open="!$event && (pending=null)">
      <div class="lf-saas-confirm">
        <NcNoteCard type="warning">
          Criar uma nova gestão separa o livro contábil e não concede a você
          nenhum papel financeiro. Confirme ambiente e proprietário.
        </NcNoteCard>
        <p><strong>Ambiente:</strong> {{ pending?.environmentName }}</p>
        <p><strong>Gestão:</strong> {{ pending?.name }}</p>
        <p><strong>Proprietário:</strong> {{ pending?.ownerUid }}</p>
        <NcNoteCard v-if="workspacesError" type="error" role="alert">
          {{ workspacesError }}
        </NcNoteCard>
        <div class="lf-saas-actions">
          <NcButton variant="tertiary" :disabled="creating" @click="pending=null">
            Cancelar
          </NcButton>
          <NcButton variant="primary" :disabled="creating" @click="createWorkspace">
            Criar gestão independente
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
