import { useEffect, useState } from 'react'
import RegisterForm from './RegisterForm'
import Login from './Login'
import ForgotPassword from './ForgotPassword'
import ResetPassword from './ResetPassword'
import Dashboard from './Dashboard'
import Landing from './Landing'
import './App.css'

const protectedPaths = new Set(['/dashboard', '/payments', '/invoices', '/clients', '/products', '/quotes', '/settings'])

function pageForPath(path) {
  const normalizedPath = path.replace(/\/+$/, '') || '/'

  if (normalizedPath === '/') return 'landing'
  if (normalizedPath === '/login') return 'login'
  if (normalizedPath === '/register') return 'register'
  if (protectedPaths.has(normalizedPath)) return localStorage.getItem('auth_token') ? 'dashboard' : 'login'
  return localStorage.getItem('auth_page') || 'register'
}

function sectionForPath(path) {
  return ({
    '/dashboard': 'Vue d’ensemble',
    '/payments': 'Paiements',
    '/invoices': 'Factures',
    '/clients': 'Clients',
    '/products': 'Produits',
    '/quotes': 'Devis',
    '/settings': 'Paramètres',
  })[path.replace(/\/+$/, '') || '/'] || 'Vue d’ensemble'
}

function App() {
  const searchParams = new URLSearchParams(window.location.search)
  const isResetPasswordPage = window.location.pathname.replace(/\/$/, '') === '/reset-password'
    || searchParams.has('token')
  const initialPage = isResetPasswordPage ? 'reset-password' : pageForPath(window.location.pathname)
  const [page, setPage] = useState(() => initialPage)
  const [dashboardPath, setDashboardPath] = useState(() => window.location.pathname)

  useEffect(() => {
    function handlePopState() {
      setPage(pageForPath(window.location.pathname))
      setDashboardPath(window.location.pathname)
    }

    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  function navigate(nextPage, path, { replace = false } = {}) {
    if (window.location.pathname !== path) {
      window.history[replace ? 'replaceState' : 'pushState']({}, '', path)
    }
    setPage(nextPage)
  }

  function showLogin() {
    localStorage.setItem('auth_page', 'login')
    navigate('login', '/login')
  }

  function showRegister() {
    localStorage.setItem('auth_page', 'register')
    navigate('register', '/register')
  }

  function showDashboard() {
    localStorage.setItem('auth_page', 'dashboard')
    navigate('dashboard', '/dashboard')
  }

  function navigateDashboard(section) {
    const sectionPaths = {
      'Vue d’ensemble': '/dashboard',
      Paiements: '/payments',
      Factures: '/invoices',
      Clients: '/clients',
      Produits: '/products',
      Devis: '/quotes',
      Paramètres: '/settings',
    }
    const path = sectionPaths[section] || '/dashboard'

    localStorage.setItem('auth_page', 'dashboard')
    setDashboardPath(path)
    navigate('dashboard', path)
  }

  function showLanding() {
    localStorage.setItem('auth_page', 'landing')
    navigate('landing', '/')
  }

  function handleLogout() {
    localStorage.removeItem('auth_token')
    showLogin()
  }

  function showForgotPassword() {
    localStorage.setItem('auth_page', 'forgot-password')
    navigate('forgot-password', '/forgot-password')
  }

  return (
    page === 'landing' ? (
      <Landing onLogin={showLogin} onRegister={showRegister} onDashboard={showDashboard} isAuthenticated={Boolean(localStorage.getItem('auth_token'))} />
    ) : page === 'dashboard' ? (
      <Dashboard
        key={dashboardPath}
        initialSection={sectionForPath(dashboardPath)}
        onNavigate={navigateDashboard}
        onLogout={handleLogout}
        onGoToLanding={showLanding}
      />
    ) : page === 'login' ? (
      <Login
        onLogin={showDashboard}
        onRegisterClick={showRegister}
        onForgotPasswordClick={showForgotPassword}
        onBackToLanding={showLanding}
      />
    ) : page === 'forgot-password' ? (
      <ForgotPassword onLoginClick={showLogin} />
    ) : page === 'reset-password' ? (
      <ResetPassword onLoginClick={showLogin} />
    ) : (
      <RegisterForm onLoginClick={showLogin} onBackToLanding={showLanding} />
    )
  )
}

export default App
