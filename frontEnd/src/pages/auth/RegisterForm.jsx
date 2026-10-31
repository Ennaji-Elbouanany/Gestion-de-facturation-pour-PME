import { useState } from 'react'
import './RegisterForm.css'
import { authRequest } from '../../services/auth'

function RegisterForm({ onLoginClick, onBackToLanding, onRegistrationSuccess }) {
  const [formData, setFormData] = useState({
    name: '',
    company_name: '',
    role: '',
    email: '',
    password: '',
    password_confirmation: '',
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

    if (formData.password !== formData.password_confirmation) {
      setMessage('Les mots de passe ne correspondent pas.')
      return
    }

    setIsSubmitting(true)
    setMessage('')

    try {
      const result = await authRequest('/api/register', {
        method: 'POST',
        body: JSON.stringify({
          name: formData.name,
          company_name: formData.company_name,
          role: formData.role,
          email: formData.email,
          password: formData.password,
          password_confirmation: formData.password_confirmation,
        }),
      }, 'Impossible de créer le compte.')

      if (typeof result.token !== 'string' || !result.token) {
        throw new Error('Impossible de créer le compte.')
      }

      localStorage.setItem('auth_token', result.token)
      setMessage('Inscription réussie')
      setFormData({
        name: '',
        company_name: '',
        role: '',
        email: '',
        password: '',
        password_confirmation: '',
      })
      window.setTimeout(() => onRegistrationSuccess?.(), 500)
    } catch (error) {
      setMessage(error.message || 'Impossible de créer le compte.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="register-page">
      <section
        className="register-panel"
        aria-labelledby="register-title"
      >
        <button className="auth-brand" type="button" onClick={onBackToLanding} aria-label="Retourner à l'accueil de Factura"><span className="auth-brand-mark">F</span><span>Factura</span></button>
        <button className="auth-home-link" type="button" onClick={onBackToLanding}>← Retour à l’accueil</button>
        <p className="register-eyebrow">Bienvenue</p>

        <h1 id="register-title">Créer un compte</h1>

        <p className="register-intro">
          Rejoignez-nous en quelques secondes.
        </p>

        <form className="register-form" onSubmit={handleSubmit}>
          <label htmlFor="name">Nom complet</label>

          <input
            id="name"
            name="name"
            type="text"
            value={formData.name}
            onChange={handleChange}
            autoComplete="name"
            required
          />

          <label htmlFor="company_name">
            Nom de l'entreprise
          </label>

          <input
            id="company_name"
            name="company_name"
            type="text"
            value={formData.company_name}
            onChange={handleChange}
            autoComplete="organization"
            required
          />

          <label htmlFor="role">Rôle</label>

          <select
            id="role"
            name="role"
            value={formData.role}
            onChange={handleChange}
            required
          >
            <option value="" disabled>
              Sélectionnez un rôle
            </option>
            <option value="employee">Employee</option>
            <option value="admin">Admin</option>
            <option value="comptable">Comptable</option>
          </select>

          <label htmlFor="email">Adresse e-mail</label>

          <input
            id="email"
            name="email"
            type="email"
            value={formData.email}
            onChange={handleChange}
            autoComplete="email"
            required
          />

          <label htmlFor="password">Mot de passe</label>

          <input
            id="password"
            name="password"
            type="password"
            value={formData.password}
            onChange={handleChange}
            autoComplete="new-password"
            minLength={8}
            required
          />

          <label htmlFor="password_confirmation">
            Confirmer le mot de passe
          </label>

          <input
            id="password_confirmation"
            name="password_confirmation"
            type="password"
            value={formData.password_confirmation}
            onChange={handleChange}
            autoComplete="new-password"
            minLength={8}
            required
          />

          <div className="register-actions">
            <button type="submit" disabled={isSubmitting}>
              {isSubmitting
                ? 'Création en cours...'
                : "S'inscrire"}
            </button>

            <button
              type="button"
              className="register-login-button"
              onClick={onLoginClick}
            >
              Se connecter
            </button>
          </div>

          {message && (
            <p className="register-message" role="alert">
              {message}
            </p>
          )}

        </form>
      </section>
    </main>
  )
}

export default RegisterForm