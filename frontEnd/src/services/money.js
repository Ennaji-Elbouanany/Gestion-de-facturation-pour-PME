function scaled(value) {
  const text = String(value ?? '0')
  if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(text)) return 0n
  const [whole, fraction = ''] = text.split('.')
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
}
function amount(cents) { return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}` }
export function lineTotals(item) {
  const subtotal = (scaled(item.quantity) * scaled(item.unit_price) + 50n) / 100n
  const tax = (subtotal * scaled(item.tax_rate) + 5000n) / 10000n
  return { subtotal: amount(subtotal), tax: amount(tax), total: amount(subtotal + tax) }
}
export function documentTotals(items) {
  const totals = items.map(lineTotals)
  return Object.fromEntries(['subtotal', 'tax', 'total'].map((key) => [key, amount(totals.reduce((sum, line) => sum + scaled(line[key]), 0n))]))
}
