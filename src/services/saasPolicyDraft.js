// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { t } from '@nextcloud/l10n'

/**
 * Editing helpers, never authorization: policies are validated authoritatively
 * on every server request, including parent restrictions and revision.
 */
export function initialPolicyDraft(policy) {
  if (!policy || typeof policy.key !== 'string') throw new Error(t('librefinance', 'Invalid policy.'))
  const value = policy.effectiveValue
  switch (policy.type) {
    case 'boolean': return value === true
    case 'ceiling': return String(value)
    case 'allowed_set': return Array.isArray(value) ? value.join(', ') : ''
    default: throw new Error(t('librefinance', 'Unknown policy type.'))
  }
}

export function policyInputValue(policy, value) {
  if (!policy?.editableByCurrentActor) {
    throw new Error(t('librefinance', 'You are not authorized to change this policy.'))
  }
  if (policy.type === 'boolean') {
    if (typeof value !== 'boolean') throw new Error(t('librefinance', 'The policy requires true or false.'))
    if (value === true && Array.isArray(policy.allowedValues)
      && !policy.allowedValues.includes(true)) {
      throw new Error(t('librefinance', 'An inherited restriction cannot be widened.'))
    }
    return value
  }
  if (policy.type === 'ceiling') {
    const text = String(value).trim()
    if (!/^(0|[1-9][0-9]*)$/.test(text)) {
      throw new Error(t('librefinance', 'Enter a nonnegative integer limit.'))
    }
    const parsed = Number(text)
    if (!Number.isSafeInteger(parsed)
      || (typeof policy.maxAllowed === 'number' && parsed > policy.maxAllowed)) {
      throw new Error(t('librefinance', 'The limit cannot exceed the inherited restriction.'))
    }
    return parsed
  }
  if (policy.type === 'allowed_set') {
    const values = String(value).split(',').map(x => x.trim()).filter(Boolean)
    if (values.length > 256 || values.some(x => x.length > 64)
      || new Set(values).size !== values.length) {
      throw new Error(t('librefinance', 'Duplicate, oversized or invalid values.'))
    }
    if (Array.isArray(policy.allowedValues)
      && values.some(x => !policy.allowedValues.includes(x))) {
      throw new Error(t('librefinance', 'Only values inherited from higher levels are allowed.'))
    }
    return values
  }
  throw new Error(t('librefinance', 'Unknown policy type.'))
}

export function formatPolicyValue(policy) {
  if (!policy) return ''
  if (policy.type === 'boolean') return policy.effectiveValue ? t('librefinance', 'Allowed') : t('librefinance', 'Blocked')
  if (policy.type === 'allowed_set') {
    return Array.isArray(policy.effectiveValue)
      ? policy.effectiveValue.join(', ') || t('librefinance', 'No allowed values')
      : ''
  }
  return String(policy.effectiveValue)
}
