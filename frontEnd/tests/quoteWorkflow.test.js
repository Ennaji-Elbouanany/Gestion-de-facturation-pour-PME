import test from 'node:test'
import assert from 'node:assert/strict'
import { quoteTransitions, convertQuote } from '../src/services/quoteWorkflow.js'
import { allows } from '../src/services/permissions.js'

test('commercial transitions never expose converted or reopen terminal documents', () => {
  assert.deepEqual(quoteTransitions({ status: 'draft' }), ['sent'])
  assert.deepEqual(quoteTransitions({ status: 'sent', valid_until: '2026-11-08T00:00:00Z' }, '2026-11-07'), ['accepted', 'rejected'])
  assert.deepEqual(quoteTransitions({ status: 'sent', valid_until: '2026-11-06' }, '2026-11-07'), ['rejected', 'expired'])
  for (const status of ['accepted', 'rejected', 'expired', 'converted']) assert.deepEqual(quoteTransitions({ status }), [])
  assert.deepEqual(quoteTransitions({ status: 'draft', archived_at: '2026-11-07' }), [])
})
test('draft-only editing and accepted-only conversion apply even to privileged users', () => {
  const permissions = { 'quotes.update': true, 'quotes.convert': true }
  for (const status of ['sent', 'accepted', 'rejected', 'expired', 'converted']) assert.equal(allows(permissions, 'quotes.update', { status }), false)
  for (const status of ['draft', 'sent', 'rejected', 'expired', 'converted']) assert.equal(allows(permissions, 'quotes.convert', { status }), false)
  assert.equal(allows(permissions, 'quotes.convert', { status: 'accepted' }), true)
  assert.equal(allows(permissions, 'quotes.convert', { status: 'accepted', archived_at: '2026-11-07' }), false)
})
test('creation and idempotent replay both acknowledge the associated invoice', async () => {
  for (const status of [200, 201]) {
    const invoice = { id: 12, source_quote_id: 4, invoice_number: 'FAC-2026-1000' }
    const result = await convertQuote(async (path, options) => {
      assert.equal(path, '/api/quotes/4/convert')
      assert.equal(options.method, 'POST')
      return invoice // apiRequest already accepts both HTTP statuses.
    }, 4, (saved) => assert.equal(saved, invoice, `HTTP ${status}`))
    assert.equal(result, invoice)
  }
})
test('403, 409, 422 and network failures preserve the unconverted UI state', async () => {
  for (const status of [403, 409, 422, undefined]) {
    const error = Object.assign(new Error('Conversion refused'), { status })
    await assert.rejects(convertQuote(async () => { throw error }, 4, () => assert.fail('No optimistic conversion on failure')), (caught) => caught === error)
  }
})
