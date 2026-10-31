import { useState } from 'react'
import './Login.css'
import { authRequest } from '../../services/auth'

function Login({ onLogin, onRegisterClick, onForgotPasswordClick, onBackToLanding }) {
  const [formData, setFormData] = useState({
    email: '',
    password: '',
  })
  const [message, setMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  function handleChange(event) {
    const { name, value } = event.target

    setFormData((currentData) => ({
      ...currentData,
      [name]: value,
    }))
    setMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSubmitting(true)
    setMessage('')

    try {
      const result = await authRequest('/api/login', {
        method: 'POST',
        body: JSON.stringify(formData),
      }, 'Adresse e-mail ou mot de passe incorrect.')

      if (typeof result.token !== 'string' || !result.token) {
        throw new Error('Adresse e-mail ou mot de passe incorrect.')
      }

      localStorage.setItem('auth_token', result.token)
      setFormData({ email: '', password: '' })
      onLogin?.()
    } catch (error) {
      setFormData((currentData) => ({ ...currentData, password: '' }))
      setMessage(error.message || 'Adresse e-mail ou mot de passe incorrect.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-panel" aria-labelledby="login-title">
        <button className="auth-brand" type="button" onClick={onBackToLanding} aria-label="Retourner à l'accueil de Factura"><span className="auth-brand-mark">F</span><span>Factura</span></button>
        <button className="auth-home-link" type="button" onClick={onBackToLanding}>← Retour à l’accueil</button>
        <p className="login-eyebrow">Bon retour</p>

        <h1 id="login-title">Se connecter</h1>

        <p className="login-intro">
          Accédez à votre espace personnel.
        </p>

        <form className="login-form" onSubmit={handleSubmit}>
          <label htmlFor="login-email">Adresse e-mail</label>

          <input
            id="login-email"
            name="email"
            type="email"
            value={formData.email}
            onChange={handleChange}
            autoComplete="email"
            required
          />

          <label htmlFor="login-password">Mot de passe</label>

          <input
            id="login-password"
            name="password"
            type="password"
            value={formData.password}
            onChange={handleChange}
            autoComplete="current-password"
            required
          />

          <button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Connexion en cours...' : 'Se connecter'}
          </button>

          <button
            type="button"
            className="login-register-button"
            onClick={onForgotPasswordClick}
          >
            Mot de passe oublié ?
          </button>

          <button
            type="button"
            className="login-register-button"
            onClick={onRegisterClick}
          >
            Retour à l'inscription
          </button>

          {message && (
            <p className="login-message" role="alert">
              {message}
            </p>
          )}
        </form>
      </section>
    </main>
  )
}

export default Login
