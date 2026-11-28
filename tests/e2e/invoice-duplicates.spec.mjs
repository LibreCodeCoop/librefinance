// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('native duplicate review distinguishes evidence, discards stale results and preserves records', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  await makeSpace(page, 'organization')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + spaceId
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const request = async (method, path, code, data) => {
    const response = await page.request.fetch(api + path, { method, headers,
      ...(data === undefined ? {} : { data }) })
    expect(response.status()).toBe(code)
    return response.json()
  }
  const contact = await request('POST', '/contacts', 201, { name: 'Synthetic supplier A', kind: 'vendor' })
  const other = await request('POST', '/contacts', 201, { name: 'Synthetic supplier B', kind: 'vendor' })
  const payload = { number: 'DUP..REF-A', kind: 'bill', contactId: contact.id,
    issueOn: '2026-11-20', dueOn: '2026-12-20', country: 'BR',
    lines: [{ description: 'Synthetic purchase', quantity: 1, unitPriceMinor: 12345 }] }
  const first = await request('POST', '/invoices', 201, payload)
  const near = await request('POST', '/invoices', 201,
    { ...payload, number: 'DUP-REF-B', issueOn: '2026-11-23' })
  const unrelated = await request('POST', '/invoices', 201,
    { ...payload, number: 'DUP-OTHER', contactId: other.id })
  const before = {
    invoices: await request('GET', '/invoices', 200),
    journals: await request('GET', '/journals', 200),
    accounts: await request('GET', '/accounts', 200),
    obligations: await request('GET', '/obligations', 200),
  }
  // API fixtures were created after the initial shell populated its catalog.
  await page.reload()
  await goToView(page, 'Faturas')
  await page.getByRole('button', { name: 'Nova fatura', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Nova fatura' })
  await dialog.getByRole('button', { name: /Compra Vou pagar/ }).click()
  const panel = dialog.getByRole('region', { name: 'Possíveis documentos duplicados' })
  await panel.getByRole('button', { name: 'Verificar possíveis duplicidades' }).click()
  const find = panel.getByRole('button', { name: 'Buscar candidatos' })
  await find.click()
  await expect(panel.getByRole('status')).toContainText('Selecione uma contraparte')
  await dialog.getByRole('textbox', { name: 'Número', exact: true }).fill(payload.number)
  await dialog.locator('#lf-invoice-contact').click()
  await page.getByRole('option').filter({ hasText: 'Synthetic supplier A' }).click()
  await dialog.locator('#lf-invoice-date').fill('2026-11-20')
  const total = panel.getByRole('textbox', { name: 'Total do documento de origem (opcional)' })
  await total.fill('123,45')
  await find.click()
  const row = id => panel.locator('[data-candidate-id="' + id + '"]')
  await expect(row(first.id)).toContainText('Mesma referência')
  await expect(row(first.id)).toContainText('Mesmo valor exato')
  await expect(row(near.id)).toContainText('Data de emissão em até três dias')
  await expect(row(unrelated.id)).toHaveCount(0)
  await expect(row(first.id)).toContainText('R$ 123,45')
  if (process.env.LF_DUPLICATE_REVIEW_CAPTURE === '1') {
    await panel.screenshot({ path: 'test-results/invoice-duplicates-create.png' })
  }

  let arrived
  let release
  const pending = new Promise(resolve => { arrived = resolve })
  const gate = new Promise(resolve => { release = resolve })
  await page.route('**/invoice-duplicate-candidates?*', async route => {
    const response = await route.fetch()
    arrived(response.status())
    await gate
    await route.fulfill({ response })
  }, { times: 1 })
  try {
    await find.click()
    expect(await pending).toBe(200)
    await dialog.getByRole('textbox', { name: 'Número', exact: true }).fill('DISTINCT-REF')
    await total.fill('9,99')
    await expect(row(first.id)).toHaveCount(0)
    await find.click()
    await expect(panel.getByRole('status')).toContainText('Nenhum candidato encontrado')
    const delayed = page.waitForResponse(r => r.url().includes('/invoice-duplicate-candidates?')
      && new URL(r.url()).searchParams.get('reference') === payload.number)
    release()
    expect((await delayed).status()).toBe(200)
    await expect(row(first.id)).toHaveCount(0)
    await expect(panel.getByRole('status')).toContainText('Nenhum candidato encontrado')
  } finally { release() }
  await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await page.getByRole('row').filter({ hasText: payload.number })
    .getByRole('button', { name: 'Detalhes' }).click()
  const detail = page.getByRole('dialog', { name: 'Detalhes da fatura' })
  const review = detail.getByRole('region', { name: 'Possíveis documentos duplicados' })
  await review.getByRole('button', { name: 'Verificar possíveis duplicidades' }).click()
  await review.getByRole('button', { name: 'Buscar candidatos' }).click()
  await expect(review.locator('[data-candidate-id="' + first.id + '"]')).toHaveCount(0)
  const candidate = review.locator('[data-candidate-id="' + near.id + '"]')
  await expect(candidate).toContainText('DUP-REF-B')
  if (process.env.LF_DUPLICATE_REVIEW_CAPTURE === '1') {
    await review.screenshot({ path: 'test-results/invoice-duplicates-detail.png' })
  }
  await candidate.getByRole('button', { name: 'Detalhes', exact: true }).click()
  await expect(detail.getByRole('heading', { name: 'DUP-REF-B', exact: true })).toBeVisible()
  expect(await request('GET', '/invoices', 200)).toEqual(before.invoices)
  expect(await request('GET', '/journals', 200)).toEqual(before.journals)
  expect(await request('GET', '/accounts', 200)).toEqual(before.accounts)
  expect(await request('GET', '/obligations', 200)).toEqual(before.obligations)
})
