import test from 'node:test'
import assert from 'node:assert/strict'
import { documentTotals, lineTotals } from '../src/services/money.js'

test('line previews round HT and tax separately in exact cents', () => {
  assert.deepEqual(lineTotals({ quantity: '0.50', unit_price: '0.03', tax_rate: '20' }), { subtotal: '0.02', tax: '0.00', total: '0.02' })
  assert.deepEqual(lineTotals({ quantity: '1', unit_price: '0.05', tax_rate: '10' }), { subtotal: '0.05', tax: '0.01', total: '0.06' })
})
test('cent sums do not use binary float arithmetic', () => {
  const items = [{ quantity: '1', unit_price: '0.10' }, { quantity: '1', unit_price: '0.20' }]
  assert.equal(documentTotals(items).total, '0.30')
})

test('multiplication overflow is an explicit error rather than a zero document total', () => {
  const item = { quantity: '2', unit_price: '9999999999.99', tax_rate: '0' }
  assert.equal(lineTotals(item).total, null)
  assert.ok(lineTotals(item).error)
  assert.equal(documentTotals([item]).total, null)
})
test('document overflow is rejected even when each line is within bounds', () => {
  const items = Array.from({ length: 2 }, () => ({ quantity: '1', unit_price: '6000000000' }))
  assert.equal(lineTotals(items[0]).total, '6000000000.00')
  assert.equal(documentTotals(items).total, null)
  assert.ok(documentTotals(items).error)
})
test('invalid inputs are not silently interpreted as zero and valid limits stay exact', () => {
  for (const value of ['-1', 'abc', '1.001', '10000000000']) {
    assert.ok(lineTotals({ quantity: '1', unit_price: value }).error)
  }
  assert.equal(documentTotals([{ quantity: '1', unit_price: '9999999999.99' }]).total, '9999999999.99')
  assert.equal(lineTotals({ quantity: '1', unit_price: '', tax_rate: '' }).total, '0.00')
})
