// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Editing helpers, never authorization: policies are validated authoritatively
 * on every server request, including parent restrictions and revision.
 */
export function initialPolicyDraft(policy) {
  if (!policy || typeof policy.key !== 'string') throw new Error('Política inválida.')
  const value = policy.effectiveValue
  switch (policy.type) {
    case 'boolean': return value === true
    case 'ceiling': return String(value)
    case 'allowed_set': return Array.isArray(value) ? value.join(', ') : ''
    default: throw new Error('Tipo de política desconhecido.')
  }
}

export function policyInputValue(policy, value) {
  if (!policy?.editableByCurrentActor) {
    throw new Error('Você não possui autorização para alterar esta política.')
  }
  if (policy.type === 'boolean') {
    if (typeof value !== 'boolean') throw new Error('A política exige verdadeiro ou falso.')
    if (value === true && Array.isArray(policy.allowedValues)
      && !policy.allowedValues.includes(true)) {
      throw new Error('Uma restrição herdada não pode ser ampliada.')
    }
    return value
  }
  if (policy.type === 'ceiling') {
    const text = String(value).trim()
    if (!/^(0|[1-9][0-9]*)$/.test(text)) {
      throw new Error('Informe um limite inteiro não negativo.')
    }
    const parsed = Number(text)
    if (!Number.isSafeInteger(parsed)
      || (typeof policy.maxAllowed === 'number' && parsed > policy.maxAllowed)) {
      throw new Error('O limite não pode ultrapassar a restrição herdada.')
    }
    return parsed
  }
  if (policy.type === 'allowed_set') {
    const values = String(value).split(',').map(x => x.trim()).filter(Boolean)
    if (values.length > 256 || values.some(x => x.length > 64)
      || new Set(values).size !== values.length) {
      throw new Error('Valores repetidos, extensos ou inválidos.')
    }
    if (Array.isArray(policy.allowedValues)
      && values.some(x => !policy.allowedValues.includes(x))) {
      throw new Error('Só são permitidos valores herdados dos níveis superiores.')
    }
    return values
  }
  throw new Error('Tipo de política desconhecido.')
}

export function formatPolicyValue(policy) {
  if (!policy) return ''
  if (policy.type === 'boolean') return policy.effectiveValue ? 'Permitido' : 'Bloqueado'
  if (policy.type === 'allowed_set') {
    return Array.isArray(policy.effectiveValue)
      ? policy.effectiveValue.join(', ') || 'Nenhum valor permitido'
      : ''
  }
  return String(policy.effectiveValue)
}
