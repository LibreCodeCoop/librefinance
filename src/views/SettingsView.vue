<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref } from 'vue'
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
const {activeId, manager, members, groupGrants, modules,
  shareUid, shareRole, groupGid, groupRole, shareUser, shareGroup,
  toggleModule, refreshCurrent, busy}=w
const props = defineProps({
  sectionKey: { type: String, default: 'access' },
})
const sections = {
  access: {
    panel: 'access', title: 'Acessos e compartilhamento',
    description: 'Defina quem pode consultar e operar esta gestão financeira.',
  },
  fields: {
    panel: 'fields', title: 'Campos personalizados',
    description: 'Personalize os cadastros conforme os dados usados pela organização.',
  },
  paymentMethods: {
    panel: 'payments', title: 'Formas de pagamento',
    description: 'Gerencie as formas de pagamento disponíveis para os lançamentos.',
  },
  modules: {
    panel: 'modules', title: 'Recursos instalados',
    description: 'Ative ou desative os recursos disponíveis nesta gestão financeira.',
  },
}
const current = computed(() => sections[props.sectionKey] || sections.access)
const section = computed(() => current.value.panel)
const shareDialog=ref('')
const removeTarget=ref(null)
const problem=ref('')
const removing=ref(false)
const selectedRole=computed({
  get:()=>shareDialog.value==='member'?shareRole.value:groupRole.value,
  set:(value)=>{if(shareDialog.value==='member')shareRole.value=value;else groupRole.value=value},
})
const roles=[
  {value:'viewer',label:'Visualizador'},{value:'auditor',label:'Auditor'},
  {value:'operator',label:'Operador'},{value:'manager',label:'Gestor'},
]
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
    const t=removeTarget.value
    await financeApi('librefinance',activeId.value,
      '/' + (t.type==='member'?'members/':'groups/') + encodeURIComponent(t.id),'DELETE')
    removeTarget.value=null
    await refreshCurrent()
  } catch(e) {problem.value=financeError(e)}
  finally {removing.value=false}
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="current.title" eyebrow="GERENCIAR"
      :description="current.description" />
    <section v-if="section==='access'" class="lf-surface">
      <div class="lf-section-heading"><div><h2>Acessos ao espaço</h2>
        <p>As permissões são verificadas pelo servidor em cada operação.
          Contas e grupos do Nextcloud não são cadastros financeiros personalizáveis:
          os campos extras pertencem a empresas, contatos, itens e lançamentos.</p></div>
        <div class="lf-header-actions">
          <NcButton variant="secondary" @click="shareDialog='member'">
            <template #icon><NcIconSvgWrapper :path="mdiAccountPlusOutline" :size="20" /></template>
            Usuário
          </NcButton>
          <NcButton variant="secondary" @click="shareDialog='group'">Grupo</NcButton>
        </div>
      </div>
      <div v-if="members.length || groupGrants.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Tipo</th><th>Identificador</th><th>Permissão</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="member in members" :key="'user-'+member.uid">
              <td>Usuário</td><td><strong>{{ member.uid }}</strong></td><td>{{member.role}}</td>
              <td><NcButton v-if="member.role!=='owner'" variant="tertiary"
                :aria-label="'Revogar acesso de '+member.uid"
                @click="removeTarget={type:'member',id:member.uid}">
                <template #icon><NcIconSvgWrapper :path="mdiAccountRemoveOutline" :size="20" /></template>
              </NcButton></td>
            </tr>
            <tr v-for="group in groupGrants" :key="'group-'+group.group_gid">
              <td>Grupo</td><td><strong>{{group.group_gid}}</strong></td><td>{{group.role}}</td>
              <td><NcButton variant="tertiary"
                :aria-label="'Revogar acesso do grupo '+group.group_gid"
                @click="removeTarget={type:'group',id:group.group_gid}">
                <template #icon><NcIconSvgWrapper :path="mdiAccountRemoveOutline" :size="20" /></template>
              </NcButton></td>
            </tr>
          </tbody>
        </table>
      </div>
      <NcEmptyContent v-else name="Sem outros acessos"
        description="Convide pessoas ou grupos quando precisar colaborar." />
    </section>
    <section v-if="section==='fields'" class="lf-surface">
      <FieldStudioManager />
    </section>
    <section v-if="section==='payments'" class="lf-surface">
      <PaymentMethodsManager />
    </section>
    <section v-if="section==='modules'" class="lf-surface">
      <div class="lf-section-heading"><h2>Recursos disponíveis</h2>
        <p>Capacidades do LibreFinance instaladas pelo administrador Nextcloud.</p></div>
      <NcNoteCard type="info">
        Ativar um recurso neste espaço não instala código novo nem concede permissão adicional.
        Aplicativos regionais, como NFS-e, permanecem opcionais.
      </NcNoteCard>
      <div v-for="module in modules" :key="module.id" class="lf-settings-module" :data-module-id="module.id">
        <div class="lf-module-descriptor">
          <NcIconSvgWrapper :path="mdiPuzzleOutline" :size="22" />
          <div><strong>{{ module.name }}</strong><small>{{module.description}}</small>
            <small>{{module.enabled?'Disponível neste espaço':'Desativado neste espaço'}}</small></div>
        </div>
        <NcButton :disabled="busy" :variant="module.enabled?'tertiary':'primary'"
          @click="toggleModule(module)">{{ module.enabled ? 'Desativar' : 'Ativar' }}</NcButton>
      </div>
      <NcEmptyContent v-if="!modules.length" name="Nenhum recurso adicional disponível" />
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(shareDialog)"
      :name="shareDialog==='member'?'Conceder acesso a usuário':'Conceder acesso a grupo'"
      @update:open="shareDialog=$event?shareDialog:''">
      <form class="lf-dialog-form" @submit.prevent="share">
        <NcTextField v-if="shareDialog==='member'" v-model="shareUid"
          label="Usuário Nextcloud" required />
        <NcTextField v-else v-model="groupGid" label="Grupo Nextcloud" required />
        <LfSelect id="lf-sharing-role" v-model="selectedRole"
          :options="roles" label="Papel no espaço" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="shareDialog=''">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">Conceder acesso</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog content-classes="lf-finance-dialog-content" :open="Boolean(removeTarget)" name="Revogar acesso"
      @update:open="removeTarget = $event ? removeTarget : null">
      <div class="lf-dialog-form">
        <p>Revogar o acesso de <strong>{{removeTarget?.id}}</strong> a este espaço financeiro?</p>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="removeTarget=null">Cancelar</NcButton>
          <NcButton variant="error" :disabled="removing" @click="confirmRemoval">
            Revogar acesso
          </NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>
