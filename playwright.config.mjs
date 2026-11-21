// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.LF_E2E_URL || 'http://127.0.0.1:18836'
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(baseURL)) {
  throw new Error('LibreFinance E2E tests are restricted to an HTTP loopback Nextcloud lab')
}

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  timeout: 45_000,
  expect: { timeout: 12_000 },
  reporter: [['line'], ['json', { outputFile: `tests/e2e-results-${process.env.LF_E2E_SHARD || 'all'}.json` }]],
  use: {
    baseURL,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
    actionTimeout: 12_000,
  },
  projects: [{
    name: 'chromium',
    use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 860 },
      launchOptions: process.env.LF_E2E_CHROMIUM_PATH
        ? { executablePath: process.env.LF_E2E_CHROMIUM_PATH }
        : {},
    },
  }],
})
