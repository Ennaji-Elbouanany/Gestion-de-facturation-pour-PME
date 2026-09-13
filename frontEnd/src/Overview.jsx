import { useCallback, useEffect, useMemo, useState } from 'react'
import './DashboardPlus.css'

const statusMeta = {
  paid: { label: 'Payée', color: '#4ca375' },
  partial: { label: 'Partielle', color: '#5b8ed1' },
  sent: { label: 'Envoyée', color: '#e08a4e' },
  draft: { label: 'Brouillon', color: '#93a19a' },
  overdue: { label: 'En retard', color: '#d1605c' },
  cancelled: { label: 'Annulée', color: '#b8b4ac' },
}

const paymentMethodMeta = {
  cash: { label: 'Espèces', color: '#4ca375' },
  bank_transfer: { label: 'Virement bancaire', color: '#5b8ed1' },
  cheque: { label: 'Chèque', color: '#e08a4e' },
  credit_card: { label: 'Carte bancaire', color: '#8f6ad0' },
  digital_wallet: { label: 'Portefeuille numérique', color: '#2e9ca6' },
  other: { label: 'Autre', color: '#93a19a' },
}

const quoteStatusMeta = {
  draft: { label: 'Brouillon', color: '#93a19a' },
  sent: { label: 'Envoyé', color: '#5b8ed1' },
  accepted: { label: 'Accepté', color: '#4ca375' },
  rejected: { label: 'Refusé', color: '#d1605c' },
  expired: { label: 'Expiré', color: '#b8b4ac' },
  converted: { label: 'Converti', color: '#e08a4e' },
}

const rankColors = ['#e98a5f', '#e0a05b', '#d9b96b', '#a3bf7c', '#79ac95']

const num = (value) => Number(value) || 0

function formatPrice(value) {
  const amount = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return `${amount.format(num(value))} DH`
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
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
      label: date.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', ''),
      full: date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }),
    })
  }
  return months
}

