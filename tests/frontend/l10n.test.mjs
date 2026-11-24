// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')

test('generated Nextcloud browser and PHP catalogs match', () => {
  const catalog = JSON.parse(read('../../l10n/pt_BR.json'))
  let registered
  runInNewContext(read('../../l10n/pt_BR.js'), {
    OC: { L10N: { register: (app, translations, pluralForm) => {
      assert.equal(app, 'librefinance')
      registered = JSON.parse(JSON.stringify({ translations, pluralForm }))
    } } },
  }, { timeout: 1000 })
  assert.deepEqual(registered, catalog)
  assert.ok(Object.keys(catalog.translations).length >= 80)
  for (const path of ['../../src/navigation.js', '../../src/App.vue', '../../src/money.js', '../../src/admin/AdminPage.vue', '../../src/components/SaasPolicyEditor.vue', '../../src/components/SaasEnvironmentsPanel.vue', '../../src/components/SaasInvitationsPanel.vue', '../../src/views/AccountsView.vue', '../../src/views/CategoriesView.vue', '../../src/views/ContactsView.vue', '../../src/views/ReportsView.vue', '../../src/views/TransactionsView.vue', '../../src/views/DashboardView.vue', '../../src/views/CashflowView.vue', '../../src/views/MovementsView.vue', '../../src/views/DocumentsView.vue', '../../src/views/InvoicesView.vue', '../../src/views/SettingsView.vue', '../../src/views/CompaniesView.vue', '../../src/views/SpacesView.vue', '../../src/components/FieldStudioManager.vue', '../../src/components/PaymentMethodsManager.vue', '../../src/views/ImportView.vue', '../../src/views/NfseView.vue', '../../src/components/CashPaymentDialog.vue', '../../src/views/AiView.vue', '../../src/components/LfMarkdownEditor.vue', '../../src/state/useFinanceWorkspace.js', '../../src/components/CustomFieldsPanel.vue', '../../src/components/FieldStudioShortcut.vue', '../../src/components/LfSortableHeader.vue', '../../src/components/LfSelect.vue', '../../src/services/saasPolicyDraft.js', '../../src/services/api.js']) {
    const calls = [...read(path).matchAll(/\bt\('librefinance', '([^']+)'(?=[,)])/g)]
    assert.ok(calls.length > 0, path)
    for (const [, key] of calls) {
      assert.ok(catalog.translations[key], 'Missing Portuguese translation: ' + key)
    }
  }
})
