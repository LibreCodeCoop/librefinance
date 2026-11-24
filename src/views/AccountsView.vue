<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { computed, inject, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { ledgerLocale } from '../money.js'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline, mdiDeleteOutline, mdiFilterVariant, mdiBankOutline, mdiCash } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import { financeApi, financeError } from '../services/api.js'
const props = defineProps({ bankOnly: { type: Boolean, default: false } })
const w = inject('lfWorkspace')
const { activeId, accounts, naturalBalance, manager, refreshCurrent } = w
const navigate = inject('lfNavigate')
function openPaymentSettings() {
  navigate('paymentMethods')
}
const dialog = ref(false)
const deleteTarget = ref(null)
const deletePreview = ref(null)
const deleteBusy = ref(false)
const deleteError = ref('')
const deleteReasons = {
  journal_entries: t('librefinance', 'Historical journal entries exist, even if the current balance is zero'),
  category_links: t('librefinance', 'Categories are linked to this account'),
  bank_transactions: t('librefinance', 'Bank statement movements are linked to this account'),
  payment_methods: t('librefinance', 'Payment methods are linked to this account'),
  bank_transfers: t('librefinance', 'Bank transfers are linked to this account'),
  invoice_allocations: t('librefinance', 'Invoice payments or receipts are linked to this account'),
  bank_logo: t('librefinance', 'A logo is linked; remove the image before deleting the account'),
  import_snapshots: t('librefinance', 'Imported source balances and identifiers exist'),
  import_mappings: t('librefinance', 'A link to imported source records exists'),
}
async function confirmDelete(account) {
  deleteTarget.value = account
  deletePreview.value = null
  deleteError.value = ''
  deleteBusy.value = true
  try {
    deletePreview.value = await financeApi('librefinance', Number(activeId.value),
      '/accounts/' + account.id + '/deletion', 'GET')
  } catch (e) { deleteError.value = financeError(e) }
  finally { deleteBusy.value = false }
}
async function deleteAccount() {
  if (!deleteTarget.value || !deletePreview.value?.canDelete || deleteBusy.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await financeApi('librefinance', Number(activeId.value),
      '/accounts/' + deleteTarget.value.id, 'DELETE')
    await refreshCurrent()
    deleteTarget.value = null
    deletePreview.value = null
  } catch (e) {
    // A reference could have been written after preview; the server is final.
    deleteError.value = financeError(e)
    deletePreview.value = null
  } finally { deleteBusy.value = false }
}
const working = ref(false)
const problem = ref('')
const filter = ref('all')
const search = ref('')
const localSort = useLoadedSort('code')
const blankDetails = () => ({
  institutionName: '', institutionCode: '', branchNumber: '',
  accountNumber: '', accountType: '', iban: '', swiftBic: '',
})
const bankFields = [
  ['institutionName', t('librefinance', 'Financial institution'), 150],
  ['institutionCode', t('librefinance', 'Institution code'), 40],
  ['branchNumber', t('librefinance', 'Branch'), 40],
  ['accountNumber', t('librefinance', 'Account number'), 70],
  ['accountType', t('librefinance', 'Account type'), 32],
  ['iban', t('librefinance', 'IBAN (international)'), 34],
  ['swiftBic', t('librefinance', 'SWIFT/BIC'), 11],
]
const form = ref({ id: 0, code: '', name: '', class: 'asset',
  isBank: true, active: true, bankDetails: blankDetails(), logo_version: null })
