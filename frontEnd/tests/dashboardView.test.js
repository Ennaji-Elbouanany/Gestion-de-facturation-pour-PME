import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dashboardView } from '../src/services/financialView.js'

const overviewSource = readFileSync(new URL('../src/pages/dashboard/Overview.jsx', import.meta.url), 'utf8')

function responseWithDeadlines(overdue = [], upcoming = []) {
  return {
    groups: [{ currency: 'MAD', series: [], axis_labels: [] }],
    selected_currency: 'MAD',
    quotes: { pending_count: 0, pending_total: null },
    details: {
      recent_invoices: [],
      recent_payments: [],
      recent_quotes: [],
      recent_clients: [],
      overdue_invoices: overdue,
      upcoming_invoices: upcoming,
      top_clients: [],
      top_products: [],
    },
  }
}

test('dashboard maps overdue and upcoming invoice lists used by Overview', () => {
  const overdue = { id: 10, invoice_number: 'FAC-10', client_id: 1, client_name: 'Client', balance_due: '30.00' }
  const upcoming = { id: 11, invoice_number: 'FAC-11', client_id: 2, client_name: 'Client 2', balance_due: '20.00' }
  const stats = dashboardView(responseWithDeadlines([overdue], [upcoming]))
  assert.deepEqual(stats.overdueInvoices.map((item) => item.id), [10])
  assert.deepEqual(stats.upcomingList.map((item) => item.id), [11])
  assert.equal(stats.remainingBy[10], '30.00')
  assert.equal(stats.remainingBy[11], '20.00')
  assert.match(overviewSource, /stats\.overdueInvoices\.length/)
  assert.doesNotMatch(overviewSource, /stats\.overdueList\b/)
})

test('dashboard maps empty deadlines to empty arrays, not undefined', () => {
  const stats = dashboardView(responseWithDeadlines())
  assert.deepEqual(stats.overdueInvoices, [])
  assert.deepEqual(stats.upcomingList, [])
  assert.deepEqual(stats.recentPayments, [])
  assert.deepEqual(stats.recentQuotes, [])
})
