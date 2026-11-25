// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict'
import { register } from 'node:module'
import test from 'node:test'

const stubs = {
  '@nextcloud/axios': 'export default config => globalThis.financeRequestStub(config)',
  '@nextcloud/router': 'export const generateUrl = path => "/index.php" + path',
  '@nextcloud/l10n': 'export const t = (_, message) => message; export const getLocale = () => "en_US"',
}
register('data:text/javascript,' + encodeURIComponent(`
  const stubs = ${JSON.stringify(stubs)}
  export function resolve(specifier, context, nextResolve) {
    if (Object.hasOwn(stubs, specifier)) {
      return { url: 'data:text/javascript,' + encodeURIComponent(stubs[specifier]), shortCircuit: true }
    }
    return nextResolve(specifier, context)
  }
`))
const { bindFinanceSelection, financeApi, requestFinancialWorkspace } = await import('../../src/services/api.js')

test('financial requests capture the selected context and reject stale work across navigation', async (t) => {
  const requests = []
  const envA = 'ad0d4e8b-78f9-49e5-bf58-d34b0cbb4d33'
  const envB = 'c40a79aa-4b75-446e-97eb-92052e66ceef'
  let selection = { spaceId: 10, context: envA }
  const unbind = bindFinanceSelection(() => selection)
  t.after(unbind)
  t.after(() => { delete globalThis.financeRequestStub })
  let release
  let rejectResponse
  let respond = () => new Promise(resolve => { release = resolve })
  globalThis.financeRequestStub = config => {
    requests.push(config)
    return respond()
  }

  const pending = requestFinancialWorkspace(10, '/index.php/apps/librefinance/api/spaces/10/accounts', 'GET')
  const rejected = assert.rejects(pending, /Invalid financial request/)
  assert.equal(requests[0].headers['X-LibreFinance-Context'], envA)
  selection = { spaceId: 20, context: envB }
  await assert.rejects(financeApi('librefinance', 10, '/accounts'), /Invalid financial request/)
  assert.equal(requests.length, 1)
  respond = () => Promise.resolve({ data: { ok: true } })
  assert.deepEqual(await financeApi('librefinance_nfse', 20, '/drafts'), { ok: true })
  assert.equal(requests[1].url, '/index.php/apps/librefinance_nfse/api/spaces/20/drafts')
  assert.equal(requests[1].headers['X-LibreFinance-Context'], envB)

  selection = { spaceId: 10, context: envA }
  release({ data: { accounts: [] } })
  await rejected
  assert.equal(requests[0].headers['X-LibreFinance-Context'], envA)

  for (const context of ['external', 'legacy']) {
    selection = { spaceId: 10, context }
    await financeApi('librefinance', 10, '/journals')
    assert.equal(requests.at(-1).headers['X-LibreFinance-Context'], context)
  }
  selection = { spaceId: 10, context: '' }
  await assert.rejects(financeApi('librefinance', 10, '/journals'), /Invalid financial request/)
  unbind()
  await assert.rejects(financeApi('librefinance', 10, '/journals'), /Invalid financial request/)
  assert.equal(requests.length, 4)

  selection = { spaceId: 10, context: envA }
  const releaseNewBinding = bindFinanceSelection(() => selection)
  t.after(releaseNewBinding)
  const conflict = new Error('revision_conflict')
  respond = () => Promise.reject(conflict)
  await assert.rejects(financeApi('librefinance', 10, '/members'), error => error === conflict)

  respond = () => new Promise((_, reject) => { rejectResponse = reject })
  const lateConflict = assert.rejects(financeApi('librefinance', 10, '/members'), /Invalid financial request/)
  selection = { spaceId: 20, context: envB }
  rejectResponse(conflict)
  await lateConflict
})


