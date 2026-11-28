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
    for (const [field, value] of [['#lf-fx-filter-source', 'JPY'], ['#lf-fx-filter-target', 'KWD']]) {
      await viewer.locator(field).fill(value)
      await viewer.getByRole('option').filter({ hasText: value }).click()
    }
    await viewer.getByRole('button', { name: 'Find rates', exact: true }).click()
    const candidate = viewer.locator('[data-rate-id="' + body.rate.id + '"]')
    await candidate.getByRole('button', { name: 'Preview conversion', exact: true }).click()
    const preview = viewer.getByRole('dialog', { name: 'Preview conversion' })
    await preview.getByRole('textbox', { name: 'Source amount (JPY)' }).fill('1')
    const revoke = await page.request.delete('/index.php/apps/librefinance/api/spaces/' + id + '/members/lfmaintestB', {
      headers: { requesttoken: await page.evaluate(() => OC.requestToken) },
    })
    expect(revoke.status()).toBe(200)
    const denied = viewer.waitForResponse(r => r.url().includes('/exchange-rates/' + body.rate.id + '/preview'))
    await preview.getByRole('button', { name: 'Calculate preview', exact: true }).click()
    expect((await denied).status()).toBe(404)
    await expect(preview).toBeHidden()
    await expect(viewer.locator('[data-rate-id]')).toHaveCount(0)
    await expect(viewer.getByRole('alert')).toBeVisible()
    await expect(viewer.getByText('No matching exchange rates', { exact: true })).toHaveCount(0)
  } finally { await context.close() }
  await makeSpace(page)
  const sibling = new URL(page.url()).searchParams.get('spaceId')
  expect(sibling).not.toBe(id)
  await page.goto('/index.php/apps/librefinance/?spaceId=' + sibling + '&view=exchangeRates&forceLanguage=en')
  await expect(page.getByText('No exchange rates recorded', { exact: true })).toBeVisible()
  await expect(page.locator('[data-rate-id]')).toHaveCount(0)
})

