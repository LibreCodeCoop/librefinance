// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

test('Nextcloud admin reads isolated SaaS lifecycle audit with cursor pagination', async ({ page }) => {
  const adminPassword = process.env.LF_TEST_ADMIN_PASSWORD
  if (!adminPassword) throw new Error('Missing synthetic lab administrator password')
  await page.goto('/index.php/login')
  await page.locator('#user').fill('synthetictestadmin')
  await page.locator('#password').fill(adminPassword)
  await page.locator('button[type=submit]').first().click()
  await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 25_000 })

  const group = {
    id: 'lf_synth_ui_audit', name: 'Grupo Auditoria', state: 'active',
    environmentId: '00000000-0000-4000-8000-000000000001',
  }
  const first = {
    id: 72, actorUid: 'synthetictestadmin', action: 'environment.recovered',
    subjectType: 'recovery_reason', subjectId: 'membership_corrected',
    occurredAt: 1795460000,
  }
  const second = {
    id: 69, actorUid: 'system', action: 'environment.group_deleted',
    subjectType: 'nextcloud_group', subjectId: group.id,
    occurredAt: 1795450000,
  }
  let auditRequests = 0
  await page.route('**/apps/librefinance/api/admin/governance', route => {
    if (route.request().method() !== 'GET') return route.continue()
    return route.fulfill({ status: 200, json: { managed: true } })
  })
  await page.route('**/apps/librefinance/api/admin/contexts', route => {
    if (route.request().method() !== 'GET') return route.continue()
    return route.fulfill({ status: 200, json: { contexts: [] } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/admin/groups?**', route => {
    return route.fulfill({ status: 200, json: { groups: [group], nextOffset: null } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/admin/groups/*/audit**', route => {
    const url = new URL(route.request().url())
    const cursor = url.searchParams.get('beforeId')
    auditRequests += 1
    if (cursor === null) return route.fulfill({ status: 200, json: {
      environmentId: group.environmentId, events: [first], nextBeforeId: 70,
    } })
    expect(cursor).toBe('70')
    return route.fulfill({ status: 200, json: {
      environmentId: group.environmentId, events: [second], nextBeforeId: null,
    } })
  })

  await page.goto('/index.php/settings/admin/librefinance')
  await expect(page.getByRole('heading', {
    name: 'LibreFinance — Administração da instância',
  })).toBeVisible()
  await page.getByRole('button', { name: 'Ver histórico de auditoria de Grupo Auditoria' }).click()
  const dialog = page.getByRole('dialog', { name: 'Histórico de auditoria do LibreFinance' })
  await expect(dialog).toBeVisible()
  const events = dialog.getByRole('list', { name: 'Eventos de auditoria administrativa' })
  await expect(events).toContainText('environment.recovered')
  await expect(events).toContainText('membership_corrected')
  await expect(dialog).not.toContainText('saldo contábil: 1234')
  await dialog.getByRole('button', { name: 'Carregar eventos anteriores' }).click()
  await expect(events).toContainText('environment.group_deleted')
  await expect(events.getByRole('listitem')).toHaveCount(2)
  expect(auditRequests).toBe(2)
  // Select the labeled footer action, not the equally named native close icon.
  await dialog.getByRole('button', { name: 'Fechar', exact: true }).filter({ hasText: 'Fechar' }).click()
  await expect(dialog).toHaveCount(0)
})
