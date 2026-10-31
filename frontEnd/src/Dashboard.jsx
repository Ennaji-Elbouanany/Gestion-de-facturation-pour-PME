import { useEffect, useState } from 'react'
import Clients from './Clients'
import Products from './Products'
import Invoices from './Invoices'
import Quotes from './Quotes'
import Payments from './Payments'
import './Dashboard.css'
import Overview from './Overview'
import Settings from './Settings'
import './DarkMode.css'

const navigation = [
  { label: 'Vue d’ensemble', icon: '⌂' },
  { label: 'Paiements', icon: '✓' },
  { label: 'Factures', icon: '▤' },
  { label: 'Clients', icon: '♙' },
  { label: 'Produits', icon: '□' },
  { label: 'Devis', icon: '◫' },
]



function Dashboard({ initialSection = 'Vue d’ensemble', onNavigate, onLogout, onGoToLanding }) {
  const [activeItem] = useState(initialSection)
  const [user, setUser] = useState(null)
  const [isDark, setIsDark] = useState(() => localStorage.getItem('dark_mode') === '1')

  useEffect(() => {
    const token = localStorage.getItem('auth_token')
    if (!token) return

    fetch(`${import.meta.env.VITE_API_URL}/api/user`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((result) => { result && setUser(result) })
      .catch((error) => console.error('Impossible de récupérer le profil utilisateur.', error))
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
    <main className={`dashboard-shell${isDark ? ' dark-mode' : ''}`}>
      <aside className="dashboard-sidebar">
        <button className="brand-lockup brand-link" type="button" onClick={onGoToLanding} aria-label="Retourner à l'accueil de Factura"><span className="brand-mark">F</span><span>Factura</span></button>
        <p className="sidebar-label">Espace de travail</p>
        <nav className="dashboard-nav" aria-label="Navigation principale">
          {navigation.map((item) => <button className={activeItem === item.label ? 'nav-item active' : 'nav-item'} key={item.label} onClick={() => onNavigate?.(item.label)} type="button"><span className="nav-icon" aria-hidden="true">{item.icon}</span>{item.label}</button>)}
        </nav>
        <div className="sidebar-bottom">
          <button className="nav-item" type="button" onClick={handleToggleTheme} aria-pressed={isDark}><span className="nav-icon" aria-hidden="true">{isDark ? '☀' : '☾'}</span>{isDark ? 'Mode clair' : 'Mode sombre'}</button>
          <button className={activeItem === 'Paramètres' ? 'nav-item active' : 'nav-item'} type="button" onClick={() => onNavigate?.('Paramètres')}><span className="nav-icon" aria-hidden="true">⚙</span>Paramètres</button>
          <button className="user-menu" type="button" onClick={onLogout}><span className="avatar">{initials}</span><span><strong>{user?.name || 'Alex Martin'}</strong><small>Se déconnecter</small></span><span className="logout-arrow" aria-hidden="true">↗</span></button>
        </div>
      </aside>

      <section className="dashboard-content">
        {activeItem === 'Paiements' ? <Payments /> : activeItem === 'Clients' ? <Clients /> : activeItem === 'Produits' ? <Products /> : activeItem === 'Factures' ? <Invoices /> : activeItem === 'Devis' ? <Quotes /> : activeItem === 'Paramètres' ? <Settings /> : <Overview user={user} onNavigate={onNavigate} />}
      </section>
    </main>
  )
}

export default Dashboard