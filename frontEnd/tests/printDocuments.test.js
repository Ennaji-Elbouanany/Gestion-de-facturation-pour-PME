import test from 'node:test'
import assert from 'node:assert/strict'
import { buildPrintableDocument, escapeDocumentHtml } from '../src/services/printDocuments.js'

const company = { name: 'Société exemple', city: 'Marrakech', currency: 'MAD', ice: '123456789', invoice_footer: 'Merci' }
const item = { description: 'Développement web', quantity: '2.00', unit_price: '100.00', tax_rate: '20.00', total: '240.00' }
const invoice = {
  id: 1, invoice_number: 'FAC-001', invoice_date: '2026-11-08', due_date: '2026-11-30',
  document_status: 'draft', client: { name: 'Client exemple' }, items: [item],
  subtotal: '200.00', tax_amount: '40.00', discount_amount: '0.00', total_amount: '240.00', notes: 'Livraison',
}
const quote = {
  id: 1, quote_number: 'DEV-001', quote_date: '2026-11-08', valid_until: '2026-11-30',
  status: 'sent', client: { name: 'Client exemple' }, items: [item],
  subtotal: '200.00', tax_amount: '40.00', discount_amount: '0.00', total_amount: '240.00',
}

test('escape all dynamic document fields to avoid HTML/script injection', () => {
  assert.equal(escapeDocumentHtml('<script>"&'), '&lt;script&gt;&quot;&amp;')
  const malicious = { ...invoice, client: { name: '<img src=x onerror=alert(1)>' }, notes: '<script>alert(1)</script>' }
  const html = buildPrintableDocument('invoice', malicious, company)
  assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'))
  assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'))
  assert.ok(!html.includes('<script>alert(1)</script>'))
})

test('draft invoices have an A4 layout, values and Save as PDF print button', () => {
  const html = buildPrintableDocument('invoice', invoice, company)
  assert.match(html, /@page\{size:A4/)
  assert.match(html, /FAC-001/)
  assert.match(html, /Client exemple/)
  assert.match(html, /240,00 MAD/)
  assert.match(html, /Enregistrer en PDF/)
  assert.match(html, /BROUILLON/)
})

test('sent quotes render without invoice-specific payment information', () => {
  const html = buildPrintableDocument('quote', quote, company)
  assert.match(html, /DEV-001/)
  assert.match(html, /Valable jusqu/)
  assert.doesNotMatch(html, /Règlements :/)
})

test('issued invoices retain company, client, item and currency from issuance snapshot', () => {
  const issued = {
    ...invoice, document_status: 'issued', client: { name: 'Client actuel' },
    issued_snapshot: {
      source: 'at_issuance', company: { name: 'Ancienne entreprise', currency: 'MAD' },
      client: { name: 'Client historique' }, currency: 'EUR',
      items: [{ ...item, description: 'Prestation historique' }],
      invoice_date: '2026-11-01', due_date: '2026-11-29', subtotal: '200.00',
      tax_amount: '40.00', discount_amount: '0.00', total_amount: '240.00', notes: 'Conditions figées',
    },
  }
  const html = buildPrintableDocument('invoice', issued, { ...company, name: 'Entreprise actuelle', currency: 'USD' })
  assert.match(html, /Ancienne entreprise/)
  assert.match(html, /Client historique/)
  assert.match(html, /Prestation historique/)
  assert.match(html, /240,00 EUR/)
  assert.doesNotMatch(html, /Entreprise actuelle/)
  assert.doesNotMatch(html, /Client actuel/)
  assert.doesNotMatch(html, /240,00 USD/)
})

test('legacy issued invoices warn instead of claiming exact historical fidelity', () => {
  const html = buildPrintableDocument('invoice', { ...invoice, document_status: 'issued' }, company)
  assert.match(html, /Vérification nécessaire/)
  assert.match(html, /instantané d’émission/)
  assert.doesNotMatch(html, /240,00 MAD/)
})

test('missing document totals are never rendered as zero', () => {
  const html = buildPrintableDocument('quote', { ...quote, total_amount: null }, company)
  assert.match(html, /À vérifier/)
})

test('malformed document data is refused', () => {
  assert.throws(() => buildPrintableDocument('invoice', { ...invoice, items: null }, company), /Lignes/)
  assert.throws(() => buildPrintableDocument('unknown', invoice, company), /Type/)
  assert.throws(() => buildPrintableDocument('quote', { ...quote, quote_number: '' }, company), /Référence/)
})

test('unsafe logo URLs are not inserted in the printable HTML', () => {
  const html = buildPrintableDocument('quote', quote, { ...company, logo: 'javascript:alert(1)' })
  assert.doesNotMatch(html, /src="javascript:/)
})