const logoData = ref('')
const logoProblem = ref('')
const logoBusy = ref(false)
function bankLogoUrl(account) {
  return account?.logo_version && activeId.value
    ? generateUrl('/apps/librefinance/api/spaces/' + Number(activeId.value)
      + '/accounts/' + Number(account.id) + '/logo?v=' + Number(account.logo_version))
    : ''
}
const invalidImageFormat = () => t('librefinance', 'Invalid format.')
async function selectBankLogo(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  logoProblem.value = ''
  if (!['image/png','image/jpeg','image/webp'].includes(file.type)
      || file.size > 524288 || !file.size) {
    logoProblem.value = t('librefinance', 'Select a PNG, JPG or WebP image up to 512 KB.')
    return
  }
  try {
    const data = await new Promise((resolve,reject)=>{
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result))
      reader.onerror = () => reject(new Error(t('librefinance', 'Failed to read the image.')))
      reader.readAsDataURL(file)
    })
    const match = /^data:image\/(?:png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$/.exec(data)
    if (!match) throw new Error(invalidImageFormat())
    logoData.value = match[1]
  } catch(e) { logoProblem.value = e.message }
}
async function removeBankLogo() {
  if (!form.value.id || logoBusy.value) return
  logoBusy.value = true
  logoProblem.value = ''
  try {
    await financeApi('librefinance',Number(activeId.value),
      '/accounts/' + Number(form.value.id) + '/logo','DELETE')
    form.value.logo_version = null
    logoData.value = ''
    await refreshCurrent()
  } catch(e) { logoProblem.value = financeError(e) }
  finally { logoBusy.value = false }
}
const filters = [
  { value: 'all', label: t('librefinance', 'All classes') },
  { value: 'asset', label: t('librefinance', 'Asset') }, { value: 'liability', label: t('librefinance', 'Liability') },
  { value: 'equity', label: t('librefinance', 'Equity') }, { value: 'revenue', label: t('librefinance', 'Revenue') },
  { value: 'expense', label: t('librefinance', 'Expense') },
]
const explanations = [
  [t('librefinance', 'Asset'), t('librefinance', 'what the organization owns or is owed (e.g. cash and bank accounts)')],
  [t('librefinance', 'Liability'), t('librefinance', 'obligations to pay (e.g. loans and suppliers)')],
  [t('librefinance', 'Equity'), t('librefinance', 'own resources and accumulated results')],
  [t('librefinance', 'Revenue'), t('librefinance', 'what was earned from sales, services or other activities')],
  [t('librefinance', 'Expense'), t('librefinance', 'spending on activities and maintenance')],
]
const listed = computed(() => localSort.order(accounts.value.filter(a =>
  (!props.bankOnly || (a.is_bank && a.class === 'asset'))
  && (props.bankOnly || filter.value === 'all' || a.class === filter.value)
  && (!search.value.trim() || (a.code + ' ' + a.name).toLocaleLowerCase(ledgerLocale())
    .includes(search.value.trim().toLocaleLowerCase(ledgerLocale())))), {
      balance: account => (['liability','equity','revenue'].includes(account.class)
        ? -1 : 1) * Number(account.balance_minor),
    }))
