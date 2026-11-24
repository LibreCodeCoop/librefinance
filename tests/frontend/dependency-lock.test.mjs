// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

test('optional WebDAV dependency is locked to the patched matcher', () => {
  const manifest = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'))
  const lock = JSON.parse(readFileSync(new URL('../../package-lock.json', import.meta.url), 'utf8'))
  assert.equal(manifest.overrides.webdav.minimatch, '11.0.1')
  const matchers = Object.entries(lock.packages).filter(([name]) => name.endsWith('/minimatch'))
  assert.ok(matchers.length > 0)
  for (const [, dependency] of matchers) {
    assert.equal(dependency.version, '11.0.1')
  }
})

test('optional WebDAV retains filename and path glob semantics when installed', {
  skip: Number(process.versions.node.split('.')[0]) < 24 ? 'Optional Nextcloud Files requires Node 24 or newer' : false,
}, async () => {
  const { processGlobFilter } = await import('../../node_modules/webdav/dist/node/response.js')
  const files = [
    { filename: '/synthetic/report.csv' },
    { filename: '/synthetic/archives/january.csv' },
    { filename: '/synthetic/notes.txt' },
  ]
  assert.deepEqual(processGlobFilter(files, '*.csv'), files.slice(0, 2))
  assert.deepEqual(processGlobFilter(files, '{report,notes}.{csv,txt}'), [files[0], files[2]])
  assert.deepEqual(processGlobFilter(files, '/synthetic/archives/*.csv'), [files[1]])
  assert.deepEqual(processGlobFilter(files, '*.pdf'), [])
})
