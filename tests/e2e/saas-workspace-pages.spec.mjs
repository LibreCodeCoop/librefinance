// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

// UI mock exercises page continuation and tenant switching without reading
// another tenant's data. The installed PHP suite checks server authorization.
test('SaaS environment workspace pages keep 52 books scoped on a mobile screen', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const environments = [
    { id: 'env-test-A', name: 'Ambiente A', state: 'active', canManage: true },
    { id: 'env-test-B', name: 'Ambiente B', state: 'active', canManage: true },
  ]
  const rowsA = Array.from({ length: 52 }, (_, i) => ({
    id: i + 1001, name: 'Gestão A ' + String(i + 1).padStart(2, '0'),
    ownerUid: 'ownerA', kind: 'organization',
  }))
  const rowsB = [{ id: 5001, name: 'Gestão B 01', ownerUid: 'ownerB', kind: 'personal' }]
  const requests = []
  await page.route('**/apps/librefinance/api/saas/v1/environments', route =>
    route.fulfill({ status: 200, json: { environments, nextCursor: null } }))
  await page.route('**/apps/librefinance/api/saas/v1/environments/*/policies/effective', route =>
    route.fulfill({ status: 200, json: { policies: [] } }))
  await page.route('**/apps/librefinance/api/saas/v1/environments/*/workspaces**', route => {
    const url = new URL(route.request().url())
    const environmentId = url.pathname.split('/').at(-2)
    const afterId = Number(url.searchParams.get('afterId') || 0)
    requests.push({ environmentId, afterId })
    const rows = environmentId === 'env-test-A' ? rowsA : rowsB
    const eligible = rows.filter(row => row.id > afterId)
    return route.fulfill({ status: 200, json: {
      workspaces: eligible.slice(0, 50),
      nextAfterId: eligible.length > 50 ? eligible[49].id : null,
    } })
  })
  await goToView(page, 'Gestões financeiras')
  await page.setViewportSize({ width: 390, height: 844 })
  const select = page.locator('#lf-saas-selected-environment')
  await select.click()
  await page.getByRole('option')
    .filter({ has: page.getByTitle('Ambiente A', { exact: true }) }).click()
  const list = page.getByRole('list', { name: 'Gestões administrativas do ambiente' })
  await expect(list.getByRole('listitem')).toHaveCount(50)
  await expect(list).not.toContainText('Gestão B')
  await page.getByRole('button', { name: 'Carregar mais gestões' }).click()
  await expect(list.getByRole('listitem')).toHaveCount(52)
  await expect(page.getByRole('button', { name: 'Carregar mais gestões' })).toHaveCount(0)
  expect(requests.filter(request => request.environmentId === 'env-test-A'))
    .toEqual([{ environmentId: 'env-test-A', afterId: 0 },
      { environmentId: 'env-test-A', afterId: 1050 }])
  await select.click()
  await page.getByRole('option')
    .filter({ has: page.getByTitle('Ambiente B', { exact: true }) }).click()
  await expect(list.getByRole('listitem')).toHaveCount(1)
  await expect(list).toContainText('Gestão B 01')
  await expect(list).not.toContainText('Gestão A')
  expect(requests.at(-1)).toEqual({ environmentId: 'env-test-B', afterId: 0 })
})
