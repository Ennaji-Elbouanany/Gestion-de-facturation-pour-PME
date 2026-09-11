import { useCallback, useEffect, useState } from 'react'
import './Invoices.css'

const emptyItem = { product_id: '', description: '', quantity: '1', unit_price: '', tax_rate: '' }

const emptyForm = {
  client_id: '',
  invoice_date: new Date().toISOString().slice(0, 10),
  due_date: '',
  status: 'draft',
  notes: '',
  items: [{ ...emptyItem }],
}

const statusLabels = {
  draft: 'Brouillon',
  sent: 'Envoyée',
  paid: 'Payée',
  overdue: 'En retard',
  cancelled: 'Annulée',
}

function Invoices() {
  const [invoices, setInvoices] = useState([])
  const [clients, setClients] = useState([])
  const [products, setProducts] = useState([])
  const [formData, setFormData] = useState(emptyForm)
  const [editingInvoice, setEditingInvoice] = useState(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(true)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [message, setMessage] = useState('')

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

  const loadInvoices = useCallback(async () => {
    setIsLoading(true)
    setMessage('')

    try {
      const response = await request('/api/invoices')
      setInvoices(response)
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsLoading(false)
    }
  }, [request])

  const loadClients = useCallback(async () => {
    try {
      const response = await request('/api/clients')
      setClients(response)
    } catch (error) {
      setMessage(error.message)
    }
  }, [request])

  const loadProducts = useCallback(async () => {
    try {
      const response = await request('/api/products')
      setProducts(response)
    } catch (error) {
      setMessage(error.message)
    }
  }, [request])

  useEffect(() => {
    Promise.resolve().then(() => {
      loadInvoices()
      loadClients()
      loadProducts()
    })
  }, [loadInvoices, loadClients, loadProducts])

  function handleChange(event) {
    setFormData((currentData) => ({
      ...currentData,
      [event.target.name]: event.target.value,
    }))
    setMessage('')
  }

  function handleItemChange(index, event) {
    const { name, value } = event.target

    setFormData((currentData) => {
      const items = currentData.items.map((item, itemIndex) => {
        if (itemIndex !== index) return item

        const updatedItem = { ...item, [name]: value }

        // Auto-fill description and unit_price when a product is selected
        if (name === 'product_id' && value) {
          const product = products.find((p) => String(p.id) === String(value))
          if (product) {
            updatedItem.description = product.name
            updatedItem.unit_price = product.unit_price
            updatedItem.tax_rate = product.tax_rate || ''
          }
        }

        return updatedItem
      })

      return { ...currentData, items }
    })
    setMessage('')
  }

  function addItem() {
    setFormData((currentData) => ({
      ...currentData,
      items: [...currentData.items, { ...emptyItem }],
    }))
  }

  function removeItem(index) {
    setFormData((currentData) => ({
      ...currentData,
      items: currentData.items.filter((_, itemIndex) => itemIndex !== index),
    }))
  }

  function openCreateForm() {
    setEditingInvoice(null)
    setFormData({ ...emptyForm, invoice_date: new Date().toISOString().slice(0, 10) })
    setMessage('')
    setIsFormOpen(true)
  }

  function openEditForm(invoice) {
    setEditingInvoice(invoice)
    setFormData({
      client_id: String(invoice.client_id || ''),
      invoice_date: invoice.invoice_date || '',
      due_date: invoice.due_date || '',
      status: invoice.status || 'draft',
      notes: invoice.notes || '',
      items: invoice.items.length > 0
        ? invoice.items.map((item) => ({
            product_id: item.product_id ? String(item.product_id) : '',
            description: item.description || '',
            quantity: item.quantity || '1',
            unit_price: item.unit_price || '',
            tax_rate: item.tax_rate || '',
          }))
        : [{ ...emptyItem }],
    })
    setMessage('')
    setIsFormOpen(true)
  }

  function closeForm() {
    setIsFormOpen(false)
    setEditingInvoice(null)
    setFormData(emptyForm)
  }

  function calculateItemSubtotal(item) {
    const quantity = Number(item.quantity) || 0
    const unitPrice = Number(item.unit_price) || 0
    return quantity * unitPrice
  }

  function calculateItemTotal(item) {
    const subtotal = calculateItemSubtotal(item)
    const taxRate = Number(item.tax_rate) || 0
    return subtotal + (subtotal * taxRate / 100)
  }

  function calculateSubtotal() {
    return formData.items.reduce((sum, item) => sum + calculateItemSubtotal(item), 0)
  }

  function calculateTaxAmount() {
    return formData.items.reduce((sum, item) => sum + (calculateItemTotal(item) - calculateItemSubtotal(item)), 0)
  }

  function calculateTotal() {
    return formData.items.reduce((sum, item) => sum + calculateItemTotal(item), 0)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSubmitting(true)
    setMessage('')

    try {
      const path = editingInvoice ? `/api/invoices/${editingInvoice.id}` : '/api/invoices'
      const method = editingInvoice ? 'PUT' : 'POST'
      const invoice = await request(path, {
        method,
        body: JSON.stringify(formData),
      })

      setInvoices((currentInvoices) => editingInvoice
        ? currentInvoices.map((currentInvoice) => currentInvoice.id === invoice.id ? invoice : currentInvoice)
        : [invoice, ...currentInvoices])
      closeForm()
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleDelete(invoice) {
    if (!window.confirm(`Supprimer la facture ${invoice.invoice_number} ?`)) return

    try {
      await request(`/api/invoices/${invoice.id}`, { method: 'DELETE' })
      setInvoices((currentInvoices) => currentInvoices.filter((item) => item.id !== invoice.id))
      setMessage('Facture supprimée avec succès.')
    } catch (error) {
      setMessage(error.message)
    }
  }

  async function handleStatusChange(invoice, status) {
    try {
      const updatedInvoice = await request(`/api/invoices/${invoice.id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      })
      setInvoices((currentInvoices) => currentInvoices.map((currentInvoice) => currentInvoice.id === updatedInvoice.id ? updatedInvoice : currentInvoice))
      setMessage('Statut mis à jour avec succès.')
    } catch (error) {
      setMessage(error.message)
    }
  }

  function formatPrice(value) {
    const amount = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    return `${amount.format(Number(value) || 0)} DH`
  }

  function formatDate(date) {
    if (!date) return '—'
    return new Date(date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
  }

  return (
    <section className="invoices-page">
      <div className="invoices-heading">
        <div><p className="dashboard-kicker">Facturation</p><h1>Factures</h1><p className="invoices-subtitle">Créez et gérez les factures de votre entreprise.</p></div>
        <button className="primary-button" type="button" onClick={openCreateForm}><span aria-hidden="true">＋</span> Nouvelle facture</button>
      </div>

      {message && <p className="invoices-message" role="alert">{message}</p>}

      {isFormOpen && <form className="invoice-form" onSubmit={handleSubmit}>
        <div className="invoice-form-heading"><div><h2>{editingInvoice ? `Modifier la facture ${editingInvoice.invoice_number}` : 'Nouvelle facture'}</h2><p>Les champs marqués sont nécessaires.</p></div><button className="close-button" type="button" onClick={closeForm} aria-label="Fermer">×</button></div>

        <div className="invoice-form-grid">
          <label>Client *<select name="client_id" value={formData.client_id} onChange={handleChange} required><option value="">— Sélectionner un client —</option>{clients.map((client) => <option key={client.id} value={client.id}>{client.name}</option>)}</select></label>
          <label>Date de facture *<input name="invoice_date" type="date" value={formData.invoice_date} onChange={handleChange} required /></label>
          <label>Date d'échéance<input name="due_date" type="date" value={formData.due_date} onChange={handleChange} /></label>
          <label>Statut<select name="status" value={formData.status} onChange={handleChange}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        </div>

        <div className="invoice-items-section">
          <div className="invoice-items-heading"><h3>Lignes de facture</h3><button className="add-item-button" type="button" onClick={addItem}>＋ Ajouter une ligne</button></div>

          <div className="invoice-items-table">
            <div className="invoice-item-row invoice-item-head"><span>PRODUIT</span><span>DESCRIPTION</span><span>QTÉ</span><span>PRIX UNITAIRE</span><span>TVA %</span><span>MONTANT</span><span /></div>
            {formData.items.map((item, index) => (
              <div className="invoice-item-row" key={index}>
                <select name="product_id" value={item.product_id} onChange={(event) => handleItemChange(index, event)}><option value="">—</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select>
                <input name="description" value={item.description} onChange={(event) => handleItemChange(index, event)} placeholder="Description" required />
                <input name="quantity" type="number" step="0.01" min="0.01" value={item.quantity} onChange={(event) => handleItemChange(index, event)} required />
                <input name="unit_price" type="number" step="0.01" min="0" value={item.unit_price} onChange={(event) => handleItemChange(index, event)} required />
                <input name="tax_rate" type="number" step="0.01" min="0" max="100" value={item.tax_rate} onChange={(event) => handleItemChange(index, event)} />
                <strong>{formatPrice(calculateItemTotal(item))}</strong>
                <button className="remove-item-button" type="button" onClick={() => removeItem(index)} aria-label="Supprimer la ligne">×</button>
              </div>
            ))}
          </div>

          <div className="invoice-totals">
            <div className="invoice-total-row"><span>Sous-total (HT)</span><strong>{formatPrice(calculateSubtotal())}</strong></div>
            <div className="invoice-total-row"><span>TVA</span><strong>{formatPrice(calculateTaxAmount())}</strong></div>
            <div className="invoice-total-row invoice-total-grand"><span>Total (TTC)</span><strong>{formatPrice(calculateTotal())}</strong></div>
          </div>
        </div>

        <label className="invoice-notes-label">Notes<input name="notes" value={formData.notes} onChange={handleChange} placeholder="Notes internes (optionnel)" /></label>

        <div className="invoice-form-actions"><button className="cancel-button" type="button" onClick={closeForm}>Annuler</button><button className="primary-button" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Enregistrement...' : 'Enregistrer'}</button></div>
      </form>}

      <section className="invoices-panel">
        <div className="invoices-panel-heading"><div><h2>Liste des factures</h2><p>{invoices.length} facture{invoices.length !== 1 ? 's' : ''}</p></div><button className="refresh-button" type="button" onClick={loadInvoices}>Actualiser</button></div>
        {isLoading ? <p className="empty-state">Chargement des factures...</p> : invoices.length === 0 ? <p className="empty-state">Aucune facture pour le moment. Créez votre première facture.</p> : <div className="invoices-table"><div className="invoice-row invoice-row-head"><span>N° FACTURE</span><span>CLIENT</span><span>DATE</span><span>ÉCHÉANCE</span><span>MONTANT</span><span>STATUT</span><span>ACTIONS</span></div>{invoices.map((invoice) => <div className="invoice-row" key={invoice.id}><strong>{invoice.invoice_number}</strong><span>{invoice.client?.name || '—'}</span><span>{formatDate(invoice.invoice_date)}</span><span>{formatDate(invoice.due_date)}</span><strong>{formatPrice(invoice.total_amount)}</strong><select className={`status-select status-${invoice.status}`} value={invoice.status} onChange={(event) => handleStatusChange(invoice, event.target.value)}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><div className="invoice-actions"><button type="button" onClick={() => openEditForm(invoice)}>Modifier</button><button className="delete-button" type="button" onClick={() => handleDelete(invoice)}>Supprimer</button></div></div>)}</div>}
      </section>
    </section>
  )
}

export default Invoices