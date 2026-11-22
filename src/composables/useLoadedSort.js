// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { ref } from 'vue'
import { sortLoadedRows } from '../sort.js'

export function useLoadedSort(initialColumn = '', initialDirection = 'asc') {
  const column = ref(initialColumn)
  const direction = ref(initialDirection)
  function sort(next) {
    if (column.value === next) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
    else { column.value = next; direction.value = 'asc' }
  }
  function ariaSort(key) {
    return column.value === key
      ? (direction.value === 'asc' ? 'ascending' : 'descending') : 'none'
  }
  function order(rows, accessors = {}) {
    return sortLoadedRows(rows, column.value, direction.value, accessors)
  }
  return { column, direction, sort, ariaSort, order }
}
