export function fieldProps(name, errors, prefix) {
  return { 'aria-invalid': Boolean(errors[name]), 'aria-describedby': errors[name] ? `${prefix}-${name}-error` : undefined }
}

export function validateProduct(data) {
  const errors = {}
  for (const name of ['unit_price', 'tax_rate']) {
    const value = name === 'tax_rate' && !data[name] ? '0' : String(data[name])
    if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(value)) {
      errors[name] = ['Utilisez un nombre positif ou nul, avec deux décimales maximum.']
    } else if (name === 'tax_rate' && Number(value) > 100) {
      errors[name] = ['La TVA doit être comprise entre 0 et 100 %.']
    }
  }
  return errors
}

export function createSubmissionGuard() {
  let pending = false
  return async (operation) => {
    if (pending) return
    pending = true
    try { return await operation() } finally { pending = false }
  }
}