test('dated currency selection previews an explicit immutable quote without posting', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  await makeSpace(page)
  const id = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + id
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const request = async (method, path, status, data) => {
    const response = await page.request.fetch(api + path, {
      method, headers, ...(data === undefined ? {} : { data }),
    })
    expect(response.status()).toBe(status)
    return response.json()
  }
  const base = { sourceCurrency: 'JPY', targetCurrency: 'KWD', rate: '0.002',
    effectiveOn: '2026-11-24', sourceType: 'manual' }
  const record = async changes => (await request('POST', '/exchange-rates', 200,
    { ...base, ...changes })).rate
  const first = await record({ sourceRef: 'synthetic-first' })
  const second = await record({ sourceRef: 'synthetic-correction', rate: '0.003' })
  const later = await record({ sourceRef: 'synthetic-future', effectiveOn: '2026-11-25' })
  const imported = await record({ sourceRef: 'synthetic-import', effectiveOn: '2026-11-23', sourceType: 'imported' })
  const wrongPair = await record({ sourceRef: 'synthetic-other-pair', targetCurrency: 'USD' })
  // Seed more than one page, including ineligible records before page boundaries.
  for (let i = 0; i < 49; i++) await record({ sourceRef: 'synthetic-page-' + i })
  const before = {
    journals: await request('GET', '/journals', 200),
    accounts: await request('GET', '/accounts', 200),
    rates: await request('GET', '/exchange-rates', 200),
  }
  const route = '/index.php/apps/librefinance/?spaceId=' + id + '&view=exchangeRates&forceLanguage=en'
  await page.goto(route)
  const filter = page.getByRole('form', { name: 'Find exchange rates' })
  await expect(filter).toBeVisible()
  const choose = async (selector, value) => {
    const input = page.locator(selector)
    await input.click()
    await input.fill(value)
    await page.getByRole('option').filter({ hasText: value }).click()
  }
  await choose('#lf-fx-filter-source', 'JPY')
  await choose('#lf-fx-filter-target', 'KWD')
  await filter.locator('#lf-fx-filter-date').fill('2026-11-24')
  await filter.locator('#lf-fx-filter-origin').click()
  await page.getByRole('option').filter({ hasText: 'Manual rate' }).click()
  const apply = filter.getByRole('button', { name: 'Find rates', exact: true })
  const row = rate => page.locator('[data-rate-id="' + rate.id + '"]')
  const listResponse = page.waitForResponse(r => {
    const url = new URL(r.url())
    return r.request().method() === 'GET' && url.pathname.endsWith('/exchange-rates')
      && url.searchParams.get('asOf') === '2026-11-24' && url.searchParams.get('sourceType') === 'manual'
  })
  await apply.click()
  expect((await listResponse).status()).toBe(200)
  await expect(row(first)).toContainText('synthetic-first')
  await expect(row(second)).toContainText('synthetic-correction')
  for (const excluded of [later, imported, wrongPair]) await expect(row(excluded)).toHaveCount(0)
  await expect(page.locator('[data-rate-id]')).toHaveCount(50)
  await page.getByRole('button', { name: 'Load more', exact: true }).click()
  await expect(page.locator('[data-rate-id]')).toHaveCount(51)
  await expect(page.getByRole('button', { name: 'Load more', exact: true })).toHaveCount(0)
  // Neither the first candidate nor the correction is implicitly selected.
  const preview = page.getByRole('dialog', { name: 'Preview conversion' })
  await expect(preview).toHaveCount(0)
  await row(second).getByRole('button', { name: 'Preview conversion', exact: true }).click()
  await expect(preview).toContainText('synthetic-correction')
  const amount = preview.getByRole('textbox', { name: 'Source amount (JPY)' })
  const calculate = preview.getByRole('button', { name: 'Calculate preview' })
  await amount.fill('1.2')
  await expect(calculate).toBeDisabled()
  await amount.fill('-1000')
  const converted = page.waitForResponse(r => {
    const url = new URL(r.url())
    return url.pathname.endsWith('/exchange-rates/' + second.id + '/preview')
      && url.searchParams.get('sourceMinor') === '-1000'
  })
  await calculate.click()
  const result = await converted
  expect(result.status()).toBe(200)
  expect(await result.json()).toMatchObject({ sourceMinor: -1000, targetMinor: -3000,
    rate: { id: second.id, sourcePrecision: 0, targetPrecision: 3 }, postingCreated: false })
  await expect(preview.getByTestId('lf-fx-preview-result')).toContainText(/KWD.*3[.,]000/)
  await amount.fill('0')
  await expect(preview.getByTestId('lf-fx-preview-result')).toHaveCount(0)
  await calculate.click()
  await expect(preview.getByTestId('lf-fx-preview-result')).toContainText(/KWD.*0[.,]000/)
  await preview.locator('form').getByRole('button', { name: 'Close', exact: true }).click()
  let delivered
  let release
  const requested = new Promise(resolve => { delivered = resolve })
  const delayed = new Promise(resolve => { release = resolve })
  await page.route('**/exchange-rates/' + second.id + '/preview?*', async route => {
    const response = await route.fetch()
    delivered(response.status())
    await delayed
    await route.fulfill({ response })
  })
  try {
    await row(second).getByRole('button', { name: 'Preview conversion', exact: true }).click()
    await amount.fill('1000')
    await calculate.click()
    expect(await requested).toBe(200)
    await preview.locator('form').getByRole('button', { name: 'Close', exact: true }).click()
    await row(first).getByRole('button', { name: 'Preview conversion', exact: true }).click()
    await amount.fill('1000')
    await calculate.click()
    await expect(preview.getByTestId('lf-fx-preview-result')).toContainText(/KWD.*2[.,]000/)
    const late = page.waitForResponse(r => r.url().includes('/exchange-rates/' + second.id + '/preview'))
    release()
    expect((await late).status()).toBe(200)
    await expect(preview).toContainText('synthetic-first')
    await expect(preview.getByTestId('lf-fx-preview-result')).toContainText(/KWD.*2[.,]000/)
    if (process.env.LF_FX_SELECTION_REVIEW_CAPTURE === '1') {
      await preview.screenshot({ path: 'test-results/exchange-rate-selection-preview.png' })
    }
  } finally {
    release()
    await page.unroute('**/exchange-rates/' + second.id + '/preview?*')
  }
  await preview.locator('form').getByRole('button', { name: 'Close', exact: true }).click()
  await filter.locator('#lf-fx-filter-date').fill('2026-11-22')
  await expect(row(first).getByRole('button', { name: 'Preview conversion', exact: true })).toBeDisabled()
  await apply.click()
  await expect(page.getByText('No matching exchange rates', { exact: true })).toBeVisible()
  await filter.getByRole('button', { name: 'Clear filters', exact: true }).click()
  await expect(row(first)).toBeVisible()
  expect(await request('GET', '/journals', 200)).toEqual(before.journals)
  expect(await request('GET', '/accounts', 200)).toEqual(before.accounts)
  expect(await request('GET', '/exchange-rates', 200)).toEqual(before.rates)
})
