// UI hints only: every operation is authorized again by Laravel.
export function allows(permissions = {}, ability, record) {
  if (ability === 'invoices.update' && record) {
    return !record.archived_at && (record.document_status || record.status) === 'draft' && Boolean(permissions[ability] || permissions['invoices.updateDraft'])
  }
  if (!permissions[ability]) return false
  if (!record) return true
  if (ability.endsWith('.archive') || ability.endsWith('.restore')) return record.status === undefined || (record.document_status || record.status) === 'draft'
  if (ability === 'quotes.convert') return !record.archived_at && record.status === 'accepted'
  if (ability === 'quotes.update') return !record.archived_at && record.status === 'draft'
  if (ability === 'quotes.updateStatus') return !record.archived_at && ['draft', 'sent'].includes(record.status)
  if (ability.endsWith('.update') || ability.endsWith('.updateStatus') || ability === 'payments.create') return !record.archived_at
  return true
}
