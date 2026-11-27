// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('statement preview cancels, rejects stale evidence and confirms only current pending rows', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  await makeSpace(page, 'personal')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + spaceId
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const get = async path => {
    const response = await page.request.get(api + path, { headers })
    expect(response.status()).toBe(200)
    return response.json()
  }
  const before = { accounts: await get('/accounts'), journals: await get('/journals'), transactions: await get('/transactions') }
  const account = before.accounts.accounts.find(row => row.is_bank && row.active)
  const csv = (id, amount, desc) => 'id,date,amount,description\n' + id + ',2026-11-20,' + amount + ',' + desc + '\n'
  const source = csv('preview-a', '-12.34', 'Synthetic source A')
  const other = csv('preview-b', '25.67', 'Synthetic source B')
  const importRequests = []
  page.on('request', request => {
    if (request.method() === 'POST' && request.url().endsWith('/imports')) importRequests.push(request.postDataJSON())
  })
  await goToView(page, 'Movimentações')
  const dialog = page.getByRole('dialog', { name: 'Importar extrato CSV' })
  const open = async () => {
    await page.locator('#lf-import-menu').click()
    await page.getByRole('menu', { name: 'Importar extrato' }).getByRole('menuitem', { name: 'Arquivo CSV' }).click()
    await expect(dialog).toBeVisible()
  }
  const select = (name, text) => dialog.locator('input[type=file]').setInputFiles({
    name, mimeType: 'text/csv', buffer: Buffer.from(text),
  })
  const preview = dialog.getByRole('button', { name: 'Pré-visualizar extrato', exact: true })
  const confirm = dialog.getByRole('button', { name: 'Confirmar importação', exact: true })
  const panel = dialog.getByRole('region', { name: 'Prévia da importação do extrato' })
  await open()
  await select('first.csv', source)
  await preview.click()
  await expect(panel).toContainText('Synthetic source A')
  await expect(panel).toContainText('Novo: 1;')
  await expect(confirm).toBeEnabled()
  if (process.env.LF_STATEMENT_PREVIEW_CAPTURE === '1') {
    await dialog.screenshot({ path: 'test-results/statement-preview-ready.png' })
  }
  expect(importRequests).toHaveLength(0)
  expect(await get('/transactions')).toEqual(before.transactions)
  await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  expect(importRequests).toHaveLength(0)

  // A delayed preview for A may not authorize B, even after the server
  // successfully prepared A. File replacement remains available while reading.
  await open()
  await select('first.csv', source)
  let reached
  let release
  const arrived = new Promise(resolve => { reached = resolve })
  const gate = new Promise(resolve => { release = resolve })
  await page.route('**/imports/preview', async route => {
    const response = await route.fetch()
    reached(response.status())
    await gate
    await route.fulfill({ response })
  }, { times: 1 })
  try {
    await preview.click()
    expect(await arrived).toBe(200)
    await select('second.csv', other)
    await expect(confirm).toHaveCount(0)
    await preview.click()
    await expect(panel).toContainText('Synthetic source B')
    const late = page.waitForResponse(r => r.url().endsWith('/imports/preview')
      && r.request().postDataJSON().csv === source)
    release()
    expect((await late).status()).toBe(200)
    await expect(panel).not.toContainText('Synthetic source A')
    await expect(panel).toContainText('Synthetic source B')
  } finally { release() }

  // Simulate a different authorized client importing the same ID after
  // preview. The browser must not silently accept the new replay state.
  const parallel = await page.request.post(api + '/imports', { headers, data: { bankAccountId: account.id, csv: other } })
  expect(parallel.status()).toBe(201)
  const rejected = page.waitForResponse(r => r.url().endsWith('/imports') && r.status() === 409)
  await confirm.click()
  expect((await rejected).status()).toBe(409)
  await expect(dialog.getByRole('alert')).toContainText('Revise o arquivo novamente')
  await expect(confirm).toHaveCount(0)
  await preview.click()
  await expect(panel).toContainText('já importado: 1;')
  await select('conflict.csv', csv('preview-b', '25.68', 'Synthetic source B'))
  await preview.click()
  await expect(panel).toContainText('conflitos: 1')
  await expect(confirm).toHaveCount(0)
  if (process.env.LF_STATEMENT_PREVIEW_CAPTURE === '1') {
    await dialog.screenshot({ path: 'test-results/statement-preview-conflict.png' })
  }
  await select('malformed.csv', csv('bad', 'bad', 'Synthetic invalid'))
  await preview.click()
  await expect(panel.getByRole('alert')).toContainText('Linha de dados 1')
  await expect(confirm).toHaveCount(0)
  await select('first.csv', source)
  await preview.click()
  await expect(panel).toContainText('Synthetic source A')
  await confirm.click()
  await expect(dialog).toHaveCount(0)
  expect(importRequests).toHaveLength(2)
  expect(importRequests.every(request => /^[0-9a-f]{64}$/.test(request.previewHash))).toBe(true)
  const entries = (await get('/transactions')).transactions
  expect(entries).toHaveLength(2)
  expect(entries.map(row => row.external_id)).toEqual(['preview-b', 'preview-a'])
  expect(entries.map(row => row.status)).toEqual(['pending', 'pending'])
  expect(await get('/accounts')).toEqual(before.accounts)
  expect(await get('/journals')).toEqual(before.journals)
})

test('account statement preview invalidates on a destination account change', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + spaceId
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const chart = await page.request.get(api + '/accounts', { headers })
  expect(chart.status()).toBe(200)
  const first = (await chart.json()).accounts.find(row => row.is_bank && row.active)
  const created = await page.request.post(api + '/accounts', { headers, data: {
    code: '1099', name: 'Synthetic reserve account', class: 'asset', isBank: true,
  } })
  expect(created.status()).toBe(201)
  const second = await created.json()
  await page.goto('/index.php/apps/librefinance/?spaceId=' + spaceId + '&view=banking&forceLanguage=en')
  await expect(page.getByRole('heading', { name: 'Account statement', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Import statement', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Import bank statement' })
  const selector = dialog.getByRole('combobox', { name: 'Destination account' })
  await selector.click()
  await page.getByRole('option').filter({ hasText: first.name }).click()
  await dialog.locator('input[type=file]').setInputFiles({
    name: 'account.csv', mimeType: 'text/csv',
    buffer: Buffer.from('id,date,amount,description\nscoped-preview,2026-11-20,1.23,Synthetic account evidence\n'),
  })
  const preview = dialog.getByRole('button', { name: 'Preview statement', exact: true })
  const confirm = dialog.getByRole('button', { name: 'Confirm import', exact: true })
  const panel = dialog.getByRole('region', { name: 'Statement import preview' })
  const requestedFirst = page.waitForResponse(r => r.url().endsWith('/imports/preview'))
  await preview.click()
  expect((await (await requestedFirst).json()).bankAccountId).toBe(first.id)
  await expect(confirm).toBeEnabled()
  await selector.click()
  await page.getByRole('option').filter({ hasText: 'Synthetic reserve account' }).click()
  await expect(panel).toHaveCount(0)
  await expect(confirm).toHaveCount(0)
  const requestedSecond = page.waitForResponse(r => r.url().endsWith('/imports/preview'))
  await preview.click()
  expect((await (await requestedSecond).json()).bankAccountId).toBe(second.id)
  await expect(confirm).toBeEnabled()
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
  const entries = await page.request.get(api + '/transactions', { headers })
  expect(entries.status()).toBe(200)
  expect((await entries.json()).transactions).toEqual([])
})
