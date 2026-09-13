import { useCallback, useEffect, useMemo, useState } from 'react'
import './Payments.css'
import './PaymentsPage.css'

const paymentMethodLabels = {
  cash: 'Espèces',
  bank_transfer: 'Virement bancaire',
  cheque: 'Chèque',
  credit_card: 'Carte bancaire',
  digital_wallet: 'Portefeuille numérique',
  other: 'Autre',
}

const paymentMethodColors = {
  cash: '#4ca375',
  bank_transfer: '#5b8ed1',
  cheque: '#e08a4e',
  credit_card: '#8f6ad0',
  digital_wallet: '#2e9ca6',
  other: '#93a19a',
}

function parseDateValue(value) {
  if (!value) return null
  const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (match) return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

function monthKeyOf(value) {
  const date = parseDateValue(value)
  return date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : ''
}

function buildMonths(count) {
  const months = []
  const now = new Date()
  for (let index = count - 1; index >= 0; index -= 1) {
    const date = new Date(now.getFullYear(), now.getMonth() - index, 1)
    months.push({
      key: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
      label: date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }),
    })
  }
  return months
}

function num(value) {
  return Number(value) || 0
}

function formatPrice(value) {
  const amount = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return `${amount.format(num(value))} DH`
}

function formatNumber(value) {
  return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(num(value))
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
}