// Exercise the real composable, not only its HTTP wrapper. This proves that
// an old action cannot publish an error or clear a newer action's busy state.
test('workspace actions and pagination cannot update a later selection', async (t) => {
  const { createRenderer, nextTick } = await import('vue')
  const { useFinanceWorkspace } = await import('../../src/state/useFinanceWorkspace.js')
  const envA = 'ad0d4e8b-78f9-49e5-bf58-d34b0cbb4d33'
  const envB = 'c40a79aa-4b75-446e-97eb-92052e66ceef'
  const spaces = [
    { id: 10, name: 'A', role: 'owner', currency: 'BRL', saasManaged: true, financialEnvironment: { id: envA } },
    { id: 20, name: 'B', role: 'owner', currency: 'BRL', saasManaged: true, financialEnvironment: { id: envB } },
  ]
  const pendingWrites = new Map()
  let oldPage
  const windowDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'window')
  Object.defineProperty(globalThis, 'window', { configurable: true, value: { location: { search: '' } } })
  t.after(() => {
    if (windowDescriptor) Object.defineProperty(globalThis, 'window', windowDescriptor)
    else delete globalThis.window
    delete globalThis.financeRequestStub
  })
  globalThis.financeRequestStub = config => {
    const path = config.url
    if (path.endsWith('/api/spaces')) return Promise.resolve({ data: { spaces } })
    const match = path.match(/\/spaces\/(\d+)\/([^/?]+)/)
    assert.ok(match, path)
    const [, rawId, endpoint] = match
    const id = Number(rawId)
    if (config.method === 'POST' && endpoint === 'accounts') {
      return new Promise(resolve => pendingWrites.set(id, resolve))
    }
    if (id === 10 && endpoint === 'journals' && path.includes('?beforeId=')) {
      return new Promise((resolve, reject) => { oldPage = { resolve, reject } })
    }
    const data = { [endpoint === 'company-fields' ? 'fields' : endpoint]: [], nextAfterId: null, nextBeforeId: null }
    if (endpoint === 'journals') {
      data.journals = [{ id: id * 100 }]
      data.nextBeforeId = id === 10 ? 1000 : null
    }
    return Promise.resolve({ data })
  }
  const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    insert() {}, remove() {}, setText() {}, setElementText() {}, patchProp() {},
    parentNode: () => null, nextSibling: () => null,
  })
  let workspace
  const app = renderer.createApp({ setup() { workspace = useFinanceWorkspace(); return () => null } })
  app.mount({})
  t.after(() => app.unmount())
  await workspace.refreshSpaces()
  const select = async id => {
    workspace.activeId.value = id
    await nextTick()
    await workspace.refreshCurrent()
  }

  await select(10)
  workspace.accountName.value = 'A account'
  workspace.accountCode.value = '1010'
  const oldWrite = workspace.createAccount()
  assert.equal(typeof pendingWrites.get(10), 'function')
  await select(20)
  workspace.accountName.value = 'B account'
  workspace.accountCode.value = '1020'
  const newWrite = workspace.createAccount()
  assert.equal(typeof pendingWrites.get(20), 'function')
  pendingWrites.get(10)({ data: { id: 1 } })
  await oldWrite
  assert.equal(workspace.error.value, '', 'late successful A write must not display an error in B')
  assert.equal(workspace.message.value, '', 'late A write must not display a success in B')
  assert.equal(workspace.busy.value, true, 'A completion must not clear the pending B write')
  assert.equal(workspace.accountName.value, 'B account')
  pendingWrites.get(20)({ data: { id: 2 } })
  await newWrite
  assert.equal(workspace.error.value, '')
  assert.equal(workspace.busy.value, false)
  assert.equal(workspace.accountName.value, '')
  assert.ok(workspace.message.value, 'the current write still reports success')

  await select(10)
  const page = workspace.loadMore()
  assert.ok(oldPage)
  await select(20)
  oldPage.reject(new Error('old page failed'))
  await page
  assert.equal(workspace.error.value, '', 'a late pagination error is not the current workspace error')
  assert.deepEqual(workspace.journals.value, [{ id: 2000 }])
})
