// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { login, makeSpace, goToView } from './helpers.mjs'

test('general ledger exports every applied posting and opens the authorized reversal journal', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  await makeSpace(page, 'personal')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + spaceId
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  const accounts = await page.request.get(api + '/accounts', { headers })
  expect(accounts.status()).toBe(200)
  const chart = (await accounts.json()).accounts
  const bank = chart.find(a => a.code === '1000')
  const income = chart.find(a => a.code === '4000')
  const expense = chart.find(a => a.code === '5000')
  const suffix = Date.now().toString(36)
  async function post(id, date, debitId, creditId, amountMinor, memo) {
    const response = await page.request.post(api + '/journals', { headers, data: {
      debitId, creditId, amountMinor, date, memo, externalKey: 'e2e-ledger-' + suffix + '-' + id,
    } })
    expect(response.status()).toBe(201)
    return response.json()
  }
  await post('opening', '2000-12-31', bank.id, income.id, 10000, 'Synthetic opening')
  const original = await post('expense', '2001-01-01', expense.id, bank.id, 1234, 'Synthetic reversal origin')
  for (let i = 0; i < 50; i++) {
    await post('receipt-' + i, '2001-01-01', bank.id, income.id, 1, 'Synthetic receipt ' + i)
  }
  const reversal = await page.request.post(api + '/journals/' + original.id + '/reverse', { headers })
  expect(reversal.status()).toBe(200)
  const reversed = await reversal.json()
  await goToView(page, 'Relatórios')
  await page.getByRole('button', { name: /Razão contábil/ }).click()
  const view = page.getByRole('region', { name: 'Razão contábil', exact: true })
  const apply = view.getByRole('button', { name: 'Aplicar período' })
  await expect(apply).toBeDisabled()
  await view.locator('#lf-ledger-account').click()
  await page.getByRole('option').filter({ hasText: '1000 · ' + bank.name }).click()
  await view.locator('#lf-ledger-from').fill('2001-01-01')
  await view.locator('#lf-ledger-through').fill('2099-12-31')
  await apply.click()
  await expect(view.getByText('Exibindo 50 de 52 registros contabilizados.')).toBeVisible()
  await expect(view.getByText('Saldo inicial').locator('..')).toContainText('100,00')
  await expect(view.getByText('Saldo final').locator('..')).toContainText('100,50')
  const table = view.locator('.lf-data-table').first()
  await expect(table.locator('tbody tr')).toHaveCount(50)
  if (process.env.LF_GENERAL_LEDGER_CAPTURE === '1') {
    await page.screenshot({ path: 'test-results/general-ledger-applied.png', animations: 'disabled' })
    await table.locator('thead').scrollIntoViewIfNeeded()
    await page.screenshot({ path: 'test-results/general-ledger-rows.png', animations: 'disabled' })
  }
  const download = page.waitForEvent('download')
  await view.getByRole('button', { name: 'Exportar CSV', exact: true }).click()
  const file = await download
  expect(file.suggestedFilename()).toBe('librefinance-general-ledger.csv')
  const csv = await readFile(await file.path(), 'utf8')
  const lines = csv.trimEnd().split('\r\n')
  expect(lines).toHaveLength(55) // header + opening + 52 postings + closing
  expect(lines[1]).toContain(',opening,')
  expect(lines[1]).toMatch(/,10000$/)
  expect(lines[2]).toContain(',-1234,0,1234,8766')
  expect(lines[53]).toContain(',' + reversed.id + ',' + original.id + ',')
  expect(lines[53]).toContain(',1234,1234,0,10050')
  expect(lines[54]).toContain(',closing,')
  expect(lines[54]).toMatch(/,10050$/)
  await expect(table.locator('tbody tr')).toHaveCount(50)
  await view.getByRole('button', { name: 'Carregar mais', exact: true }).click()
  await expect(table.locator('tbody tr')).toHaveCount(52)
  await view.getByRole('button', { name: '#' + reversed.id, exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Lançamento realizado' })
  await expect(dialog.getByText('Estorno: Synthetic reversal origin')).toBeVisible()
  await expect(dialog.locator('tbody tr')).toHaveCount(2)
  if (process.env.LF_GENERAL_LEDGER_CAPTURE === '1') await page.screenshot({ path: 'test-results/general-ledger-journal.png', animations: 'disabled' })
  await dialog.getByRole('button', { name: 'Estorno do lançamento #' + original.id }).click()
  await expect(dialog.getByText('Synthetic reversal origin', { exact: true })).toBeVisible()
  await expect(dialog.locator('tbody tr')).toHaveCount(2)
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  // Re-apply, change the ledger, and ensure stale pagination cannot mix versions.
  await apply.click()
  await expect(table.locator('tbody tr')).toHaveCount(50)
  await post('backdated', '2000-12-30', bank.id, income.id, 9, 'Synthetic later posting')
  await view.getByRole('button', { name: 'Carregar mais', exact: true }).click()
  await expect(view.getByRole('alert')).toContainText('O razão foi alterado')
  await expect(table).toHaveCount(0)
  await apply.click()
  await expect(view.getByText('Saldo inicial').locator('..')).toContainText('100,09')
})
