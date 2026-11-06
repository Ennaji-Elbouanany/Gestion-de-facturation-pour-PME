import { useCallback, useEffect, useState } from 'react'
import { apiRequest } from '../../services/api'
import './Clients.css'

const emptyForm = { name: '', email: '', phone: '', city: '', country: '' }

function Clients() {
  const [clients, setClients] = useState([])
  const [formData, setFormData] = useState(emptyForm)
  const [editingClient, setEditingClient] = useState(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(true)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [message, setMessage] = useState('')
  const [pagination, setPagination] = useState(null)
  const [showArchived, setShowArchived] = useState(false)


  const loadClients = useCallback(async (page = 1) => {
    setIsLoading(true)
    setMessage('')

    try {
      const response = await apiRequest(`/api/clients?per_page=25&page=${page}&archived=${showArchived ? 1 : 0}`)
      if (!Array.isArray(response) && response.data.length === 0 && response.current_page > 1) {
        const previous = await apiRequest(`/api/clients?per_page=25&page=${response.current_page - 1}&archived=${showArchived ? 1 : 0}`)
        setClients(previous.data)
        setPagination(previous)
        return
      }
      setClients(Array.isArray(response) ? response : response.data)
      setPagination(Array.isArray(response) ? null : response)
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsLoading(false)
    }
  }, [showArchived])

  useEffect(() => {
    Promise.resolve().then(loadClients)
  }, [loadClients])

  function handleChange(event) {
    setFormData((currentData) => ({
      ...currentData,
      [event.target.name]: event.target.value,
    }))
    setMessage('')
  }

  function openCreateForm() {
    setEditingClient(null)
    setFormData(emptyForm)
    setMessage('')
    setIsFormOpen(true)
  }

  function openEditForm(client) {
    setEditingClient(client)
    setFormData({
      name: client.name || '',
      email: client.email || '',
      phone: client.phone || '',
      city: client.city || '',
      country: client.country || '',
    })
    setMessage('')
    setIsFormOpen(true)
  }

  function closeForm() {
    setIsFormOpen(false)
    setEditingClient(null)
    setFormData(emptyForm)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSubmitting(true)
    setMessage('')

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
      setMessage(error.message)
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleArchive(client) {
    if (!window.confirm(`Archiver le client ${client.name} ? Ses documents et règlements seront conservés.`)) return
    setIsSubmitting(true)
    try {
      await apiRequest(`/api/clients/${client.id}`, { method: 'DELETE' })
      if (editingClient?.id === client.id) closeForm()
      await loadClients(pagination?.current_page || 1)
      setMessage('Client archivé. Historique conservé.')
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleRestore(client) {
    setIsSubmitting(true)
    try {
      await apiRequest(`/api/clients/${client.id}/restore`, { method: 'PATCH' })
      await loadClients(pagination?.current_page || 1)
      setMessage('Client restauré.')
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <section className="clients-page">
      <div className="clients-heading">
        <div><p className="dashboard-kicker">Carnet d’adresses</p><h1>Clients</h1><p className="clients-subtitle">Gérez les clients de votre entreprise.</p></div>
        <button className="primary-button" type="button" onClick={openCreateForm}><span aria-hidden="true">＋</span> Nouveau client</button>
      </div>

      {message && <p className="clients-message" role="alert">{message}</p>}

      {isFormOpen && <form className="client-form" onSubmit={handleSubmit}>
        <div className="client-form-heading"><div><h2>{editingClient ? 'Modifier le client' : 'Nouveau client'}</h2><p>Les champs marqués sont nécessaires.</p></div><button className="close-button" type="button" onClick={closeForm} aria-label="Fermer">×</button></div>
        <div className="client-form-grid">
          <label>Nom complet *<input name="name" value={formData.name} onChange={handleChange} required maxLength={255} /></label>
          <label>E-mail<input name="email" type="email" value={formData.email} onChange={handleChange} /></label>
          <label>Téléphone<input name="phone" value={formData.phone} onChange={handleChange} /></label>
          <label>Ville<input name="city" value={formData.city} onChange={handleChange} /></label>
          <label>Pays<input name="country" value={formData.country} onChange={handleChange} /></label>
        </div>
        <div className="client-form-actions"><button className="cancel-button" type="button" onClick={closeForm}>Annuler</button><button className="primary-button" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Enregistrement...' : 'Enregistrer'}</button></div>
      </form>}

      <section className="clients-panel">
        <label><input type="checkbox" checked={showArchived} onChange={(event) => { closeForm(); setShowArchived(event.target.checked) }} /> Afficher les clients archivés</label>
        <div className="clients-panel-heading"><div><h2>{showArchived ? 'Clients archivés' : 'Clients actifs'}</h2><p>{pagination?.total ?? clients.length} client{(pagination?.total ?? clients.length) !== 1 ? 's' : ''}</p></div><button className="refresh-button" type="button" onClick={() => loadClients(pagination?.current_page || 1)}>Actualiser</button></div>
        {isLoading ? <p className="empty-state">Chargement des clients...</p> : clients.length === 0 ? <p className="empty-state">Aucun client pour le moment. Ajoutez votre premier client.</p> : <div className="clients-table"><div className="client-row client-row-head"><span>CLIENT</span><span>E-MAIL</span><span>TÉLÉPHONE</span><span>VILLE</span><span>ACTIONS</span></div>{clients.map((client) => <div className="client-row" key={client.id}><strong>{client.name}</strong><span>{client.email || '—'}</span><span>{client.phone || '—'}</span><span>{client.city || '—'}</span><div className="client-actions">{showArchived ? <button type="button" disabled={isSubmitting} onClick={() => handleRestore(client)}>Restaurer</button> : <><button type="button" onClick={() => openEditForm(client)}>Modifier</button><button className="delete-button" type="button" disabled={isSubmitting} onClick={() => handleArchive(client)}>Archiver</button></>}</div></div>)}</div>}
        {pagination && pagination.last_page > 1 && <div className="clients-panel-heading"><button className="refresh-button" type="button" disabled={!pagination.prev_page_url} onClick={() => loadClients(pagination.current_page - 1)}>Précédent</button><span>Page {pagination.current_page} sur {pagination.last_page}</span><button className="refresh-button" type="button" disabled={!pagination.next_page_url} onClick={() => loadClients(pagination.current_page + 1)}>Suivant</button></div>}
      </section>
    </section>
  )
}

export default Clients