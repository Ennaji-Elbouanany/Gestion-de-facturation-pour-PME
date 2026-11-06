// UI hints only: every operation is authorized again by Laravel.
export function allows(permissions = {}, ability, record) {
  if (ability === 'invoices.update' && record) {
    return !record.archived_at && Boolean(permissions[ability] || (record.status === 'draft' && permissions['invoices.updateDraft']))
  }
  if (!permissions[ability]) return false
  if (!record) return true
  if (ability.endsWith('.archive') || ability.endsWith('.restore')) return record.status === undefined || record.status === 'draft'
  if (ability === 'quotes.convert') return !record.archived_at && record.status !== 'converted'
  if (ability.endsWith('.update') || ability.endsWith('.updateStatus') || ability === 'payments.create') return !record.archived_at
  return true
}
