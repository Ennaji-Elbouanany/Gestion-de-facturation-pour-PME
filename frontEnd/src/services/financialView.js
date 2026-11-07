export function formatMoney(value, currency) {
  if (value === null) return 'Non consolidable'
  const text = String(value ?? '0.00')
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(text)) return 'Montant à vérifier'
  const [whole, decimal = ''] = text.split('.')
  const integer = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(BigInt(whole))
  const negativeZero = text.startsWith('-') && BigInt(whole) === 0n ? '−' : ''
  const suffix = currency === 'UNKNOWN' ? 'devise à vérifier' : currency
  return `${negativeZero}${integer},${decimal.padEnd(2, '0')}${suffix ? ` ${suffix}` : ''}`
}

export function dashboardView(response) {
  const group = response.groups.find((item) => item.currency === response.selected_currency) || {}
  const details = response.details
  const invoice = (row) => ({ ...row, client: { id: row.client_id, name: row.client_name } })
  const quote = (row) => ({ ...row, client: { name: row.client_name } })
  const count = (key) => group[key] ?? 0
  const money = (key) => Object.hasOwn(group, key) ? group[key] : '0.00'
  const recentInvoices = details.recent_invoices.map(invoice)
  const recentPayments = details.recent_payments
  const recentQuotes = details.recent_quotes.map(quote)
  const events = [
    ...recentInvoices.map((row) => ({ at: row.created_at || row.invoice_date, icon: '＋', kind: 'invoice', label: 'Facture créée', detail: row.invoice_number, value: null })),
    ...recentPayments.map((row) => ({ at: row.created_at || row.payment_date, icon: '✓', kind: 'payment', label: 'Paiement reçu', detail: row.invoice_number, value: row.amount })),
    ...recentQuotes.map((row) => ({ at: row.created_at || row.quote_date, icon: '◫', kind: 'quote', label: 'Devis créé', detail: row.quote_number, value: null })),
    ...(details.recent_clients || []).map((row) => ({ at: row.created_at, icon: '♙', kind: 'client', label: 'Nouveau client', detail: row.name, value: null })),
  ].sort((a, b) => String(b.at || '').localeCompare(String(a.at || ''))).slice(0, 8)
  return {
    group, invoices: recentInvoices, quotes: recentQuotes, payments: recentPayments,
    totals: money('billed_total'), totalCollected: money('collected_total'), totalOutstanding: money('outstanding_total'),
    outstandingCount: count('outstanding_count'), overdueCount: count('overdue_count'), overdueAmount: money('overdue_total'),
    nonCancelledCount: count('issued_count'), invoiceCount: count('invoice_count'), paymentCount: count('payment_count'),
    recoveryRate: group.recovery_rate ?? '—', billedPct: group.billed_change_pct, collectedPct: group.collected_change_pct,
    pendingQuotesCount: response.quotes.pending_count, pendingQuotesAmount: response.quotes.pending_total,
    overdueInvoices: details.overdue_invoices.map(invoice), upcomingList: details.upcoming_invoices.map(invoice),
    remainingBy: Object.fromEntries([...details.overdue_invoices, ...details.upcoming_invoices].map((row) => [row.id, row.balance_due])),
    latestInvoices: recentInvoices, recentPayments, recentQuotes, events,
    topClients: details.top_clients.map((row) => ({ ...row, sub: `${row.count} facture(s)` })),
    topProducts: details.top_products.map((row) => ({ ...row, label: row.description, sub: `${row.quantity} unité(s) · lignes avant remise globale` })),
    series: (group.series || []).map((row) => ({ key: row.month, label: new Date(`${row.month}-01T12:00:00Z`).toLocaleDateString('fr-FR', { month: 'short', timeZone: 'UTC' }), full: row.month, a: row.billed, b: row.collected, a_plot: row.billed_plot, b_plot: row.collected_plot })),
    axisLabels: group.axis_labels || ['0.00','0.00','0.00','0.00','0.00'],
  }
}
