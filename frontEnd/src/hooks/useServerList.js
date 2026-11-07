import { useCallback, useEffect, useState } from 'react'
import { apiRequest } from '../services/api'
import { latestRequests, listQuery, readPage } from '../services/lists'

export function useServerList(path, initial = {}) {
  const [items, setItems] = useState([])
  const [meta, setMeta] = useState(null)
  const [filters, setFilters] = useState(initial)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [requests] = useState(latestRequests)
  const query = listQuery(filters, page)
  const key = `${path}?${query}`
  const load = useCallback(async (requestedPage) => {
    if (typeof requestedPage === 'number' && requestedPage !== page) { setPage(requestedPage); return false }
    const token = requests.begin(key)
    if (token === null) return false
    setLoading(true)
    setError('')
    try {
      const payload = await apiRequest(key)
      if (!requests.current(key, token)) return false
      const result = readPage(payload)
      if (!result.items.length && page > Math.max(1, payload.last_page || 1)) { setPage(Math.max(1, payload.last_page || 1)); return false }
      setItems(result.items); setMeta(result.meta)
      return true
    } catch (failure) { if (requests.current(key, token)) setError(failure.message); return false }
    finally { if (requests.current(key, token)) setLoading(false) }
  }, [key, page, requests])
  useEffect(() => {
    requests.activate(key)
    const timer = setTimeout(() => { load() }, 250)
    return () => { clearTimeout(timer); requests.activate('') }
  }, [key, load, requests])
  const setFilter = (name, value) => {
    if (filters[name] === value) return
    requests.activate(''); setLoading(true)
    setFilters((current) => ({ ...current, [name]: value })); setPage(1)
  }
  const goPage = (next) => { if (next !== page) { requests.activate(''); setLoading(true); setPage(next) } }
  return { items, setItems, meta, filters, setFilter, page, setPage: goPage, loading, load, error }
}
