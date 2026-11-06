// A confirmed mutation must be acknowledged before any fallible follow-up read.
export async function savePayment({ request, path, method, form, onSaved, refresh }) {
  const payment = await request(path, { method, body: JSON.stringify(form) })
  onSaved(payment)
  try {
    await refresh()
    return { payment, refreshError: null }
  } catch (refreshError) {
    return { payment, refreshError }
  }
}

export function paymentDeadlineState(invoice, now = new Date()) {
  if (!invoice.due_date || ['paid', 'cancelled'].includes(invoice.status)) return null
  const date = String(invoice.due_date).slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null
  const due = new Date(`${date}T00:00:00Z`)
  if (Number.isNaN(due.getTime()) || due.toISOString().slice(0, 10) !== date) return null
  // Compare calendar days; local DST changes must not change the day count.
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())
  const days = (due.getTime() - today) / 86400000
  return days < 0 ? 'overdue' : days <= 3 ? 'due-soon' : null
}

export function rebasePaymentCorrection(form, latest) {
  return { ...form, version: latest.version }
}
