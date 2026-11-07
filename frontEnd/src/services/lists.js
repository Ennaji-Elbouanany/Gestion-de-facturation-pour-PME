export function listQuery(filters = {}, page = 1) {
  const query = new URLSearchParams({ per_page: '25', page: String(page) })
  for (const [key, value] of Object.entries(filters)) if (value !== '' && value !== null && value !== undefined) query.set(key, String(value))
  return query.toString()
}
export function readPage(response) {
  if (!response || !Array.isArray(response.data)) throw new Error('Réponse de liste invalide. Rechargez la page.')
  return { items: response.data, meta: response }
}
export function latestRequests() {
  let active = ''; let sequence = 0
  return { activate(key) { active = key; sequence++ }, begin(key) { return key === active ? ++sequence : null }, current(key, token) { return key === active && token === sequence } }
}
