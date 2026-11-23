<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, onMounted, onBeforeUnmount, ref } from 'vue'
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
import { financeError } from '../services/api.js'
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
onMounted(() => { refreshArchived(); loadCreationPolicy() })
onBeforeUnmount(() => { ++requestSeq })
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
      color: newKind.value === 'organization' ? '#64748B' : newColor.value,
    })
    creating.value = false
    newName.value = ''
    newColor.value = '#64748B'
    await w.refreshSpaces()
    w.activeId.value = Number(created.id)
    // Wait until activeId watchers have reset navigation before opening onboarding.
    await nextTick()
    notice.value = 'Gestão independente criada.'
    if (newKind.value === 'organization') {
      onboarding.value = true
      navigate('companies')
    } else {
      navigate('dashboard')
    }
  } catch (error) {
    problem.value = financeError(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="lf-view">
    <LfPageHeader title="Gestões financeiras" eyebrow="GERENCIAR"
      description="Cada área financeira contém um livro contábil, caixa e acessos independentes. Cadastros de empresas dentro de uma área não criam contabilidade separada." >
      <template #actions>
        <NcButton v-if="canCreate" variant="primary" @click="creating=true">Nova gestão</NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{notice}}</NcNoteCard>
    <div class="lf-area-guidance">
      <p><strong>Área financeira</strong> significa um caixa e livro contábil próprios, com
        contas, lançamentos, documentos e acessos independentes. Não é um departamento,
        nem um cadastro de empresa ou filial.</p>
      <p>Para cadastrar ou editar empresas e filiais no financeiro atual, use
        <strong>Dados da organização</strong>, em Cadastros, sem criar outra área.
        Para apuração e saldos separados por CNPJ, use gestões independentes: várias empresas cadastradas na mesma gestão não possuem livros contábeis segregados.</p>
      <NcButton v-if="w.manager.value && w.activeId.value" variant="secondary"
        @click="navigate('companies')">Cadastrar ou editar empresas</NcButton>
    </div>
    <SaasEnvironmentsPanel />
    <div v-if="w.spaces.value.length || archived.length" class="lf-native-workspace-filter" role="search">
      <NcTextField v-model="search" label="Filtrar gestões financeiras"
        type="search" placeholder="Nome da área ou tipo" />
    </div>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>Minhas gestões</h2>
          <p>Abrir acessa o livro financeiro. Renomear ou arquivar não modifica os lançamentos.</p></div>
      </div>
      <ul v-if="owned.length" class="lf-native-space-list" aria-label="Áreas de minha propriedade">
        <NcListItem v-for="item in owned" :key="item.id"
          :name="item.name" :details="item.currency"
          :href="href(item)" :actions-aria-label="'Ações da gestão ' + item.name"
          force-menu force-display-actions @click="openSpace($event, item)">
          <template #icon><NcIconSvgWrapper :path="typeIcon(item.kind)" :size="26" /></template>
          <template #subname>
            <span v-if="item.kind!=='organization'" class="lf-category-dot" :style="{backgroundColor:item.accent_color||'#64748B'}" aria-hidden="true" />
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
              Editar nome e cor
            </NcActionButton>
            <NcActionButton close-after-click @click="problem=''; archiving=item">
              <template #icon><NcIconSvgWrapper :path="mdiArchiveArrowDownOutline" :size="20" /></template>
              Arquivar
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
      <NcEmptyContent v-else name="Nenhuma gestão nesta busca"
        description="Crie uma gestão, remova o filtro ou restaure uma gestão arquivada." />
    </section>
    <section v-if="archivedFiltered.length" class="lf-surface">
      <div class="lf-section-heading"><h2>Gestões arquivadas</h2></div>
      <ul class="lf-native-space-list" aria-label="Gestões financeiras arquivadas">
        <NcListItem v-for="item in archivedFiltered" :key="item.id" :name="item.name"
          :details="item.currency" :actions-aria-label="'Ações da gestão arquivada ' + item.name" force-menu force-display-actions>
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
      <ul class="lf-native-space-list" aria-label="Gestões financeiras compartilhadas">
        <NcListItem v-for="item in other" :key="item.id" :name="item.name"
          :href="href(item)" :actions-aria-label="'Ações da gestão ' + item.name"
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

    <NcDialog :open="creating" name="Criar gestão financeira"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="creating=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <NcNoteCard type="warning">
          <strong>Esta opção cria uma gestão financeira independente.</strong>
          Contas, caixa, títulos, saldos e acessos ficarão separados.
          Se você precisa apenas cadastrar outra empresa ou filial no mesmo financeiro,
          cancele e use Gerenciar → Dados da organização.
        </NcNoteCard>
        <p>Use uma área separada somente para um financeiro realmente independente,
          como sua casa, outro negócio ou um grupo que não compartilha o caixa.</p>
        <NcButton v-if="w.manager.value && w.activeId.value" variant="tertiary"
          @click="creating=false; navigate('companies')">
          Quero cadastrar uma empresa no financeiro atual
        </NcButton>
        <NcTextField v-model="newName" label="Nome da gestão" maxlength="150" required />
        <LfSelect id="lf-space-kind-new" v-model="newKind"
          label="Tipo de gestão" :options="kinds" />
        <div v-if="newKind!=='organization'" class="lf-field"><span class="lf-field-label">Cor de identificação da gestão</span>
          <NcColorPicker v-model="newColor">
            <NcButton variant="secondary" aria-label="Escolher cor da gestão financeira">
              <span class="lf-category-color-swatch" :style="{backgroundColor:newColor}" aria-hidden="true" />
              Selecionar cor
            </NcButton>
          </NcColorPicker>
        </div>
        <p v-if="newKind==='organization'" class="lf-muted">
          A cor, o logotipo e a identidade visual pertencem à empresa, não à gestão.
          Em seguida, configure o cadastro legal, a identidade visual, o logotipo
          e os campos adicionais em Dados da organização.</p>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="creating=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading || !newName.trim()">Criar gestão independente</NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(editing)" name="Editar gestão financeira"
      content-classes="lf-finance-dialog-content" size="normal"
      @update:open="editing=$event?editing:null">
      <form class="lf-dialog-form" @submit.prevent="rename">
        <NcTextField v-model="name" label="Nome da gestão" required maxlength="150" />
        <div v-if="editing?.kind!=='organization'" class="lf-field"><span class="lf-field-label">Cor de identificação da gestão</span>
          <NcColorPicker v-model="editColor">
            <NcButton variant="secondary" aria-label="Alterar cor da gestão financeira">
              <span class="lf-category-color-swatch" :style="{backgroundColor:editColor}" aria-hidden="true" />
              Selecionar cor
            </NcButton>
          </NcColorPicker>
        </div>
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
