import { useCallback, useEffect, useState } from 'react'
import './Settings.css'

const sections = [
  { key: 'profil', label: 'Profil', icon: '👤' },
  { key: 'entreprise', label: 'Entreprise', icon: '🏢' },
  { key: 'facturation', label: 'Facturation', icon: '🧾' },
  { key: 'documents', label: 'Documents', icon: '📄' },
  { key: 'notifications', label: 'Notifications', icon: '🔔' },
  { key: 'securite', label: 'Sécurité', icon: '🔐' },
]

const roleLabels = {
  admin: 'Administrateur',
  comptable: 'Comptable',
  employee: 'Employé',
}

const emptyProfileForm = { name: '', email: '' }
const emptyCompanyForm = { name: '', email: '', phone: '', tax_number: '', ice: '', address: '', city: '', country: '' }
const emptyBillingForm = { payment_terms_days: '30', default_tax_rate: '20', currency: 'MAD' }
const emptyDocumentsForm = { invoice_prefix: 'FAC-', invoice_footer: '', logo: '' }
const emptyNotificationsForm = { notify_on_new_invoice: true, notify_on_payment: true, notify_on_quote: true, notify_on_overdue: true }
const emptySecurityForm = { current_password: '', password: '', password_confirmation: '' }

function pickCompany(value) {
  return {
    name: value?.name || '',
    email: value?.email || '',
    phone: value?.phone || '',
    tax_number: value?.tax_number || '',
    ice: value?.ice || '',
    address: value?.address || '',
    city: value?.city || '',
    country: value?.country || '',
  }
}

function pickBilling(value) {
  return {
    payment_terms_days: String(value?.payment_terms_days ?? '30'),
    default_tax_rate: value?.default_tax_rate === null || value?.default_tax_rate === undefined ? '20' : String(value.default_tax_rate),
    currency: value?.currency || 'MAD',
  }
}

function pickDocuments(value) {
  return {
    invoice_prefix: value?.invoice_prefix || 'FAC-',
    invoice_footer: value?.invoice_footer || '',
    logo: value?.logo || '',
  }
}

function pickNotifications(value) {
  return {
    notify_on_new_invoice: value?.notify_on_new_invoice ?? true,
    notify_on_payment: value?.notify_on_payment ?? true,
    notify_on_quote: value?.notify_on_quote ?? true,
    notify_on_overdue: value?.notify_on_overdue ?? true,
  }
}

