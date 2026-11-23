// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Never mix administrative workspaces from two Nextcloud group environments. */
export function eligibleSaasEnvironments(environments) {
  return (Array.isArray(environments) ? environments : [])
    .filter(item => item?.state === 'active' && item?.canManage === true)
}

export function chooseSaasEnvironment(environments, previousId = '') {
  const eligible = eligibleSaasEnvironments(environments)
  if (previousId && eligible.some(item => item.id === previousId)) return previousId
  // With multiple groups, require explicit selection. A revoked selection
  // must never be replaced by another available tenant implicitly.
  if (previousId) return ''
  return eligible.length === 1 ? eligible[0].id : ''
}

/**
 * Preserve the idempotency key when an HTTP request may have committed
 * but its response was lost. Changing any financial creation input
 * generates a different key; success clears the claim for the next book.
 */
export function createWorkspaceClaimKeys(generate = () => globalThis.crypto?.randomUUID?.()) {
  const keys = new Map()
  const fingerprint = (environmentId, draft) => JSON.stringify([
    environmentId,
    String(draft.name || '').trim(),
    String(draft.ownerUid || '').trim(),
    draft.kind,
    draft.currency || 'BRL',
    draft.color || '#64748B',
  ])
  return {
    get(environmentId, draft) {
      const entry = fingerprint(environmentId, draft)
      if (!keys.has(entry)) {
        const nextKey = generate()
        if (typeof nextKey !== 'string' || !/^[A-Za-z0-9_-]{8,64}$/.test(nextKey)) {
          throw new Error('Navegador sem gerador seguro para a chave da operação.')
        }
        keys.set(entry, nextKey)
      }
      return keys.get(entry)
    },
    clear(environmentId, draft) {
      if (environmentId && draft) keys.delete(fingerprint(environmentId, draft))
      else keys.clear()
    },
  }
}
