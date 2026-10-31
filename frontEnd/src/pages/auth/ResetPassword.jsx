import { useState } from 'react'
import './Login.css'
import PasswordField from './PasswordField'
import { authRequest } from '../../services/auth'

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
      const result = await authRequest('/api/reset-password', {
        method: 'POST',
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      }, 'Impossible de réinitialiser le mot de passe.')

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
            onChange={(event) => setEmail(event.target.value.trim().toLowerCase())}
            autoComplete="email"
            required
          />

          <PasswordField id="reset-password" label="Nouveau mot de passe" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" showStrength />
          <PasswordField id="reset-password-confirmation" label="Confirmer le mot de passe" value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} autoComplete="new-password" />

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
