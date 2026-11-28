// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace } from './helpers.mjs'

test('reconciliation suggestions find unloaded journals, ignore stale results and require manual confirmation', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  await makeSpace(page, 'personal')
  const id = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + id
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  async function request(method, path, status, data) {
    const response = await page.request.fetch(api + path, {
      method, headers, ...(data === undefined ? {} : { data }),
    })
    expect(response.status(), method + ' ' + path).toBe(status)
    return response.json()
  }
  const chart = (await request('GET', '/accounts', 200)).accounts
  const bank = chart.find(a => a.code === '1000')
  const income = chart.find(a => a.code === '4000')
  const tag = Date.now().toString(36)
  const date = '2026-11-20'
  async function post(key, amountMinor, memo, on = date) {
    return request('POST', '/journals', 201, {
      debitId: bank.id, creditId: income.id, amountMinor, memo, date: on,
      externalKey: 'candidate-' + tag + '-' + key,
    })
  }
  const first = await post('first', 3210, 'Synthetic candidate same day')
  const second = await post('second', 3210, 'Synthetic candidate near date', '2026-11-23')
  for (let n = 0; n < 51; n++) {
    await post('distractor-' + n, 100, 'Synthetic distractor ' + n)
  }
  const loaded = await request('GET', '/journals', 200)
  expect(loaded.journals).toHaveLength(50)
  expect(loaded.journals.map(row => row.id)).not.toContain(first.id)
  expect(loaded.journals.map(row => row.id)).not.toContain(second.id)
  await request('POST', '/imports', 201, {
    bankAccountId: bank.id,
    csv: 'id,date,amount,description\n'
      + 'target-' + tag + ',' + date + ',32.10,Synthetic evidence target\n'
      + 'other-' + tag + ',' + date + ',19.00,Synthetic evidence other\n',
  })
  const beforeAccounts = await request('GET', '/accounts', 200)
  const beforeStatement = await request('GET', '/transactions', 200)
  const target = beforeStatement.transactions.find(row => row.external_id === 'target-' + tag)
  const other = beforeStatement.transactions.find(row => row.external_id === 'other-' + tag)
  const writes = []
  page.on('request', r => {
    if (r.method() !== 'GET' && new URL(r.url()).pathname.includes('/api/spaces/' + id + '/')) writes.push(r)
  })
  const url = '/index.php/apps/librefinance/?spaceId=' + id + '&forceLanguage=pt_BR&view='
  await page.goto(url + 'banking')
  const row = name => page.getByRole('row').filter({ hasText: name })
  const dialog = page.getByRole('dialog', { name: 'Conciliar movimentação', exact: true })
  const suggestions = dialog.getByRole('region', { name: 'Sugestões de conciliação', exact: true })
  const trigger = suggestions.getByRole('button', { name: 'Buscar sugestões de conciliação', exact: true })
  const confirm = dialog.getByRole('button', { name: 'Confirmar conciliação', exact: true })
  async function open(name) {
    await row(name).getByRole('button', { name: 'Conciliar movimento ' + (name.endsWith('target') ? target.id : other.id), exact: true }).click()
    await expect(dialog).toBeVisible()
    await expect(confirm).toBeDisabled()
  }
  await open('Synthetic evidence target')
  let release, reached
  const gate = new Promise(resolve => { release = resolve })
  const held = new Promise(resolve => { reached = resolve })
  const pattern = new RegExp('/transactions/' + target.id + '/reconciliation-candidates\\?')
  const handler = async route => {
    const response = await route.fetch()
    reached(response.status())
    await gate
    await route.fulfill({ response })
  }
  await page.route(pattern, handler)
  try {
    await trigger.click()
    expect(await held).toBe(200)
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
    await expect(dialog).toHaveCount(0)
    await open('Synthetic evidence other')
    await trigger.click()
    await expect(suggestions.getByText(/Nenhum candidato encontrado/)).toBeVisible()
    const stale = page.waitForResponse(r => pattern.test(r.url()))
    release()
    await (await stale).finished()
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))
    await expect(suggestions.locator('[data-journal-id]')).toHaveCount(0)
    await expect(suggestions.getByText(/Nenhum candidato encontrado/)).toBeVisible()
    await expect(confirm).toBeDisabled()
  } finally {
    release()
    await page.unroute(pattern, handler)
  }
  await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await open('Synthetic evidence target')
  await trigger.click()
  await expect(suggestions.getByText(/Vários candidatos correspondem/)).toBeVisible()
  await expect(suggestions.locator('[data-journal-id]')).toHaveCount(2)
  await expect(suggestions.locator('[data-journal-id="' + first.id + '"]')).toContainText('Mesma data do extrato')
  await expect(suggestions.locator('[data-journal-id="' + second.id + '"]')).toContainText('Dentro do intervalo')
  await expect(confirm).toBeDisabled()
  if (process.env.LF_RECONCILIATION_CAPTURE === '1') await page.screenshot({ path: 'test-results/reconciliation-ambiguous.png', animations: 'disabled' })
  // Filter changes invalidate evidence and any selected suggestion.
  await suggestions.locator('#lf-reconciliation-window').click()
  await page.getByRole('option').filter({ hasText: 'Somente a data do extrato' }).click()
  await expect(suggestions.locator('[data-journal-id]')).toHaveCount(0)
  await suggestions.getByRole('button', { name: 'Pesquisar novamente' }).click()
  await expect(suggestions.getByText(/Um candidato encontrado/)).toBeVisible()
  await expect(suggestions.locator('[data-journal-id]')).toHaveCount(1)
  await suggestions.getByRole('button', { name: 'Selecionar este lançamento' }).click()
  await expect(confirm).toBeEnabled()
  expect(writes).toHaveLength(0)
  expect(await request('GET', '/transactions', 200)).toEqual(beforeStatement)
  expect(await request('GET', '/accounts', 200)).toEqual(beforeAccounts)
  // Cancel is still read-only, and the native movements flow uses the same component.
  await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await page.goto(url + 'finance')
  const nativeRow = row('Synthetic evidence target')
  const search = page.getByRole('searchbox', { name: 'Pesquisar movimentações' })
  await expect(search).toBeVisible()
  await search.fill('Synthetic evidence target')
  await search.press('Enter')
  await expect(nativeRow).toBeVisible()
  await nativeRow.getByRole('button', { name: 'Conferir', exact: true }).click()
  const native = page.getByRole('dialog', { name: 'Conferir movimentação', exact: true })
  const nativeSuggestions = native.getByRole('region', { name: 'Sugestões de conciliação', exact: true })
  await nativeSuggestions.getByRole('button', { name: 'Buscar sugestões de conciliação' }).click()
  await expect(nativeSuggestions.getByText(/Vários candidatos correspondem/)).toBeVisible()
  await nativeSuggestions.locator('[data-journal-id="' + first.id + '"]').getByRole('button', { name: 'Selecionar este lançamento' }).click()
  const confirmNative = native.getByRole('button', { name: 'Confirmar conferência' })
  await expect(confirmNative).toBeEnabled()
  if (process.env.LF_RECONCILIATION_CAPTURE === '1') await page.screenshot({ path: 'test-results/reconciliation-selected.png', animations: 'disabled' })
  const posted = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/transactions/' + target.id + '/match'))
  await confirmNative.click()
  expect((await posted).status()).toBe(200)
  await expect(native).toHaveCount(0)
  const after = await request('GET', '/transactions', 200)
  expect(after.transactions.find(row => row.id === target.id)).toMatchObject({ status: 'matched', matched_journal_id: first.id })
  expect(after.transactions.find(row => row.id === other.id).status).toBe('pending')
  expect(await request('GET', '/accounts', 200)).toEqual(beforeAccounts)
  expect(await request('GET', '/journals', 200)).toEqual(loaded)
  expect(writes.map(r => r.method())).toEqual(['POST'])
})
