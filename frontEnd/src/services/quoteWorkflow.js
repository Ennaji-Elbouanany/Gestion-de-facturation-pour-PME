export function quoteTransitions(quote, today = new Date().toISOString().slice(0, 10)) {
  if (quote.archived_at) return []
  if (quote.status === 'draft') return ['sent']
  if (quote.status !== 'sent') return []
  const expired = quote.valid_until && quote.valid_until.slice(0, 10) < today
  return expired ? ['rejected', 'expired'] : ['accepted', 'rejected']
}

export async function convertQuote(request, quoteId, onConverted) {
  // 200 replay and 201 creation share the same invoice representation.
  const invoice = await request(`/api/quotes/${quoteId}/convert`, { method: 'POST' })
  onConverted(invoice)
  return invoice
}
