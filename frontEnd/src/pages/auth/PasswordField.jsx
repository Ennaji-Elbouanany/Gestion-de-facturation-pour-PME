import { useState } from 'react'

function getPasswordChecks(value) {
  return [
    { label: '12 caractères minimum', valid: value.length >= 12 },
    { label: 'Une majuscule', valid: /[A-Z]/.test(value) },
    { label: 'Une minuscule', valid: /[a-z]/.test(value) },
    { label: 'Un chiffre', valid: /\d/.test(value) },
    { label: 'Un caractère spécial', valid: /[^A-Za-z0-9]/.test(value) },
  ]
}

function PasswordField({ id, name = id, label, value, onChange, autoComplete, showStrength = false }) {
  const [isVisible, setIsVisible] = useState(false)
  const checks = getPasswordChecks(value)

  return (
    <div className="password-field">
      <label htmlFor={id}>{label}</label>
      <div className="password-input-wrapper">
        <input
          id={id}
          name={name}
          type={isVisible ? 'text' : 'password'}
          value={value}
          onChange={onChange}
          autoComplete={autoComplete}
          minLength={12}
          required
        />
        <button
          className="password-visibility-button"
          type="button"
          onClick={() => setIsVisible((visible) => !visible)}
          aria-label={isVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
        >
          {isVisible ? 'Masquer' : 'Afficher'}
        </button>
      </div>
      {showStrength && (
        <ul className="password-requirements" aria-label="Exigences du mot de passe">
          {checks.map((check) => (
            <li className={check.valid ? 'valid' : ''} key={check.label}>
              {check.valid ? '✓' : '○'} {check.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export default PasswordField
