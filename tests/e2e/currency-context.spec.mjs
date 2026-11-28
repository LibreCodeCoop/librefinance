// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView, selectFinanceSpace } from './helpers.mjs'

test('authorized currency metadata changes presentation and invalid input never posts', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  const firstName = await makeSpace(page)
  const first = new URL(page.url()).searchParams.get('spaceId')
  const secondName = await makeSpace(page)
  const second = new URL(page.url()).searchParams.get('spaceId')
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const api = id => '/index.php/apps/librefinance/api/spaces/' + id
  for (const id of [first, second]) {
    const response = await page.request.get(api(id) + '/accounts', { headers })
    expect(response.status()).toBe(200)
    const chart = (await response.json()).accounts
    const posting = await page.request.post(api(id) + '/journals', { headers, data: {
      debitId: chart.find(row => row.code === '1000').id,
      creditId: chart.find(row => row.code === '4000').id,
      amountMinor: 1234, date: new Date().toISOString().slice(0, 10),
      memo: 'Synthetic currency context', externalKey: 'currency-fixture-' + id,
    } })
    expect(posting.status()).toBe(201)
  }
  // Fixture-only metadata changes on the authorized read contract. No foreign
  // book is created, no mutation is forwarded with a foreign currency.
  const fixtures = new Map([
    [first, { currency: 'JPY', currencyPrecision: 0, currencyMetadataVersion: 1 }],
    [second, { currency: 'KWD', currencyPrecision: 3, currencyMetadataVersion: 1 }],
  ])
  const routeFor = (id, view) => '/index.php/apps/librefinance/?spaceId=' + id + '&view=' + view
  const spaceRoute = '**/apps/librefinance/api/spaces'
  const accountRoute = '**/apps/librefinance/api/spaces/*/accounts'
  await page.route(spaceRoute, async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const result = await response.json()
    await route.fulfill({ response, json: { ...result,
      spaces: result.spaces.map(row => ({ ...row, ...fixtures.get(String(row.id)) })),
    } })
  })
  await page.route(accountRoute, async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const id = new URL(route.request().url()).pathname.match(/\/spaces\/(\d+)\/accounts$/)?.[1]
    const response = await route.fetch()
    const result = await response.json()
    await route.fulfill({ response, json: { ...result,
      accounts: result.accounts.map(row => ({ ...row, ...fixtures.get(id) })),
    } })
  })
  const writes = []
  // Prevent an accidental foreign-currency submission from reaching the lab.
  await page.route('**/apps/librefinance/api/spaces/*/journals', async route => {
    if (route.request().method() === 'GET') return route.continue()
    writes.push(route.request().postDataJSON())
    await route.fulfill({ status: 422, json: { error: 'synthetic_currency_fixture' } })
  })
  await page.route('**/apps/librefinance/api/spaces/*/obligations', async route => {
    if (route.request().method() === 'GET') return route.continue()
    writes.push(route.request().postDataJSON())
    await route.fulfill({ status: 422, json: { error: 'synthetic_currency_fixture' } })
  })
  const cash = page.getByRole('row').filter({ has: page.getByText('1000', { exact: true }) })
    .locator('.lf-numeric')
  await page.goto(routeFor(first, 'accounts'))
  await expect(page.locator('.lf-context-identity')).toContainText(firstName)
  await expect(cash).toContainText(/(?:¥|JPY)\s*1[.,]234/)
  if (process.env.LF_CURRENCY_CONTEXT_REVIEW_CAPTURE === '1') await page.screenshot({ path: 'test-results/currency-context-jpy.png' })
  await goToView(page, 'Movimentações')
  await page.getByRole('button', { name: 'Registrar', exact: true }).click()
  const form = page.getByRole('dialog', { name: 'Registrar movimentação' })
  await form.getByRole('button', { name: /Vai acontecer/ }).click()
  await form.getByRole('textbox', { name: 'Descrição' }).fill('Synthetic invalid JPY')
  await form.getByRole('textbox', { name: 'Valor' }).fill('1,2')
  await form.getByRole('button', { name: 'Salvar', exact: true }).click()
  await expect(form.getByRole('alert')).toContainText('Valor fora dos limites admitidos.')
  expect(writes).toHaveLength(0)
  await page.keyboard.press('Escape')
  await selectFinanceSpace(page, secondName)
  await goToView(page, 'Plano de contas')
  await expect(page.locator('.lf-context-identity')).toContainText(secondName)
  await expect(cash).toContainText(/KWD\s*1[.,]234/)
  if (process.env.LF_CURRENCY_CONTEXT_REVIEW_CAPTURE === '1') await page.screenshot({ path: 'test-results/currency-context-kwd.png' })
  await goToView(page, 'Movimentações')
  await page.getByRole('button', { name: 'Registrar', exact: true }).click()
  await expect(form.getByRole('textbox', { name: 'Valor' })).toHaveValue('')
  await expect(form.getByRole('textbox', { name: 'Valor' })).toHaveAttribute('placeholder', '0,000')
  await page.keyboard.press('Escape')

  fixtures.set(second, { currency: 'KWD', currencyPrecision: null, currencyMetadataVersion: 1 })
  await page.goto(routeFor(second, 'accounts'))
  await expect(cash).toContainText('Indisponível')
  await goToView(page, 'Movimentações')
  await page.getByRole('button', { name: 'Registrar', exact: true }).click()
  await form.getByRole('button', { name: /Vai acontecer/ }).click()
  await form.getByRole('textbox', { name: 'Descrição' }).fill('Synthetic unknown precision')
  await form.getByRole('textbox', { name: 'Valor' }).fill('1')
  await form.getByRole('button', { name: 'Salvar', exact: true }).click()
  await expect(form.getByRole('alert')).toContainText('Valor fora dos limites admitidos.')
  expect(writes).toHaveLength(0)
  await page.unroute(spaceRoute)
  await page.unroute(accountRoute)
  await page.goto(routeFor(second, 'accounts'))
  await expect(cash).toContainText('R$ 12,34')
})
