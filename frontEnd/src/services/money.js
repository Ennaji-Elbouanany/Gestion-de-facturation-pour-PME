const MAX_CENTS = 999999999999n
const invalid = (error) => ({ subtotal: null, tax: null, total: null, error })
function scaled(value) {
  const text = String(value ?? '0') || '0'
  if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(text)) return null
  const [whole, fraction = ''] = text.split('.')
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
}
function amount(cents) { return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}` }
export function lineTotals(item) {
  const quantity = scaled(item.quantity)
  const price = scaled(item.unit_price)
  const rate = scaled(item.tax_rate)
  if (quantity === null || price === null || rate === null) return invalid('Saisissez des montants valides avec deux décimales au maximum.')
  if (quantity <= 0n || rate > 10000n) return invalid('La quantité doit être positive et la TVA comprise entre 0 et 100 %.')
  const subtotal = (quantity * price + 50n) / 100n
  const tax = (subtotal * rate + 5000n) / 10000n
  if ([subtotal, tax, subtotal + tax].some((value) => value > MAX_CENTS)) return invalid('Le montant dépasse la limite de 9 999 999 999,99.')
  return { subtotal: amount(subtotal), tax: amount(tax), total: amount(subtotal + tax) }
}
export function documentTotals(items) {
  const totals = items.map(lineTotals)
  const error = totals.find((line) => line.error)?.error
  if (error) return invalid(error)
  const sums = Object.fromEntries(['subtotal', 'tax', 'total'].map((key) => [key, totals.reduce((sum, line) => sum + scaled(line[key]), 0n)]))
  if (Object.values(sums).some((value) => value > MAX_CENTS)) return invalid('Le total dépasse la limite de 9 999 999 999,99.')
  return Object.fromEntries(Object.entries(sums).map(([key, value]) => [key, amount(value)]))
}
