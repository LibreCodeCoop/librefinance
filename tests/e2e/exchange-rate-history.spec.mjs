// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, labIdentity } from './helpers.mjs'

test('native exchange-rate history preserves exact evidence and isolates financial workspaces', async ({ page, browser }) => {
  await login(page)
  await makeSpace(page)
  const id = new URL(page.url()).searchParams.get('spaceId')
  const route = '/index.php/apps/librefinance/?spaceId=' + id + '&view=exchangeRates'
  await page.goto(route + '&forceLanguage=en')
  await expect(page.getByRole('heading', { name: 'Exchange rates', exact: true })).toBeVisible()
  await expect(page.getByText('No exchange rates recorded', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Record exchange rate', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Record exchange rate' })
  const save = dialog.getByRole('button', { name: 'Save', exact: true })
  await expect(save).toBeDisabled()
  for (const [field, value] of [['#lf-fx-source', 'JPY'], ['#lf-fx-target', 'KWD']]) {
    await dialog.locator(field).fill(value)
    await page.getByRole('option').filter({ hasText: value }).click()
  }
  await dialog.getByRole('textbox', { name: 'Exchange rate', exact: true }).fill('0,002000')
  await dialog.getByRole('textbox', { name: 'Source reference', exact: true }).fill('synthetic-e2e-fx')
  await expect(save).toBeEnabled()
  const response = page.waitForResponse(r => r.url().includes('/exchange-rates') && r.request().method() === 'POST')
  await save.click()
  const created = await response
  expect(created.status()).toBe(200)
  expect(created.request().postDataJSON().rate).toBe('0.002000')
  const body = await created.json()
  expect(body.replayed).toBe(false)
  expect(body.rate.rate).toBe('0.002')
  const row = page.locator('[data-rate-id="' + body.rate.id + '"]')
  await expect(row).toContainText('0.002')
  await page.goto(route + '&forceLanguage=pt_BR')
  await expect(page.getByRole('heading', { name: 'Taxas de câmbio', exact: true })).toBeVisible()
  await expect(row).toContainText('synthetic-e2e-fx')
  await expect(row).toContainText('0.002')

  const member = await page.request.post('/index.php/apps/librefinance/api/spaces/' + id + '/members', {
    data: { uid: 'lfmaintestB', role: 'viewer' },
    headers: { requesttoken: await page.evaluate(() => OC.requestToken) },
  })
  expect(member.status()).toBe(200)
  const context = await browser.newContext({ baseURL: new URL(page.url()).origin })
  try {
    const viewer = await context.newPage()
    await viewer.goto('/index.php/login')
    await viewer.locator('#user').fill('lfmaintestB')
    await viewer.locator('#password').fill(labIdentity().password)
    await viewer.locator('button[type=submit]').first().click()
    await viewer.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 25000 })
    await viewer.goto(route + '&forceLanguage=en')
    await expect(viewer.getByRole('heading', { name: 'Exchange rates', exact: true })).toBeVisible()
    await expect(viewer.locator('[data-rate-id="' + body.rate.id + '"]')).toContainText('0.002')
    await expect(viewer.getByRole('button', { name: 'Record exchange rate', exact: true })).toHaveCount(0)
  } finally { await context.close() }
  await makeSpace(page)
  const sibling = new URL(page.url()).searchParams.get('spaceId')
  expect(sibling).not.toBe(id)
  await page.goto('/index.php/apps/librefinance/?spaceId=' + sibling + '&view=exchangeRates&forceLanguage=en')
  await expect(page.getByText('No exchange rates recorded', { exact: true })).toBeVisible()
  await expect(page.locator('[data-rate-id]')).toHaveCount(0)
})
