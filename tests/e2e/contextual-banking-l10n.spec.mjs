// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace } from './helpers.mjs'

test('contextual bank statement localizes import and preserves exact evidence across languages', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const id = new URL(page.url()).searchParams.get('spaceId')
  const url = '/index.php/apps/librefinance/?spaceId=' + id + '&view=banking&forceLanguage='
  await page.goto(url + 'en')
  await expect(page.getByRole('heading', { name: 'Account statement', exact: true })).toBeVisible()
  await expect(page.getByText('No movements imported', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Import statement', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Import bank statement' })
  await expect(dialog.getByRole('button', { name: 'Preview statement' })).toBeDisabled()
  const description = 'Synthetic bank evidence ' + Date.now().toString(36)
  const date = '2026-11-20'
  await dialog.locator('input[type=file]').setInputFiles({
    name: 'synthetic.csv', mimeType: 'text/csv',
    buffer: Buffer.from('id,date,amount,description\nsynthetic-locale,' + date + ',-14.27,' + description + '\n'),
  })
  await expect(dialog.getByText('synthetic.csv', { exact: true })).toBeVisible()
  const imported = page.waitForResponse(r => r.request().method() === 'POST' && /\/imports$/.test(r.url()))
  await dialog.getByRole('button', { name: 'Preview statement' }).click()
  await expect(dialog.getByRole('region', { name: 'Statement import preview' })).toBeVisible()
  await dialog.getByRole('button', { name: 'Confirm import', exact: true }).click()
  const response = await imported
  expect(response.status()).toBe(201)
  expect((await response.json()).imported).toBe(1)
  await expect(dialog).toHaveCount(0)
  const row = page.getByRole('row').filter({ hasText: description })
  await expect(row).toHaveCount(1)
  await expect(row.locator('time')).toHaveAttribute('datetime', date)
  await expect(row).toContainText('Pending')
  const amount = await row.locator('.lf-numeric').innerText()
  await page.goto(url + 'pt_BR')
  await expect(page.getByRole('heading', { name: 'Extrato da conta', exact: true })).toBeVisible()
  await expect(row).toHaveCount(1)
  await expect(row.locator('time')).toHaveAttribute('datetime', date)
  await expect(row.locator('.lf-numeric')).toHaveText(amount)
  await expect(row).toContainText('Pendente')
  const stored = await page.request.get('/index.php/apps/librefinance/api/spaces/' + id + '/transactions', {
    headers: { requesttoken: await page.evaluate(() => OC.requestToken) },
  })
  expect(stored.status()).toBe(200)
  const rows = (await stored.json()).transactions.filter(item => item.description === description)
  expect(rows).toHaveLength(1)
  expect(rows[0]).toMatchObject({ amount_minor: -1427, booking_date: date, status: 'pending' })
})

test('statement period filters imported evidence without changing the ledger', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  await page.goto('/index.php/apps/librefinance/?spaceId=' + spaceId + '&view=banking&forceLanguage=en')
  await expect(page.getByRole('heading', { name: 'Account statement', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Import statement', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Import bank statement' })
  const suffix = Date.now().toString(36)
  const firstDescription = 'Synthetic earlier ' + suffix
  const secondDescription = 'Synthetic later ' + suffix
  const csv = 'id,date,amount,description\n'
    + 'earlier-' + suffix + ',2026-11-20,-14.27,' + firstDescription + '\n'
    + 'later-' + suffix + ',2026-11-21,15.50,' + secondDescription + '\n'
  await dialog.locator('input[type=file]').setInputFiles({
    name: 'period.csv', mimeType: 'text/csv', buffer: Buffer.from(csv),
  })
  const imported = page.waitForResponse(r => r.request().method() === 'POST' && /\/imports$/.test(r.url()))
  await dialog.getByRole('button', { name: 'Preview statement' }).click()
  await expect(dialog.getByRole('region', { name: 'Statement import preview' })).toBeVisible()
  await dialog.getByRole('button', { name: 'Confirm import', exact: true }).click()
  const response = await imported
  expect(response.status()).toBe(201)
  expect((await response.json()).imported).toBe(2)
  await expect(dialog).toHaveCount(0)
  const first = page.getByRole('row').filter({ hasText: firstDescription })
  const second = page.getByRole('row').filter({ hasText: secondDescription })
  await expect(first).toHaveCount(1)
  await expect(second).toHaveCount(1)
  const from = page.getByLabel('From', { exact: true })
  const through = page.getByLabel('Through', { exact: true })
  const apply = page.getByRole('button', { name: 'Apply period', exact: true })
  await from.fill('2026-11-21')
  await through.fill('2026-11-20')
  await expect(apply).toBeDisabled()
  await through.fill('2026-11-21')
  await expect(first).toHaveCount(1)
  await expect(second).toHaveCount(1)
  await apply.click()
  await expect(first).toHaveCount(0)
  await expect(second).toHaveCount(1)
  await expect(second).toContainText('Pending')
  await from.fill('2026-11-20')
  await expect(first).toHaveCount(0)
  await expect(second).toHaveCount(1)
  await page.getByRole('button', { name: 'Clear filters', exact: true }).click()
  await expect(first).toHaveCount(1)
  await expect(second).toHaveCount(1)
})
