import { useCallback, useEffect, useState } from 'react'
import { apiRequest } from '../../services/api'
import './Collaborators.css'

const roleLabels = { employee: 'Employé', comptable: 'Comptable' }

function invitationStatus(invitation) {
  if (invitation.accepted_at) return 'Acceptée'
  if (new Date(invitation.expires_at).getTime() <= Date.now()) return 'Expirée'
  return 'En attente'
}

function Collaborators() {
  const [invitations, setInvitations] = useState([])
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('employee')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [cancellingId, setCancellingId] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const result = await apiRequest('/api/invitations')
      if (!Array.isArray(result)) throw new Error('Réponse des invitations invalide.')
      setInvitations(result)
      setError('')
    } catch (err) {
      setError(err.message || 'Impossible de charger les invitations.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    Promise.resolve().then(refresh)
  }, [refresh])

  async function handleInvite(event) {
    event.preventDefault()
    setSubmitting(true)
    setNotice('')
    setError('')
    try {
      const response = await apiRequest('/api/invitations', {
        method: 'POST',
        body: JSON.stringify({ email: email.trim().toLowerCase(), role }),
      })
      setNotice(response.message || 'Invitation envoyée.')
      setEmail('')
      await refresh()
    } catch (err) {
      setError(err.message || 'Invitation impossible.')
    } finally {
      setSubmitting(false)
    }
  }

  async function cancelInvitation(invitation) {
    if (!window.confirm(`Annuler l’invitation envoyée à ${invitation.email} ?`)) return
    setCancellingId(invitation.id)
    setNotice('')
    setError('')
    try {
      const response = await apiRequest(`/api/invitations/${invitation.id}`, { method: 'DELETE' })
      setNotice(response.message || 'Invitation annulée.')
      await refresh()
    } catch (err) {
      setError(err.message || 'Annulation impossible.')
    } finally {
      setCancellingId(null)
    }
  }

  return (
    <div className="collaborators-page">
      <header className="dashboard-header">
        <div>
          <p className="dashboard-kicker">Administration de l’entreprise</p>
          <h1>Collaborateurs</h1>
          <p className="dashboard-subtitle">Invitez des employés et comptables à rejoindre votre espace Factura.</p>
        </div>
      </header>

      <div className="collaborators-panel">
        <h2>Envoyer une invitation</h2>
        <p className="collaborators-hint">Un lien personnel sera envoyé par e-mail. Il expirera après 48 heures.</p>
        <form className="collaborators-form" onSubmit={handleInvite}>
          <label htmlFor="invite-email">Adresse e-mail
            <input id="invite-email" type="email" required maxLength={255} value={email}
              autoComplete="off" onChange={(event) => setEmail(event.target.value)} placeholder="collaborateur@entreprise.com" />
          </label>
          <label htmlFor="invite-role">Rôle
            <select id="invite-role" value={role} onChange={(event) => setRole(event.target.value)}>
              <option value="employee">Employé</option>
              <option value="comptable">Comptable</option>
            </select>
          </label>
          <button className="primary-button" type="submit" disabled={submitting || !email.trim()}>
            {submitting ? 'Envoi en cours…' : 'Envoyer l’invitation'}
          </button>
        </form>
      </div>

      {error && <p className="collaborators-alert" role="alert">{error}</p>}
      {notice && <p className="collaborators-notice" role="status">{notice}</p>}

      <section className="collaborators-panel" aria-labelledby="invitations-heading">
        <div className="collaborators-heading">
          <div>
            <h2 id="invitations-heading">Invitations envoyées</h2>
            <p className="collaborators-hint">Vous pouvez annuler les invitations qui n’ont pas été acceptées.</p>
          </div>
          <button className="collaborators-secondary" type="button" onClick={refresh} disabled={loading}>Actualiser</button>
        </div>
        {loading ? <p role="status">Chargement des invitations…</p> : invitations.length === 0 ? (
          <p>Aucune invitation pour le moment.</p>
        ) : (
          <div className="collaborators-table-wrap">
            <table className="collaborators-table">
              <thead><tr><th>E-mail</th><th>Rôle</th><th>Statut</th><th>Expiration</th><th>Action</th></tr></thead>
              <tbody>
                {invitations.map((invite) => {
                  const status = invitationStatus(invite)
                  return (
                    <tr key={invite.id}>
                      <td>{invite.email}</td>
                      <td>{roleLabels[invite.role] || invite.role}</td>
                      <td><span className="collaborators-status">{status}</span></td>
                      <td>{new Date(invite.expires_at).toLocaleString('fr-FR')}</td>
                      <td>{invite.accepted_at ? '—' : (
                        <button className="collaborators-secondary" type="button" disabled={cancellingId !== null}
                          onClick={() => cancelInvitation(invite)}>
                          {cancellingId === invite.id ? 'Annulation…' : 'Annuler'}
                        </button>
                      )}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}

export default Collaborators
