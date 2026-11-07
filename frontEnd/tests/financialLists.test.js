import test from 'node:test'
import assert from 'node:assert/strict'
import { formatMoney, dashboardView } from '../src/services/financialView.js'
import { latestRequests, listQuery, readPage } from '../src/services/lists.js'

test('financial formatting preserves amounts beyond Number safe precision', () => {
  const text = formatMoney('9007199254740993.01', 'MAD').replace(/[\s\u202f\u00a0]/g, '')
  assert.equal(text, '9007199254740993,01MAD')
  assert.ok(formatMoney('0.30', 'UNKNOWN').includes('devise à vérifier'))
  assert.equal(formatMoney('NaN'), 'Montant à vérifier')
  assert.ok(formatMoney('-0.01').startsWith('−0,01'))
})

test('list requests carry server filters and bounded pagination', () => {
  const params = new URLSearchParams(listQuery({ q: 'a%_ & b', status: 'paid', from: '', client_id: '42' }, 3))
  assert.equal(params.get('per_page'), '25'); assert.equal(params.get('page'), '3')
  assert.equal(params.get('q'), 'a%_ & b'); assert.equal(params.get('client_id'), '42')
  assert.equal(params.has('from'), false)
  assert.throws(() => readPage([]), /invalide/)
})

test('old filters and overlapping reads cannot replace the latest result', () => {
  const requests = latestRequests(); requests.activate('first')
  const old = requests.begin('first'); requests.activate('second')
  assert.equal(requests.current('first', old), false)
  assert.equal(requests.begin('first'), null)
  const one = requests.begin('second'); const two = requests.begin('second')
  assert.equal(requests.current('second', one), false)
  assert.equal(requests.current('second', two), true)
})

test('dashboard retains true zero counts and separates identities/currencies without summing money', () => {
  const response = { selected_currency: 'MAD', quotes: { pending_count: 0, pending_total: '0.00' }, groups: [{ currency: 'MAD', billed_total: '0.30', collected_total: '0.10', overdue_count: 8, invoice_count: 12, series: [], axis_labels: [] }], details: { recent_invoices: [], recent_payments: [], recent_quotes: [], overdue_invoices: [], upcoming_invoices: [], top_clients: [{ client_id: 1, label: 'Same', value: '0.10' }, { client_id: 2, label: 'Same', value: '0.20' }], top_products: [] } }
  const view = dashboardView(response)
  assert.equal(view.pendingQuotesCount, 0)
  assert.equal(view.overdueCount, 8)
  assert.equal(view.totals, '0.30')
  assert.equal(view.topClients.length, 2)
  assert.notEqual(view.topClients[0].client_id, view.topClients[1].client_id)
})