function Settings() {
  const [activeSection, setActiveSection] = useState('entreprise')
  const [user, setUser] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [messageType, setMessageType] = useState('success')
  const [profileForm, setProfileForm] = useState(emptyProfileForm)
  const [companyForm, setCompanyForm] = useState(emptyCompanyForm)
  const [billingForm, setBillingForm] = useState(emptyBillingForm)
  const [documentsForm, setDocumentsForm] = useState(emptyDocumentsForm)
  const [notificationsForm, setNotificationsForm] = useState(emptyNotificationsForm)
  const [securityForm, setSecurityForm] = useState(emptySecurityForm)

  const request = useCallback(async (path, options = {}) => {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`${import.meta.env.VITE_API_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        ...options.headers,
      },
    })

    const result = await response.json()

    if (!response.ok) {
      const validationMessage = result.errors
        ? Object.values(result.errors).flat().join(' ')
        : result.message
      throw new Error(validationMessage || 'Une erreur est survenue.')
    }

    return result
  }, [])

  const loadSettings = useCallback(async () => {
    setIsLoading(true)
    setMessage('')

    try {
      const result = await request('/api/settings')
      setUser(result.user)
      setProfileForm({ name: result.user.name || '', email: result.user.email || '' })
      setCompanyForm(pickCompany(result.company))
      setBillingForm(pickBilling(result.company))
      setDocumentsForm(pickDocuments(result.company))
      setNotificationsForm(pickNotifications(result.company))
    } catch (error) {
      setMessageType('error')
      setMessage(error.message)
    } finally {
      setIsLoading(false)
    }
  }, [request])

  useEffect(() => {
    Promise.resolve().then(loadSettings)
  }, [loadSettings])

  function handleChange(setter) {
    return (event) => {
      const { name, value } = event.target
      setter((current) => ({ ...current, [name]: value }))
      setMessage('')
    }
  }

  async function runSave(path, payload, onSuccess) {
    setIsSaving(true)
    setMessage('')

    try {
      const result = await request(path, { method: 'PATCH', body: JSON.stringify(payload) })
      onSuccess?.(result)
      setMessageType('success')
      setMessage(result.message || 'Modifications enregistrées avec succès.')
    } catch (error) {
      setMessageType('error')
      setMessage(error.message)
    } finally {
      setIsSaving(false)
    }
  }

  function saveProfile(event) {
    event.preventDefault()
    runSave('/api/settings/profile', profileForm, (result) => {
      setUser(result.user)
      setProfileForm({ name: result.user.name, email: result.user.email })
    })
  }

  function saveCompany(event) {
    event.preventDefault()
    runSave('/api/settings/company', companyForm, (result) => {
      setCompanyForm(pickCompany(result.company))
    })
  }

  function saveBilling(event) {
    event.preventDefault()
    runSave('/api/settings/billing', billingForm, (result) => {
      setBillingForm(pickBilling(result.company))
    })
  }

  function saveDocuments(event) {
    event.preventDefault()
    runSave('/api/settings/documents', documentsForm, (result) => {
      setDocumentsForm(pickDocuments(result.company))
    })
  }

  function saveNotifications(payload = notificationsForm) {
    runSave('/api/settings/notifications', payload, (result) => {
      setNotificationsForm(pickNotifications(result.company))
    })
  }

  function handleToggleNotification(key) {
    const next = { ...notificationsForm, [key]: !notificationsForm[key] }
    setNotificationsForm(next)
    saveNotifications(next)
  }

  function saveSecurity(event) {
    event.preventDefault()
    runSave('/api/settings/security', securityForm, () => {
      setSecurityForm(emptySecurityForm)
    })
  }

  function renderProfile() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Profil</h2><p>Vos informations personnelles de connexion.</p></div></div>
        <form className="settings-form" onSubmit={saveProfile}>
          <div className="settings-form-grid">
            <label>Nom complet *<input name="name" value={profileForm.name} onChange={handleChange(setProfileForm)} required maxLength={255} /></label>
            <label>Adresse e-mail *<input name="email" type="email" value={profileForm.email} onChange={handleChange(setProfileForm)} required maxLength={255} /></label>
            <div className="settings-role-field"><span className="settings-role-label">Rôle</span><span className="settings-role-badge">{roleLabels[user?.role] || user?.role || '—'}</span></div>
          </div>
          <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>
        </form>
      </div>
    )
  }

  function renderCompany() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Informations de l’entreprise</h2><p>Ces informations apparaissent sur vos documents de facturation.</p></div></div>
        <form className="settings-form" onSubmit={saveCompany}>
          <div className="settings-form-grid">
            <label>Nom de l’entreprise *<input name="name" value={companyForm.name} onChange={handleChange(setCompanyForm)} required maxLength={255} /></label>
            <label>E-mail<input name="email" type="email" value={companyForm.email} onChange={handleChange(setCompanyForm)} maxLength={255} /></label>
            <label>Téléphone<input name="phone" value={companyForm.phone} onChange={handleChange(setCompanyForm)} maxLength={30} /></label>
            <label>ICE (Identifiant Commun de l’Entreprise)<input name="ice" value={companyForm.ice} onChange={handleChange(setCompanyForm)} maxLength={100} /></label>
            <label>IF (Identifiant Fiscal)<input name="tax_number" value={companyForm.tax_number} onChange={handleChange(setCompanyForm)} maxLength={100} /></label>
            <label className="settings-field--wide">Adresse<textarea className="settings-input-textarea" name="address" value={companyForm.address} onChange={handleChange(setCompanyForm)} rows="2"></textarea></label>
            <label>Ville<input name="city" value={companyForm.city} onChange={handleChange(setCompanyForm)} maxLength={100} /></label>
            <label>Pays<input name="country" value={companyForm.country} onChange={handleChange(setCompanyForm)} maxLength={100} /></label>
          </div>
          <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>
        </form>
      </div>
    )
  }

  function renderBilling() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Facturation</h2><p>Préférences par défaut appliquées à vos factures.</p></div></div>
        <form className="settings-form" onSubmit={saveBilling}>
          <div className="settings-form-grid">
            <label>Délai de paiement (en jours)<input name="payment_terms_days" type="number" min="0" max="365" value={billingForm.payment_terms_days} onChange={handleChange(setBillingForm)} /></label>
            <label>TVA par défaut (%)<input name="default_tax_rate" type="number" min="0" max="100" step="0.01" value={billingForm.default_tax_rate} onChange={handleChange(setBillingForm)} /></label>
            <label>Devise<select name="currency" value={billingForm.currency} onChange={handleChange(setBillingForm)}><option value="MAD">DH — Dirham marocain</option><option value="EUR">€ — Euro</option><option value="USD">$ — Dollar américain</option></select></label>
          </div>
          <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>
        </form>
      </div>
    )
  }

  function renderDocuments() {
    const previewNumber = `${documentsForm.invoice_prefix || 'FAC-'}${new Date().getFullYear()}-001`
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Documents</h2><p>Personnalisez l’apparence de vos factures et devis.</p></div></div>
        <form className="settings-form" onSubmit={saveDocuments}>
          <div className="settings-form-grid">
            <label>Préfixe des factures<input name="invoice_prefix" value={documentsForm.invoice_prefix} onChange={handleChange(setDocumentsForm)} maxLength={20} placeholder="FAC-" /></label>
            <label>Logo (URL)<input name="logo" value={documentsForm.logo} onChange={handleChange(setDocumentsForm)} maxLength={255} placeholder="https://..." /></label>
            <label className="settings-field--wide">Pied de page des factures<textarea className="settings-input-textarea" name="invoice_footer" value={documentsForm.invoice_footer} onChange={handleChange(setDocumentsForm)} rows="3" placeholder="Merci de votre confiance. Paiement à 30 jours."></textarea></label>
            <div className="settings-document-preview">Aperçu du prochain numéro : <code>{previewNumber}</code></div>
          </div>
          <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>
        </form>
      </div>
    )
  }

  function renderNotifications() {
    const items = [
      { key: 'notify_on_new_invoice', label: 'Nouvelle facture créée', hint: 'Recevoir une notification quand une facture est créée.' },
      { key: 'notify_on_payment', label: 'Paiement reçu', hint: 'Recevoir une notification quand un paiement est enregistré.' },
      { key: 'notify_on_quote', label: 'Nouveau devis', hint: 'Recevoir une notification quand un devis est créé.' },
      { key: 'notify_on_overdue', label: 'Facture en retard', hint: 'Être alerté quand une facture dépasse son échéance.' },
    ]
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Notifications</h2><p>Choisissez les événements pour lesquels vous souhaitez être alerté.</p></div></div>
        <div className="settings-notifications">
          {items.map((item) => (
            <label className="settings-switch" key={item.key}>
              <input type="checkbox" checked={notificationsForm[item.key]} onChange={() => handleToggleNotification(item.key)} />
              <span className="settings-switch-track" aria-hidden="true"></span>
              <span className="settings-switch-text"><strong>{item.label}</strong><small>{item.hint}</small></span>
            </label>
          ))}
        </div>
        <p className="settings-hint">Les modifications sont enregistrées automatiquement.</p>
      </div>
    )
  }

  function renderSecurity() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Sécurité</h2><p>Modifiez le mot de passe de votre compte.</p></div></div>
        <form className="settings-form" onSubmit={saveSecurity}>
          <div className="settings-form-grid">
            <label className="settings-field--wide">Mot de passe actuel *<input name="current_password" type="password" value={securityForm.current_password} onChange={handleChange(setSecurityForm)} required autoComplete="current-password" /></label>
            <label>Nouveau mot de passe *<input name="password" type="password" value={securityForm.password} onChange={handleChange(setSecurityForm)} required minLength={8} autoComplete="new-password" /></label>
            <label>Confirmation du nouveau mot de passe *<input name="password_confirmation" type="password" value={securityForm.password_confirmation} onChange={handleChange(setSecurityForm)} required minLength={8} autoComplete="new-password" /></label>
          </div>
          <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Mettre à jour le mot de passe'}</button></div>
        </form>
      </div>
    )
  }

  return (
    <section className="settings-page">
      <div className="settings-heading">
        <div>
          <p className="dashboard-kicker">Configuration</p>
          <h1>Paramètres</h1>
          <p className="settings-subtitle">Gérez votre profil, votre entreprise et vos préférences de facturation.</p>
        </div>
      </div>

      {message && <p className={`settings-message settings-message--${messageType}`} role="alert">{message}</p>}

      {isLoading ? <p className="empty-state">Chargement des paramètres...</p> : (
        <div className="settings-layout">
          <aside className="settings-nav" aria-label="Sections des paramètres">
            {sections.map((section) => (
              <button className={activeSection === section.key ? 'settings-nav-item active' : 'settings-nav-item'} key={section.key} type="button" onClick={() => { setActiveSection(section.key); setMessage('') }}>
                <span className="settings-nav-icon" aria-hidden="true">{section.icon}</span>{section.label}
              </button>
            ))}
          </aside>

          <div className="settings-content">
            {activeSection === 'profil' && renderProfile()}
            {activeSection === 'entreprise' && renderCompany()}
            {activeSection === 'facturation' && renderBilling()}
            {activeSection === 'documents' && renderDocuments()}
            {activeSection === 'notifications' && renderNotifications()}
            {activeSection === 'securite' && renderSecurity()}
          </div>
        </div>
      )}
    </section>
  )
}

export default Settings