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
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFolderAccountOutline, mdiHomeOutline, mdiDomain,
  mdiAccountGroupOutline, mdiPencilOutline, mdiArchiveArrowDownOutline,
  mdiBackupRestore, mdiFolderOpenOutline } from '@mdi/js'
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
  { value: 'personal', label: 'Pessoal ou da casa' },
  { value: 'collective', label: 'Grupo ou coletivo' },
  { value: 'organization', label: 'Negócio ou organização' },
]
const search = ref('')
const needle = computed(() => search.value.toLocaleLowerCase('pt-BR').trim())
const matches = item => !needle.value
  || item.name.toLocaleLowerCase('pt-BR').includes(needle.value)
  || (kinds.find(k => k.value === item.kind)?.label || '').toLocaleLowerCase('pt-BR').includes(needle.value)
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
    notice.value = 'Área arquivada sem apagar lançamentos ou documentos.'
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
    notice.value = 'Área restaurada com seu histórico.'
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
    notice.value = 'Gestão financeira independente criada.'
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
    <LfPageHeader title="Áreas financeiras" eyebrow="ORGANIZAÇÃO DOS DADOS"
      description="Cada área financeira tem caixa, contas, saldos e permissões independentes. Cadastre empresas e filiais na mesma área quando compartilharem o mesmo financeiro." >
      <template #actions>
        <NcButton variant="primary" @click="creating=true">Criar área</NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{notice}}</NcNoteCard>
    <NcNoteCard type="info">Uma área não é um departamento nem uma empresa:
      é uma gestão financeira completa e isolada. Para cadastrar outra empresa ou filial
      que compartilha o mesmo caixa, use <strong>Gerenciar → Dados fiscais da empresa</strong>.
      Só crie uma segunda área se precisar separar também saldos, contas, histórico e acessos.</NcNoteCard>
    <div class="lf-native-workspace-filter" role="search">
      <NcTextField v-model="search" label="Filtrar áreas financeiras"
        type="search" placeholder="Nome da área ou tipo" />
    </div>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>Minhas áreas</h2>
          <p>Abrir acessa o livro financeiro. Renomear ou arquivar não modifica os lançamentos.</p></div>
      </div>
      <ul v-if="owned.length" class="lf-native-space-list" aria-label="Áreas de minha propriedade">
        <NcListItem v-for="item in owned" :key="item.id"
          :name="item.name" :details="item.currency"
          :href="href(item)" :actions-aria-label="'Ações da área ' + item.name"
          force-menu force-display-actions @click="openSpace($event, item)">
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>
            {{ kinds.find(k => k.value===item.kind)?.label || item.kind }}
            · Proprietário
          </template>
          <template #actions>
            <NcActionButton close-after-click @click="openSpace({button:0,preventDefault(){}},item)">
              <template #icon><NcIconSvgWrapper :path="mdiFolderOpenOutline" :size="20" /></template>
              Abrir
            </NcActionButton>
            <NcActionButton close-after-click @click="startEdit(item)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
              Renomear
            </NcActionButton>
            <NcActionButton close-after-click @click="problem=''; archiving=item">
              <template #icon><NcIconSvgWrapper :path="mdiArchiveArrowDownOutline" :size="20" /></template>
              Arquivar
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
      <NcEmptyContent v-else name="Nenhuma área nesta busca"
        description="Crie uma área, remova o filtro ou restaure uma área arquivada." />
    </section>
    <section v-if="archivedFiltered.length" class="lf-surface">
      <div class="lf-section-heading"><h2>Áreas arquivadas</h2></div>
      <ul class="lf-native-space-list" aria-label="Áreas financeiras arquivadas">
        <NcListItem v-for="item in archivedFiltered" :key="item.id" :name="item.name"
          :details="item.currency" :actions-aria-label="'Ações da área arquivada ' + item.name" force-menu force-display-actions>
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>
            {{ kinds.find(k => k.value===item.kind)?.label || item.kind }} · Arquivada
          </template>
          <template #actions>
            <NcActionButton :disabled="loading" close-after-click @click="restore(item)">
              <template #icon><NcIconSvgWrapper :path="mdiBackupRestore" :size="20" /></template>
              Restaurar
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
    </section>
    <section v-if="other.length" class="lf-surface">
      <div class="lf-section-heading"><h2>Compartilhadas comigo</h2></div>
      <ul class="lf-native-space-list" aria-label="Áreas financeiras compartilhadas">
        <NcListItem v-for="item in other" :key="item.id" :name="item.name"
          :href="href(item)" :actions-aria-label="'Ações da área ' + item.name"
          :details="item.currency" force-menu force-display-actions @click="openSpace($event,item)">
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>{{ item.role }} · Compartilhada</template>
          <template #actions>
            <NcActionButton close-after-click @click="openSpace({button:0,preventDefault(){}},item)">
              <template #icon><NcIconSvgWrapper :path="mdiFolderOpenOutline" :size="20" /></template>
              Abrir
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
    </section>
    <p class="lf-muted lf-area-retention-note">Não há exclusão permanente de áreas
      nesta versão. O arquivamento é reversível e preserva os registros e a
      auditoria; uma exclusão segura requer proteger também dados e referências
      de módulos vinculados.</p>

    <NcDialog :open="creating" name="Criar área financeira"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="creating=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <NcNoteCard type="warning">
          <strong>Esta opção cria uma gestão financeira independente.</strong>
          Contas, caixa, títulos, saldos e acessos ficarão separados.
          Se você quer apenas cadastrar outra empresa ou filial com o mesmo financeiro,
          cancele e use Gerenciar → Dados fiscais da empresa.
        </NcNoteCard>
        <p>Use uma área separada somente para um financeiro realmente independente,
          como sua casa, outro negócio ou um grupo que não compartilha o caixa.</p>
        <NcTextField v-model="newName" label="Nome da área" maxlength="150" required />
        <LfSelect id="lf-space-kind-new" v-model="newKind"
          label="Tipo de gestão" :options="kinds" />
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="creating=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading || !newName.trim()">Criar gestão independente</NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(editing)" name="Renomear área financeira"
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

    <NcDialog :open="Boolean(archiving)" name="Arquivar área financeira"
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
          <NcButton variant="error" :disabled="loading" @click="archive">Arquivar área</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
