import test from 'node:test'
import assert from 'node:assert/strict'
import { paymentDeadlineState, rebasePaymentCorrection, savePayment } from '../src/services/paymentWorkflow.js'

test('confirmed payment is acknowledged before a failed refresh, without retrying the mutation', async () => {
  for (const method of ['POST', 'PUT']) {
    const events = []
    const payment = { id: 1, version: 2 }
    const result = await savePayment({
      method, path: '/payments', form: { version: 1, reason: 'Correction' },
      request: async (_path, options) => { events.push(options.method); return payment },
      onSaved: (saved) => { assert.equal(saved.version, 2); events.push('saved') },
      refresh: async () => { events.push('refresh'); throw new Error('Network unavailable') },
    })
    assert.deepEqual(events, [method, 'saved', 'refresh'])
    assert.equal(result.payment, payment)
    assert.equal(result.refreshError.message, 'Network unavailable')
  }
})
test('mutation failures preserve the input and do not acknowledge success or refresh', async () => {
  for (const status of [403, 409, 422]) {
    const form = { amount: '25.00', reason: 'Keep my reason', version: 1 }
    const before = { ...form }
    const error = Object.assign(new Error('Rejected'), { status })
    await assert.rejects(savePayment({
      path: '/payments', method: 'PUT', form,
      request: async () => { throw error },
      onSaved: () => assert.fail('No success on rejection'),
      refresh: () => assert.fail('No refresh on rejection'),
    }), (caught) => caught === error)
    assert.deepEqual(form, before)
  }
})
test('successful refresh completes after acknowledgement', async () => {
  const events = []
  const result = await savePayment({ request: async () => ({ id: 1 }), path: '/', method: 'POST', form: {},
    onSaved: () => events.push('saved'), refresh: async () => events.push('refreshed') })
  assert.deepEqual(events, ['saved', 'refreshed'])
  assert.equal(result.refreshError, null)
})
test('deadline badges accept real Laravel ISO dates and date-only values', () => {
  const now = new Date(2026, 10, 6, 12)
  for (const suffix of ['', 'T00:00:00.000000Z']) {
    assert.equal(paymentDeadlineState({ due_date: `2026-11-05${suffix}` }, now), 'overdue')
    assert.equal(paymentDeadlineState({ due_date: `2026-11-06${suffix}` }, now), 'due-soon')
    assert.equal(paymentDeadlineState({ due_date: `2026-11-09${suffix}` }, now), 'due-soon')
    assert.equal(paymentDeadlineState({ due_date: `2026-11-10${suffix}` }, now), null)
  }
})
test('invalid dates, paid and cancelled invoices have no deadline badge', () => {
  const now = new Date(2026, 10, 6, 12)
  for (const due_date of [null, '', 'invalid', '2026-02-30']) assert.equal(paymentDeadlineState({ due_date }, now), null)
  for (const status of ['paid', 'cancelled']) assert.equal(paymentDeadlineState({ status, due_date: '2026-11-05' }, now), null)
})

test('explicit conflict reload updates only the version and preserves the correction draft', () => {
  const draft = { amount: '25.00', reason: 'Keep my reason', payment_date: '2026-11-06', version: 1 }
  const latest = { amount: '40.00', payment_date: '2026-11-07', version: 3 }
  assert.deepEqual(rebasePaymentCorrection(draft, latest), { ...draft, version: 3 })
  assert.equal(draft.version, 1)
})
