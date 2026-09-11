import { useState } from 'react'
import Clients from './Clients'
import Products from './Products'
import Invoices from './Invoices'
import Quotes from './Quotes'
import './Dashboard.css'

const navigation = [
  { label: 'Vue d’ensemble', icon: '⌂' },
  { label: 'Factures', icon: '▤' },
  { label: 'Clients', icon: '♙' },
  { label: 'Produits', icon: '□' },
  { label: 'Devis', icon: '◫' },
]

const invoices = [
  { id: '#FAC-2026-014', client: 'Atelier Nova', date: '08 sept. 2026', amount: '2 450,00 DH', status: 'Payée' },
  { id: '#FAC-2026-013', client: 'Studio Marée', date: '06 sept. 2026', amount: '890,00 DH', status: 'En attente' },
  { id: '#FAC-2026-012', client: 'Maison Lenoir', date: '02 sept. 2026', amount: '1 275,00 DH', status: 'En retard' },
  { id: '#FAC-2026-011', client: 'Café Auguste', date: '28 août 2026', amount: '640,00 DH', status: 'Payée' },
]

function Dashboard({ onLogout }) {
  const [activeItem, setActiveItem] = useState('Vue d’ensemble')

  return (
    <main className="dashboard-shell">
      <aside className="dashboard-sidebar">
        <div className="brand-lockup"><span className="brand-mark">F</span><span>Factura</span></div>
        <p className="sidebar-label">Espace de travail</p>
        <nav className="dashboard-nav" aria-label="Navigation principale">
          {navigation.map((item) => <button className={activeItem === item.label ? 'nav-item active' : 'nav-item'} key={item.label} onClick={() => setActiveItem(item.label)} type="button"><span className="nav-icon" aria-hidden="true">{item.icon}</span>{item.label}</button>)}
        </nav>
        <div className="sidebar-bottom">
          <button className="nav-item" type="button"><span className="nav-icon" aria-hidden="true">⚙</span>Paramètres</button>
          <button className="user-menu" type="button" onClick={onLogout}><span className="avatar">AM</span><span><strong>Alex Martin</strong><small>Se déconnecter</small></span><span className="logout-arrow" aria-hidden="true">↗</span></button>
        </div>
      </aside>

      <section className="dashboard-content">
        {activeItem === 'Clients' ? <Clients /> : activeItem === 'Produits' ? <Products /> : activeItem === 'Factures' ? <Invoices /> : activeItem === 'Devis' ? <Quotes /> : <>
        <header className="dashboard-header">
          <div><p className="dashboard-kicker">Mardi 10 septembre 2026</p><h1>Bonjour, Alex <span aria-hidden="true">✦</span></h1><p className="dashboard-subtitle">Voici ce qui se passe dans votre entreprise aujourd’hui.</p></div>
          <div className="header-actions"><button className="icon-button" type="button" aria-label="Notifications">♢<span className="notification-dot" /></button><button className="primary-button" type="button"><span aria-hidden="true">＋</span> Nouvelle facture</button></div>
        </header>

        <div className="metric-grid">
          <article className="metric-card revenue-card"><div className="metric-heading"><span>Chiffre d’affaires</span><span className="metric-icon">↗</span></div><strong>24 680,00 DH</strong><p><span className="positive">+12,8 %</span> <span>vs. mois dernier</span></p><div className="mini-chart" aria-label="Evolution positive du chiffre d’affaires"><i /><i /><i /><i /><i /><i /><i /></div></article>
          <article className="metric-card"><div className="metric-heading"><span>Factures en attente</span><span className="metric-icon peach">◷</span></div><strong>8 420,00 DH</strong><p><span className="neutral">12 factures</span> <span>à encaisser</span></p><div className="progress-line"><span /></div></article>
          <article className="metric-card"><div className="metric-heading"><span>Clients actifs</span><span className="metric-icon blue">♙</span></div><strong>48</strong><p><span className="positive">+4</span> <span>ce mois-ci</span></p><div className="client-dots"><i /><i /><i /><i /><i /><i /><i /><b>+42</b></div></article>
        </div>

        <div className="dashboard-columns">
          <section className="panel invoice-panel"><div className="panel-heading"><div><h2>Dernières factures</h2><p>Suivez vos factures récentes</p></div><button className="text-button" type="button">Voir toutes <span aria-hidden="true">→</span></button></div><div className="invoice-table"><div className="table-row table-head"><span>FACTURE</span><span>CLIENT</span><span>DATE</span><span>MONTANT</span><span>STATUT</span></div>{invoices.map((invoice) => <div className="table-row" key={invoice.id}><strong>{invoice.id}</strong><span>{invoice.client}</span><span>{invoice.date}</span><strong>{invoice.amount}</strong><span className={`status ${invoice.status.toLowerCase().replace(' ', '-')}`}>{invoice.status}</span></div>)}</div></section>
          <section className="panel activity-panel"><div className="panel-heading"><div><h2>Activité récente</h2><p>Les dernières actions</p></div><button className="more-button" type="button" aria-label="Plus d'options">•••</button></div><div className="activity-list"><div className="activity-item"><span className="activity-badge green">✓</span><p><strong>Paiement reçu</strong><span>Facture #FAC-2026-014 · Atelier Nova</span><small>Il y a 2 heures</small></p><b>+2 450 DH</b></div><div className="activity-item"><span className="activity-badge orange">＋</span><p><strong>Nouvelle facture créée</strong><span>Facture #FAC-2026-013 · Studio Marée</span><small>Il y a 5 heures</small></p></div><div className="activity-item"><span className="activity-badge blue">♙</span><p><strong>Nouveau client ajouté</strong><span>Entreprise Dupont</span><small>Hier à 16:42</small></p></div></div></section>
        </div>
        </>}
      </section>
    </main>
  )
}

export default Dashboard