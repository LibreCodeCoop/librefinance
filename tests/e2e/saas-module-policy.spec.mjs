// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from "@playwright/test"
import { login, makeSpace, goToView } from "./helpers.mjs"

test("SaaS module policy explains denial and cannot be overridden by a local toggle", async ({ page }) => {
  await login(page)
  await makeSpace(page, "personal")
  let policyDenied = true
  // This mock exercises only the presentation contract. The real server-side
  // ACL and restrictive policy are tested against installed Nextcloud in
  // tests/integration/saas-access.php and tests/integration/modules.php.
  await page.route("**/apps/librefinance/api/spaces/*/modules", async route => {
    if (route.request().method() !== "GET") return route.continue()
    const response = await route.fetch()
    const body = await response.json()
    const modules = (body.modules || []).map(module => module.id === "librefinance_reports"
      ? { ...module, enabled: false, blockedByPolicy: policyDenied }
      : module)
    return route.fulfill({ response, json: { ...body, modules } })
  })
  await page.reload()
  await goToView(page, "Recursos instalados")
  const module = page.locator(".lf-settings-module[data-module-id=\"librefinance_reports\"]")
  await expect(module.getByText("Bloqueado por política administrativa. A ativação local não remove a restrição.")).toBeVisible()
  await expect(module.getByRole("button", { name: "Bloqueado por política" })).toBeDisabled()
  await expect(module.getByRole("button", { name: "Ativar" })).toHaveCount(0)

  // A normal local disablement is different: the owner may activate it again.
  policyDenied = false
  await page.reload()
  await goToView(page, "Recursos instalados")
  await expect(module.getByText("Bloqueado por política administrativa.", { exact: false })).toHaveCount(0)
  await expect(module.getByRole("button", { name: "Ativar" })).toBeEnabled()
})
