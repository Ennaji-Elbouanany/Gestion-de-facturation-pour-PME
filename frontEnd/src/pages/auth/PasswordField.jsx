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
  const [isVisible, setIsVisible] = useState(true)
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
          aria-label="Changer la visibilité du mot de passe"
          title="Changer la visibilité du mot de passe"
        >
          {isVisible ? (
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
              <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
              <circle cx="12" cy="12" r="2.5" />
            </svg>
          ) : (
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
              <path d="m3 3 18 18" />
              <path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17.5 17.5 0 0 1-3.1 3.8M6.2 6.3C3.9 7.7 2.5 12 2.5 12s3.5 6 9.5 6c1.2 0 2.3-.2 3.3-.6" />
            </svg>
          )}
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
