// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { devices, test, expect } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { build } from 'vite'
import appConfig from '../../vite.config.js'

test.use({ locale: 'ar-EG', timezoneId: 'Pacific/Apia' })

async function mountDateFields(page) {
  const [{ output }] = await build({
    configFile: false,
    logLevel: 'error',
    plugins: appConfig.plugins,
    define: appConfig.define,
    build: {
      write: false,
      lib: {
        entry: fileURLToPath(new URL('./fixtures/date-field.js', import.meta.url)),
        name: 'DateFieldFixture',
        formats: ['iife'],
      },
    },
  })
  await page.setContent('<html lang="ar" dir="rtl"><meta name="viewport" content="width=device-width, initial-scale=1"><div id="app"></div></html>')
  await page.addStyleTag({ content: output.find(file => file.fileName.endsWith('.css')).source })
  await page.addScriptTag({ content: output.find(file => file.type === 'chunk').code })
}

test('native date field keeps the ISO form model across edits and clearing', async ({ page }) => {
  await mountDateFields(page)
  const entry = page.getByLabel('Entry date', { exact: true })
  const saved = page.getByLabel('Saved date', { exact: true })
  const model = page.locator('#form-model')
  await expect(entry).toHaveAttribute('type', 'date')
  await expect(entry).toHaveValue('0096-02-29')
  await expect(saved).toBeDisabled()
  await page.getByRole('button', { name: 'Before dates' }).press('Tab')
  await expect(entry).toBeFocused()

  await entry.fill('2011-12-30')
  await expect(model).toHaveText('["2011-12-30",1]')
  await expect(saved).toHaveValue('2011-12-30')

  // HTML date inputs can hold years beyond the four-digit form contract.
  await entry.fill('10000-01-01')
  await expect(page.locator('#field-validity')).toHaveText('false')
  await expect(model).toHaveText('["2011-12-30",1]')
  await expect(entry).toHaveValue('2011-12-30')

  await entry.fill('0001-01-01')
  await expect(page.locator('#field-validity')).toHaveText('true')
  await expect(model).toHaveText('["0001-01-01",2]')
  await expect(saved).toHaveValue('0001-01-01')
  await entry.clear()
  await expect(page.locator('#field-validity')).toHaveText('true')
  await expect(model).toHaveText('["",3]')
  await expect(saved).toHaveValue('')
})

test.describe('mobile date field', () => {
  const { viewport, deviceScaleFactor, isMobile, hasTouch } = devices['Pixel 7']
  test.use({ viewport, deviceScaleFactor, isMobile, hasTouch })

  test('rejects an out-of-contract date from an empty field and accepts a leap day', async ({ page }) => {
    await mountDateFields(page)
    const entry = page.getByLabel('Entry date', { exact: true })
    const model = page.locator('#form-model')
    await entry.clear()
    await expect(model).toHaveText('["",1]')

    await entry.fill('10000-01-01')
    await expect(page.locator('#field-validity')).toHaveText('false')
    await expect(entry).toHaveValue('')
    await expect(model).toHaveText('["",1]')

    await entry.fill('2000-02-29')
    await expect(page.locator('#field-validity')).toHaveText('true')
    await expect(model).toHaveText('["2000-02-29",2]')
    await expect(page.getByLabel('Saved date', { exact: true })).toHaveValue('2000-02-29')
  })
})
