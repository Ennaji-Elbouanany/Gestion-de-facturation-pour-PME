const API_URL = import.meta.env.VITE_API_URL

export async function apiRequest(path, options = {}) {
  const token = localStorage.getItem('auth_token')
  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
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
