import { useState } from 'react'
import { createSubmissionGuard } from '../services/formValidation'

export function useSubmission() {
  const [guard] = useState(createSubmissionGuard)
  const [busy, setBusy] = useState(false)
  return [busy, (operation) => guard(async () => {
    setBusy(true)
    try { return await operation() } finally { setBusy(false) }
  })]
}
