// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login } from './helpers.mjs'

test('native environment selection binds finance requests during a delayed workspace load', async ({ page }) => {
  await login(page)
  const a = { id: 70001, name: 'Synthetic book A', financialEnvironment: {
    id: 'ad0d4e8b-78f9-49e5-bf58-d34b0cbb4d33', name: 'Synthetic environment A',
  } }
  const b = { id: 70002, name: 'Synthetic book B', financialEnvironment: {
    id: 'c40a79aa-4b75-446e-97eb-92052e66ceef', name: 'Synthetic environment B',
  } }
  const legacy = { id: 70003, name: 'Synthetic book without environment metadata' }
  const books = [a, b, legacy].map(book => ({
    ...book, saasManaged: true, role: 'viewer', kind: 'organization', currency: 'BRL',
  }))
  await page.route('**/apps/librefinance/api/spaces', route => route.fulfill({ json: { spaces: books } }))
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/me/dashboard.kpis.enabled',
    route => route.fulfill({ json: { effectiveValue: false } }))
  const observed = []
  const contextFor = (book, endpoint) => observed.find(entry =>
    entry.id === book.id && entry.endpoint === endpoint)?.context
  let delayedAccounts
  await page.route(/\/apps\/librefinance\/api\/spaces\/7000[123]\//, route => {
    const request = route.request()
    const [, id, endpoint] = new URL(request.url()).pathname.match(/\/spaces\/(\d+)\/([^/]+)/)
    observed.push({ id: Number(id), endpoint, context: request.headers()['x-librefinance-context'] })
    if (Number(id) === a.id && endpoint === 'accounts') {
      delayedAccounts = route
      return
    }
    const key = { 'company-fields': 'fields', 'dashboard-layout': 'widgets' }[endpoint] ?? endpoint
    return route.fulfill({ json: { [key]: [], nextAfterId: null, nextBeforeId: null } })
  })

  await page.goto('/index.php/apps/librefinance/')
  const picker = page.locator('#lf-environment-select')
  const select = async book => {
    await picker.click()
    await page.getByRole('option').filter({ hasText: book.financialEnvironment?.name ?? book.name }).click()
    await expect(page.locator('.lf-context-identity')).toContainText(book.name)
  }
  await select(a)
  await expect.poll(() => Boolean(delayedAccounts)).toBe(true)
  expect(contextFor(a, 'accounts')).toBe(a.financialEnvironment.id)

  const switchOffset = observed.length
  await select(b)
  await expect.poll(() => contextFor(b, 'accounts')).toBe(b.financialEnvironment.id)
  await expect.poll(() => contextFor(b, 'dashboard-layout')).toBe(b.financialEnvironment.id)
  const delayedResponse = page.waitForResponse(response => response.url().endsWith('/' + a.id + '/accounts'))
  await delayedAccounts.fulfill({ status: 409, json: { error: 'synthetic_stale_workspace_response' } })
  await (await delayedResponse).finished()
  await expect(page.locator('.lf-context-identity')).toContainText(b.name)
  const workspace = page.getByTestId('librefinance-workspace')
  await expect(workspace).toHaveAttribute('data-modules-loaded', 'true')
  await expect(workspace.getByRole('alert')).toHaveCount(0)
  expect(observed.slice(switchOffset).filter(entry => entry.endpoint === 'dashboard-layout'))
    .toEqual([{ id: b.id, endpoint: 'dashboard-layout', context: b.financialEnvironment.id }])

  await select(legacy)
  await expect.poll(() => contextFor(legacy, 'accounts')).toBe('legacy')
  for (const { id, context } of observed) {
    expect(context).toBe(books.find(book => book.id === id).financialEnvironment?.id ?? 'legacy')
  }
})
