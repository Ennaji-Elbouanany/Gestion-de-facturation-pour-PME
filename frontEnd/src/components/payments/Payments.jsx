import { useCallback, useEffect, useState } from 'react'
import { apiRequest } from '../../services/api'
import { useServerList } from '../../hooks/useServerList'
import { formatMoney } from '../../services/financialView'
import ListControls from '../shared/ListControls'
import './Payments.css'
import './PaymentsPage.css'

const methods = { cash: 'Espèces', bank_transfer: 'Virement bancaire', cheque: 'Chèque', credit_card: 'Carte bancaire', digital_wallet: 'Portefeuille numérique', other: 'Autre' }
const date = (value) => value ? new Date(value).toLocaleDateString('fr-FR', { timeZone: 'UTC' }) : '—'
export default function Payments() {
  const list = useServerList('/api/payments')
  const [metrics, setMetrics] = useState(null)
  const [error, setError] = useState('')
  const loadMetrics = useCallback(async () => {
    try { setMetrics(await apiRequest('/api/dashboard/financial')); setError('') }
    catch (failure) { setError(failure.message) }
  }, [])
  useEffect(() => { Promise.resolve().then(loadMetrics) }, [loadMetrics])
  return <section className="payments-page">
    <div className="payments-heading"><div><p className="dashboard-kicker">Trésorerie</p><h1>Paiements</h1><p className="payments-subtitle">Règlements conservés et corrigés, une seule fois par paiement.</p></div><button type="button" className="refresh-button" disabled={list.loading} onClick={() => { list.load(); loadMetrics() }}>Actualiser</button></div>
    {error && <p role="alert">{error} <button type="button" onClick={loadMetrics}>Réessayer les indicateurs</button></p>}
    {metrics?.groups.map((group) => <section key={group.currency} aria-label={`Indicateurs ${group.currency}`}>
      <h2>{group.currency === 'UNKNOWN' ? 'Devise historique à vérifier' : group.currency}</h2>
      {group.requires_review && <p role="alert">Des documents de cette devise nécessitent une revue.</p>}
      <div className="pay-kpi-grid">
        <article className="pay-kpi"><span>Encaissé au total</span><strong>{formatMoney(group.collected_total, group.currency)}</strong><small>{group.payment_count} paiement(s)</small></article>
        <article className="pay-kpi"><span>Encaissé ce mois</span><strong>{formatMoney(group.consolidated === false ? null : (group.series.find((row) => row.month === metrics.as_of_date.slice(0, 7))?.collected || '0.00'), group.currency)}</strong></article>
        <article className="pay-kpi"><span>Montant moyen</span><strong>{formatMoney(group.collection_average, group.currency)}</strong></article>
      </div>
      <div className="pay-method-chips" aria-label="Répartition par mode de paiement">{group.methods.map((row) => <span className="pay-chip" key={row.method}>{methods[row.method] || row.method} : {formatMoney(row.amount, group.currency)}</span>)}</div>
    </section>)}
    <section className="payments-panel">
      <h2>Historique des règlements</h2>
      <ListControls list={list} clients />
      <label>Mode de paiement<select value={list.filters.payment_method || ''} onChange={(event) => list.setFilter('payment_method', event.target.value)}><option value="">Tous</option>{Object.entries(methods).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <p>Résultat du filtre (toutes les pages) : {(list.meta?.totals_by_currency || []).map((row) => formatMoney(row.amount, row.currency)).join(' · ') || '—'}</p>
      {list.loading ? <p role="status">Chargement des paiements…</p> : list.items.length === 0 ? <p>Aucun paiement ne correspond à ces critères.</p> : <div className="payments-table">
        <div className="payment-row payment-row-head payment-row-head--page"><span>DATE</span><span>FACTURE</span><span>CLIENT</span><span>MODE</span><span>RÉFÉRENCE</span><span>MONTANT</span><span>ACTIONS</span></div>
        {list.items.map((payment) => <div className="payment-row payment-row--page" key={payment.id}><span>{date(payment.payment_date)}</span><strong>{payment.invoice.invoice_number}</strong><span>{payment.invoice.client.name}</span><span>{methods[payment.payment_method]}</span><span>{payment.reference || '—'}</span><strong>{formatMoney(payment.amount, payment.currency)}</strong><span title="Les règlements sont conservés">Conservé</span></div>)}
      </div>}
    </section>
  </section>
}