function Payments() {
  const [payments, setPayments] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [search, setSearch] = useState('')
  const [methodFilter, setMethodFilter] = useState('all')
  const [monthFilter, setMonthFilter] = useState('all')

  const request = useCallback(async (path, options = {}) => {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`${import.meta.env.VITE_API_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        ...options.headers,
      },
    })

    const result = await response.json()

    if (!response.ok) {
      const validationMessage = result.errors
        ? Object.values(result.errors).flat().join(' ')
        : result.message
      throw new Error(validationMessage || 'Une erreur est survenue.')
    }

    return result
  }, [])

  const loadPayments = useCallback(async () => {
    setIsLoading(true)
    setMessage('')

    try {
      const response = await request('/api/payments')
      setPayments(Array.isArray(response) ? response : [])
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsLoading(false)
    }
  }, [request])

  useEffect(() => {
    Promise.resolve().then(loadPayments)
  }, [loadPayments, reloadKey])

  const months = useMemo(() => buildMonths(12), [])

  const stats = useMemo(() => {
    const now = new Date()
    const currentKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`

    const total = payments.reduce((sum, payment) => sum + num(payment.amount), 0)
    const thisMonth = payments
      .filter((payment) => monthKeyOf(payment.payment_date) === currentKey)
      .reduce((sum, payment) => sum + num(payment.amount), 0)
    const average = payments.length > 0 ? total / payments.length : 0

    const byMethod = {}
    payments.forEach((payment) => {
      const method = paymentMethodLabels[payment.payment_method] ? payment.payment_method : 'other'
      byMethod[method] = (byMethod[method] || 0) + num(payment.amount)
    })

    return {
      total,
      thisMonth,
      average,
      count: payments.length,
      methodChips: Object.entries(paymentMethodLabels)
        .map(([key, label]) => ({ key, label, total: byMethod[key] || 0, color: paymentMethodColors[key] }))
        .filter((chip) => chip.total > 0),
    }
  }, [payments])

  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase()
    return payments.filter((payment) => {
      if (methodFilter !== 'all' && payment.payment_method !== methodFilter) return false
      if (monthFilter !== 'all' && monthKeyOf(payment.payment_date) !== monthFilter) return false
      if (needle) {
        const haystack = [
          payment.invoice?.invoice_number || '',
          payment.invoice?.client?.name || '',
          payment.reference || '',
          payment.notes || '',
        ].join(' ').toLowerCase()
        if (!haystack.includes(needle)) return false
      }
      return true
    })
  }, [payments, search, methodFilter, monthFilter])

  const filteredTotal = filtered.reduce((sum, payment) => sum + num(payment.amount), 0)

  async function handleDelete(payment) {
    if (!window.confirm(`Supprimer ce paiement de ${formatPrice(payment.amount)} ?`)) return
    setMessage('')

    try {
      await request(`/api/invoices/${payment.invoice_id}/payments/${payment.id}`, { method: 'DELETE' })
      setPayments((current) => current.filter((item) => item.id !== payment.id))
      setMessage('Paiement supprimé avec succès.')
    } catch (error) {
      setMessage(error.message)
    }
  }

  return (
    <section className="payments-page">
      <div className="payments-heading">
        <div>
          <p className="dashboard-kicker">Trésorerie</p>
          <h1>Paiements</h1>
          <p className="payments-subtitle">Suivez l’ensemble des règlements reçus par votre entreprise.</p>
        </div>
        <button className="refresh-button" type="button" onClick={() => setReloadKey((key) => key + 1)} disabled={isLoading}>Actualiser</button>
      </div>

      {message && <p className="payments-message" role="alert">{message}</p>}

      {!isLoading && payments.length > 0 && (
        <section className="pay-kpi-grid" aria-label="Indicateurs de trésorerie">
          <article className="pay-kpi pay-kpi--green"><span className="pay-kpi__label">Encaissé au total</span><strong>{formatPrice(stats.total)}</strong><small>{formatNumber(stats.count)} paiement{stats.count > 1 ? 's' : ''} enregistré{stats.count > 1 ? 's' : ''}</small></article>
          <article className="pay-kpi pay-kpi--blue"><span className="pay-kpi__label">Encaissé ce mois</span><strong>{formatPrice(stats.thisMonth)}</strong><small>Règlements reçus ce mois-ci</small></article>
          <article className="pay-kpi pay-kpi--orange"><span className="pay-kpi__label">Montant moyen</span><strong>{formatPrice(stats.average)}</strong><small>Par règlement</small></article>
          <article className="pay-kpi pay-kpi--purple"><span className="pay-kpi__label">Résultat du filtre</span><strong>{formatPrice(filteredTotal)}</strong><small>{filtered.length} paiement{filtered.length > 1 ? 's' : ''} affiché{filtered.length > 1 ? 's' : ''}</small></article>
        </section>
      )}

      {!isLoading && stats.methodChips.length > 0 && (
        <div className="pay-method-chips" aria-label="Répartition par mode de paiement">
          {stats.methodChips.map((chip) => (
            <span className="pay-chip" key={chip.key}>
              <i style={{ background: chip.color }} aria-hidden="true" />
              <span>{chip.label}</span>
              <strong>{formatPrice(chip.total)}</strong>
            </span>
          ))}
        </div>
      )}

      <section className="payments-panel pay-list-panel">
        <div className="payments-panel-heading">
          <div><h2>Historique des règlements</h2><p>{filtered.length} paiement{filtered.length !== 1 ? 's' : ''} sur {payments.length}</p></div>
        </div>

        <div className="pay-toolbar">
          <label className="pay-filter pay-filter--search"><span>Rechercher</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Facture, client, référence…" /></label>
          <label className="pay-filter"><span>Mode de paiement</span><select value={methodFilter} onChange={(event) => setMethodFilter(event.target.value)}><option value="all">Tous les modes</option>{Object.entries(paymentMethodLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          <label className="pay-filter"><span>Période</span><select value={monthFilter} onChange={(event) => setMonthFilter(event.target.value)}><option value="all">Toutes les périodes</option>{months.map((month) => <option key={month.key} value={month.key}>{month.label}</option>)}</select></label>
        </div>

        {isLoading ? <p className="empty-state">Chargement des paiements…</p> : payments.length === 0 ? (
          <p className="empty-state">Aucun paiement enregistré pour le moment. Ajoutez un règlement depuis une facture pour le voir apparaître ici.</p>
        ) : filtered.length === 0 ? (
          <p className="empty-state">Aucun paiement ne correspond à vos critères de recherche.</p>
        ) : (
          <div className="payments-table">
            <div className="payment-row payment-row-head payment-row-head--page"><span>DATE</span><span>FACTURE</span><span>CLIENT</span><span>MODE</span><span>RÉFÉRENCE</span><span>MONTANT</span><span>ACTIONS</span></div>
            {filtered.map((payment) => (
              <div className="payment-row payment-row--page" key={payment.id}>
                <span>{formatDate(payment.payment_date)}</span>
                <strong>{payment.invoice?.invoice_number || `Facture #${payment.invoice_id}`}</strong>
                <span>{payment.invoice?.client?.name || '—'}</span>
                <span className="pay-method-cell"><i className="pay-method-dot" style={{ background: paymentMethodColors[payment.payment_method] || '#93a19a' }} aria-hidden="true" />{paymentMethodLabels[payment.payment_method] || payment.payment_method}</span>
                <span>{payment.reference || '—'}</span>
                <strong className="pay-amount">{formatPrice(payment.amount)}</strong>
                <div className="pay-actions"><button type="button" onClick={() => handleDelete(payment)}>Supprimer</button></div>
              </div>
            ))}
          </div>
        )}
      </section>
    </section>
  )
}

export default Payments