function create() {
  problem.value = ''
  logoData.value = ''
  logoProblem.value = ''
  form.value = {
    id: 0, bankDetails: blankDetails(), logo_version: null, code: props.bankOnly ? 'BANK-' + crypto.randomUUID().replaceAll('-', '').slice(0, 20).toUpperCase() : '',
    name: '', class: 'asset', isBank: true, active: true,
  }
  dialog.value = true
}
function edit(account) {
  problem.value = ''
  logoData.value = ''
  logoProblem.value = ''
  form.value = { id: account.id, code: account.code, name: account.name,
    class: account.class, isBank: account.is_bank, active: account.active,
    logo_version: account.logo_version,
    bankDetails: Object.fromEntries(Object.keys(blankDetails()).map(key => {
      const snake = key.replace(/[A-Z]/g, letter=>'_'+letter.toLowerCase())
      return [key, account[snake] || '']
    })) }
  dialog.value = true
}
async function submit() {
  if (working.value) return
  problem.value = ''
  working.value = true
  try {
    const selected = form.value

    const usesBankDetails = props.bankOnly || (selected.class === 'asset' && selected.isBank)
    const details = usesBankDetails ? selected.bankDetails : {}
    let accountId = Number(selected.id)
    if (accountId) {
      await financeApi('librefinance', Number(activeId.value), '/accounts/' + accountId, 'PUT',
        { name: selected.name.trim(), active: selected.active, bankDetails: details })
    } else {
      const created = await financeApi('librefinance', Number(activeId.value), '/accounts', 'POST', {
        code: selected.code.trim(), name: selected.name.trim(),
        class: props.bankOnly ? 'asset' : selected.class,
        isBank: usesBankDetails, bankDetails: details,
      })
      accountId = Number(created.id)
      selected.id = accountId
    }
    if (usesBankDetails && logoData.value) {
      try {
        await financeApi('librefinance', Number(activeId.value),
          '/accounts/' + accountId + '/logo','POST',{imageBase64: logoData.value})
      } catch(e) {
        logoProblem.value = t('librefinance', 'Account saved, but the image was not uploaded: {error}', { error: financeError(e) }, { escape: false, sanitize: false })
        await refreshCurrent()
        return
      }
    }
    await refreshCurrent()
    logoData.value = ''
    dialog.value = false
  } catch (e) { problem.value = financeError(e) }
  finally { working.value = false }
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="props.bankOnly ? t('librefinance', 'Accounts and banks') : t('librefinance', 'Chart of accounts')"
      :eyebrow="props.bankOnly ? t('librefinance', 'FINANCE') : t('librefinance', 'ACCOUNTING')"
      :description="props.bankOnly
        ? t('librefinance', 'Record where your money is held: bank accounts and cash. Select an account when recording payments or importing statements.')
        : t('librefinance', 'Organize and review the accounts that support financial reports. Accounting knowledge is not required to record income and expenses.')">
      <template #actions>
        <NcButton v-if="props.bankOnly" variant="tertiary" @click="navigate('finance')">
          {{ t('librefinance', 'Financial movements') }}
        </NcButton>
        <NcButton v-else variant="secondary" @click="openPaymentSettings">
          {{ t('librefinance', 'Payment methods') }}
        </NcButton>
        <NcButton v-if="manager" variant="primary" @click="create">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{props.bankOnly ? t('librefinance', 'New bank or cash account') : t('librefinance', 'Create account')}}
        </NcButton>
      </template>
    </LfPageHeader>
    <details v-if="props.bankOnly" class="lf-finance-explanation">
      <summary>{{ t('librefinance', 'About connecting your bank') }}</summary>
      <p>{{ t('librefinance', 'For now, you can record movements and import statements manually. Automatic connections to your bank are not available yet. Do not enter passwords here.') }}</p>
    </details>
    <NcNoteCard v-else type="info">
      <strong>{{ t('librefinance', 'Financial account vs. chart of accounts:') }}</strong>
      {{ t('librefinance', 'A bank account is where money is held; the chart of accounts explains the nature of each movement. The five classes are accounting standards, not customizable categories. You can create accounts within them.') }}
    </NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>{{props.bankOnly ? t('librefinance', 'My bank and cash accounts') : t('librefinance', 'Registered accounts')}}</h2>
        <NcTextField v-if="!props.bankOnly || accounts.some(a=>a.is_bank && a.class==='asset')"
          v-model="search" :label="t('librefinance', 'Search loaded accounts')" type="search"
          :placeholder="t('librefinance', 'Code or name')" class="lf-search-field" />
      </div>
      <div v-if="!props.bankOnly && listed.length" class="lf-filter-row lf-account-filter">
        <span class="lf-muted">{{ t('librefinance', '{shown} of {total} accounts', { shown: listed.length, total: accounts.length }, { escape: false, sanitize: false }) }}</span>
        <NcPopover popup-role="dialog">
          <template #trigger>
            <NcButton id="lf-account-class-filter" variant="tertiary" :pressed="filter!=='all'">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
              {{ t('librefinance', 'Class: {class}', { class: filters.find(item => item.value === filter)?.label }, { escape: false, sanitize: false }) }}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter chart of accounts by class')">
            <NcButton v-for="choice in filters" :key="choice.value" variant="tertiary"
              :aria-pressed="filter===choice.value" @click="filter=choice.value">{{choice.label}}</NcButton>
          </div>
        </NcPopover>
      </div>
      <div v-if="listed.length" class="lf-table-scroll"><table class="lf-data-table">
        <thead><tr>
          <th v-for="col in [
            ...(!props.bankOnly ? [{key:'code',label:t('librefinance', 'Code')}] : []),
            {key:'name',label:t('librefinance', 'Account')},
            ...(!props.bankOnly ? [{key:'class',label:t('librefinance', 'Account nature')}] : []),
            {key:'active',label:t('librefinance', 'Status')},
            {key:'balance',label:t('librefinance', 'Balance')}]" :key="col.key"
            :class="{ 'lf-numeric': col.key==='balance' }" :aria-sort="localSort.ariaSort(col.key)">
            <LfSortableHeader :label="col.label" :column="col.key"
              :active-column="localSort.column.value" :direction="localSort.direction.value"
              scope="loaded" @sort="localSort.sort" />
          </th>
          <th v-if="manager">{{ t('librefinance', 'Actions') }}</th></tr></thead>
        <tbody><tr v-for="account in listed" :key="account.id">
          <td v-if="!props.bankOnly" class="lf-code">{{ account.code }}</td>
          <td>
            <div class="lf-bank-account-identity">
              <img v-if="account.is_bank && account.logo_version"
                :src="bankLogoUrl(account)" :alt="t('librefinance', 'Logo of {name}', { name: account.institution_name || account.name }, { escape: false, sanitize: false })"
                class="lf-bank-account-logo" />
              <NcIconSvgWrapper v-else-if="account.is_bank"
                :path="account.institution_name ? mdiBankOutline : mdiCash" :size="24" />
              <div><strong>{{ account.name }}</strong>
                <small v-if="account.is_bank" class="lf-muted">
                  {{ account.institution_name || t('librefinance', 'Bank or cash') }}
                  <template v-if="account.branch_number"> · {{ t('librefinance', 'Branch {branch}', { branch: account.branch_number }, { escape: false, sanitize: false }) }}</template>
                </small>
              </div>
            </div>
          </td>
          <td v-if="!props.bankOnly">{{ filters.find(f=>f.value===account.class)?.label || account.class }}</td>
          <td><span :class="account.active ? 'lf-state lf-state-good' : 'lf-state'">
            {{ account.active ? t('librefinance', 'Enabled account') : t('librefinance', 'Disabled account') }}</span></td>
          <td class="lf-numeric"><strong>{{ naturalBalance(account) }}</strong></td>
          <td v-if="manager">
            <div class="lf-row-actions">
              <NcButton variant="tertiary" :aria-label="t('librefinance', 'Edit {name}', { name: account.name }, { escape: false, sanitize: false })"
                @click="edit(account)">
                <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
              </NcButton>
              <NcButton variant="tertiary" :aria-label="t('librefinance', 'Delete {name}', { name: account.name }, { escape: false, sanitize: false })"
                @click="confirmDelete(account)">
                <template #icon><NcIconSvgWrapper :path="mdiDeleteOutline" :size="20" /></template>
              </NcButton>
            </div>
          </td>
        </tr></tbody></table>
      </div>
      <NcEmptyContent v-else :name="props.bankOnly ? t('librefinance', 'No bank or cash account registered') : t('librefinance', 'No account found')"
        :description="props.bankOnly
          ? t('librefinance', 'Add your first bank or cash account to organize payments, receipts and statements.')
          : t('librefinance', 'Change the filter or create an account in this financial workspace.')">
        <template v-if="props.bankOnly && manager" #action>
          <NcButton variant="primary" @click="create">{{ t('librefinance', 'New bank or cash account') }}</NcButton>
        </template>
      </NcEmptyContent>
      <details v-if="!props.bankOnly" class="lf-finance-explanation">
        <summary>{{ t('librefinance', 'What do Asset, Liability, Equity, Revenue and Expense mean?') }}</summary>
        <ul><li v-for="[label, description] in explanations" :key="label">
          <strong>{{ label }}:</strong> {{ description }}.</li></ul>
        <p>{{ t('librefinance', 'These classifications must remain stable to keep balances and results consistent.') }}</p>
      </details>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="props.bankOnly ? (form.id ? t('librefinance', 'Edit bank or cash account') : t('librefinance', 'New bank or cash account'))
        : (form.id ? t('librefinance', 'Edit financial account') : t('librefinance', 'Create financial account'))"
      @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="submit">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="form.name" :label="t('librefinance', 'Account name')" maxlength="150" required />
        <NcTextField v-if="!props.bankOnly" v-model="form.code"
          :label="t('librefinance', 'Accounting code')" maxlength="32"
          :disabled="Boolean(form.id)" required :placeholder="t('librefinance', 'E.g. BANK-001')" />
        <LfSelect v-if="!props.bankOnly" id="lf-account-class"
          v-model="form.class" :label="t('librefinance', 'Accounting nature')"
          :disabled="Boolean(form.id)" :options="filters.slice(1)" />
        <NcCheckboxRadioSwitch v-if="!props.bankOnly && form.class === 'asset' && !form.id"
          v-model="form.isBank" type="checkbox">
          {{ t('librefinance', 'This account represents money held in a bank or in cash') }}
        </NcCheckboxRadioSwitch>
        <fieldset v-if="props.bankOnly || (form.class==='asset' && form.isBank)" class="lf-bank-account-fields">
          <legend>{{ t('librefinance', 'Bank details (optional)') }}</legend>
          <p class="lf-muted">{{ t('librefinance', 'Enter the information provided by the institution. Do not enter passwords, tokens, credentials or card numbers. These details do not connect your bank.') }}</p>
          <NcTextField v-for="[key, label, max] in bankFields" :key="key"
            v-model="form.bankDetails[key]" :label="label" :maxlength="max" />
          <div class="lf-bank-logo-form">
            <label for="lf-bank-account-logo-file">{{ t('librefinance', 'Institution logo (optional)') }}</label>
            <img v-if="form.id && form.logo_version" class="lf-bank-account-logo"
              :src="bankLogoUrl(form)" :alt="t('librefinance', 'Current logo')" />
            <input id="lf-bank-account-logo-file" type="file" accept="image/png,image/jpeg,image/webp"
              :aria-label="t('librefinance', 'Select bank logo')" @change="selectBankLogo" />
            <small v-if="logoData" class="lf-muted">{{ t('librefinance', 'Image selected for saving.') }}</small>
            <small class="lf-muted">{{ t('librefinance', 'PNG, JPG or WebP, up to 512 KB. Private profile image.') }}</small>
            <NcButton v-if="form.id && form.logo_version" variant="tertiary"
              :disabled="logoBusy" @click.prevent="removeBankLogo">{{ t('librefinance', 'Remove logo') }}</NcButton>
            <NcNoteCard v-if="logoProblem" type="error">{{logoProblem}}</NcNoteCard>
          </div>
        </fieldset>
        <NcCheckboxRadioSwitch v-if="form.id" v-model="form.active" type="checkbox">
          {{ t('librefinance', 'Allow new journal entries in this account') }}
        </NcCheckboxRadioSwitch>
        <NcNoteCard v-if="!props.bankOnly" type="info">{{ t('librefinance', 'An existing account code does not change because it identifies its journal entries. Accounts with a balance or history have restrictions on editing and deletion. Do not enter bank passwords here.') }}</NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="dialog=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton type="submit" variant="primary" :disabled="working">{{ t('librefinance', 'Save account') }}</NcButton></div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content"
      :open="Boolean(deleteTarget)" :name="t('librefinance', 'Delete account')"
      @update:open="!$event && (deleteTarget = null)">
      <div class="lf-dialog-form">
        <h3>{{ deleteTarget?.name }} ({{ deleteTarget?.code }})</h3>
        <NcNoteCard v-if="deleteError" type="error" role="alert">
          {{ t('librefinance', '{error}. The account was not deleted. Refresh the check and try again.', { error: deleteError }, { escape: false, sanitize: false }) }}
        </NcNoteCard>
        <p v-if="deleteBusy" role="status">{{ t('librefinance', 'Checking the history and links of this account…') }}</p>
        <template v-else-if="deletePreview && !deletePreview.canDelete">
          <NcNoteCard type="warning">
            {{ t('librefinance', 'This account cannot be deleted because it has history or links. Deletion does not erase journal entries, statements or invoices.') }}
          </NcNoteCard>
          <ul>
            <li v-for="reason in deletePreview.reasons" :key="reason">
              {{ deleteReasons[reason] || t('librefinance', 'Linked records must be preserved') }}.
            </li>
          </ul>
          <p>{{ t('librefinance', 'You can disable accounts without a balance while preserving their history.') }}</p>
        </template>
        <NcNoteCard v-else-if="deletePreview?.canDelete" type="warning">
          {{ t('librefinance', 'This account has no known journal entries or links. Deleting the account is permanent and cannot be undone. No financial records will be erased.') }}
        </NcNoteCard>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="deleteTarget=null">{{ t('librefinance', 'Close') }}</NcButton>
          <NcButton v-if="deletePreview && !deletePreview.canDelete" variant="secondary"
            @click="edit(deleteTarget); deleteTarget=null">{{ t('librefinance', 'Edit or disable') }}</NcButton>
          <NcButton v-if="deletePreview?.canDelete" variant="error" :disabled="deleteBusy"
            @click="deleteAccount">{{ t('librefinance', 'Delete account permanently') }}</NcButton>
          <NcButton v-if="deleteError" variant="secondary" :disabled="deleteBusy"
            @click="confirmDelete(deleteTarget)">{{ t('librefinance', 'Check again') }}</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>

<style scoped>
.lf-bank-account-identity { display:flex;align-items:center;gap:10px }
.lf-bank-account-identity>div { display:flex;flex-direction:column;gap:2px }
.lf-bank-account-logo { width:36px;height:36px;object-fit:contain;border-radius:var(--border-radius-element) }
.lf-bank-account-fields { display:grid;gap:10px;border:1px solid var(--color-border);border-radius:var(--border-radius-large);padding:14px;min-width:0 }
.lf-bank-account-fields legend { font-weight:600;padding:0 6px }
.lf-bank-logo-form { display:flex;flex-direction:column;gap:8px }
</style>
