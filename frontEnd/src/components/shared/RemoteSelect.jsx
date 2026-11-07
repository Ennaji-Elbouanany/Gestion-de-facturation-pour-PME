import { useEffect, useState } from 'react'
import { apiRequest } from '../../services/api'

export default function RemoteSelect({ resource, name, value, onChange, required = false, selectedLabel, disabled = false }) {
  const [search, setSearch] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const label = resource === 'clients' ? 'client' : 'produit'
  useEffect(() => {
    let active = true
    const timer = setTimeout(async () => {
      setLoading(true); setError('')
      try {
        const response = await apiRequest(`/api/${resource}?lookup=1&per_page=25&q=${encodeURIComponent(search)}`)
        if (active) setItems(response.data)
      } catch (failure) { if (active) setError(failure.message) }
      finally { if (active) setLoading(false) }
    }, 250)
    return () => { active = false; clearTimeout(timer) }
  }, [resource, search])
  return <span className="remote-select">
    <input type="search" aria-label={`Rechercher un ${label}`} placeholder={`Rechercher un ${label}…`} value={search} maxLength={150} disabled={disabled} onChange={(event) => setSearch(event.target.value)} />
    <select aria-label={`Sélectionner un ${label}`} aria-busy={loading} name={name} value={value} required={required} disabled={disabled} onChange={(event) => onChange(event, items.find((item) => String(item.id) === event.target.value))}>
      <option value="">— Sélectionner —</option>
      {value && !items.some((item) => String(item.id) === String(value)) && <option value={value}>{selectedLabel || `${label} #${value}`} (sélection actuelle)</option>}
      {items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
    </select>
    {loading && <small role="status">Recherche…</small>}
    {error && <small role="alert">{error}</small>}
  </span>
}
