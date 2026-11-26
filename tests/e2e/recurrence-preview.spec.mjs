// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('recurrence preview uses live scoped dates without creating financial records', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  const spaceName = await makeSpace(page, 'organization')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  expect(spaceId).toBeTruthy()
  const api = '/index.php/apps/librefinance/api/spaces/' + spaceId
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const request = async (method, path, status, data) => {
    const response = await page.request.fetch(api + path, {
      method, headers, ...(data === undefined ? {} : { data }),
    })
    expect(response.status(), method + ' ' + path).toBe(status)
    return response.json()
  }
  const description = 'Synthetic standalone recurrence'
  const source = await request('POST', '/obligations', 201, {
    kind: 'payable', description, amountMinor: 12345,
    issuedOn: '2028-01-31', dueOn: '2028-01-31',
  })
  const snapshot = async () => ({
    obligations: await request('GET', '/obligations', 200),
    journals: await request('GET', '/journals', 200),
  })
  const before = await snapshot()
  expect(before.obligations.obligations.map(row => row.id)).toEqual([source.id])
  const routePath = '/api/spaces/' + spaceId + '/obligations/' + source.id + '/recurrence-preview'
  const match = (url, query) => url.pathname.endsWith(routePath)
    && Object.entries(query).every(([key, value]) => url.searchParams.get(key) === value)
  const responseFor = query => page.waitForResponse(response =>
    response.request().method() === 'GET' && match(new URL(response.url()), query))
  const monthly = { count: '2', stepMonths: '1', endOfMonth: 'clamp' }
  const row = page.getByRole('row').filter({ hasText: description })
  const dialog = page.getByRole('dialog', { name: 'Prévia de recorrência', exact: true })
  const preview = dialog.getByRole('button', { name: 'Pré-visualizar recorrência', exact: true })
  const trigger = row.getByRole('button', { name: 'Pré-visualizar recorrência', exact: true })
  const dates = () => dialog.locator('tbody tr').evaluateAll(rows => rows.map(row =>
    Array.from(row.querySelectorAll('time'), time => time.dateTime)))
  const choose = async (id, name) => {
    await dialog.locator('#' + id).click()
    // NcSelect splits labels into highlight spans; DOM text remains intact.
    await page.getByRole('option').filter({ hasText: new RegExp('^' + name + '$') }).click()
  }
  const writes = []
  page.on('request', request => {
    if (new URL(request.url()).pathname.includes('/api/spaces/' + spaceId + '/')
      && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method())) writes.push(request.method())
  })
  await goToView(page, 'Movimentações')
  await expect(row).toBeVisible()
  await expect(row.getByRole('button', { name: 'Pagar', exact: true })).toBeVisible()

  const opened = responseFor(monthly)
  await trigger.click()
  const openedResponse = await opened
  expect(openedResponse.status()).toBe(200)
  expect(await openedResponse.json()).toMatchObject({
    templateId: source.id, amountMinor: 12345, currency: 'BRL',
    occurrences: [
      { issuedOn: '2028-02-29', dueOn: '2028-02-29' },
      { issuedOn: '2028-03-31', dueOn: '2028-03-31' },
    ],
  })
  await expect(dialog.getByText('Esta é apenas uma prévia. Nenhuma obrigação ou pagamento será criado.', { exact: true })).toBeVisible()
  await expect(dialog.getByText(/123,45/)).toBeVisible()
  await expect.poll(dates).toEqual([['2028-02-29', '2028-02-29'], ['2028-03-31', '2028-03-31']])

  // Hold the real server response for one specific query. Release it only
  // after a later request or dialog instance has visibly settled.
  async function withHeldResponse(query, action) {
    let release
    let reached
    const gate = new Promise(resolve => { release = resolve })
    const held = new Promise(resolve => { reached = resolve })
    const pattern = new RegExp(routePath + '(?:\\?|$)')
    const handler = async route => {
      if (route.request().method() !== 'GET' || !match(new URL(route.request().url()), query)) {
        await route.continue()
        return
      }
      const response = await route.fetch()
      reached(response.status())
      await gate
      await route.fulfill({ response })
    }
    await page.route(pattern, handler)
    try {
      await preview.click()
      expect(await held).toBe(200)
      await action()
      const stale = responseFor(query)
      release()
      await (await stale).finished()
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))
    } finally {
      release()
      await page.unroute(pattern, handler)
    }
  }

  const rejection = dialog.getByRole('alert').filter({
    hasText: 'Um dia não existe em um dos meses selecionados.',
  })
  await withHeldResponse(monthly, async () => {
    await choose('lf-recurrence-policy', 'Rejeitar dia inexistente')
    await expect(dialog.locator('tbody tr')).toHaveCount(0)
    const rejected = responseFor({ ...monthly, endOfMonth: 'reject' })
    await preview.click()
    const rejectedResponse = await rejected
    expect(rejectedResponse.status()).toBe(422)
    expect(await rejectedResponse.json()).toEqual({ error: 'recurrence_day_unavailable' })
    await expect(rejection).toBeVisible()
  })
  await expect(rejection).toBeVisible()
  await expect(dialog.locator('tbody tr')).toHaveCount(0)

  await choose('lf-recurrence-policy', 'Usar o último dia do mês')
  await choose('lf-recurrence-count', '3')
  await choose('lf-recurrence-interval', '2')
  const bimonthly = { count: '3', stepMonths: '2', endOfMonth: 'clamp' }
  const updated = responseFor(bimonthly)
  await preview.click()
  expect((await updated).status()).toBe(200)
  await expect.poll(dates).toEqual([
    ['2028-03-31', '2028-03-31'], ['2028-05-31', '2028-05-31'], ['2028-07-31', '2028-07-31'],
  ])

  // Closing and reopening must not let the older three-row preview replace
  // the newly opened dialog's default two-row preview.
  await withHeldResponse(bimonthly, async () => {
    await dialog.locator('.lf-form-actions').getByRole('button', { name: 'Fechar', exact: true }).click()
    await expect(dialog).toHaveCount(0)
    const reopened = responseFor(monthly)
    await trigger.click()
    expect((await reopened).status()).toBe(200)
    await expect.poll(dates).toEqual([['2028-02-29', '2028-02-29'], ['2028-03-31', '2028-03-31']])
  })
  await expect.poll(dates).toEqual([['2028-02-29', '2028-02-29'], ['2028-03-31', '2028-03-31']])
  await dialog.locator('.lf-form-actions').getByRole('button', { name: 'Fechar', exact: true }).click()
  await expect(trigger).toBeFocused()
  await page.reload()
  await goToView(page, 'Movimentações')
  await expect(row).toHaveCount(1)
  await expect(row).toContainText('R$ 123,45')
  await expect(page.locator('.lf-context-identity')).toContainText(spaceName)
  expect(await snapshot()).toEqual(before)
  expect(writes).toEqual([])

  // The backend is still the authority; these actual ineligible records
  // remain visible/payable but must not expose a recurrence action.
  const partial = await request('POST', '/obligations', 201, {
    kind: 'payable', description: 'Synthetic partially settled recurrence',
    amountMinor: 10000, issuedOn: '2028-01-31', dueOn: '2028-01-31',
  })
  await request('POST', '/obligations/' + partial.id + '/settlements', 201, {
    amountMinor: 1000, paidOn: '2028-01-31',
    operationKey: 'e2e-recurrence-' + spaceId,
  })
  const contact = await request('POST', '/contacts', 201, { name: 'Synthetic invoice contact', kind: 'customer' })
  const number = 'RECUR-LINK-' + spaceId
  const invoice = await request('POST', '/invoices', 201, {
    number, kind: 'invoice', contactId: contact.id, issueOn: '2028-01-31',
    dueOn: '2028-01-31', country: 'BR',
    lines: [{ description: 'Synthetic linked invoice line', quantity: 1, unitPriceMinor: 5000 }],
  })
  await request('POST', '/invoices/' + invoice.id + '/issue', 200, {})
  await page.reload()
  await goToView(page, 'Movimentações')
  for (const label of ['Synthetic partially settled recurrence', number]) {
    const ineligible = page.getByRole('row').filter({ hasText: label })
    await expect(ineligible).toBeVisible()
    await expect(ineligible.getByRole('button', { name: 'Pré-visualizar recorrência', exact: true })).toHaveCount(0)
  }
  await expect(trigger).toBeVisible()
})
