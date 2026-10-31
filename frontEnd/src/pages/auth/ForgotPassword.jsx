import { useState } from 'react'
import './Login.css'
import { authRequest } from '../../services/auth'

function ForgotPassword({ onLoginClick }) {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSubmitting(true)
    setMessage('')

    try {
      const result = await authRequest('/api/forgot-password', {
        method: 'POST',
        body: JSON.stringify({ email }),
      }, 'Impossible d’envoyer le lien.')
      setMessage(result.message || 'Le lien de réinitialisation a été envoyé par e-mail.')
    } catch (error) {
      setMessage(error.message || 'Une erreur est survenue. Réessayez.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-panel" aria-labelledby="forgot-password-title">
        <p className="login-eyebrow">Compte</p>
        <h1 id="forgot-password-title">Mot de passe oublié</h1>
        <p className="login-intro">
          Recevez un lien pour choisir un nouveau mot de passe.
        </p>

        <form className="login-form" onSubmit={handleSubmit}>
          <label htmlFor="forgot-email">Adresse e-mail</label>
          <input
            id="forgot-email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            required
          />

          <button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Envoi en cours...' : 'Envoyer le lien'}
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

export default ForgotPassword
