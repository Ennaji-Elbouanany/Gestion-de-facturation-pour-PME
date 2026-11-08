import { useEffect, useState } from 'react'
import Clients from '../../components/clients/Clients'
import Products from '../../components/products/Products'
import Invoices from '../../components/invoices/Invoices'
import Quotes from '../../components/quotes/Quotes'
import Payments from '../../components/payments/Payments'
import './Dashboard.css'
import Overview from './Overview'
import Settings from '../../components/settings/Settings'
import './DarkMode.css'
import { apiRequest } from '../../services/api'
import { PermissionsContext } from '../../contexts/PermissionsContext'
import { allows } from '../../services/permissions'
import EmployeeOverview from './EmployeeOverview'
import Collaborators from '../../components/collaborators/Collaborators'

const navigation = [
  { label: 'Vue d’ensemble', icon: '⌂' },
  { label: 'Paiements', icon: '✓' },
  { label: 'Factures', icon: '▤' },
  { label: 'Clients', icon: '♙' },
  { label: 'Produits', icon: '□' },
  { label: 'Devis', icon: '◫' },
  { label: 'Collaborateurs', icon: '♧' },
]



function Dashboard({ initialSection = 'Vue d’ensemble', onNavigate, onLogout, onGoToLanding }) {
  const [activeItem] = useState(initialSection)
  const [user, setUser] = useState(null)
  const [profileError, setProfileError] = useState('')
  const can = (ability, record) => allows(user?.permissions, ability, record)
  const [isDark, setIsDark] = useState(() => localStorage.getItem('dark_mode') === '1')

  useEffect(() => {
    apiRequest('/api/user')
      .then((result) => result)
      .then((result) => { result && setUser(result) })
      .catch((error) => setProfileError(error.message))
  }, [])

  const initials = user?.name
    ? user.name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
    : 'FA'

  function handleToggleTheme() {
    const next = !isDark
    setIsDark(next)
    localStorage.setItem('dark_mode', next ? '1' : '0')
  }

  return (
    <PermissionsContext.Provider value={{ user, can }}>
    <main className={`dashboard-shell${isDark ? ' dark-mode' : ''}`}>
      <aside className="dashboard-sidebar">
        <button className="brand-lockup brand-link" type="button" onClick={onGoToLanding} aria-label="Retourner à l'accueil de Factura"><span className="brand-mark">F</span><span>Factura</span></button>
        <p className="sidebar-label">Espace de travail</p>
        <nav className="dashboard-nav" aria-label="Navigation principale">
          {navigation.filter((item) => (item.label !== 'Paiements' || can('payments.view')) && (item.label !== 'Collaborateurs' || user?.role === 'admin')).map((item) => <button className={activeItem === item.label ? 'nav-item active' : 'nav-item'} key={item.label} onClick={() => onNavigate?.(item.label)} type="button"><span className="nav-icon" aria-hidden="true">{item.icon}</span>{item.label}</button>)}
        </nav>
        <div className="sidebar-bottom">
          <button className="nav-item" type="button" onClick={handleToggleTheme} aria-pressed={isDark}><span className="nav-icon" aria-hidden="true">{isDark ? '☀' : '☾'}</span>{isDark ? 'Mode clair' : 'Mode sombre'}</button>
          <button className={activeItem === 'Paramètres' ? 'nav-item active' : 'nav-item'} type="button" onClick={() => onNavigate?.('Paramètres')}><span className="nav-icon" aria-hidden="true">⚙</span>Paramètres</button>
          <button className="user-menu" type="button" onClick={onLogout}><span className="avatar">{initials}</span><span><strong>{user?.name || 'Alex Martin'}</strong><small>Se déconnecter</small></span><span className="logout-arrow" aria-hidden="true">↗</span></button>
        </div>
      </aside>

      <section className="dashboard-content">
        {profileError ? <p role="alert">{profileError}</p> : !user ? <p role="status">Chargement des permissions...</p> :
          activeItem === 'Paramètres' ? <Settings /> : activeItem === 'Collaborateurs' ? (user.role === 'admin' ? <Collaborators /> : <p role="alert">Accès réservé aux administrateurs.</p>) : !can('dashboard.view') ? <p role="alert">Accès métier indisponible : votre rôle ou votre entreprise doit être vérifié par un administrateur.</p> :
          activeItem === 'Paiements' ? (can('payments.view') ? <Payments /> : <p role="alert">Accès refusé : la consultation des règlements est réservée aux administrateurs et comptables.</p>) : activeItem === 'Clients' ? <Clients /> : activeItem === 'Produits' ? <Products /> : activeItem === 'Factures' ? <Invoices /> : activeItem === 'Devis' ? <Quotes /> :
          can('dashboard.financial') ? <Overview user={user} onNavigate={onNavigate} /> : <EmployeeOverview onNavigate={onNavigate} />}

      </section>
    </main>
    </PermissionsContext.Provider>
  )
}

export default Dashboard