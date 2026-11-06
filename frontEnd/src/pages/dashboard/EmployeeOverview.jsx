import { useEffect, useState } from 'react'
import { apiRequest } from '../../services/api'

export default function EmployeeOverview({ onNavigate }) {
  const [summary, setSummary] = useState(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    apiRequest('/api/dashboard/summary').then((result) => { if (active) setSummary(result) })
      .catch((failure) => { if (active) setError(failure.message) })
    return () => { active = false }
  }, [])
  return (
    <section className="clients-page">
      <h1>Vue d’ensemble</h1>
      <p>Votre espace commercial. Les règlements et les statistiques financières sont réservés aux administrateurs et comptables.</p>
      {error && <p role="alert">{error}</p>}
      {!summary && !error && <p role="status">Chargement...</p>}
      {summary && <div className="stats-strip">
        {[['clients_count', 'Clients'], ['products_count', 'Produits'], ['invoices_count', 'Factures'], ['quotes_count', 'Devis']].map(([key, label]) => (
          <button type="button" key={key} onClick={() => onNavigate(label)}>{label} : {summary[key]}</button>
        ))}
      </div>}
    </section>
  )
}
