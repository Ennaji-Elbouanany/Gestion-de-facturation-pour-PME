import RemoteSelect from './RemoteSelect'
import '../../styles/ListControls.css'

export function PageControls({ meta, busy, onPage }) {
  if (!meta) return null
  return <nav className="list-pagination" aria-label="Pagination">
    <button type="button" disabled={busy || !meta.prev_page_url} onClick={() => onPage(meta.current_page - 1)}>Précédent</button>
    <span role="status">Page {meta.current_page} sur {meta.last_page} · {meta.total} résultat(s)</span>
    <button type="button" disabled={busy || !meta.next_page_url} onClick={() => onPage(meta.current_page + 1)}>Suivant</button>
  </nav>
}

export default function ListControls({ list, statuses, archives = false, clients = false }) {
  return <div className="list-controls">
    <div className="list-filters">
      <label>Rechercher<input type="search" maxLength={150} value={list.filters.q || ''} onChange={(event) => list.setFilter('q', event.target.value)} placeholder="Nom ou référence…" /></label>
      <label>Du<input type="date" value={list.filters.from || ''} onChange={(event) => list.setFilter('from', event.target.value)} /></label>
      <label>Au<input type="date" value={list.filters.to || ''} onChange={(event) => list.setFilter('to', event.target.value)} /></label>
      {statuses && <label>Statut<select value={list.filters.status || ''} onChange={(event) => list.setFilter('status', event.target.value)}><option value="">Tous</option>{Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>}
      {archives && <label>Archives<select value={list.filters.archived || 'all'} onChange={(event) => list.setFilter('archived', event.target.value)}><option value="all">Tous les documents</option><option value="0">Non archivés</option><option value="1">Archivés</option></select></label>}
      {clients && <label>Client<RemoteSelect resource="clients" name="client_id" value={list.filters.client_id || ''} onChange={(event) => list.setFilter('client_id', event.target.value)} /></label>}
    </div>
    {list.error && <p role="alert">{list.error} <button type="button" onClick={() => list.load()}>Réessayer</button></p>}
    <PageControls meta={list.meta} busy={list.loading} onPage={list.setPage} />
  </div>
}
