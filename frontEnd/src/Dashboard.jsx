import { useState } from 'react'
import Clients from './Clients'
import Products from './Products'
import Invoices from './Invoices'
import Quotes from './Quotes'
import Payments from './Payments'
import './Dashboard.css'
import Overview from './Overview'

const navigation = [
  { label: 'Vue d’ensemble', icon: '⌂' },
  { label: 'Paiements', icon: '✓' },
  { label: 'Factures', icon: '▤' },
  { label: 'Clients', icon: '♙' },
  { label: 'Produits', icon: '□' },
  { label: 'Devis', icon: '◫' },
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
        {activeItem === 'Paiements' ? <Payments /> : activeItem === 'Clients' ? <Clients /> : activeItem === 'Produits' ? <Products /> : activeItem === 'Factures' ? <Invoices /> : activeItem === 'Devis' ? <Quotes /> : <Overview onNavigate={setActiveItem} />}
      </section>
    </main>
  )
}

export default Dashboard