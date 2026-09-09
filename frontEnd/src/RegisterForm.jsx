import { useState } from 'react'
import './RegisterForm.css'


const REGISTER_URL = `${import.meta.env.VITE_API_URL}/api/register`

function RegisterForm({ onLoginClick }) {
  const [formData, setFormData] = useState({
    name: '',
    company_name: '',
    role: '',
    email: '',
    password: '',
    password_confirmation: '',
  })

  const [message, setMessage] = useState('')
  const [registrationResult, setRegistrationResult] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  function handleChange(event) {
    const { name, value } = event.target

    setFormData((currentData) => ({
      ...currentData,
      [name]: value,
    }))

    setMessage('')
    setRegistrationResult(null)
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
      const response = await fetch(REGISTER_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          name: formData.name,
          company_name: formData.company_name,
          role: formData.role,
          email: formData.email,
          password: formData.password,
          password_confirmation: formData.password_confirmation,
        }),
      })

      const result = await response.json()

      if (!response.ok) {
        const validationMessage = result.errors
          ? Object.values(result.errors).flat().join(' ')
          : result.message

        throw new Error(
          validationMessage || 'Impossible de créer le compte.'
        )
      }

      localStorage.setItem('auth_token', result.token)
      setRegistrationResult({
        token: result.token,
        userId: result.user_id,
      })
      setMessage('Votre compte a été créé avec succès.')

      setFormData({
        name: '',
        company_name: '',
        role: '',
        email: '',
        password: '',
        password_confirmation: '',
      })
    } catch (error) {
      setMessage(
        error.message || 'Une erreur est survenue. Réessayez.'
      )
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

          {registrationResult && (
            <div className="register-message" role="status">
              <p>ID utilisateur : {registrationResult.userId}</p>
              <p>Token : {registrationResult.token}</p>
            </div>
          )}
        </form>
      </section>
    </main>
  )
}

export default RegisterForm