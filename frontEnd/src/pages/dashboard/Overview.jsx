import { dashboardView, formatMoney } from '../../services/financialView'
import '../../styles/ListControls.css'
import { useCallback, useEffect, useState } from 'react'
import { apiRequest } from '../../services/api'
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


function formatPrice(value) { return formatMoney(value) }

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' })
}

function BarChart({ series, aLabel, bLabel, axisLabels }) {
  if (axisLabels.every((value) => value === null)) return <p>La devise historique doit être vérifiée avant de consolider ces montants.</p>
  const width = 640
  const height = 250
  const topPad = 22
  const bottomPad = 34
  const sidePad = 14
  const plotWidth = width - sidePad * 2
  const plotHeight = height - topPad - bottomPad
  const maxValue = 10000
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
            <text className="chart-axis-label" x={width - sidePad - 4} y={y - 4} textAnchor="end">{formatMoney(axisLabels[Math.round(ratio * 4)])}</text>
          </g>
        )
      })}
      {series.map((item, index) => {
        const centerX = sidePad + index * groupWidth + groupWidth / 2
        const aHeight = plotHeight * (item.a_plot / maxValue)
        const bHeight = plotHeight * (item.b_plot / maxValue)
        return (
          <g key={item.key}>
            <rect className="chart-bar chart-bar-a" x={centerX - barWidth - gap / 2} y={yFor(item.a_plot)} width={barWidth} height={Math.max(0, aHeight)} rx={3}>
              <title>{`${aLabel} — ${item.full} : ${formatPrice(item.a)}`}</title>
            </rect>
            <rect className="chart-bar chart-bar-b" x={centerX + gap / 2} y={yFor(item.b_plot)} width={barWidth} height={Math.max(0, bHeight)} rx={3}>
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
              <title>{`${segment.label} : ${segment.amount !== undefined ? formatPrice(segment.amount) : segment.value}`}</title>
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
            <b>{segment.amount !== undefined ? formatPrice(segment.amount) : segment.value}</b>
            <em>{Math.round(((segment.value || 0) / total) * 100)} %</em>
          </div>
        ))}
      </div>
    </div>
  )
}

function RankList({ items, format }) {
  const max = 10000
  return (
    <div className="rank-list">
      {items.map((item, index) => (
        <div className="rank-item" key={`${item.label}-${index}`}>
          <span className="rank-pos">{index + 1}</span>
          <div className="rank-main">
            <div className="rank-top"><strong title={item.label}>{item.label}</strong><b>{format(item.value)}</b></div>
            <div className="rank-track">
              <span className="rank-fill" style={{ width: `${Math.max(3, (item.plot / max) * 100)}%`, background: item.color || rankColors[index % rankColors.length] }} />
            </div>
            {item.sub && <small>{item.sub}</small>}
          </div>
        </div>
      ))}
    </div>
  )
}


