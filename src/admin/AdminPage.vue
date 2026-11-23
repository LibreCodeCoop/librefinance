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

const managed = ref(false)
const contexts = ref([])
const name = ref('')
const gid = ref('')
const groupSearch = ref('')
const groups = ref([])
const groupSearchError = ref('')
const kind = ref('organization')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const loaded = ref(false)
const bindingId = ref(0)
const bindingGid = ref('')
const path = route => generateUrl('/apps/librefinance/api/admin/' + route)
const message = cause => cause?.response?.data?.error || cause.message || 'Erro inesperado'

async function searchGroups() {
  groupSearchError.value = ''
  try {
    const response=await axios.get(path('groups'),{
      params:{search:groupSearch.value.trim().slice(0,100),offset:0},
    })
    groups.value=response.data.groups||[]
  } catch (cause) {groupSearchError.value=message(cause) }
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
    <p>Quando ativado, usuários comuns não podem criar contextos financeiros.
      O administrador atribui cada contexto a um grupo Nextcloud, e os
      subadministradores desse grupo recebem permissão gerencial nesse contexto.
      Contextos existentes permanecem preservados e devem ser vinculados separadamente.</p>

    <div v-if="managed" class="lf-admin-card">
      <h3>Grupos Nextcloud disponíveis</h3>
      <p>Selecione um grupo existente. A administração delegada desse grupo
        é definida nas configurações de usuários do Nextcloud.</p>
      <form @submit.prevent="searchGroups">
        <NcTextField v-model="groupSearch" label="Pesquisar grupos" maxlength="100" />
        <NcButton type="submit" :disabled="busy">Buscar grupos</NcButton>
      </form>
      <NcNoteCard v-if="groupSearchError" type="error">{{ groupSearchError }}</NcNoteCard>
      <p v-if="groups.length===25" class="lf-admin-hint">Mostrando os primeiros 25 resultados.
        Refine a pesquisa para localizar outros grupos.</p>
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
  </section>
</template>

<style scoped>
.lf-admin-page{max-width:860px;padding:16px 0;display:grid;gap:14px}
.lf-admin-card{border:1px solid var(--color-border);border-radius:var(--border-radius-large);padding:16px}
.lf-admin-card form{display:flex;flex-direction:column;gap:12px;max-width:480px}
.lf-admin-card ul{padding-inline-start:22px;margin:12px 0}
.lf-admin-card select{min-height:40px;border-radius:var(--border-radius)}
</style>
