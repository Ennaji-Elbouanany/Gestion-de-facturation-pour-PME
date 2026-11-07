import { useCallback, useEffect, useState } from 'react'
import FieldError from '../shared/FieldError'
import { fieldProps } from '../../services/formValidation'
import { useSubmission } from '../../hooks/useSubmission'
import { apiRequest } from '../../services/api'
import { usePermissions } from '../../hooks/usePermissions'
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

const emptyProfileForm = { name: '', email: '', current_password: '' }
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
    default_tax_rate: value?.default_tax_rate === null || value?.default_tax_rate === undefined ? '' : String(value.default_tax_rate),
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
  const { can } = usePermissions()
  const [activeSection, setActiveSection] = useState('entreprise')
  const [user, setUser] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, submit] = useSubmission()
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState({})
  const [messageType, setMessageType] = useState('success')
  const [profileForm, setProfileForm] = useState(emptyProfileForm)
  const [companyForm, setCompanyForm] = useState(emptyCompanyForm)
  const [billingForm, setBillingForm] = useState(emptyBillingForm)
  const [documentsForm, setDocumentsForm] = useState(emptyDocumentsForm)
  const [notificationsForm, setNotificationsForm] = useState(emptyNotificationsForm)
  const [securityForm, setSecurityForm] = useState(emptySecurityForm)


  const loadSettings = useCallback(async () => {
    setIsLoading(true)
    setMessage('')
    setErrors({})

    try {
      const result = await apiRequest('/api/settings')
      setUser(result.user)
      setProfileForm({ name: result.user.name || '', email: result.user.email || '', current_password: '' })
      setCompanyForm(pickCompany(result.company))
      setBillingForm(pickBilling(result.company))
      setDocumentsForm(pickDocuments(result.company))
      setNotificationsForm(pickNotifications(result.company))
    } catch (error) {
      setMessageType('error')
      setErrors(error.errors || {})
      setMessage(error.message)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    Promise.resolve().then(loadSettings)
  }, [loadSettings])

  function handleChange(setter) {
    return (event) => {
      const { name, value } = event.target
      setter((current) => ({ ...current, [name]: value }))
      setMessage('')
      setErrors({})
    }
  }

  async function runSave(path, payload, onSuccess) {
    return submit(async () => {
      setMessage('')
      setErrors({})

      try {
        const result = await apiRequest(path, { method: path === '/api/email/verification-notification' ? 'POST' : 'PATCH', body: JSON.stringify(payload) })
        onSuccess?.(result)
        setMessageType('success')
        setMessage(result.message || 'Modifications enregistrées avec succès.')
      } catch (error) {
        setMessageType('error')
        setErrors(error.errors || {})
        setMessage(error.message)
      }
    })
  }

  function saveProfile(event) {
    event.preventDefault()
    runSave('/api/settings/profile', profileForm, (result) => {
      setUser(result.user)
      setProfileForm({ name: result.user.name, email: result.user.email, current_password: '' })
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
    runSave('/api/settings/billing', { ...billingForm, default_tax_rate: billingForm.default_tax_rate === '' ? null : billingForm.default_tax_rate }, (result) => {
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
          {!can('profile.update') && <p role="status">Consultation uniquement : vous ne pouvez pas modifier ces paramètres.</p>}
          <fieldset className="permission-fields" disabled={isSaving || !can('profile.update')}>
          {!user?.email_verified_at && <p role="status">Adresse e-mail non vérifiée. <button type="button" disabled={isSaving} onClick={() => runSave('/api/email/verification-notification', {}, null)}>Renvoyer le lien</button></p>}
          <div className="settings-form-grid">
            <label>Nom complet *<input name="name" {...fieldProps('name', errors, 'settings')} value={profileForm.name} onChange={handleChange(setProfileForm)} required maxLength={255} /><FieldError name="name" errors={errors} prefix="settings" /></label>
            <label>Adresse e-mail *<input name="email" {...fieldProps('email', errors, 'settings')} type="email" value={profileForm.email} onChange={handleChange(setProfileForm)} required maxLength={255} /><FieldError name="email" errors={errors} prefix="settings" /></label>
            <label>Mot de passe actuel (pour changer l’e-mail)<input name="current_password" {...fieldProps('current_password', errors, 'settings')} type="password" autoComplete="current-password" value={profileForm.current_password} onChange={handleChange(setProfileForm)} required={Boolean(user && profileForm.email.trim().toLowerCase() !== user.email)} /><FieldError name="current_password" errors={errors} prefix="settings" /></label>
            <div className="settings-role-field"><span className="settings-role-label">Rôle</span><span className="settings-role-badge">{roleLabels[user?.role] || user?.role || '—'}</span></div>
          </div>
          {can('profile.update') && <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>}
                  </fieldset>
        </form>
      </div>
    )
  }

  function renderCompany() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Informations de l’entreprise</h2><p>Ces informations apparaissent sur vos documents de facturation.</p></div></div>
        <form className="settings-form" onSubmit={saveCompany}>
          {!can('settings.update') && <p role="status">Consultation uniquement : vous ne pouvez pas modifier ces paramètres.</p>}
          <fieldset className="permission-fields" disabled={isSaving || !can('settings.update')}>
          <div className="settings-form-grid">
            <label>Nom de l’entreprise *<input name="name" {...fieldProps('name', errors, 'settings')} value={companyForm.name} onChange={handleChange(setCompanyForm)} required maxLength={255} /><FieldError name="name" errors={errors} prefix="settings" /></label>
            <label>E-mail<input name="email" {...fieldProps('email', errors, 'settings')} type="email" value={companyForm.email} onChange={handleChange(setCompanyForm)} maxLength={255} /><FieldError name="email" errors={errors} prefix="settings" /></label>
            <label>Téléphone<input name="phone" {...fieldProps('phone', errors, 'settings')} value={companyForm.phone} onChange={handleChange(setCompanyForm)} maxLength={30} /><FieldError name="phone" errors={errors} prefix="settings" /></label>
            <label>ICE (Identifiant Commun de l’Entreprise)<input name="ice" {...fieldProps('ice', errors, 'settings')} value={companyForm.ice} onChange={handleChange(setCompanyForm)} maxLength={100} /><FieldError name="ice" errors={errors} prefix="settings" /></label>
            <label>IF (Identifiant Fiscal)<input name="tax_number" {...fieldProps('tax_number', errors, 'settings')} value={companyForm.tax_number} onChange={handleChange(setCompanyForm)} maxLength={100} /><FieldError name="tax_number" errors={errors} prefix="settings" /></label>
            <label className="settings-field--wide">Adresse<textarea className="settings-input-textarea" name="address" {...fieldProps('address', errors, 'settings')} maxLength={5000} value={companyForm.address} onChange={handleChange(setCompanyForm)} rows="2"></textarea><FieldError name="address" errors={errors} prefix="settings" /></label>
            <label>Ville<input name="city" {...fieldProps('city', errors, 'settings')} value={companyForm.city} onChange={handleChange(setCompanyForm)} maxLength={100} /><FieldError name="city" errors={errors} prefix="settings" /></label>
            <label>Pays<input name="country" {...fieldProps('country', errors, 'settings')} value={companyForm.country} onChange={handleChange(setCompanyForm)} maxLength={100} /><FieldError name="country" errors={errors} prefix="settings" /></label>
          </div>
          {can('settings.update') && <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>}
                  </fieldset>
        </form>
      </div>
    )
  }

  function renderBilling() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Facturation</h2><p>Préférences par défaut appliquées à vos factures.</p></div></div>
        <form className="settings-form" onSubmit={saveBilling}>
          {!can('settings.updateBilling') && <p role="status">Consultation uniquement : vous ne pouvez pas modifier ces paramètres.</p>}
          <fieldset className="permission-fields" disabled={isSaving || !can('settings.updateBilling')}>
          <div className="settings-form-grid">
            <label>Délai de paiement (en jours)<input name="payment_terms_days" {...fieldProps('payment_terms_days', errors, 'settings')} type="number" min="0" max="365" required value={billingForm.payment_terms_days} onChange={handleChange(setBillingForm)} /><FieldError name="payment_terms_days" errors={errors} prefix="settings" /></label>
            <label>TVA par défaut (%)<input name="default_tax_rate" {...fieldProps('default_tax_rate', errors, 'settings')} type="number" min="0" max="100" step="0.01" value={billingForm.default_tax_rate} onChange={handleChange(setBillingForm)} /><FieldError name="default_tax_rate" errors={errors} prefix="settings" /></label>
            <label>Devise<select name="currency" {...fieldProps('currency', errors, 'settings')} value={billingForm.currency} onChange={handleChange(setBillingForm)}><option value="MAD">DH — Dirham marocain</option><option value="EUR">€ — Euro</option><option value="USD">$ — Dollar américain</option></select><FieldError name="currency" errors={errors} prefix="settings" /></label>
          </div>
          {can('settings.updateBilling') && <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>}
                  </fieldset>
        </form>
      </div>
    )
  }

  function renderDocuments() {
    const previewNumber = `${documentsForm.invoice_prefix || 'FAC-'}${new Date().getUTCFullYear()}-001`
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Documents</h2><p>Personnalisez l’apparence de vos factures et devis.</p></div></div>
        <form className="settings-form" onSubmit={saveDocuments}>
          {!can('settings.updateDocuments') && <p role="status">Consultation uniquement : vous ne pouvez pas modifier ces paramètres.</p>}
          <fieldset className="permission-fields" disabled={isSaving || !can('settings.updateDocuments')}>
          <div className="settings-form-grid">
            <label>Préfixe des factures<input name="invoice_prefix" {...fieldProps('invoice_prefix', errors, 'settings')} value={documentsForm.invoice_prefix} onChange={handleChange(setDocumentsForm)} maxLength={20} required pattern="[A-Za-z0-9_-]+" placeholder="FAC-" /><FieldError name="invoice_prefix" errors={errors} prefix="settings" /></label>
            <label>Logo (URL)<input name="logo" {...fieldProps('logo', errors, 'settings')} type="url" value={documentsForm.logo} onChange={handleChange(setDocumentsForm)} maxLength={255} placeholder="https://..." /><FieldError name="logo" errors={errors} prefix="settings" /></label>
            <label className="settings-field--wide">Pied de page des factures<textarea className="settings-input-textarea" name="invoice_footer" {...fieldProps('invoice_footer', errors, 'settings')} maxLength={5000} value={documentsForm.invoice_footer} onChange={handleChange(setDocumentsForm)} rows="3" placeholder="Merci de votre confiance. Paiement à 30 jours."></textarea><FieldError name="invoice_footer" errors={errors} prefix="settings" /></label>
            <div className="settings-document-preview">Aperçu du prochain numéro : <code>{previewNumber}</code></div>
          </div>
          {can('settings.updateDocuments') && <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Enregistrer'}</button></div>}
                  </fieldset>
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
              <input type="checkbox" disabled={isSaving || !can('settings.updateNotifications')} checked={notificationsForm[item.key]} onChange={() => handleToggleNotification(item.key)} />
              <span className="settings-switch-track" aria-hidden="true"></span>
              <span className="settings-switch-text"><strong>{item.label}</strong><small>{item.hint}</small></span>
            </label>
          ))}
        </div>
        <p className="settings-hint">{can('settings.updateNotifications') ? 'Les modifications sont enregistrées automatiquement.' : 'Consultation uniquement : les notifications concernent toute l’entreprise.'}</p>
      </div>
    )
  }

  function renderSecurity() {
    return (
      <div className="settings-card">
        <div className="settings-card-heading"><div><h2>Sécurité</h2><p>Modifiez le mot de passe de votre compte.</p></div></div>
        <form className="settings-form" onSubmit={saveSecurity}>
          {!can('profile.updateSecurity') && <p role="status">Consultation uniquement : vous ne pouvez pas modifier ces paramètres.</p>}
          <fieldset className="permission-fields" disabled={isSaving || !can('profile.updateSecurity')}>
          <div className="settings-form-grid">
            <label className="settings-field--wide">Mot de passe actuel *<input name="current_password" {...fieldProps('current_password', errors, 'settings')} type="password" value={securityForm.current_password} onChange={handleChange(setSecurityForm)} required autoComplete="current-password" /><FieldError name="current_password" errors={errors} prefix="settings" /></label>
            <label>Nouveau mot de passe *<input name="password" {...fieldProps('password', errors, 'settings')} type="password" value={securityForm.password} onChange={handleChange(setSecurityForm)} required minLength={12} autoComplete="new-password" /><FieldError name="password" errors={errors} prefix="settings" /></label>
            <label>Confirmation du nouveau mot de passe *<input name="password_confirmation" {...fieldProps('password_confirmation', errors, 'settings')} type="password" value={securityForm.password_confirmation} onChange={handleChange(setSecurityForm)} required minLength={12} autoComplete="new-password" /><FieldError name="password_confirmation" errors={errors} prefix="settings" /></label>
          </div>
          {can('profile.updateSecurity') && <div className="settings-form-actions"><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Enregistrement...' : 'Mettre à jour le mot de passe'}</button></div>}
                  </fieldset>
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
