import { useState } from 'react'
import RegisterForm from './RegisterForm'
import Login from './Login'
import ForgotPassword from './ForgotPassword'
import ResetPassword from './ResetPassword'
import Dashboard from './Dashboard'
import './App.css'

function App() {
  const searchParams = new URLSearchParams(window.location.search)
  const isResetPasswordPage = window.location.pathname.replace(/\/$/, '') === '/reset-password'
    || searchParams.has('token')
  const initialPage = isResetPasswordPage
    ? 'reset-password'
    : localStorage.getItem('auth_token')
      ? 'dashboard'
      : localStorage.getItem('auth_page') || 'register'
  const [page, setPage] = useState(
    () => initialPage
  )

  function showLogin() {
    localStorage.setItem('auth_page', 'login')
    setPage('login')
  }

  function showRegister() {
    localStorage.setItem('auth_page', 'register')
    setPage('register')
  }

  function showDashboard() {
    localStorage.setItem('auth_page', 'dashboard')
    setPage('dashboard')
  }

  function handleLogout() {
    localStorage.removeItem('auth_token')
    showLogin()
  }

  function showForgotPassword() {
    localStorage.setItem('auth_page', 'forgot-password')
    setPage('forgot-password')
  }

  return (
    page === 'dashboard' ? (
      <Dashboard onLogout={handleLogout} />
    ) : page === 'login' ? (
      <Login
        onLogin={showDashboard}
        onRegisterClick={showRegister}
        onForgotPasswordClick={showForgotPassword}
      />
    ) : page === 'forgot-password' ? (
      <ForgotPassword onLoginClick={showLogin} />
    ) : page === 'reset-password' ? (
      <ResetPassword onLoginClick={showLogin} />
    ) : (
      <RegisterForm onLoginClick={showLogin} />
    )
  )
}

export default App
