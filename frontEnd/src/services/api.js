import { csrfToken, ensureCsrfCookie } from './auth'

const API_URL = import.meta.env.VITE_API_URL

export async function apiRequest(path, options = {}) {
  if (options.method && options.method !== 'GET') {
    await ensureCsrfCookie()
  }

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(csrfToken() ? { 'X-XSRF-TOKEN': csrfToken() } : {}),
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
}
