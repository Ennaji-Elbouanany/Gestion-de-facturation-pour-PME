import { useCallback, useEffect, useState } from 'react'
import './Quotes.css'

const emptyItem = { product_id: '', description: '', quantity: '1', unit_price: '', tax_rate: '' }

const emptyForm = {
  client_id: '',
  quote_date: new Date().toISOString().slice(0, 10),
  valid_until: '',
  status: 'draft',
  notes: '',
  items: [{ ...emptyItem }],
}

const statusLabels = {
  draft: 'Brouillon',
  sent: 'Envoyé',
  accepted: 'Accepté',
  rejected: 'Refusé',
  expired: 'Expiré',
  converted: 'Converti',
}

function Quotes() {
  const [quotes, setQuotes] = useState([])
  const [clients, setClients] = useState([])
  const [products, setProducts] = useState([])
  const [formData, setFormData] = useState(emptyForm)
  const [editingQuote, setEditingQuote] = useState(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(true)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [message, setMessage] = useState('')
  const [selectedQuote, setSelectedQuote] = useState(null)
  const [quoteItems, setQuoteItems] = useState([])
  const [isItemsLoading, setIsItemsLoading] = useState(false)
  const [itemForm, setItemForm] = useState({ ...emptyItem })
  const [editingItem, setEditingItem] = useState(null)
  const [isItemSubmitting, setIsItemSubmitting] = useState(false)

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

  const loadQuotes = useCallback(async () => {
    setIsLoading(true)
    setMessage('')

    try {
      const response = await request('/api/quotes')
      setQuotes(response)
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
      loadQuotes()
      loadClients()
      loadProducts()
    })
  }, [loadQuotes, loadClients, loadProducts])

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
    setEditingQuote(null)
    setFormData({ ...emptyForm, quote_date: new Date().toISOString().slice(0, 10) })
    setMessage('')
    setIsFormOpen(true)
  }

  function openEditForm(quote) {
    setEditingQuote(quote)
    setFormData({
      client_id: String(quote.client_id || ''),
      quote_date: quote.quote_date || '',
      valid_until: quote.valid_until || '',
      status: quote.status || 'draft',
      notes: quote.notes || '',
      items: quote.items.length > 0
        ? quote.items.map((item) => ({
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
    setEditingQuote(null)
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
      const path = editingQuote ? `/api/quotes/${editingQuote.id}` : '/api/quotes'
      const method = editingQuote ? 'PUT' : 'POST'
      const quote = await request(path, {
        method,
        body: JSON.stringify(formData),
      })

      setQuotes((currentQuotes) => editingQuote
        ? currentQuotes.map((currentQuote) => currentQuote.id === quote.id ? quote : currentQuote)
        : [quote, ...currentQuotes])
      closeForm()
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleDelete(quote) {
    if (!window.confirm(`Supprimer le devis ${quote.quote_number} ?`)) return

    try {
      await request(`/api/quotes/${quote.id}`, { method: 'DELETE' })
      setQuotes((currentQuotes) => currentQuotes.filter((item) => item.id !== quote.id))
      setMessage('Devis supprimé avec succès.')
    } catch (error) {
      setMessage(error.message)
    }
  }

  async function handleStatusChange(quote, status) {
    try {
      const updatedQuote = await request(`/api/quotes/${quote.id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      })
      setQuotes((currentQuotes) => currentQuotes.map((currentQuote) => currentQuote.id === updatedQuote.id ? updatedQuote : currentQuote))
      setMessage('Statut mis à jour avec succès.')
    } catch (error) {
      setMessage(error.message)
    }
  }

  async function handleConvertToInvoice(quote) {
    if (!window.confirm(`Convertir le devis ${quote.quote_number} en facture ?`)) return

    try {
      const invoice = await request(`/api/quotes/${quote.id}/convert`, { method: 'POST' })
      setQuotes((currentQuotes) => currentQuotes.map((currentQuote) => currentQuote.id === quote.id ? { ...currentQuote, status: 'converted' } : currentQuote))
      setMessage(`Devis converti en facture ${invoice.invoice_number} avec succès.`)
    } catch (error) {
      setMessage(error.message)
    }
  }

  async function openItemsManager(quote) {
    setSelectedQuote(quote)
    setEditingItem(null)
    setItemForm({ ...emptyItem })
    setMessage('')
    await loadQuoteItems(quote.id)
  }

  function closeItemsManager() {
    setSelectedQuote(null)
    setQuoteItems([])
    setEditingItem(null)
    setItemForm({ ...emptyItem })
  }

  async function loadQuoteItems(quoteId) {
    setIsItemsLoading(true)

    try {
      const response = await request(`/api/quotes/${quoteId}/items`)
      setQuoteItems(response)
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsItemsLoading(false)
    }
  }

  function handleItemFormChange(event) {
    const { name, value } = event.target

    setItemForm((currentItem) => {
      const updatedItem = { ...currentItem, [name]: value }

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
    setMessage('')
  }

  function startEditItem(item) {
    setEditingItem(item)
    setItemForm({
      product_id: item.product_id ? String(item.product_id) : '',
      description: item.description || '',
      quantity: item.quantity || '1',
      unit_price: item.unit_price || '',
      tax_rate: item.tax_rate || '',
    })
    setMessage('')
  }

  function cancelEditItem() {
    setEditingItem(null)
    setItemForm({ ...emptyItem })
  }

  async function handleItemSubmit(event) {
    event.preventDefault()
    if (!selectedQuote) return
    setIsItemSubmitting(true)
    setMessage('')

    try {
      const path = editingItem
        ? `/api/quotes/${selectedQuote.id}/items/${editingItem.id}`
        : `/api/quotes/${selectedQuote.id}/items`
      const method = editingItem ? 'PUT' : 'POST'
      const savedItem = await request(path, {
        method,
        body: JSON.stringify(itemForm),
      })

      setQuoteItems((currentItems) => editingItem
        ? currentItems.map((currentItem) => currentItem.id === savedItem.id ? savedItem : currentItem)
        : [savedItem, ...currentItems])
      setEditingItem(null)
      setItemForm({ ...emptyItem })
      setMessage(editingItem ? 'Ligne mise à jour avec succès.' : 'Ligne ajoutée avec succès.')
      loadQuotes()
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsItemSubmitting(false)
    }
  }

  async function handleItemDelete(item) {
    if (!selectedQuote) return
    if (!window.confirm(`Supprimer la ligne "${item.description}" ?`)) return

    try {
      await request(`/api/quotes/${selectedQuote.id}/items/${item.id}`, { method: 'DELETE' })
      setQuoteItems((currentItems) => currentItems.filter((currentItem) => currentItem.id !== item.id))
      setMessage('Ligne supprimée avec succès.')
      loadQuotes()
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
    <section className="quotes-page">
      <div className="quotes-heading">
        <div><p className="dashboard-kicker">Devis</p><h1>Devis</h1><p className="quotes-subtitle">Gérez les devis et leurs lignes via l API dédiée.</p></div>
        <button className="primary-button" type="button" onClick={openCreateForm}><span aria-hidden="true">+</span> Nouveau devis</button>
      </div>

      {message && <p className="quotes-message" role="alert">{message}</p>}

      {isFormOpen && <form className="quote-form" onSubmit={handleSubmit}>
        <div className="quote-form-heading"><div><h2>{editingQuote ? `Modifier le devis ${editingQuote.quote_number}` : 'Nouveau devis'}</h2><p>Les champs marqués sont nécessaires.</p></div><button className="close-button" type="button" onClick={closeForm} aria-label="Fermer">x</button></div>

        <div className="quote-form-grid">
          <label>Client *<select name="client_id" value={formData.client_id} onChange={handleChange} required><option value="">Selectionner un client</option>{clients.map((client) => <option key={client.id} value={client.id}>{client.name}</option>)}</select></label>
          <label>Date du devis *<input name="quote_date" type="date" value={formData.quote_date} onChange={handleChange} required /></label>
          <label>Valide jusqu au<input name="valid_until" type="date" value={formData.valid_until} onChange={handleChange} /></label>
          <label>Statut<select name="status" value={formData.status} onChange={handleChange}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        </div>

        <div className="quote-items-section">
          <div className="quote-items-heading"><h3>Lignes du devis</h3><button className="add-item-button" type="button" onClick={addItem}>+ Ajouter une ligne</button></div>

          <div className="quote-items-table">
            <div className="quote-item-row quote-item-head"><span>PRODUIT</span><span>DESCRIPTION</span><span>QTE</span><span>PRIX UNITAIRE</span><span>TVA %</span><span>MONTANT</span><span /></div>
            {formData.items.map((item, index) => (
              <div className="quote-item-row" key={index}>
                <select name="product_id" value={item.product_id} onChange={(event) => handleItemChange(index, event)}><option value="">-</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select>
                <input name="description" value={item.description} onChange={(event) => handleItemChange(index, event)} placeholder="Description" required />
                <input name="quantity" type="number" step="0.01" min="0.01" value={item.quantity} onChange={(event) => handleItemChange(index, event)} required />
                <input name="unit_price" type="number" step="0.01" min="0" value={item.unit_price} onChange={(event) => handleItemChange(index, event)} required />
                <input name="tax_rate" type="number" step="0.01" min="0" max="100" value={item.tax_rate} onChange={(event) => handleItemChange(index, event)} />
                <strong>{formatPrice(calculateItemTotal(item))}</strong>
                <button className="remove-item-button" type="button" onClick={() => removeItem(index)} aria-label="Supprimer la ligne">x</button>
              </div>
            ))}
          </div>

          <div className="quote-totals">
            <div className="quote-total-row"><span>Sous-total (HT)</span><strong>{formatPrice(calculateSubtotal())}</strong></div>
            <div className="quote-total-row"><span>TVA</span><strong>{formatPrice(calculateTaxAmount())}</strong></div>
            <div className="quote-total-row quote-total-grand"><span>Total (TTC)</span><strong>{formatPrice(calculateTotal())}</strong></div>
          </div>
        </div>

        <label className="quote-notes-label">Notes<input name="notes" value={formData.notes} onChange={handleChange} placeholder="Notes internes (optionnel)" /></label>

        <div className="quote-form-actions"><button className="cancel-button" type="button" onClick={closeForm}>Annuler</button><button className="primary-button" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Enregistrement...' : 'Enregistrer'}</button></div>
      </form>}

      {selectedQuote && <section className="quotes-panel">
        <div className="quotes-panel-heading"><div><h2>Lignes du devis {selectedQuote.quote_number}</h2><p>{quoteItems.length} lignes via API quote_items dediee</p></div><div><button className="refresh-button" type="button" onClick={() => loadQuoteItems(selectedQuote.id)}>Actualiser</button> <button className="cancel-button" type="button" onClick={closeItemsManager}>Fermer</button></div></div>
        <form className="quote-form" onSubmit={handleItemSubmit}>
          <div className="quote-form-heading"><div><h2>{editingItem ? 'Modifier la ligne' : 'Nouvelle ligne'}</h2><p>Ajout direct via POST et PUT sur /api/quotes/{selectedQuote.id}/items.</p></div>{editingItem && <button className="close-button" type="button" onClick={cancelEditItem} aria-label="Annuler">x</button>}</div>
          <div className="quote-items-table">
            <div className="quote-item-row quote-item-head"><span>PRODUIT</span><span>DESCRIPTION</span><span>QTE</span><span>PRIX UNITAIRE</span><span>TVA %</span><span>MONTANT</span><span /></div>
            <div className="quote-item-row">
              <select name="product_id" value={itemForm.product_id} onChange={handleItemFormChange}><option value="">-</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select>
              <input name="description" value={itemForm.description} onChange={handleItemFormChange} placeholder="Description" required />
              <input name="quantity" type="number" step="0.01" min="0.01" value={itemForm.quantity} onChange={handleItemFormChange} required />
              <input name="unit_price" type="number" step="0.01" min="0" value={itemForm.unit_price} onChange={handleItemFormChange} required />
              <input name="tax_rate" type="number" step="0.01" min="0" max="100" value={itemForm.tax_rate} onChange={handleItemFormChange} />
              <strong>{formatPrice(calculateItemTotal(itemForm))}</strong>
              <span />
            </div>
          </div>
          <div className="quote-form-actions">{editingItem && <button className="cancel-button" type="button" onClick={cancelEditItem}>Annuler</button>}<button className="primary-button" type="submit" disabled={isItemSubmitting}>{isItemSubmitting ? 'Enregistrement...' : editingItem ? 'Mettre a jour' : 'Ajouter la ligne'}</button></div>
        </form>
        {isItemsLoading ? <p className="empty-state">Chargement des lignes...</p> : quoteItems.length === 0 ? <p className="empty-state">Aucune ligne pour ce devis.</p> : <div className="table-scroll"><table className="data-table quote-lines-table"><thead><tr><th>DESCRIPTION</th><th>PRODUIT</th><th>QTÉ</th><th className="num">PRIX UNITAIRE</th><th>TVA</th><th className="num">TOTAL</th><th className="actions-col">ACTIONS</th></tr></thead><tbody>{quoteItems.map((item) => <tr key={item.id}><td><strong>{item.description}</strong></td><td>{item.product ? item.product.name : '-'}</td><td>{item.quantity}</td><td className="num">{formatPrice(item.unit_price)}</td><td>{item.tax_rate ? `${item.tax_rate} %` : '-'}</td><td className="num"><strong>{formatPrice(item.total)}</strong></td><td className="actions-cell"><div className="quote-actions"><button type="button" onClick={() => startEditItem(item)}>Modifier</button><button className="delete-button" type="button" onClick={() => handleItemDelete(item)}>Supprimer</button></div></td></tr>)}</tbody></table></div>}
      </section>}

      <section className="quotes-panel">
        <div className="quotes-panel-heading"><div><h2>Liste des devis</h2><p>{quotes.length} devis</p></div><button className="refresh-button" type="button" onClick={loadQuotes}>Actualiser</button></div>
        {isLoading ? <p className="empty-state">Chargement des devis...</p> : quotes.length === 0 ? <p className="empty-state">Aucun devis pour le moment. Créez votre premier devis.</p> : <div className="table-scroll"><table className="data-table quotes-table"><thead><tr><th className="ref">RÉFÉRENCE</th><th>CLIENT</th><th>DATE</th><th>ÉCHÉANCE</th><th className="num">HT</th><th className="num">TVA</th><th className="num">TTC</th><th className="center">STATUT</th><th className="actions-col">ACTIONS</th></tr></thead><tbody>{quotes.map((quote) => <tr key={quote.id}><td className="ref"><strong title={quote.quote_number}>{quote.quote_number}</strong></td><td><span className="truncate" title={quote.client ? quote.client.name : '-'}>{quote.client ? quote.client.name : '-'}</span></td><td className="date">{formatDate(quote.quote_date)}</td><td className="date">{formatDate(quote.valid_until)}</td><td className="num">{formatPrice(quote.subtotal)}</td><td className="num">{formatPrice(quote.tax_amount)}</td><td className="num ttc"><strong>{formatPrice(quote.total_amount)}</strong></td><td className="center"><select className={`status-select status-${quote.status}`} value={quote.status} onChange={(event) => handleStatusChange(quote, event.target.value)}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></td><td className="actions-cell"><div className="quote-actions"><button type="button" onClick={() => openEditForm(quote)}>Modifier</button><button type="button" onClick={() => openItemsManager(quote)}>Lignes</button>{quote.status !== 'converted' && <button className="convert-button" type="button" onClick={() => handleConvertToInvoice(quote)}>Convertir</button>}<button className="delete-button" type="button" onClick={() => handleDelete(quote)}>Supprimer</button></div></td></tr>)}</tbody></table></div>}
      </section>
    </section>
  )
}

export default Quotes