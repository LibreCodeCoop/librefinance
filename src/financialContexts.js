// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { t } from '@nextcloud/l10n'

// This partitions only already-authorized workspaces. It is not an ACL.
export function contextKey(space) {
  if (!space) return ''
  if (space.externalInvitation === true) return 'external'
  if (space.saasManaged === true) {
    const id = space.financialEnvironment?.id
    return typeof id === 'string' && id !== '' ? 'environment:' + id : 'managed:' + space.id
  }
  return 'legacy'
}

export function financialContexts(spaces) {
  const groups = new Map()
  for (const space of spaces) {
    const key = contextKey(space)
    if (!groups.has(key)) {
      const label = key === 'external' ? t('librefinance', 'Shared financial workspaces')
        : key === 'legacy' ? t('librefinance', 'Personal and legacy workspaces')
        : space.financialEnvironment?.name || space.name
      groups.set(key, { value: key, label, spaces: [] })
    }
    groups.get(key).spaces.push(space)
  }
  const contexts = [...groups.values()]
  const duplicates = new Map()
  for (const item of contexts) duplicates.set(item.label, (duplicates.get(item.label) || 0) + 1)
  return contexts.map(item => duplicates.get(item.label) > 1
    ? { ...item, label: item.label + ' · ' + item.value.split(':').at(-1) }
    : item)
}

export function authorizedInitialWorkspace(spaces, preferredId = 0) {
  if (spaces.some(space => Number(space.id) === Number(preferredId))) return Number(preferredId)
  return financialContexts(spaces).length === 1 ? Number(spaces[0].id) : 0
}
