// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { workQueueItems } from '../../src/financeWorklist.js'

test('only unissued documents and unreviewed bank evidence enter the work queue', () => {
  const drafts = [
    { id: 2, kind:'invoice', status:'draft', number:'D-2', issue_on:'2026-11-22', total_minor:7500 },
    // An issued document in this server-filtered projection has no obligation link.
    { id: 3, kind:'bill', status:'issued', number:'P-3', issue_on:'2026-11-22', total_minor:1000 },
    { id: 4, kind:'bill', status:'draft', number:'P-4', issue_on:'2026-11-22', total_minor:2100 },
  ]
  const bank = [
    { id: 2, status:'pending', booking_date:'2026-11-22', amount_minor:-2100, description:'External payment' },
    { id: 3, status:'matched', booking_date:'2026-11-22', amount_minor:900, description:'Already matched' },
  ]
  const result = workQueueItems(drafts,bank)
  assert.deepEqual(result.map(item=>item.key), ['draft:2','unlinked:3','draft:4','bank:2'])
  assert.deepEqual(result.map(item=>item.amountMinor), [7500,-1000,-2100,-2100])
  assert.equal(result[3].status,'reconcile')
  assert.equal(result[2].status,'confirm')
  assert.equal(result[1].status,'link_review')
  assert.deepEqual(result.map(item=>item.label), ['sales_document','historic_unlinked','purchase_document','bank_outflow'])
  // A bank statement and a commercial document can share an ID and value,
  // yet neither is another ledger entry or a duplicate debt.
  assert.equal(new Set(result.map(item=>item.key)).size,4)
})
test('queue ignores malformed rows and never invents a cash movement', () => {
  assert.deepEqual(workQueueItems([
    {id:4,kind:'invoice',status:'draft',total_minor:Infinity},
    {id:0,kind:'invoice',status:'draft',total_minor:1000},
    {id:7,kind:'other',status:'draft',total_minor:3000},
    {id:null,kind:'invoice',status:'draft',total_minor:1000},
    {id:true,kind:'invoice',status:'draft',total_minor:1000},
    {id:'',kind:'invoice',status:'draft',total_minor:1000},
    {id:'1',kind:'invoice',status:'draft',total_minor:1000},
    {id:1,kind:'invoice',status:'draft',total_minor:null},
    {id:1,kind:'invoice',status:'draft',total_minor:true},
    {id:1,kind:'invoice',status:'draft',total_minor:''},
    {id:undefined,kind:'invoice',status:'draft',total_minor:1000},
    {id:1,kind:'invoice',status:'draft',total_minor:undefined},
    {id:1.5,kind:'invoice',status:'draft',total_minor:1000},
    {id:1,kind:'invoice',status:'draft',total_minor:1000.5},
    null,
    undefined,
    {}
  ],[
    {id:1,status:'pending',amount_minor:NaN},
    {id:2,status:'matched',amount_minor:4500},
    {id:null,status:'pending',amount_minor:1000},
    {id:true,status:'pending',amount_minor:1000},
    {id:'',status:'pending',amount_minor:1000},
    {id:'1',status:'pending',amount_minor:1000},
    {id:1,status:'pending',amount_minor:null},
    {id:1,status:'pending',amount_minor:true},
    {id:1,status:'pending',amount_minor:''},
    {id:undefined,status:'pending',amount_minor:1000},
    {id:1,status:'pending',amount_minor:undefined},
    {id:1.5,status:'pending',amount_minor:1000},
    {id:1,status:'pending',amount_minor:1000.5},
    null,
    undefined,
    {}
  ]),[])
  // Positive control: zero-valued draft remains accepted, signed negative and positive bank amounts remain accepted
  const positiveResult = workQueueItems(
    [{id:5,kind:'invoice',status:'draft',number:'D-5',issue_on:'2026-11-22',total_minor:0}],
    [
      {id:5,status:'pending',booking_date:'2026-11-22',amount_minor:-500,description:'Negative outflow'},
      {id:6,status:'pending',booking_date:'2026-11-22',amount_minor:500,description:'Positive inflow'}
    ]
  )
  assert.equal(positiveResult.length, 3)
  assert.deepEqual(positiveResult.map(item => item.amountMinor), [0, -500, 500])
})
