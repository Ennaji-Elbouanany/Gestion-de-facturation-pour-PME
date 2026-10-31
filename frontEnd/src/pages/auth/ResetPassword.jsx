import { useState } from 'react'
import './Login.css'

const RESET_PASSWORD_URL = `${import.meta.env.VITE_API_URL}/api/reset-password`

function ResetPassword({ onLoginClick }) {
  const searchParams = new URLSearchParams(window.location.search)
  const [email, setEmail] = useState(searchParams.get('email') || '')
  const [token] = useState(searchParams.get('token') || '')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [message, setMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()

    if (password !== passwordConfirmation) {
      setMessage('Les mots de passe ne correspondent pas.')
      return
    }

    setIsSubmitting(true)
    setMessage('')

    try {
      const response = await fetch(RESET_PASSWORD_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      })
      const result = await response.json()

      if (!response.ok) {
        const validationMessage = result.errors
          ? Object.values(result.errors).flat().join(' ')
          : result.message
        throw new Error(validationMessage || 'Impossible de réinitialiser le mot de passe.')
      }

      setMessage(result.message)
      setPassword('')
      setPasswordConfirmation('')
    } catch (error) {
      setMessage(error.message || 'Une erreur est survenue. Réessayez.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-panel" aria-labelledby="reset-password-title">
        <p className="login-eyebrow">Compte</p>
        <h1 id="reset-password-title">Nouveau mot de passe</h1>
        <p className="login-intro">
          Choisissez un nouveau mot de passe pour votre compte.
        </p>

        <form className="login-form" onSubmit={handleSubmit}>
          <label htmlFor="reset-email">Adresse e-mail</label>
          <input
            id="reset-email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            required
          />

          <label htmlFor="reset-password">Nouveau mot de passe</label>
          <input
            id="reset-password"
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
            minLength={8}
            required
          />

          <label htmlFor="reset-password-confirmation">Confirmer le mot de passe</label>
          <input
            id="reset-password-confirmation"
            type="password"
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
            autoComplete="new-password"
            minLength={8}
            required
          />

          <button type="submit" disabled={isSubmitting || !token}>
            {isSubmitting ? 'Modification en cours...' : 'Modifier le mot de passe'}
          </button>

          <button
            type="button"
            className="login-register-button"
            onClick={onLoginClick}
          >
            Retour à la connexion
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

export default ResetPassword
