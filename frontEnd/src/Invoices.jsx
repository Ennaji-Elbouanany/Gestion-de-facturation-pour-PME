import { useCallback, useEffect, useState } from 'react'
import './Invoices.css'
import './Payments.css'
import './PaidInvoice.css'
import './PaymentDeadline.css'

const emptyItem = { product_id: '', description: '', quantity: '1', unit_price: '', tax_rate: '' }

const emptyForm = {
  client_id: '',
  invoice_date: new Date().toISOString().slice(0, 10),
  due_date: '',
  status: 'draft',
  notes: '',
  items: [{ ...emptyItem }],
}

const emptyPaymentForm = {
  amount: '',
  payment_date: new Date().toISOString().slice(0, 10),
  payment_method: 'cash',
  reference: '',
  notes: '',
}

const paymentMethodLabels = {
  cash: 'Espèces',
  bank_transfer: 'Virement bancaire',
  cheque: 'Chèque',
  credit_card: 'Carte bancaire',
  digital_wallet: 'Portefeuille numérique',
  other: 'Autre',
}

const statusLabels = {
  draft: 'Brouillon',
  sent: 'Envoyée',
  paid: 'Payée',
  partial: 'Partielle',
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
  const [selectedInvoice, setSelectedInvoice] = useState(null)
  const [payments, setPayments] = useState([])
  const [paymentForm, setPaymentForm] = useState(emptyPaymentForm)
  const [editingPayment, setEditingPayment] = useState(null)
  const [isPaymentsLoading, setIsPaymentsLoading] = useState(false)
  const [isPaymentSubmitting, setIsPaymentSubmitting] = useState(false)

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

  async function refreshInvoice(invoiceId) {
    const refreshedInvoice = await request(`/api/invoices/${invoiceId}`)
    setInvoices((currentInvoices) => currentInvoices.map((invoice) => invoice.id === refreshedInvoice.id ? refreshedInvoice : invoice))
    setSelectedInvoice(refreshedInvoice)
    return refreshedInvoice
  }

  async function openPaymentsManager(invoice) {
    setSelectedInvoice(invoice)
    setPayments([])
    setEditingPayment(null)
    setPaymentForm({ ...emptyPaymentForm, payment_date: new Date().toISOString().slice(0, 10) })
    setMessage('')
    setIsPaymentsLoading(true)

    try {
      const [paymentList, refreshedInvoice] = await Promise.all([
        request(`/api/invoices/${invoice.id}/payments`),
        request(`/api/invoices/${invoice.id}`),
      ])
      setPayments(paymentList)
      setSelectedInvoice(refreshedInvoice)
      setInvoices((currentInvoices) => currentInvoices.map((currentInvoice) => currentInvoice.id === refreshedInvoice.id ? refreshedInvoice : currentInvoice))
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsPaymentsLoading(false)
    }
  }

  function closePaymentsManager() {
    setSelectedInvoice(null)
    setPayments([])
    setEditingPayment(null)
    setPaymentForm(emptyPaymentForm)
  }

  function handlePaymentFormChange(event) {
    const { name, value } = event.target
    setPaymentForm((currentPayment) => ({ ...currentPayment, [name]: value }))
    setMessage('')
  }

  function startEditPayment(payment) {
    setEditingPayment(payment)
    setPaymentForm({
      amount: payment.amount || '',
      payment_date: payment.payment_date || '',
      payment_method: payment.payment_method || 'cash',
      reference: payment.reference || '',
      notes: payment.notes || '',
    })
    setMessage('')
  }

  function cancelEditPayment() {
    setEditingPayment(null)
    setPaymentForm({ ...emptyPaymentForm, payment_date: new Date().toISOString().slice(0, 10) })
  }

  function paidTotal() {
    return payments.reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0)
  }

  function remainingBalance() {
    return Math.max(0, (Number(selectedInvoice?.total_amount) || 0) - paidTotal())
  }

  function isInvoicePaid() {
    const total = Number(selectedInvoice?.total_amount) || 0
    return selectedInvoice?.status === 'paid' || (total > 0 && paidTotal() >= total)
  }

  function isInvoicePartial() {
    const total = Number(selectedInvoice?.total_amount) || 0
    return selectedInvoice?.status === 'partial' || (total > 0 && paidTotal() > 0 && paidTotal() < total)
  }

  async function handlePaymentSubmit(event) {
    event.preventDefault()
    if (!selectedInvoice) return

    setIsPaymentSubmitting(true)
    setMessage('')

    try {
      const path = editingPayment
        ? `/api/invoices/${selectedInvoice.id}/payments/${editingPayment.id}`
        : `/api/invoices/${selectedInvoice.id}/payments`
      const payment = await request(path, {
        method: editingPayment ? 'PUT' : 'POST',
        body: JSON.stringify(paymentForm),
      })

      setPayments((currentPayments) => editingPayment
        ? currentPayments.map((currentPayment) => currentPayment.id === payment.id ? payment : currentPayment)
        : [payment, ...currentPayments])
      await refreshInvoice(selectedInvoice.id)
      cancelEditPayment()
      setMessage(editingPayment ? 'Paiement mis à jour avec succès.' : 'Paiement enregistré avec succès.')
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsPaymentSubmitting(false)
    }
  }

  async function handlePaymentDelete(payment) {
    if (!selectedInvoice || !window.confirm('Supprimer ce paiement ?')) return

    try {
      await request(`/api/invoices/${selectedInvoice.id}/payments/${payment.id}`, { method: 'DELETE' })
      setPayments((currentPayments) => currentPayments.filter((currentPayment) => currentPayment.id !== payment.id))
      await refreshInvoice(selectedInvoice.id)
      if (editingPayment?.id === payment.id) cancelEditPayment()
      setMessage('Paiement supprimé avec succès.')
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

  function getPaymentDeadlineState(invoice) {
    if (!invoice.due_date || ['paid', 'cancelled'].includes(invoice.status)) return null

    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const dueDate = new Date(`${invoice.due_date}T00:00:00`)
    const daysUntilDue = Math.ceil((dueDate - today) / 86400000)

    if (daysUntilDue < 0) return 'overdue'
    if (daysUntilDue <= 3) return 'due-soon'
    return null
  }

  function paymentDeadlineLabel(invoice) {
    const deadlineState = getPaymentDeadlineState(invoice)
    if (deadlineState === 'overdue') return 'En retard'
    if (deadlineState === 'due-soon') return 'Échéance proche'
    return null
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

      {selectedInvoice && <section className="payments-panel">
        <div className="payments-panel-heading">
          <div><h2>Paiements — {selectedInvoice.invoice_number}</h2><p>Suivez les règlements et le solde de cette facture.</p></div>
          <button className="cancel-button" type="button" onClick={closePaymentsManager}>Fermer</button>
        </div>

        <div className="payment-summary">
          <div><span>Total facture</span><strong>{formatPrice(selectedInvoice.total_amount)}</strong></div>
          <div><span>Déjà encaissé</span><strong>{formatPrice(paidTotal())}</strong></div>
          <div className={remainingBalance() > 0 ? 'payment-balance' : 'payment-balance is-settled'}><span>Solde restant</span><strong>{formatPrice(remainingBalance())}</strong></div>
        </div>

        {isInvoicePaid() && <p className="invoice-paid-message" role="status"><span aria-hidden="true">✓</span> Cette facture est entièrement payée.</p>}
        {isInvoicePartial() && <p className="invoice-partial-message" role="status"><span aria-hidden="true">◷</span> Paiement partiel : il reste {formatPrice(remainingBalance())} à régler.</p>}

        {selectedInvoice.status === 'cancelled' ? <p className="payment-disabled">Cette facture est annulée : aucun paiement ne peut être ajouté.</p> : <form className="payment-form" onSubmit={handlePaymentSubmit}>
          <div className="payment-form-heading"><div><h3>{editingPayment ? 'Modifier le paiement' : 'Enregistrer un paiement'}</h3><p>Le montant ne peut pas dépasser le solde restant.</p></div>{editingPayment && <button className="close-button" type="button" onClick={cancelEditPayment} aria-label="Annuler la modification">×</button>}</div>
          <div className="payment-form-grid">
            <label>Montant *<input name="amount" type="number" min="0.01" max={editingPayment ? undefined : remainingBalance()} step="0.01" value={paymentForm.amount} onChange={handlePaymentFormChange} required /></label>
            <label>Date de paiement *<input name="payment_date" type="date" value={paymentForm.payment_date} onChange={handlePaymentFormChange} required /></label>
            <label>Mode de paiement *<select name="payment_method" value={paymentForm.payment_method} onChange={handlePaymentFormChange} required>{Object.entries(paymentMethodLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label>Référence<input name="reference" value={paymentForm.reference} onChange={handlePaymentFormChange} maxLength="100" placeholder="Ex. VIRT-2026-001" /></label>
          </div>
          <label className="payment-notes-label">Notes<textarea name="notes" value={paymentForm.notes} onChange={handlePaymentFormChange} placeholder="Information complémentaire (optionnel)" /></label>
          <div className="invoice-form-actions"><button className="cancel-button" type="button" onClick={cancelEditPayment}>Annuler</button><button className="primary-button" type="submit" disabled={isPaymentSubmitting}>{isPaymentSubmitting ? 'Enregistrement...' : editingPayment ? 'Mettre à jour' : 'Ajouter le paiement'}</button></div>
        </form>}

        <div className="payments-history">
          <div className="payments-history-heading"><h3>Historique des paiements</h3><button className="refresh-button" type="button" onClick={() => openPaymentsManager(selectedInvoice)}>Actualiser</button></div>
          {isPaymentsLoading ? <p className="empty-state">Chargement des paiements...</p> : payments.length === 0 ? <p className="empty-state">Aucun paiement enregistré pour cette facture.</p> : <div className="table-scroll"><table className="data-table payments-table"><thead><tr><th>DATE</th><th>MODE</th><th>RÉFÉRENCE</th><th className="num">MONTANT</th><th className="actions-col">ACTIONS</th></tr></thead><tbody>{payments.map((payment) => <tr key={payment.id}><td>{formatDate(payment.payment_date)}</td><td>{paymentMethodLabels[payment.payment_method] || payment.payment_method}</td><td>{payment.reference || '—'}</td><td className="num"><strong>{formatPrice(payment.amount)}</strong></td><td className="actions-cell"><div className="invoice-actions"><button type="button" onClick={() => startEditPayment(payment)}>Modifier</button><button className="delete-button" type="button" onClick={() => handlePaymentDelete(payment)}>Supprimer</button></div></td></tr>)}</tbody></table></div>}
        </div>
      </section>}

      <section className="invoices-panel">
        <div className="invoices-panel-heading"><div><h2>Liste des factures</h2><p>{invoices.length} facture{invoices.length !== 1 ? 's' : ''}</p></div><div className="invoices-panel-tools"><label className="payment-invoice-picker">Gérer les paiements<select value={selectedInvoice?.id || ''} onChange={(event) => { const invoice = invoices.find((item) => String(item.id) === event.target.value); if (invoice) openPaymentsManager(invoice) }}><option value="">Sélectionner une facture</option>{invoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.invoice_number}</option>)}</select></label><button className="refresh-button" type="button" onClick={loadInvoices}>Actualiser</button></div></div>
        {isLoading ? <p className="empty-state">Chargement des factures...</p> : invoices.length === 0 ? <p className="empty-state">Aucune facture pour le moment. Créez votre première facture.</p> : <div className="table-scroll"><table className="data-table invoices-table"><thead><tr><th className="ref">N° FACTURE</th><th>CLIENT</th><th>DATE</th><th>ÉCHÉANCE</th><th className="num">HT</th><th className="num">TVA</th><th className="num">TTC</th><th className="center">PAIEMENT</th><th className="actions-col">ACTIONS</th></tr></thead><tbody>{invoices.map((invoice) => <tr key={invoice.id}><td className="ref"><strong title={invoice.invoice_number}>{invoice.invoice_number}</strong></td><td><span className="truncate" title={invoice.client?.name || '—'}>{invoice.client?.name || '—'}</span></td><td className="date">{formatDate(invoice.invoice_date)}</td><td className="date"><span className={`invoice-due-date ${getPaymentDeadlineState(invoice) || ''}`}>{formatDate(invoice.due_date)}{paymentDeadlineLabel(invoice) && <small>{paymentDeadlineLabel(invoice)}</small>}</span></td><td className="num">{formatPrice(invoice.subtotal)}</td><td className="num">{formatPrice(invoice.tax_amount)}</td><td className="num ttc"><strong>{formatPrice(invoice.total_amount)}</strong></td><td className="center"><select className={`status-select status-${invoice.status}`} value={invoice.status} onChange={(event) => handleStatusChange(invoice, event.target.value)}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></td><td className="actions-cell"><div className="invoice-actions"><button className="payment-button" type="button" onClick={() => openPaymentsManager(invoice)}>Paiements</button><button type="button" onClick={() => openEditForm(invoice)}>Modifier</button><button className="delete-button" type="button" onClick={() => handleDelete(invoice)}>Supprimer</button></div></td></tr>)}</tbody></table></div>}
      </section>
    </section>
  )
}

export default Invoices
