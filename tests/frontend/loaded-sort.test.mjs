// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { sortLoadedRows } from '../../src/sort.js'

test('sorts Brazilian labels naturally without mutating financial records', () => {
  const rows = [{name:'Conta 10'}, {name:'Conta 2'}, {name:'Água'}]
  assert.deepEqual(sortLoadedRows(rows,'name').map(x=>x.name), ['Água','Conta 2','Conta 10'])
  assert.equal(rows[0].name,'Conta 10')
})
test('sorts minor-unit money as integers, never formatted strings', () => {
  const rows = [{amount_minor:1000},{amount_minor:-100},{amount_minor:99}]
  assert.deepEqual(sortLoadedRows(rows,'amount_minor','desc').map(x=>x.amount_minor),
    [1000,99,-100])
})
test('keeps missing values at the end and equal keys stable', () => {
  const rows = [{id:1,k:null},{id:2,k:'A'},{id:3,k:'a'},{id:4,k:undefined}]
  assert.deepEqual(sortLoadedRows(rows,'k','desc').map(x=>x.id),[2,3,1,4])
})
test('can order by safe derived display fields while preserving the page scope', () => {
  const rows = [{id:1,kind:'liability',balance_minor:-100},{id:2,kind:'asset',balance_minor:150}]
  const result = sortLoadedRows(rows,'balance','asc',
    {balance:row=>row.kind==='liability'?-row.balance_minor:row.balance_minor})
  assert.deepEqual(result.map(x=>x.id),[1,2])
})
