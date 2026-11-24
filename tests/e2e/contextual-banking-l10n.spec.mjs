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
  await expect(dialog.getByRole('button', { name: 'Import for review' })).toBeDisabled()
  const description = 'Synthetic bank evidence ' + Date.now().toString(36)
  const date = '2026-11-20'
  await dialog.locator('input[type=file]').setInputFiles({
    name: 'synthetic.csv', mimeType: 'text/csv',
    buffer: Buffer.from('id,date,amount,description\nsynthetic-locale,' + date + ',-14.27,' + description + '\n'),
  })
  await expect(dialog.getByText('synthetic.csv', { exact: true })).toBeVisible()
  const imported = page.waitForResponse(r => r.request().method() === 'POST' && /\/imports$/.test(r.url()))
  await dialog.getByRole('button', { name: 'Import for review' }).click()
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