function timeAgo(value) {
  const date = parseDateValue(value) || new Date(value)
  if (!date || Number.isNaN(date.getTime())) return ''
  const minutes = Math.floor((Date.now() - date.getTime()) / 60000)
  if (minutes < 1) return "à l'instant"
  if (minutes < 60) return `il y a ${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `il y a ${hours} h`
  const days = Math.floor(hours / 24)
  if (days < 30) return `il y a ${days} jour${days > 1 ? 's' : ''}`
  return `il y a ${Math.floor(days / 30)} mois`
}

function signedPct(value) {
  return `${value > 0 ? '+' : ''}${value.toFixed(1)} %`
}

function pctChange(current, previous) {
  if (previous <= 0) return current > 0 ? 100 : 0
  return ((current - previous) / previous) * 100
}

function trendClass(value) {
  if (value > 0.05) return 'trend is-up'
  if (value < -0.05) return 'trend is-down'
  return 'trend is-flat'
}

function niceCeil(value) {
  if (value <= 0) return 1
  const exponent = Math.floor(Math.log10(value))
  const base = 10 ** exponent
  const fraction = value / base
  const niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10
  return niceFraction * base
}

function axisValue(value) {
  if (value >= 1000) return `${(value / 1000).toFixed(value >= 10000 ? 0 : 1).replace('.', ',')}k`
  return Math.round(value).toString()
}

function BarChart({ series, aLabel, bLabel }) {
  const width = 640
  const height = 250
  const topPad = 22
  const bottomPad = 34
  const sidePad = 14
  const plotWidth = width - sidePad * 2
  const plotHeight = height - topPad - bottomPad
  const maxValue = niceCeil(Math.max(1, ...series.flatMap((item) => [item.a, item.b])))
  const groupWidth = plotWidth / Math.max(1, series.length)
  const barWidth = Math.min(16, groupWidth * 0.3)
  const gap = 5
  const yFor = (value) => topPad + plotHeight - (value / maxValue) * plotHeight

  return (
    <svg className="bar-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Évolution mensuelle de la facturation et des encaissements">
      {[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
        const y = topPad + plotHeight * (1 - ratio)
        return (
          <g key={ratio}>
            <line className="chart-gridline" x1={sidePad} x2={width - sidePad} y1={y} y2={y} />
            <text className="chart-axis-label" x={width - sidePad - 4} y={y - 4} textAnchor="end">{axisValue(maxValue * ratio)}</text>
          </g>
        )
      })}
      {series.map((item, index) => {
        const centerX = sidePad + index * groupWidth + groupWidth / 2
        const aHeight = plotHeight * (item.a / maxValue)
        const bHeight = plotHeight * (item.b / maxValue)
        return (
          <g key={item.key}>
            <rect className="chart-bar chart-bar-a" x={centerX - barWidth - gap / 2} y={yFor(item.a)} width={barWidth} height={Math.max(0, aHeight)} rx={3}>
              <title>{`${aLabel} — ${item.full} : ${formatPrice(item.a)}`}</title>
            </rect>
            <rect className="chart-bar chart-bar-b" x={centerX + gap / 2} y={yFor(item.b)} width={barWidth} height={Math.max(0, bHeight)} rx={3}>
              <title>{`${bLabel} — ${item.full} : ${formatPrice(item.b)}`}</title>
            </rect>
            <text className="chart-x-label" x={centerX} y={height - bottomPad + 16} textAnchor="middle">{item.label}</text>
          </g>
        )
      })}
    </svg>
  )
}

function DonutChart({ segments, centerValue, centerLabel }) {
  const size = 168
  const stroke = 16
  const radius = (size - stroke) / 2 - 3
  const circumference = 2 * Math.PI * radius
  const total = segments.reduce((sum, segment) => sum + (segment.value || 0), 0)

  if (total <= 0) return <div className="donut-empty">Aucune donnée disponible</div>

  const slices = segments.reduce((result, segment) => {
    const dash = ((segment.value || 0) / total) * circumference
    const previous = result[result.length - 1]
    return [...result, { ...segment, dash, offset: previous ? previous.offset + previous.dash : 0 }]
  }, [])

  return (
    <div className="donut-chart-wrap">
      <svg className="donut-chart" viewBox={`0 0 ${size} ${size}`} role="img" aria-label="Répartition en pourcentage">
        <circle className="donut-track" cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={stroke} />
        <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
          {slices.map((segment) => (
            <circle
              className="donut-segment"
              key={segment.label}
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke={segment.color}
              strokeWidth={stroke}
              strokeDasharray={`${segment.dash} ${circumference - segment.dash}`}
              strokeDashoffset={-segment.offset}
            >
              <title>{`${segment.label} : ${formatPrice(segment.value)}`}</title>
            </circle>
          ))}
        </g>
        <text className="donut-center-value" x="50%" y="47%" textAnchor="middle">{centerValue}</text>
        <text className="donut-center-label" x="50%" y="60%" textAnchor="middle">{centerLabel}</text>
      </svg>
      <div className="donut-legend">
        {slices.map((segment) => (
          <div className="dlegend-row" key={segment.label}>
            <span className="dlegend-dot" style={{ background: segment.color }} />
            <span>{segment.label}</span>
            <b>{formatPrice(segment.value)}</b>
            <em>{Math.round(((segment.value || 0) / total) * 100)} %</em>
          </div>
        ))}
      </div>
    </div>
  )
}

function RankList({ items, format }) {
  const max = Math.max(1, ...items.map((item) => item.value))
  return (
    <div className="rank-list">
      {items.map((item, index) => (
        <div className="rank-item" key={`${item.label}-${index}`}>
          <span className="rank-pos">{index + 1}</span>
          <div className="rank-main">
            <div className="rank-top"><strong title={item.label}>{item.label}</strong><b>{format(item.value)}</b></div>
            <div className="rank-track">
              <span className="rank-fill" style={{ width: `${Math.max(3, (item.value / max) * 100)}%`, background: item.color || rankColors[index % rankColors.length] }} />
            </div>
            {item.sub && <small>{item.sub}</small>}
          </div>
        </div>
      ))}
    </div>
  )
}

function formatNumber(value) {
  return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(num(value))
}

function Overview({ onNavigate }) {
  const [user, setUser] = useState(null)
  const [clients, setClients] = useState([])
  const [products, setProducts] = useState([])
  const [invoices, setInvoices] = useState([])
  const [quotes, setQuotes] = useState([])
  const [payments, setPayments] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadedOnce, setLoadedOnce] = useState(false)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

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

  const loadAll = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const [userData, invoicesData, clientsData, productsData, quotesData, paymentsData] = await Promise.all([
        request('/api/user'),
        request('/api/invoices'),
        request('/api/clients'),
        request('/api/products'),
        request('/api/quotes'),
        request('/api/payments'),
      ])

      const invoiceList = Array.isArray(invoicesData) ? invoicesData : []

      const allPayments = (Array.isArray(paymentsData) ? paymentsData : []).map((payment) => ({
        ...payment,
        invoice_number: payment.invoice?.invoice_number || '',
        client: payment.invoice?.client || null,
      }))

      setUser(userData && userData.id ? userData : null)
      setInvoices(invoiceList)
      setClients(Array.isArray(clientsData) ? clientsData : [])
      setProducts(Array.isArray(productsData) ? productsData : [])
      setQuotes(Array.isArray(quotesData) ? quotesData : [])
      setPayments(allPayments)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
      setLoadedOnce(true)
    }
  }, [request])

  useEffect(() => {
    Promise.resolve().then(loadAll)
  }, [loadAll, reloadKey])

  const stats = useMemo(() => {
    const now = new Date()
    const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const currentKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    const previousDate = new Date(now.getFullYear(), now.getMonth() - 1, 1)
    const previousKey = `${previousDate.getFullYear()}-${String(previousDate.getMonth() + 1).padStart(2, '0')}`

    const activeInvoices = invoices.filter((invoice) => invoice.status !== 'cancelled')

    const totals = activeInvoices.reduce((sum, invoice) => sum + num(invoice.total_amount), 0)

    const collectedByInvoice = new Map()
    payments.forEach((payment) => {
      collectedByInvoice.set(payment.invoice_id, (collectedByInvoice.get(payment.invoice_id) || 0) + num(payment.amount))
    })

    const remaining = (invoice) => {
      if (invoice.status === 'paid') return 0
      return Math.max(0, num(invoice.total_amount) - (collectedByInvoice.get(invoice.id) || 0))
    }

    const totalCollected = payments.reduce((sum, payment) => sum + num(payment.amount), 0)
    const outstandingInvoices = activeInvoices.filter((invoice) => remaining(invoice) > 0.005)
    const totalOutstanding = outstandingInvoices.reduce((sum, invoice) => sum + remaining(invoice), 0)

    const overdueInvoices = outstandingInvoices.filter((invoice) => (
      invoice.status === 'overdue' || (invoice.due_date && parseDateValue(invoice.due_date) < todayStart)
    ))
    const overdueAmount = overdueInvoices.reduce((sum, invoice) => sum + remaining(invoice), 0)

    const billedThisMonth = activeInvoices
      .filter((invoice) => monthKeyOf(invoice.invoice_date) === currentKey)
      .reduce((sum, invoice) => sum + num(invoice.total_amount), 0)
    const billedLastMonth = activeInvoices
      .filter((invoice) => monthKeyOf(invoice.invoice_date) === previousKey)
      .reduce((sum, invoice) => sum + num(invoice.total_amount), 0)
    const collectedThisMonth = payments
      .filter((payment) => monthKeyOf(payment.payment_date) === currentKey)
      .reduce((sum, payment) => sum + num(payment.amount), 0)
    const collectedLastMonth = payments
      .filter((payment) => monthKeyOf(payment.payment_date) === previousKey)
      .reduce((sum, payment) => sum + num(payment.amount), 0)

    const newClientsThisMonth = clients.filter((client) => monthKeyOf(client.created_at) === currentKey).length

    const pendingQuotes = quotes.filter((quote) => quote.status === 'sent' || quote.status === 'accepted')
    const pendingQuotesAmount = pendingQuotes.reduce((sum, quote) => sum + num(quote.total_amount), 0)

    const series = buildMonths(6).map((month) => ({
      key: month.key,
      label: month.label,
      full: month.full,
      a: activeInvoices
        .filter((invoice) => monthKeyOf(invoice.invoice_date) === month.key)
        .reduce((sum, invoice) => sum + num(invoice.total_amount), 0),
      b: payments
        .filter((payment) => monthKeyOf(payment.payment_date) === month.key)
        .reduce((sum, payment) => sum + num(payment.amount), 0),
    }))

    const methodTotals = {}
    payments.forEach((payment) => {
      const method = paymentMethodMeta[payment.payment_method] ? payment.payment_method : 'other'
      methodTotals[method] = (methodTotals[method] || 0) + num(payment.amount)
    })
    const methodSegments = Object.entries(paymentMethodMeta)
      .map(([key, meta]) => ({ label: meta.label, color: meta.color, value: methodTotals[key] || 0 }))
      .filter((segment) => segment.value > 0)

    const statusSegments = Object.entries(statusMeta)
      .map(([key, meta]) => ({
        label: meta.label,
        color: meta.color,
        value: invoices.filter((invoice) => invoice.status === key).length,
      }))
      .filter((segment) => segment.value > 0)

    const clientBilling = new Map()
    activeInvoices.forEach((invoice) => {
      const name = invoice.client?.name || 'Client inconnu'
      const current = clientBilling.get(name) || { label: name, value: 0, count: 0 }
      current.value += num(invoice.total_amount)
      current.count += 1
      clientBilling.set(name, current)
    })
    const topClients = [...clientBilling.values()]
      .sort((a, b) => b.value - a.value)
      .slice(0, 5)
      .map((item) => ({ ...item, sub: `${item.count} facture${item.count > 1 ? 's' : ''}` }))

    const productTotals = new Map()
    activeInvoices.forEach((invoice) => {
      (invoice.items || []).forEach((item) => {
        const key = item.product_id ? `${item.product_id}::${item.description}` : item.description
        const current = productTotals.get(key) || { label: item.description, value: 0, qty: 0 }
        current.value += num(item.total)
        current.qty += num(item.quantity)
        productTotals.set(key, current)
      })
    })
    const topProducts = [...productTotals.values()]
      .sort((a, b) => b.value - a.value)
      .slice(0, 5)
      .map((item) => ({ ...item, sub: `${formatNumber(item.qty)} unité(s) vendue(s)` }))

    const latestInvoices = [...activeInvoices]
      .sort((a, b) => (b.invoice_date || '').localeCompare(a.invoice_date || '') || b.id - a.id)
      .slice(0, 6)

    const dueSorted = [...outstandingInvoices].sort((a, b) => (a.due_date || '').localeCompare(b.due_date || ''))
    const overdueList = dueSorted
      .filter((invoice) => invoice.status === 'overdue' || (invoice.due_date && parseDateValue(invoice.due_date) < todayStart))
    const upcomingList = dueSorted
      .filter((invoice) => invoice.due_date && parseDateValue(invoice.due_date) >= todayStart)
      .slice(0, 5)

    const remainingBy = Object.fromEntries(outstandingInvoices.map((invoice) => [invoice.id, remaining(invoice)]))

    const recentPayments = [...payments]
      .sort((a, b) => (b.payment_date || '').localeCompare(a.payment_date || '') || (b.created_at || '').localeCompare(a.created_at || ''))
      .slice(0, 6)

    const recentQuotes = [...quotes]
      .sort((a, b) => (b.quote_date || '').localeCompare(a.quote_date || '') || b.id - a.id)
      .slice(0, 4)

    const events = []
    payments.forEach((payment) => {
      events.push({
        at: payment.created_at || payment.payment_date,
        icon: '✓',
        kind: 'payment',
        label: 'Paiement reçu',
        detail: `${payment.invoice_number} · ${paymentMethodMeta[payment.payment_method]?.label || payment.payment_method}`,
        value: num(payment.amount),
      })
    })
    activeInvoices.forEach((invoice) => {
      events.push({
        at: invoice.created_at || invoice.invoice_date,
        icon: '＋',
        kind: 'invoice',
        label: 'Facture créée',
        detail: `${invoice.invoice_number} · ${invoice.client?.name || 'Client'}`,
        value: null,
      })
    })
    clients.forEach((client) => {
      events.push({
        at: client.created_at,
        icon: '♙',
        kind: 'client',
        label: 'Nouveau client',
        detail: client.name,
        value: null,
      })
    })
    quotes.forEach((quote) => {
      events.push({
        at: quote.created_at || quote.quote_date,
        icon: '◫',
        kind: 'quote',
        label: 'Devis créé',
        detail: `${quote.quote_number} · ${quote.client?.name || 'Client'}`,
        value: null,
      })
    })
    events.sort((a, b) => new Date(b.at || 0) - new Date(a.at || 0))

    const recoveryRate = totals > 0 ? Math.round((totalCollected / totals) * 100) : 0

    return {
      totals,
      totalCollected,
      totalOutstanding,
      outstandingCount: outstandingInvoices.length,
      nonCancelledCount: activeInvoices.length,
      overdueInvoices,
      overdueAmount,
      billedPct: pctChange(billedThisMonth, billedLastMonth),
      collectedPct: pctChange(collectedThisMonth, collectedLastMonth),
      newClientsThisMonth,
      pendingQuotes,
      pendingQuotesAmount,
      recoveryRate,
      series,
      methodSegments,
      statusSegments,
      topClients,
      topProducts,
      latestInvoices,
      overdueList,
      upcomingList,
      remainingBy,
      recentPayments,
      recentQuotes,
      events,
    }
  }, [invoices, payments, clients, quotes])

  const firstName = (user?.name || 'Utilisateur').trim().split(/\s+/)[0] || 'Utilisateur'
  const greetingDate = new Date()
    .toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    .replace(/^./, (letter) => letter.toUpperCase())

  if (!loadedOnce && loading) {
    return (
      <div className="dash-overview" aria-busy="true" aria-label="Chargement du tableau de bord">
        <div className="skeleton skeleton-heading" />
        <div className="kpi-grid">
          <div className="skeleton skeleton-card" />
          <div className="skeleton skeleton-card" />
          <div className="skeleton skeleton-card" />
          <div className="skeleton skeleton-card" />
        </div>
        <div className="charts-grid">
          <div className="skeleton skeleton-card" />
          <div className="skeleton skeleton-card" />
        </div>
      </div>
    )
  }

  const hasData = invoices.length > 0 || clients.length > 0 || quotes.length > 0 || payments.length > 0

  return (
    <div className="dash-overview">
      <header className="dashboard-header">
        <div>
          <p className="dashboard-kicker">{greetingDate}</p>
          <h1>Bonjour, {firstName} <span aria-hidden="true">✦</span></h1>
          <p className="dashboard-subtitle">Voici ce qui se passe dans votre entreprise aujourd’hui.</p>
        </div>
        <div className="header-actions">
          <button className="icon-button" type="button" aria-label="Actualiser les données" onClick={() => setReloadKey((key) => key + 1)}>⟳</button>
          <button className="icon-button" type="button" aria-label="Notifications">♢</button>
          <button className="primary-button" type="button" onClick={() => onNavigate('Factures')}><span aria-hidden="true">＋</span> Nouvelle facture</button>
        </div>
      </header>

      {error && <div className="dash-error" role="alert"><span aria-hidden="true">⚠</span><p>{error}</p><button type="button" onClick={() => setReloadKey((key) => key + 1)}>Réessayer</button></div>}

      <section className="kpi-grid" aria-label="Indicateurs clés">
        <article className="kpi-card kpi-card--green">
          <div className="kpi-card__head"><span className="kpi-card__label">Chiffre d’affaires</span><span className="kpi-card__icon kpi-icon--green" aria-hidden="true">↗</span></div>
          <strong className="kpi-card__value">{formatPrice(stats.totals)}</strong>
          <p className="kpi-card__foot"><span className={trendClass(stats.billedPct)}>{signedPct(stats.billedPct)}</span><span>vs. mois dernier</span></p>
          <p className="kpi-card__sub">{stats.nonCancelledCount} facture(s) non annulée(s)</p>
        </article>
        <article className="kpi-card kpi-card--blue">
          <div className="kpi-card__head"><span className="kpi-card__label">Encaissé</span><span className="kpi-card__icon kpi-icon--blue" aria-hidden="true">✓</span></div>
          <strong className="kpi-card__value">{formatPrice(stats.totalCollected)}</strong>
          <p className="kpi-card__foot"><span className={trendClass(stats.collectedPct)}>{signedPct(stats.collectedPct)}</span><span>vs. mois dernier</span></p>
          <p className="kpi-card__sub">{payments.length} paiement(s) reçu(s)</p>
        </article>
        <article className="kpi-card kpi-card--orange">
          <div className="kpi-card__head"><span className="kpi-card__label">À encaisser</span><span className="kpi-card__icon kpi-icon--orange" aria-hidden="true">◷</span></div>
          <strong className="kpi-card__value">{formatPrice(stats.totalOutstanding)}</strong>
          <p className="kpi-card__foot">{stats.outstandingCount} facture(s) à encaisser</p>
          <p className="kpi-card__sub">dont {stats.overdueInvoices.length} en retard</p>
        </article>
        <article className="kpi-card kpi-card--red">
          <div className="kpi-card__head"><span className="kpi-card__label">En retard</span><span className="kpi-card__icon kpi-icon--red" aria-hidden="true">⚠</span></div>
          <strong className="kpi-card__value kpi-card__value--danger">{stats.overdueInvoices.length}</strong>
          <p className="kpi-card__foot"><strong className="trend is-down">{formatPrice(stats.overdueAmount)}</strong><span>échéances dépassées</span></p>
          <p className="kpi-card__sub">à relancer rapidement</p>
        </article>
      </section>

      <section className="stats-strip" aria-label="Statistiques complémentaires">
        <div className="stat-chip"><span className="stat-chip__label">Clients</span><strong>{clients.length}</strong><small><em className="trend is-up">+{stats.newClientsThisMonth}</em> ce mois-ci</small></div>
        <div className="stat-chip"><span className="stat-chip__label">Devis en attente</span><strong>{stats.pendingQuotes.length}</strong><small>{formatPrice(stats.pendingQuotesAmount)} au total</small></div>
        <div className="stat-chip"><span className="stat-chip__label">Taux de recouvrement</span><strong>{stats.recoveryRate} %</strong><small>encaissé / facturé</small></div>
        <div className="stat-chip"><span className="stat-chip__label">Produits au catalogue</span><strong>{products.length}</strong><small>références disponibles</small></div>
      </section>

      {hasData ? (
        <>
          <section className="charts-grid">
            <article className="chart-card">
              <div className="chart-card__head">
                <div><h2>Évolution de la facturation</h2><p>Facturé et encaissé sur les 6 derniers mois</p></div>
                <div className="chart-legend"><span className="legend-item"><span className="legend-dot legend-dot--a" />Facturé</span><span className="legend-item"><span className="legend-dot legend-dot--b" />Encaissé</span></div>
              </div>
              <BarChart series={stats.series} aLabel="Facturé" bLabel="Encaissé" />
            </article>
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Paiements par méthode</h2><p>Répartition des encaissements</p></div></div>
              <DonutChart segments={stats.methodSegments} centerValue={formatPrice(stats.totalCollected)} centerLabel="encaissé" />
            </article>
          </section>

          <section className="panels-grid">
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Top clients</h2><p>Par chiffre d’affaires facturé</p></div></div>
              {stats.topClients.length ? <RankList items={stats.topClients} format={formatPrice} /> : <p className="dash-empty-text">Aucun client facturé pour le moment.</p>}
            </article>
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Top produits</h2><p>Les plus vendus sur vos factures</p></div></div>
              {stats.topProducts.length ? <RankList items={stats.topProducts} format={formatPrice} /> : <p className="dash-empty-text">Aucun produit facturé pour le moment.</p>}
            </article>
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Statut des factures</h2><p>{invoices.length} facture(s) au total</p></div></div>
              <DonutChart segments={stats.statusSegments} centerValue={invoices.length} centerLabel="factures" />
            </article>
          </section>

          <section className="panels-grid">
            <article className="panel-card panel-card--wide">
              <div className="chart-card__head">
                <div><h2>Dernières factures</h2><p>Les factures les plus récentes</p></div>
                <button className="text-link" type="button" onClick={() => onNavigate('Factures')}>Voir toutes <span aria-hidden="true">→</span></button>
              </div>
              {stats.latestInvoices.length ? (
                <div className="dash-table">
                  <div className="dash-row dash-row--head"><span>FACTURE</span><span>CLIENT</span><span>DATE</span><span>MONTANT</span><span>STATUT</span></div>
                  {stats.latestInvoices.map((invoice) => (
                    <div className="dash-row" key={invoice.id}>
                      <strong>{invoice.invoice_number}</strong>
                      <span>{invoice.client?.name || '—'}</span>
                      <span>{formatDate(invoice.invoice_date)}</span>
                      <strong>{formatPrice(invoice.total_amount)}</strong>
                      <span className={`dash-badge dash-badge--${invoice.status}`}>{statusMeta[invoice.status]?.label || invoice.status}</span>
                    </div>
                  ))}
                </div>
              ) : <p className="dash-empty-text">Aucune facture pour le moment.</p>}
            </article>

            <article className="panel-card">
              <div className="chart-card__head"><div><h2>Activité récente</h2><p>Les dernières actions</p></div></div>
              {stats.events.length ? (
                <div className="feed">
                  {stats.events.slice(0, 6).map((event, index) => (
                    <div className="feed-item" key={`${event.at}-${index}`}>
                      <span className={`feed-badge feed-badge--${event.kind}`} aria-hidden="true">{event.icon}</span>
                      <p><strong>{event.label}</strong><span>{event.detail}</span><small>{timeAgo(event.at)}</small></p>
                      {event.value !== null && <b>+{formatPrice(event.value)}</b>}
                    </div>
                  ))}
                </div>
              ) : <p className="dash-empty-text">Aucune activité récente.</p>}
            </article>
          </section>

          <section className="panels-grid">
            <article className="panel-card">
              <div className="chart-card__head"><div><h2>Échéances &amp; retards</h2><p>Surveillez vos créances</p></div></div>
              {stats.overdueList.length === 0 && stats.upcomingList.length === 0 ? <p className="dash-empty-text">Aucune échéance à surveiller.</p> : (
                <div className="due-list">
                  {stats.overdueList.length > 0 && <p className="due-heading due-heading--late">En retard ({stats.overdueList.length})</p>}
                  {stats.overdueList.slice(0, 4).map((invoice) => (
                    <div className="due-item due-item--late" key={invoice.id}>
                      <div className="due-item__main"><strong>{invoice.invoice_number}</strong><small>{invoice.client?.name || 'Client'}</small></div>
                      <span className="due-item__due">Échue le {formatDate(invoice.due_date) === '—' ? '—' : formatDate(invoice.due_date)}</span>
                      <strong className="due-amount">{formatPrice(stats.remainingBy[invoice.id] || 0)}</strong>
                    </div>
                  ))}
                  {stats.upcomingList.length > 0 && <p className="due-heading">À venir ({stats.upcomingList.length})</p>}
                  {stats.upcomingList.map((invoice) => (
                    <div className="due-item" key={invoice.id}>
                      <div className="due-item__main"><strong>{invoice.invoice_number}</strong><small>{invoice.client?.name || 'Client'}</small></div>
                      <span className="due-item__due">Échéance le {formatDate(invoice.due_date)}</span>
                      <strong className="due-amount">{formatPrice(stats.remainingBy[invoice.id] || 0)}</strong>
                    </div>
                  ))}
                </div>
              )}
            </article>

            <article className="panel-card">
              <div className="chart-card__head">
                <div><h2>Paiements récents</h2><p>Les derniers encaissements</p></div>
                <button className="text-link" type="button" onClick={() => onNavigate('Paiements')}>Tous <span aria-hidden="true">→</span></button>
              </div>
              {stats.recentPayments.length ? (
                <div className="dash-table">
                  <div className="dash-row dash-row--head dash-row--payments"><span>DATE</span><span>FACTURE</span><span>MODE</span><span>MONTANT</span></div>
                  {stats.recentPayments.map((payment) => (
                    <div className="dash-row dash-row--payments" key={payment.id}>
                      <span>{formatDate(payment.payment_date)}</span>
                      <strong>{payment.invoice_number}</strong>
                      <span>{paymentMethodMeta[payment.payment_method]?.label || payment.payment_method}</span>
                      <strong>{formatPrice(payment.amount)}</strong>
                    </div>
                  ))}
                </div>
              ) : <p className="dash-empty-text">Aucun paiement enregistré.</p>}
            </article>

            <article className="panel-card">
              <div className="chart-card__head">
                <div><h2>Devis récents</h2><p>Les dernières propositions</p></div>
                <button className="text-link" type="button" onClick={() => onNavigate('Devis')}>Tous <span aria-hidden="true">→</span></button>
              </div>
              {stats.recentQuotes.length ? (
                <div className="feed">
                  {stats.recentQuotes.map((quote) => (
                    <div className="feed-item feed-item--quote" key={quote.id}>
                      <span className="feed-badge feed-badge--quote" aria-hidden="true">◫</span>
                      <p><strong>{quote.quote_number}</strong><span>{quote.client?.name || 'Client'}</span><small>Créé le {formatDate(quote.quote_date)} · {quoteStatusMeta[quote.status]?.label || quote.status}</small></p>
                      <b>{formatPrice(quote.total_amount)}</b>
                    </div>
                  ))}
                </div>
              ) : <p className="dash-empty-text">Aucun devis pour le moment.</p>}
            </article>
          </section>
        </>
      ) : (
        <section className="dash-empty">
          <span className="dash-empty__icon" aria-hidden="true">▤</span>
          <h2>Bienvenue dans votre tableau de bord</h2>
          <p>Créez votre première facture ou ajoutez un client pour voir apparaître ici une vue globale de l’activité de votre entreprise.</p>
          <div className="dash-empty__actions">
            <button className="primary-button" type="button" onClick={() => onNavigate('Factures')}><span aria-hidden="true">＋</span> Créer une facture</button>
            <button className="text-link" type="button" onClick={() => onNavigate('Clients')}>Ajouter un client <span aria-hidden="true">→</span></button>
          </div>
        </section>
      )}
    </div>
  )
}

export default Overview