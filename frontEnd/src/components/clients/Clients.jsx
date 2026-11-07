import { useServerList } from '../../hooks/useServerList'
import ListControls from '../shared/ListControls'
import { useState } from 'react'
import FieldError from '../shared/FieldError'
import { fieldProps } from '../../services/formValidation'
import { useSubmission } from '../../hooks/useSubmission'
import { apiRequest } from '../../services/api'
import { usePermissions } from '../../hooks/usePermissions'
import './Clients.css'

const emptyForm = { name: '', email: '', phone: '', tax_number: '', address: '', city: '', country: '' }

function Clients() {
  const { can } = usePermissions()
  const list = useServerList('/api/clients', { archived: '0' })
  const { items: clients, loading: isLoading, load: loadClients } = list
  const pagination = list.meta
  const [formData, setFormData] = useState(emptyForm)
  const [editingClient, setEditingClient] = useState(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [isSubmitting, submit] = useSubmission()
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState({})
  const [showArchived, setShowArchived] = useState(false)



  function handleChange(event) {
    setFormData((currentData) => ({
      ...currentData,
      [event.target.name]: event.target.value,
    }))
    setMessage('')
    setErrors({})
  }

  function openCreateForm() {
    setEditingClient(null)
    setFormData(emptyForm)
    setMessage('')
    setErrors({})
    setIsFormOpen(true)
  }

  function openEditForm(client) {
    setEditingClient(client)
    setFormData({
      name: client.name || '',
      email: client.email || '',
      phone: client.phone || '',
      tax_number: client.tax_number || '',
      address: client.address || '',
      city: client.city || '',
      country: client.country || '',
    })
    setMessage('')
    setErrors({})
    setIsFormOpen(true)
  }

  function closeForm() {
    setIsFormOpen(false)
    setEditingClient(null)
    setFormData(emptyForm)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    return submit(async () => {
      setMessage('')
      setErrors({})

      try {
        const path = editingClient ? `/api/clients/${editingClient.id}` : '/api/clients'
        const method = editingClient ? 'PUT' : 'POST'
        const client = await apiRequest(path, {
          method,
          body: JSON.stringify(formData),
        })

        if (!client.id) throw new Error('Réponse client invalide.')
        closeForm()
        await loadClients(pagination?.current_page || 1)
      } catch (error) {
        setErrors(error.errors || {})
        setMessage(error.message)
      }
    })
  }

  async function handleArchive(client) {
    if (!window.confirm(`Archiver le client ${client.name} ? Ses documents et règlements seront conservés.`)) return
    return submit(async () => {
      try {
        await apiRequest(`/api/clients/${client.id}`, { method: 'DELETE' })
        if (editingClient?.id === client.id) closeForm()
        await loadClients(pagination?.current_page || 1)
        setMessage('Client archivé. Historique conservé.')
      } catch (error) {
        setErrors(error.errors || {})
        setMessage(error.message)
      }
    })
  }

  async function handleRestore(client) {
    return submit(async () => {
      try {
        await apiRequest(`/api/clients/${client.id}/restore`, { method: 'PATCH' })
        await loadClients(pagination?.current_page || 1)
        setMessage('Client restauré.')
      } catch (error) {
        setErrors(error.errors || {})
        setMessage(error.message)
      }
    })
  }

  return (
    <section className="clients-page">
      <div className="clients-heading">
        <div><p className="dashboard-kicker">Carnet d’adresses</p><h1>Clients</h1><p className="clients-subtitle">Gérez les clients de votre entreprise.</p></div>
        {can('clients.create') && <button className="primary-button" type="button" onClick={openCreateForm}><span aria-hidden="true">＋</span> Nouveau client</button>}
      </div>

      {message && <p className="clients-message" role="alert">{message}</p>}

      {isFormOpen && <form className="client-form" onSubmit={handleSubmit}>
        <div className="client-form-heading"><div><h2>{editingClient ? 'Modifier le client' : 'Nouveau client'}</h2><p>Les champs marqués sont nécessaires.</p></div><button className="close-button" type="button" onClick={closeForm} aria-label="Fermer">×</button></div>
        <div className="client-form-grid">
          <label>Nom complet *<input name="name" {...fieldProps('name', errors, 'client')} value={formData.name} onChange={handleChange} required maxLength={255} /><FieldError name="name" errors={errors} prefix="client" /></label>
          <label>E-mail<input name="email" {...fieldProps('email', errors, 'client')} type="email" maxLength={255} value={formData.email} onChange={handleChange} /><FieldError name="email" errors={errors} prefix="client" /></label>
          <label>Téléphone<input name="phone" {...fieldProps('phone', errors, 'client')} type="tel" maxLength={30} value={formData.phone} onChange={handleChange} /><FieldError name="phone" errors={errors} prefix="client" /></label>
          <label>Identifiant fiscal<input name="tax_number" {...fieldProps('tax_number', errors, 'client')} maxLength={100} value={formData.tax_number} onChange={handleChange} /><FieldError name="tax_number" errors={errors} prefix="client" /></label>
          <label>Adresse<input name="address" {...fieldProps('address', errors, 'client')} maxLength={5000} value={formData.address} onChange={handleChange} /><FieldError name="address" errors={errors} prefix="client" /></label>
          <label>Ville<input name="city" {...fieldProps('city', errors, 'client')} maxLength={100} value={formData.city} onChange={handleChange} /><FieldError name="city" errors={errors} prefix="client" /></label>
          <label>Pays<input name="country" {...fieldProps('country', errors, 'client')} maxLength={100} value={formData.country} onChange={handleChange} /><FieldError name="country" errors={errors} prefix="client" /></label>
        </div>
        <div className="client-form-actions"><button className="cancel-button" type="button" onClick={closeForm}>Annuler</button><button className="primary-button" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Enregistrement...' : 'Enregistrer'}</button></div>
      </form>}

      <ListControls list={list} />
      <section className="clients-panel">
        <label><input type="checkbox" checked={showArchived} onChange={(event) => { closeForm(); setShowArchived(event.target.checked); list.setFilter('archived', event.target.checked ? '1' : '0') }} /> Afficher les clients archivés</label>
        <div className="clients-panel-heading"><div><h2>{showArchived ? 'Clients archivés' : 'Clients actifs'}</h2><p>{pagination?.total ?? clients.length} client{(pagination?.total ?? clients.length) !== 1 ? 's' : ''}</p></div><button className="refresh-button" type="button" onClick={() => loadClients(pagination?.current_page || 1)}>Actualiser</button></div>
        {isLoading ? <p className="empty-state">Chargement des clients...</p> : clients.length === 0 ? <p className="empty-state">Aucun client pour le moment. Ajoutez votre premier client.</p> : <div className="clients-table"><div className="client-row client-row-head"><span>CLIENT</span><span>E-MAIL</span><span>TÉLÉPHONE</span><span>VILLE</span><span>ACTIONS</span></div>{clients.map((client) => <div className="client-row" key={client.id}><strong>{client.name}</strong><span>{client.email || '—'}</span><span>{client.phone || '—'}</span><span>{client.city || '—'}</span><div className="client-actions">{showArchived ? (can('clients.restore') && <button type="button" disabled={isSubmitting} onClick={() => handleRestore(client)}>Restaurer</button>) : <>{can('clients.update') && <button type="button" onClick={() => openEditForm(client)}>Modifier</button>}{can('clients.archive') && <button className="delete-button" type="button" disabled={isSubmitting} onClick={() => handleArchive(client)}>Archiver</button>}</>}</div></div>)}</div>}
      </section>
    </section>
  )
}

export default Clients
