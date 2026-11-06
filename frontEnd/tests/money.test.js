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
