// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { build } from 'vite'
import appConfig from '../../vite.config.js'

async function mountMarkdown(page) {
  const [{ output }] = await build({
    configFile: false,
    logLevel: 'error',
    plugins: appConfig.plugins,
    define: appConfig.define,
    build: {
      write: false,
      lib: {
        entry: fileURLToPath(new URL('../frontend/fixtures/markdown-editor.js', import.meta.url)),
        name: 'MarkdownEditorFixture',
        formats: ['iife'],
      },
    },
  })
  await page.setContent('<html lang="en"><div id="app"></div></html>')
  const css = output.find(file => file.fileName.endsWith('.css'))
  if (css) await page.addStyleTag({ content: css.source })
  await page.addScriptTag({ content: output.find(file => file.type === 'chunk').code })
}

test('fallback Markdown toolbar restores keyboard focus and selection', async ({ page }) => {
  await mountMarkdown(page)
  const notes = page.getByRole('textbox', { name: 'Markdown notes' })
  const selected = 'Synthetic note'
  await notes.fill(selected)
  await notes.press('Control+a')
  for (let step = 0; step < 3; step++) await page.keyboard.press('Shift+Tab')
  await expect(page.getByRole('button', { name: '**Bold**', exact: true })).toBeFocused()
  await page.keyboard.press('Enter')
  const bold = '**' + selected + '**'
  await expect(notes).toHaveValue(bold)
  await expect(notes).toBeFocused({ timeout: 3000 })
  await expect(notes).toHaveJSProperty('selectionStart', 2)
  await expect(notes).toHaveJSProperty('selectionEnd', 2 + selected.length)
  await notes.press('Control+End')
  await page.keyboard.press('Shift+Tab')
  await page.keyboard.press('Shift+Tab')
  await expect(page.getByRole('button', { name: '*Italic*', exact: true })).toBeFocused()
  await page.keyboard.press('Space')
  await expect(notes).toBeFocused()
  await page.keyboard.type('verified')
  const expected = bold + '_verified_'
  await expect(notes).toHaveValue(expected)
  await expect(page.locator('#markdown-model')).toHaveText(expected)
})

test('formatting at the character limit never discards existing notes', async ({ page }) => {
  await mountMarkdown(page)
  const notes = page.getByRole('textbox', { name: 'Markdown notes' })
  const full = 'START' + 'x'.repeat(4991) + 'TAIL'
  expect(full.length).toBe(5000)
  await notes.fill(full)
  await notes.press('Control+Home')
  await page.getByRole('button', { name: '**Bold**', exact: true }).click()
  await expect(notes).toHaveValue(full)
  await expect(page.locator('#markdown-model')).toHaveText(full)
})
