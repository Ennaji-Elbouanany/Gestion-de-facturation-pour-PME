import { useEffect, useState } from 'react'
import { authRequest } from '../../services/auth'
import PasswordField from './PasswordField'
import './Login.css'

function AcceptInvitation({ onLoginClick }) {
  const [token] = useState(() => new URLSearchParams(window.location.search).get('token') || '')
  const [invitation, setInvitation] = useState(null)
  const [checking, setChecking] = useState(true)
  const [message, setMessage] = useState('')
  const [success, setSuccess] = useState(false)
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!token) return
    let cancelled = false
    authRequest(`/api/invitations/validate?token=${encodeURIComponent(token)}`)
      .then((result) => { if (!cancelled) setInvitation(result) })
      .catch((error) => { if (!cancelled) setMessage(error.message || 'Invitation invalide ou expirée.') })
      .finally(() => { if (!cancelled) setChecking(false) })
    return () => { cancelled = true }
  }, [token])

  async function handleSubmit(event) {
    event.preventDefault()
    if (password !== confirmation) {
      setMessage('Les mots de passe ne correspondent pas.')
      return
    }
    setSubmitting(true)
    setMessage('')
    try {
      const response = await authRequest('/api/invitations/accept', {
        method: 'POST',
        body: JSON.stringify({ token, name: name.trim(), password, password_confirmation: confirmation }),
      })
      setSuccess(true)
      setMessage(response.message || 'Votre compte a été créé. Connectez-vous.')
      setPassword('')
      setConfirmation('')
      window.history.replaceState({}, '', '/accept-invitation')
    } catch (error) {
      setMessage(error.message || 'Impossible d’accepter cette invitation.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-panel" aria-labelledby="accept-invitation-title">
        <div className="auth-brand"><span className="auth-brand-mark">F</span><span>Factura</span></div>
        <p className="login-eyebrow">Invitation</p>
        <h1 id="accept-invitation-title">Rejoindre une entreprise</h1>
        {!token && <p role="alert" className="login-message">Le lien d’invitation est incomplet.</p>}
        {token && checking && <p role="status">Vérification de l’invitation…</p>}
        {invitation && !success && (
          <>
            <p className="login-intro">Vous rejoignez <strong>{invitation.company_name}</strong> en tant que <strong>{invitation.role === 'comptable' ? 'comptable' : 'employé(e)'}</strong> avec l’adresse <strong>{invitation.email}</strong>.</p>
            <form className="login-form" onSubmit={handleSubmit}>
              <label htmlFor="invitation-name">Nom complet</label>
              <input id="invitation-name" autoComplete="name" required maxLength={255} value={name}
                onChange={(event) => setName(event.target.value)} />
              <PasswordField id="invitation-password" label="Mot de passe" autoComplete="new-password" value={password}
                onChange={(event) => setPassword(event.target.value)} showStrength />
              <PasswordField id="invitation-password-confirmation" label="Confirmer le mot de passe"
                autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
              <button type="submit" disabled={submitting || !name.trim()}>
                {submitting ? 'Création en cours…' : 'Créer mon compte'}
              </button>
            </form>
          </>
        )}
        {message && <p className="login-message" role={success ? 'status' : 'alert'}>{message}</p>}
        <button className="login-register-button" type="button" onClick={onLoginClick}>Aller à la connexion</button>
      </section>
    </main>
  )
}

export default AcceptInvitation