function Overview({ user, onNavigate }) {
  const [response, setResponse] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [filters, setFilters] = useState({ from: '', to: '', currency: '' })
  const [reloadKey, setReloadKey] = useState(0)
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)).toString()
  const load = useCallback(async (active) => {
    setLoading(true); setError('')
    try {
      const result = await apiRequest(`/api/dashboard/financial?${query}`)
      if (active()) setResponse(result)
    } catch (failure) { if (active()) setError(failure.message) }
    finally { if (active()) setLoading(false) }
  }, [query])
  useEffect(() => {
    let active = true
    let sequence = 0
    const refresh = () => { const request = ++sequence; return load(() => active && request === sequence) }
    const timer = setTimeout(refresh, 250)
    const onFocus = () => { if (document.visibilityState === 'visible') refresh() }
    let clockTimer
    const midnight = () => {
      const now = new Date()
      clockTimer = setTimeout(() => { refresh(); midnight() }, Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + 1) - Date.now() + 1000)
    }
    midnight()
    document.addEventListener('visibilitychange', onFocus)
    return () => { active = false; clearTimeout(timer); clearTimeout(clockTimer); document.removeEventListener('visibilitychange', onFocus) }
  }, [load, reloadKey])
  const loadedOnce = Boolean(response)
  const stats = response ? dashboardView(response) : null
  const quotes = stats?.quotes || []
  const summary = response?.summary
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

  if (!response) return <p role="alert">{error || 'Chargement…'} <button type="button" onClick={() => setReloadKey((key) => key + 1)}>Réessayer</button></p>
  const hasData = stats.invoiceCount > 0 || (summary?.clients_count || 0) > 0 || quotes.length > 0 || stats.paymentCount > 0

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

      <div className="list-filters">
        <label>Du<input type="date" value={filters.from} onChange={(event) => setFilters((old) => ({ ...old, from: event.target.value }))} /></label>
        <label>Au<input type="date" value={filters.to} onChange={(event) => setFilters((old) => ({ ...old, to: event.target.value }))} /></label>
        <label>Devise<select value={filters.currency || response.selected_currency} onChange={(event) => setFilters((old) => ({ ...old, currency: event.target.value }))}>{Array.from(new Set([response.selected_currency, ...response.available_currencies])).map((value) => <option key={value} value={value}>{value === 'UNKNOWN' ? 'Devise historique à vérifier' : value}</option>)}</select></label>
      </div>
      <p>Période affichée : {response.from} au {response.to}. Stock des créances : registre courant.</p>
      <p role="status">{loading ? 'Actualisation en cours ; les valeurs précédentes restent affichées.' : `Calculé le ${formatDate(response.calculated_at)} · ${response.timezone} · ${response.selected_currency === 'UNKNOWN' ? 'devise à vérifier' : response.selected_currency}`}</p>
      {stats.group.requires_review && <p role="alert">{stats.group.review_count} document(s) nécessitent une revue financière. Les montants sont présentés selon le registre courant ; aucun historique n’a été reconstruit.</p>}
      {error && <div className="dash-error" role="alert"><span aria-hidden="true">⚠</span><p>{error}</p><button type="button" onClick={() => setReloadKey((key) => key + 1)}>Réessayer</button></div>}

      <section className="kpi-grid" aria-label="Indicateurs clés">
        <article className="kpi-card kpi-card--green">
          <div className="kpi-card__head"><span className="kpi-card__label">Facturé TTC (cumul)</span><span className="kpi-card__icon kpi-icon--green" aria-hidden="true">↗</span></div>
          <strong className="kpi-card__value">{formatPrice(stats.totals)}</strong>
          <p className="kpi-card__foot"><span className={Number(stats.billedPct) >= 0 ? 'trend is-up' : 'trend is-down'}>{stats.billedPct === null ? '—' : `${stats.billedPct} %`}</span><span>dernier mois / précédent</span></p>
          <p className="kpi-card__sub">{stats.nonCancelledCount} facture(s) émise(s) · {formatMoney(stats.group.period_billed)} sur la période</p>
        </article>
        <article className="kpi-card kpi-card--blue">
          <div className="kpi-card__head"><span className="kpi-card__label">Encaissé (cumul)</span><span className="kpi-card__icon kpi-icon--blue" aria-hidden="true">✓</span></div>
          <strong className="kpi-card__value">{formatPrice(stats.totalCollected)}</strong>
          <p className="kpi-card__foot"><span className={Number(stats.collectedPct) >= 0 ? 'trend is-up' : 'trend is-down'}>{stats.collectedPct === null ? '—' : `${stats.collectedPct} %`}</span><span>dernier mois / précédent</span></p>
          <p className="kpi-card__sub">{stats.paymentCount} paiement(s) reçu(s) · {formatMoney(stats.group.period_collected)} sur la période</p>
        </article>
        <article className="kpi-card kpi-card--orange">
          <div className="kpi-card__head"><span className="kpi-card__label">À encaisser</span><span className="kpi-card__icon kpi-icon--orange" aria-hidden="true">◷</span></div>
          <strong className="kpi-card__value">{formatPrice(stats.totalOutstanding)}</strong>
          <p className="kpi-card__foot">{stats.outstandingCount} facture(s) à encaisser</p>
          <p className="kpi-card__sub">dont {stats.overdueCount} en retard</p>
        </article>
        <article className="kpi-card kpi-card--red">
          <div className="kpi-card__head"><span className="kpi-card__label">En retard</span><span className="kpi-card__icon kpi-icon--red" aria-hidden="true">⚠</span></div>
          <strong className="kpi-card__value kpi-card__value--danger">{stats.overdueCount}</strong>
          <p className="kpi-card__foot"><strong className="trend is-down">{formatPrice(stats.overdueAmount)}</strong><span>échéances dépassées</span></p>
          <p className="kpi-card__sub">à relancer rapidement</p>
        </article>
      </section>

      <section className="stats-strip" aria-label="Statistiques complémentaires">
        <div className="stat-chip"><span className="stat-chip__label">Clients</span><strong>{summary?.clients_count ?? 0}</strong><small><em className="trend is-up">+{summary?.new_clients_this_month ?? 0}</em> ce mois-ci</small></div>
        <div className="stat-chip"><span className="stat-chip__label">Devis en attente</span><strong>{stats.pendingQuotesCount}</strong><small>Montants non consolidables : devise non historisée</small></div>
        <div className="stat-chip"><span className="stat-chip__label">Taux de recouvrement</span><strong>{stats.recoveryRate} %</strong><small>encaissé / facturé</small></div>
        <div className="stat-chip"><span className="stat-chip__label">Produits au catalogue</span><strong>{summary?.products_count ?? 0}</strong><small>références disponibles</small></div>
      </section>

      {hasData ? (
        <>
          <section className="charts-grid">
            <article className="chart-card">
              <div className="chart-card__head">
                <div><h2>Évolution de la facturation</h2><p>Facturation et encaissements sur la période sélectionnée</p></div>
                <div className="chart-legend"><span className="legend-item"><span className="legend-dot legend-dot--a" />Facturé</span><span className="legend-item"><span className="legend-dot legend-dot--b" />Encaissé</span></div>
              </div>
              <BarChart series={stats.series} axisLabels={stats.axisLabels} aLabel="Facturé" bLabel="Encaissé" />
              <details><summary>Valeurs mensuelles du graphique</summary><div className="table-scroll"><table className="data-table"><thead><tr><th>Mois</th><th>Facturé TTC</th><th>Encaissé</th></tr></thead><tbody>{stats.series.map((row) => <tr key={row.key}><td>{row.full}</td><td>{formatMoney(row.a, response.selected_currency)}</td><td>{formatMoney(row.b, response.selected_currency)}</td></tr>)}</tbody></table></div></details>
            </article>
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Paiements par méthode</h2><p>Répartition des encaissements</p></div></div>
              <DonutChart segments={(stats.group.methods || []).map((row) => ({ label: paymentMethodMeta[row.method]?.label || row.method, color: paymentMethodMeta[row.method]?.color || '#93a19a', value: row.weight, amount: row.amount }))} centerValue={formatPrice(stats.totalCollected)} centerLabel="encaissé" />
            </article>
          </section>

          <section className="panels-grid">
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Top clients</h2><p>Par montant facturé TTC sur la période</p></div></div>
              {stats.topClients.length ? <RankList items={stats.topClients} format={formatPrice} /> : <p className="dash-empty-text">Aucun client facturé pour le moment.</p>}
            </article>
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Top produits</h2><p>Lignes historiques avant remise globale</p></div></div>
              {stats.topProducts.length ? <RankList items={stats.topProducts} format={formatPrice} /> : <p className="dash-empty-text">Aucun produit facturé pour le moment.</p>}
            </article>
            <article className="chart-card">
              <div className="chart-card__head"><div><h2>Statut des factures</h2><p>{summary.invoices_count} facture(s), toutes devises</p></div></div>
              <DonutChart segments={(response.document_counts ? Object.entries(response.document_counts).map(([status, count]) => ({ status, count })) : (stats.group.statuses || [])).map((row) => ({ label: statusMeta[row.status]?.label || row.status, color: statusMeta[row.status]?.color || '#93a19a', value: row.count }))} centerValue={summary.invoices_count} centerLabel="factures" />
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
                      <p><strong>{event.label}</strong><span>{event.detail}</span><small>{formatDate(event.at)}</small></p>
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