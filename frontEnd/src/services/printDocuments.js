import { formatMoney } from './financialView'
import { apiRequest } from './api'

export function escapeDocumentHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c])
}

const text = (value) => escapeDocumentHtml(value === null || value === undefined || value === '' ? '—' : value)
const money = (value, currency) => text(value == null ? 'À vérifier' : formatMoney(value, currency))
function date(value) {
  if (!value) return '—'
  const iso = String(value).slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '—'
  const d = new Date(iso + 'T12:00:00Z')
  return Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== iso
    ? '—' : d.toLocaleDateString('fr-FR', { timeZone: 'UTC', day: '2-digit', month: 'long', year: 'numeric' })
}

function contacts(record) {
  return [record?.address, [record?.city, record?.country].filter(Boolean).join(', '), record?.phone, record?.email]
    .filter(Boolean).map((v) => '<div>' + text(v) + '</div>').join('')
}

function safeLogo(url) {
  if (!url) return ''
  try {
    const parsed = new URL(url)
    return parsed.protocol === 'https:' ? parsed.href : ''
  } catch {
    return ''
  }
}

export function buildPrintableDocument(kind, document, companySettings) {
  if (!['invoice', 'quote'].includes(kind)) throw new Error('Type de document inconnu.')
  if (!document || !Array.isArray(document.items)) throw new Error('Lignes du document indisponibles.')
  const invoice = kind === 'invoice'
  const number = document[invoice ? 'invoice_number' : 'quote_number']
  if (!number) throw new Error('Référence du document indisponible.')
  const title = invoice ? 'FACTURE' : 'DEVIS'
  const issued = invoice && document.document_status !== 'draft'
  const snapshot = issued && document.issued_snapshot?.source === 'at_issuance' ? document.issued_snapshot : null
  const company = snapshot?.company || companySettings || {}
  const client = snapshot?.client || document.client || {}
  const rows = snapshot?.items || document.items
  if (!Array.isArray(rows)) throw new Error('Lignes historiques indisponibles.')
  const currency = snapshot?.currency || (issued ? null : companySettings?.currency)
  const amount = (key) => snapshot && Object.hasOwn(snapshot, key) ? snapshot[key] : document[key]
  const logo = safeLogo(company.logo)
  const status = invoice
    ? (document.document_status === 'draft' ? 'BROUILLON' : document.document_status === 'cancelled' ? 'ANNULÉE' : 'ÉMISE')
    : document.status
  const docDate = snapshot?.invoice_date || document[invoice ? 'invoice_date' : 'quote_date']
  const deadline = snapshot?.due_date || document[invoice ? 'due_date' : 'valid_until']
  const legacy = issued && !snapshot
  const financialProblems = Array.isArray(document.financial_issues) ? document.financial_issues : []
  const caution = legacy || document.financial_review_required
    ? '<section class="warning"><strong>Vérification nécessaire.</strong> ' +
      (legacy ? 'Cette facture historique ne possède pas d’instantané d’émission vérifié. Les coordonnées ou la devise peuvent avoir changé.' : 'Anomalies financières à vérifier avant transmission.') +
      ' ' + financialProblems.map(text).join(' · ') + '</section>' : ''
  const lines = rows.map((row) => '<tr><td>' + text(row.description) + '</td><td class="right">' +
    text(row.quantity) + '</td><td class="right">' + money(row.unit_price, currency) +
    '</td><td class="right">' + text(row.tax_rate) + ' %</td><td class="right">' +
    money(row.total, currency) + '</td></tr>').join('')
  const totalRows = [['Sous-total HT', amount('subtotal')], ['Remise', amount('discount_amount')],
    ['TVA', amount('tax_amount')], ['TOTAL TTC', amount('total_amount')]]
    .map(([label, value], index) => '<div class="total-row' + (index === 3 ? ' grand' : '') +
      '"><span>' + label + '</span><strong>' + money(value, currency) + '</strong></div>').join('')
  const pay = invoice && document.document_status === 'issued' && document.payment_status
    ? '<section class="payments">Règlements : ' + text(document.payment_status) +
      (document.paid_amount != null ? ' — Payé : ' + money(document.paid_amount, currency) : '') +
      (document.balance_due != null ? ' — Solde : ' + money(document.balance_due, currency) : '') + '</section>' : ''
  return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' + text(title + ' ' + number) + '</title>' +
    '<style>' +
    '@page{size:A4;margin:16mm 14mm}*{box-sizing:border-box}body{font:12px/1.5 Arial,sans-serif;color:#17352e;margin:0}' +
    '.actions{display:flex;justify-content:end;padding:12px;background:#eef5f2;margin:-16mm -14mm 24px}' +
    'button{border:0;background:#116b58;color:white;padding:12px;cursor:pointer}' +
    'header{display:flex;justify-content:space-between;border-bottom:2px solid #116b58;padding-bottom:20px;gap:20px}' +
    'h1{font-size:26px;color:#116b58;margin:0}h2{font-size:13px;margin:0 0 5px}' +
    '.brand{max-width:50%}.meta{text-align:right}.logo{max-height:65px;max-width:150px;display:block;margin-bottom:8px}' +
    '.contacts{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:25px 0}' +
    'table{width:100%;border-collapse:collapse;margin:25px 0}th{background:#e9f3ef;text-align:left;padding:9px;border-bottom:1px solid #adc8bd}' +
    'td{padding:9px;border-bottom:1px solid #e5ebe9;vertical-align:top}.right{text-align:right}' +
    'tr{break-inside:avoid;page-break-inside:avoid}thead{display:table-header-group}' +
    '.totals{width:48%;margin-left:auto;break-inside:avoid}.total-row{display:flex;justify-content:space-between;padding:7px 0;gap:12px}' +
    '.grand{font-size:16px;border-top:2px solid #116b58}.warning{padding:10px;border:2px solid #bc8422;background:#fff7df;margin:20px 0}' +
    '.notes{margin-top:26px;white-space:pre-wrap;overflow-wrap:anywhere}footer{margin-top:36px;border-top:1px solid #d0dcd5;padding-top:12px;white-space:pre-wrap}' +
    '@media print{.actions{display:none}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}' +
    '</style></head><body><div class="actions"><button onclick="window.print()">Imprimer / Enregistrer en PDF</button></div>' +
    '<header><div class="brand">' + (logo ? '<img class="logo" src="' + text(logo) + '" alt="Logo">' : '') +
    '<h2>' + text(company.name || 'Entreprise') + '</h2>' + contacts(company) + '</div><div class="meta">' +
    '<h1>' + title + '</h1><strong>' + text(number) + '</strong><div>' + text(date(docDate)) + '</div><div>' +
    (invoice ? 'Échéance' : 'Valable jusqu’au') + ' : ' + text(date(deadline)) + '</div><div>' + text(status) +
    '</div></div></header><div class="contacts"><section><h2>Émetteur</h2>' +
    contacts(company) + (company.ice ? '<div>ICE : ' + text(company.ice) + '</div>' : '') +
    (company.tax_number ? '<div>Identifiant fiscal : ' + text(company.tax_number) + '</div>' : '') +
    '</section><section><h2>Client</h2><strong>' + text(client.name) + '</strong>' + contacts(client) + '</section></div>' +
    caution + '<table><thead><tr><th>Description</th><th class="right">Quantité</th><th class="right">Prix unitaire</th>' +
    '<th class="right">TVA</th><th class="right">Montant TTC</th></tr></thead><tbody>' +
    (lines || '<tr><td colspan="5">Aucune ligne</td></tr>') + '</tbody></table>' +
    '<section class="totals">' + totalRows + '</section>' + pay +
    (document.notes ? '<section class="notes"><h2>Notes</h2>' + text(snapshot?.notes ?? document.notes) + '</section>' : '') +
    '<footer>' + (company.invoice_footer ? '<p>' + text(company.invoice_footer) + '</p>' : '') +
    'Document en lecture seule, préparé pour impression A4.</footer></body></html>'
}

export async function printCommercialDocument(kind, id) {
  // User click: open the tab before awaiting any requests to avoid popup blocking.
  const popup = window.open('', '_blank')
  if (!popup) throw new Error('Autorisez les fenêtres pop-up pour imprimer le document.')
  popup.document.write('<!doctype html><html lang="fr"><meta charset="utf-8"><title>Préparation…</title><p>Préparation du document…</p></html>')
  popup.document.close()
  try {
    if (!['invoice', 'quote'].includes(kind) || !Number.isSafeInteger(Number(id)) || Number(id) < 1) {
      throw new Error('Référence de document invalide.')
    }
    const resource = kind === 'invoice' ? 'invoices' : 'quotes'
    const [document, settings] = await Promise.all([
      apiRequest('/api/' + resource + '/' + id + (kind === 'invoice' ? '?include_payments=0' : '')),
      apiRequest('/api/settings'),
    ])
    if (popup.closed) return
    const html = buildPrintableDocument(kind, document, settings.company)
    popup.document.open()
    popup.document.write(html)
    popup.document.close()
    popup.focus()
  } catch (error) {
    if (!popup.closed) popup.close()
    throw error
  }
}
