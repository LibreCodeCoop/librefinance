// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('native contact profiles keep billing and shipping distinct and preserve legacy fields', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  await makeSpace(page, 'organization')
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  const api = '/index.php/apps/librefinance/api/spaces/' + spaceId
  const headers = { requesttoken: await page.evaluate(() => OC.requestToken) }
  async function request(method, path, status, data) {
    const response = await page.request.fetch(api + path, {
      method, headers, ...(data === undefined ? {} : { data }),
    })
    expect(response.status(), method + ' ' + path).toBe(status)
    return response.json()
  }
  const name = 'Synthetic profile customer ' + Date.now().toString(36)
  await goToView(page, 'Clientes e fornecedores')
  await page.getByRole('button', { name: 'Novo contato', exact: true }).click()
  const create = page.getByRole('dialog', { name: 'Novo contato', exact: true })
  await expect(create).toBeVisible()
  await create.getByRole('textbox', { name: 'Nome', exact: true }).fill(name)
  const section = create.getByRole('region', { name: 'Perfil do contato' })
  await section.locator('summary').filter({ hasText: 'Perfil do contato' }).click()
  await section.getByRole('textbox', { name: 'Pessoa de contato' }).fill('Pessoa Sintética')
  await section.getByRole('textbox', { name: 'Telefone', exact: true }).fill('+55 21 0000-0000')
  await section.locator('summary').filter({ hasText: 'Endereço de cobrança' }).click()
  const billing = section.getByRole('group', { name: 'Endereço de cobrança', exact: true })
  await billing.getByRole('textbox', { name: 'Endereço, linha 1' }).fill('Rua Exemplo, 10')
  await billing.getByRole('textbox', { name: 'Cidade', exact: true }).fill('Rio de Janeiro')
  await billing.getByRole('textbox', { name: 'Código postal (CEP)' }).fill('00123-000')
  await billing.getByRole('textbox', { name: 'País (ISO 2)', exact: true }).fill('BR')
  await section.locator('summary').filter({ hasText: 'Endereço de entrega' }).click()
  const shipping = section.getByRole('group', { name: 'Endereço de entrega', exact: true })
  await shipping.getByRole('textbox', { name: 'Endereço, linha 1' }).fill('Synthetic Street, 2')
  await shipping.getByRole('textbox', { name: 'Cidade', exact: true }).fill('Lisboa')
  await shipping.getByRole('textbox', { name: 'País (ISO 2)', exact: true }).fill('PT')
  if (process.env.LF_CONTACT_PROFILE_CAPTURE === '1') {
    await shipping.scrollIntoViewIfNeeded()
    await page.screenshot({ path: 'test-results/contact-profile-create.png', animations: 'disabled' })
  }
  await create.getByRole('button', { name: 'Salvar cadastro', exact: true }).click()
  await expect(create).toHaveCount(0)
  const row = page.getByRole('row').filter({ hasText: name })
  await expect(row).toBeVisible()
  const data = (await request('GET', '/contacts', 200)).contacts.find(c => c.name === name)
  expect(data.profile).toMatchObject({
    contactPerson: 'Pessoa Sintética', phone: '+55 21 0000-0000',
    billingAddress: { line1: 'Rua Exemplo, 10', city: 'Rio de Janeiro', postalCode: '00123-000', country: 'BR' },
    shippingAddress: { line1: 'Synthetic Street, 2', city: 'Lisboa', country: 'PT' },
  })
  await row.getByRole('button', { name: 'Editar ' + name, exact: true }).click()
  const edit = page.getByRole('dialog', { name: 'Editar contato', exact: true })
  await expect(edit).toBeVisible()
  const editing = edit.getByRole('region', { name: 'Perfil do contato' })
  await editing.locator('summary').filter({ hasText: 'Endereço de cobrança' }).click()
  const editingBilling = editing.getByRole('group', { name: 'Endereço de cobrança', exact: true })
  await expect(editingBilling.getByRole('textbox', { name: 'Código postal (CEP)' })).toHaveValue('00123-000')
  // A separate authorized API caller changes an unrelated optional field
  // after the dialog opens; submitting only the edited field preserves it.
  await request('PUT', '/contacts/' + data.id, 200, {
    name, kind: data.kind, profile: { phone: '+55 21 9999-9999' },
  })
  await editingBilling.getByRole('textbox', { name: 'Cidade', exact: true }).fill('São José')
  await edit.getByRole('button', { name: 'Salvar cadastro', exact: true }).click()
  await expect(edit).toHaveCount(0)
  const changed = (await request('GET', '/contacts', 200)).contacts.find(c => c.id === data.id)
  expect(changed.profile.phone).toBe('+55 21 9999-9999')
  expect(changed.profile.billingAddress.city).toBe('São José')
  expect(changed.profile.shippingAddress).toEqual(data.profile.shippingAddress)
  await row.getByRole('button', { name: 'Editar ' + name, exact: true }).click()
  await expect(edit).toBeVisible()
  await edit.getByText('Registro ativo', { exact: true }).click()
  await edit.getByRole('button', { name: 'Salvar cadastro', exact: true }).click()
  await expect(edit).toHaveCount(0)
  await expect(row).toContainText('Inativo')
  const archived = (await request('GET', '/contacts', 200)).contacts.find(c => c.id === data.id)
  expect(archived.active).toBe(false)
  expect(archived.profile).toEqual(changed.profile)
})
