import { useState } from 'react'
import './Login.css'

const LOGIN_URL = `${import.meta.env.VITE_API_URL}/api/login`

function Login({ onLogin, onRegisterClick, onForgotPasswordClick }) {
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
      const response = await fetch(LOGIN_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(formData),
      })

      const result = await response.json()

      if (!response.ok) {
        const validationMessage = result.errors
          ? Object.values(result.errors).flat().join(' ')
          : result.message

        throw new Error(
          validationMessage || 'Adresse e-mail ou mot de passe incorrect.'
        )
      }

      if (result.token) {
        localStorage.setItem('auth_token', result.token)
      }

      onLogin?.(result)
    } catch (error) {
      setMessage(error.message || 'Une erreur est survenue. Réessayez.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-panel" aria-labelledby="login-title">
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
