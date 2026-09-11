import { useCallback, useEffect, useState } from 'react'
import './Products.css'

const emptyForm = { name: '', description: '', unit_price: '', tax_rate: '' }

function Products() {
  const [products, setProducts] = useState([])
  const [formData, setFormData] = useState(emptyForm)
  const [editingProduct, setEditingProduct] = useState(null)
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

  const loadProducts = useCallback(async () => {
    setIsLoading(true)
    setMessage('')

    try {
      const response = await request('/api/products')
      setProducts(response)
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsLoading(false)
    }
  }, [request])

  useEffect(() => {
    Promise.resolve().then(loadProducts)
  }, [loadProducts])

  function handleChange(event) {
    setFormData((currentData) => ({
      ...currentData,
      [event.target.name]: event.target.value,
    }))
    setMessage('')
  }

  function openCreateForm() {
    setEditingProduct(null)
    setFormData(emptyForm)
    setMessage('')
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
    setIsFormOpen(true)
  }

  function closeForm() {
    setIsFormOpen(false)
    setEditingProduct(null)
    setFormData(emptyForm)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSubmitting(true)
    setMessage('')

    try {
      const path = editingProduct ? `/api/products/${editingProduct.id}` : '/api/products'
      const method = editingProduct ? 'PUT' : 'POST'
      const product = await request(path, {
        method,
        body: JSON.stringify(formData),
      })

      setProducts((currentProducts) => editingProduct
        ? currentProducts.map((currentProduct) => currentProduct.id === product.id ? product : currentProduct)
        : [product, ...currentProducts])
      closeForm()
    } catch (error) {
      setMessage(error.message)
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleDelete(product) {
    if (!window.confirm(`Supprimer le produit ${product.name} ?`)) return

    try {
      await request(`/api/products/${product.id}`, { method: 'DELETE' })
      setProducts((currentProducts) => currentProducts.filter((item) => item.id !== product.id))
      setMessage('Produit supprimé avec succès.')
    } catch (error) {
      setMessage(error.message)
    }
  }

  function formatPrice(value) {
    const amount = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    return `${amount.format(Number(value) || 0)} DH`
  }

  return (
    <section className="products-page">
      <div className="products-heading">
        <div><p className="dashboard-kicker">Catalogue</p><h1>Produits</h1><p className="products-subtitle">Gérez les produits et services de votre entreprise.</p></div>
        <button className="primary-button" type="button" onClick={openCreateForm}><span aria-hidden="true">＋</span> Nouveau produit</button>
      </div>

      {message && <p className="products-message" role="alert">{message}</p>}

      {isFormOpen && <form className="product-form" onSubmit={handleSubmit}>
        <div className="product-form-heading"><div><h2>{editingProduct ? 'Modifier le produit' : 'Nouveau produit'}</h2><p>Les champs marqués sont nécessaires.</p></div><button className="close-button" type="button" onClick={closeForm} aria-label="Fermer">×</button></div>
        <div className="product-form-grid">
          <label>Nom du produit *<input name="name" value={formData.name} onChange={handleChange} required maxLength={255} /></label>
          <label>Prix unitaire (DH) *<input name="unit_price" type="number" step="0.01" min="0" value={formData.unit_price} onChange={handleChange} required /></label>
          <label>Taux de TVA (%)<input name="tax_rate" type="number" step="0.01" min="0" max="100" value={formData.tax_rate} onChange={handleChange} /></label>
          <label className="product-form-full">Description<input name="description" value={formData.description} onChange={handleChange} /></label>
        </div>
        <div className="product-form-actions"><button className="cancel-button" type="button" onClick={closeForm}>Annuler</button><button className="primary-button" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Enregistrement...' : 'Enregistrer'}</button></div>
      </form>}

      <section className="products-panel">
        <div className="products-panel-heading"><div><h2>Liste des produits</h2><p>{products.length} produit{products.length !== 1 ? 's' : ''}</p></div><button className="refresh-button" type="button" onClick={loadProducts}>Actualiser</button></div>
        {isLoading ? <p className="empty-state">Chargement des produits...</p> : products.length === 0 ? <p className="empty-state">Aucun produit pour le moment. Ajoutez votre premier produit.</p> : <div className="products-table"><div className="product-row product-row-head"><span>PRODUIT</span><span>DESCRIPTION</span><span>PRIX UNITAIRE</span><span>TVA</span><span>ACTIONS</span></div>{products.map((product) => <div className="product-row" key={product.id}><strong>{product.name}</strong><span>{product.description || '—'}</span><span>{formatPrice(product.unit_price)}</span><span>{product.tax_rate ? `${product.tax_rate} %` : '—'}</span><div className="product-actions"><button type="button" onClick={() => openEditForm(product)}>Modifier</button><button className="delete-button" type="button" onClick={() => handleDelete(product)}>Supprimer</button></div></div>)}</div>}
      </section>
    </section>
  )
}

export default Products