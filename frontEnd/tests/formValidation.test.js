import test from 'node:test'
import assert from 'node:assert/strict'
import { createSubmissionGuard, fieldProps, validateProduct } from '../src/services/formValidation.js'

test('product decimal boundaries and optional tax match the API contract', () => {
  assert.deepEqual(validateProduct({ unit_price: '9999999999.99', tax_rate: '100.00' }), {})
  assert.deepEqual(validateProduct({ unit_price: '0', tax_rate: '' }), {})
  for (const value of ['-1', '1.001', '1e2', '10000000000', 'NaN', '']) {
    assert.ok(validateProduct({ unit_price: value, tax_rate: '20' }).unit_price, value)
  }
  assert.ok(validateProduct({ unit_price: '10', tax_rate: '100.01' }).tax_rate)
})

test('field errors are linked to accessible descriptions', () => {
  assert.deepEqual(fieldProps('email', { email: ['Invalide'] }, 'client'), { 'aria-invalid': true, 'aria-describedby': 'client-email-error' })
  assert.equal(fieldProps('email', {}, 'client')['aria-invalid'], false)
})

test('submission guard suppresses overlapping writes and permits later retry', async () => {
  const run = createSubmissionGuard()
  let complete
  let writes = 0
  const pending = run(async () => { writes++; await new Promise((resolve) => { complete = resolve }) })
  await run(async () => { writes++ })
  assert.equal(writes, 1)
  complete()
  await pending
  await run(async () => { writes++ })
  assert.equal(writes, 2)
  await assert.rejects(run(async () => { throw new Error('network') }))
  await run(async () => { writes++ })
  assert.equal(writes, 3)
})
