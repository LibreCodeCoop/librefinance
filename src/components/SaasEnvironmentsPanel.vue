<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { financeError } from '../services/api.js'

const environments = ref([])
const loading = ref(false)
const activating = ref(null)
const problem = ref('')
const notice = ref('')
let requestSeq = 0

const endpoint = () => generateUrl('/apps/librefinance/api/saas/v1/environments')
const stateText = state => ({
  enabled_pending_admin: 'Aguardando administrador',
  active: 'Ativo',
  suspended: 'Suspenso',
})[state] || 'Indisponível'

async function reload() {
  const seq = ++requestSeq
  loading.value = true
  problem.value = ''
  try {
    const response = await axios.get(endpoint())
    if (seq !== requestSeq) return
    // Only administrative eligibility; no access to financial records.
    environments.value = Array.isArray(response.data.environments)
      ? response.data.environments : []
  } catch (error) {
    if (seq === requestSeq) problem.value = financeError(error)
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

async function activate(environment) {
  if (activating.value !== null
    || environment.state !== 'enabled_pending_admin'
    || !environment.canManage) return
  activating.value = environment.id
  problem.value = ''
  notice.value = ''
  try {
    await axios.post(endpoint() + '/' + encodeURIComponent(environment.id) + '/provision', {})
    await reload()
    notice.value = 'Ambiente administrativo ativado. Os acessos financeiros são definidos separadamente.'
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    activating.value = null
  }
}

onMounted(reload)
onBeforeUnmount(() => { ++requestSeq })
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
  </section>
</template>

<style scoped>
.lf-saas-environments{list-style:none;display:grid;gap:12px;margin:0;padding:0}
.lf-saas-environment-item{border-bottom:1px solid var(--color-border);padding:12px 0;
  display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
.lf-saas-environment-item p{margin:4px 0 0}
</style>
