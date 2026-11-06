import { useState } from 'react'
import './RegisterForm.css'
import { authRequest } from '../../services/auth'
import PasswordField from './PasswordField'

function RegisterForm({ onLoginClick, onBackToLanding, onRegistrationSuccess }) {
  const [formData, setFormData] = useState({
    name: '',
    company_name: '',

    email: '',
    password: '',
    password_confirmation: '',
  })

  const [message, setMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  function handleChange(event) {
    const { name, value } = event.target
    const nextValue = name === 'email' ? value.trim().toLowerCase() : value

    setFormData((currentData) => ({
      ...currentData,
      [name]: nextValue,
    }))

    setMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (!/[A-Z]/.test(formData.password)
      || !/[a-z]/.test(formData.password)
      || !/\d/.test(formData.password)
      || !/[^A-Za-z0-9]/.test(formData.password)
      || formData.password.length < 12) {
      setMessage('Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.')
      return
    }

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

          email: formData.email,
          password: formData.password,
          password_confirmation: formData.password_confirmation,
        }),
      }, 'Impossible de créer le compte.')

      if (!result.user) {
        throw new Error('Impossible de créer le compte.')
      }

      setMessage('Inscription réussie')
      setFormData({
        name: '',
        company_name: '',

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

          <PasswordField id="password" label="Mot de passe" value={formData.password} onChange={handleChange} autoComplete="new-password" showStrength />
          <PasswordField id="password_confirmation" label="Confirmer le mot de passe" value={formData.password_confirmation} onChange={handleChange} autoComplete="new-password" />

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