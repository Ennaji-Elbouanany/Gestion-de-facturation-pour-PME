import { useServerList } from '../../hooks/useServerList'
import ListControls from '../shared/ListControls'
import { useState } from 'react'
import FieldError from '../shared/FieldError'
import { fieldProps, validateProduct } from '../../services/formValidation'
import { useSubmission } from '../../hooks/useSubmission'
import { apiRequest } from '../../services/api'
import { usePermissions } from '../../hooks/usePermissions'
import './Products.css'

const emptyForm = { name: '', description: '', unit_price: '', tax_rate: '' }

function Products() {
  const { can } = usePermissions()
  const list = useServerList('/api/products', {})
  const { items: products, setItems: setProducts, loading: isLoading, load: loadProducts } = list
  const pagination = list.meta
  const [formData, setFormData] = useState(emptyForm)
  const [editingProduct, setEditingProduct] = useState(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [isSubmitting, submit] = useSubmission()
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState({})



  function handleChange(event) {
    setFormData((currentData) => ({
      ...currentData,
      [event.target.name]: event.target.value,
    }))
    setMessage('')
    setErrors({})
  }

  function openCreateForm() {
    setEditingProduct(null)
    setFormData(emptyForm)
    setMessage('')
    setErrors({})
    setIsFormOpen(true)
  }

  function openEditForm(product) {
    setEditingProduct(product)
    setFormData({
      name: product.name || '',
      description: product.description || '',
      unit_price: product.unit_price || '',
      tax_rate: product.tax_rate || '',
    })
    setMessage('')
    setErrors({})
    setIsFormOpen(true)
  }

  function closeForm() {
    setIsFormOpen(false)
    setEditingProduct(null)
    setFormData(emptyForm)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const validation = validateProduct(formData)
    if (Object.keys(validation).length) { setErrors(validation); setMessage('Vérifiez les champs du formulaire.'); return }
    return submit(async () => {
      setMessage('')
      setErrors({})

      try {
        const path = editingProduct ? `/api/products/${editingProduct.id}` : '/api/products'
        const method = editingProduct ? 'PUT' : 'POST'
        await apiRequest(path, {
          method,
          body: JSON.stringify(formData),
        })

        closeForm()
      await loadProducts()
      } catch (error) {
        setErrors(error.errors || {})
        setMessage(error.message)
      }
    })
  }

  async function handleDelete(product) {
    if (!window.confirm(`Supprimer le produit ${product.name} ?`)) return

    return submit(async () => {
      try {
        await apiRequest(`/api/products/${product.id}`, { method: 'DELETE' })
        setProducts((currentProducts) => currentProducts.filter((item) => item.id !== product.id))
        setMessage('Produit supprimé avec succès.')
      await loadProducts()
      } catch (error) {
        setErrors(error.errors || {})
        setMessage(error.message)
      }
    })
  }

  function formatPrice(value) {
    const amount = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    return `${amount.format(Number(value) || 0)} DH`
  }

  return (
    <section className="products-page">
      <div className="products-heading">
        <div><p className="dashboard-kicker">Catalogue</p><h1>Produits</h1><p className="products-subtitle">Gérez les produits et services de votre entreprise.</p></div>
        {can('products.create') && <button className="primary-button" type="button" onClick={openCreateForm}><span aria-hidden="true">＋</span> Nouveau produit</button>}
      </div>

      {message && <p className="products-message" role="alert">{message}</p>}

      {isFormOpen && <form className="product-form" onSubmit={handleSubmit}>
        <div className="product-form-heading"><div><h2>{editingProduct ? 'Modifier le produit' : 'Nouveau produit'}</h2><p>Les champs marqués sont nécessaires.</p></div><button className="close-button" type="button" onClick={closeForm} aria-label="Fermer">×</button></div>
        <div className="product-form-grid">
          <label>Nom du produit *<input name="name" {...fieldProps('name', errors, 'product')} value={formData.name} onChange={handleChange} required maxLength={255} /><FieldError name="name" errors={errors} prefix="product" /></label>
          <label>Prix unitaire (DH) *<input name="unit_price" {...fieldProps('unit_price', errors, 'product')} type="number" step="0.01" min="0" max="9999999999.99" value={formData.unit_price} onChange={handleChange} required /><FieldError name="unit_price" errors={errors} prefix="product" /></label>
          <label>Taux de TVA (%)<input name="tax_rate" {...fieldProps('tax_rate', errors, 'product')} type="number" step="0.01" min="0" max="100" value={formData.tax_rate} onChange={handleChange} /><FieldError name="tax_rate" errors={errors} prefix="product" /></label>
          <label className="product-form-full">Description<input name="description" {...fieldProps('description', errors, 'product')} maxLength={5000} value={formData.description} onChange={handleChange} /><FieldError name="description" errors={errors} prefix="product" /></label>
        </div>
        <div className="product-form-actions"><button className="cancel-button" type="button" onClick={closeForm}>Annuler</button><button className="primary-button" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Enregistrement...' : 'Enregistrer'}</button></div>
      </form>}

      <ListControls list={list} />
      <section className="products-panel">
        <div className="products-panel-heading"><div><h2>Liste des produits</h2><p>{pagination?.total ?? products.length} produit{products.length !== 1 ? 's' : ''}</p></div><button className="refresh-button" type="button" onClick={loadProducts}>Actualiser</button></div>
        {isLoading ? <p className="empty-state">Chargement des produits...</p> : products.length === 0 ? <p className="empty-state">Aucun produit pour le moment. Ajoutez votre premier produit.</p> : <div className="products-table"><div className="product-row product-row-head"><span>PRODUIT</span><span>DESCRIPTION</span><span>PRIX UNITAIRE</span><span>TVA</span><span>ACTIONS</span></div>{products.map((product) => <div className="product-row" key={product.id}><strong>{product.name}</strong><span>{product.description || '—'}</span><span>{formatPrice(product.unit_price)}</span><span>{product.tax_rate ? `${product.tax_rate} %` : '—'}</span><div className="product-actions">{can('products.update') && <button type="button" onClick={() => openEditForm(product)}>Modifier</button>}{can('products.delete') && <button className="delete-button" type="button" disabled={isSubmitting} onClick={() => handleDelete(product)}>Supprimer</button>}</div></div>)}</div>}
      </section>
    </section>
  )
}

export default Products
