<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onMounted, onBeforeUnmount, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import { financeError } from '../services/api.js'

const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const archived = ref([])
const loading = ref(false)
const creating = ref(false)
const editing = ref(null)
const archiving = ref(null)
const problem = ref('')
const notice = ref('')
const name = ref('')
const newName = ref('')
const newKind = ref('personal')
const kinds = [
  { value: 'personal', label: 'Finanças pessoais' },
  { value: 'collective', label: 'Grupo ou coletivo' },
  { value: 'organization', label: 'Organização' },
]
const owned = computed(() => w.spaces.value.filter(space => space.role === 'owner'))
const other = computed(() => w.spaces.value.filter(space => space.role !== 'owner'))
let requestSeq = 0
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
onMounted(refreshArchived)
onBeforeUnmount(() => { ++requestSeq })
function startEdit(space) {
  problem.value = ''
  editing.value = space
  name.value = space.name
}
async function rename() {
  if (!editing.value || !name.value.trim() || loading.value) return
  const target = editing.value
  loading.value = true
  problem.value = ''
  try {
    await w.call('PUT', '/api/spaces/' + Number(target.id), { name: name.value.trim() })
    editing.value = null
    await w.refreshSpaces()
    notice.value = 'Nome da gestão atualizado.'
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
    notice.value = 'Gestão arquivada sem apagar lançamentos ou documentos.'
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
    notice.value = 'Gestão restaurada com seu histórico.'
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
async function create() {
  if (!newName.value.trim() || loading.value) return
  loading.value = true
  problem.value = ''
  try {
    const created = await w.call('POST', '/api/spaces', {
      name: newName.value.trim(), kind: newKind.value, currency: 'BRL',
    })
    creating.value = false
    newName.value = ''
    await w.refreshSpaces()
    w.activeId.value = Number(created.id)
    notice.value = 'Gestão financeira criada.'
    navigate('dashboard')
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="lf-view">
    <LfPageHeader title="Gestões financeiras" eyebrow="ADMINISTRAÇÃO"
      description="Administre os espaços financeiros que você possui e os que foram compartilhados com você.">
      <template #actions>
        <NcButton variant="primary" @click="creating=true">Criar gestão</NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{notice}}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>Minhas gestões</h2><p>Renomeie ou arquive sem excluir o histórico financeiro.</p></div>
      </div>
      <div v-if="owned.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Nome</th><th>Tipo</th><th>Moeda</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in owned" :key="item.id">
              <td><strong>{{item.name}}</strong></td>
              <td>{{kinds.find(k => k.value===item.kind)?.label || item.kind}}</td>
              <td>{{item.currency}}</td>
              <td class="lf-row-actions">
                <NcButton variant="tertiary" @click="w.activeId.value=Number(item.id); navigate('dashboard')">
                  Abrir
                </NcButton>
                <NcButton variant="tertiary" @click="startEdit(item)">Renomear</NcButton>
                <NcButton variant="tertiary" @click="problem=''; archiving=item">Arquivar</NcButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <NcEmptyContent v-else name="Nenhuma gestão ativa sua"
        description="Crie uma gestão pessoal, de grupo ou organização, ou restaure uma gestão arquivada." />
    </section>
    <section v-if="archived.length" class="lf-surface">
      <div class="lf-section-heading"><h2>Gestões arquivadas</h2></div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Nome</th><th>Tipo</th><th>Ação</th></tr></thead>
          <tbody>
            <tr v-for="item in archived" :key="item.id">
              <td><strong>{{item.name}}</strong></td>
              <td>{{kinds.find(k => k.value===item.kind)?.label || item.kind}}</td>
              <td><NcButton variant="secondary" :disabled="loading"
                @click="restore(item)">Restaurar</NcButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <section v-if="other.length" class="lf-surface">
      <div class="lf-section-heading"><h2>Compartilhadas comigo</h2></div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Nome</th><th>Meu acesso</th><th>Ação</th></tr></thead>
          <tbody>
            <tr v-for="item in other" :key="item.id">
              <td><strong>{{item.name}}</strong></td><td>{{item.role}}</td>
              <td><NcButton variant="tertiary" @click="w.activeId.value=Number(item.id); navigate('dashboard')">Abrir</NcButton></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <NcDialog :open="creating" name="Criar gestão financeira"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="creating=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <p>Uma gestão é um conjunto separado de contas, compromissos, registros e permissões.</p>
        <NcTextField v-model="newName" label="Nome da gestão" maxlength="150" required />
        <LfSelect id="lf-space-kind-new" v-model="newKind"
          label="Tipo de gestão" :options="kinds" />
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="creating=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading || !newName.trim()">Criar</NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(editing)" name="Renomear gestão financeira"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="editing=$event?editing:null">
      <form class="lf-dialog-form" @submit.prevent="rename">
        <NcTextField v-model="name" label="Novo nome" required maxlength="150" />
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="editing=null">Cancelar</NcButton>
          <NcButton type="submit" variant="primary"
            :disabled="loading || !name.trim()">Salvar nome</NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(archiving)" name="Arquivar gestão financeira"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="archiving=$event?archiving:null">
      <div class="lf-dialog-form">
        <p>Arquivar <strong>{{archiving?.name}}</strong>?</p>
        <NcNoteCard type="warning">
          A gestão deixará de aparecer nas listas e os acessos serão suspensos.
          Contas, documentos, lançamentos e auditoria serão preservados. O proprietário
          poderá restaurá-la nesta mesma tela.
        </NcNoteCard>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="archiving=null">Cancelar</NcButton>
          <NcButton variant="error" :disabled="loading" @click="archive">Arquivar gestão</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
