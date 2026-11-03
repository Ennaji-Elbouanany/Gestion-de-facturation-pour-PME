const API_URL = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '')
const TOKEN_KEY = 'ffactura_access_token'

export function hasAccessToken() {
  return Boolean(sessionStorage.getItem(TOKEN_KEY))
}

export function clearAccessToken() {
  sessionStorage.removeItem(TOKEN_KEY)
}

function getValidationMessage(errors) {
  if (!errors || typeof errors !== 'object') return ''

  return Object.values(errors)
    .flat()
    .filter((message) => typeof message === 'string')
    .join(' ')
}

function getReadableError(result, fallback) {
  const validationMessage = getValidationMessage(result?.errors)

  if (validationMessage) return validationMessage
  if (typeof result?.message === 'string' && result.message.trim()) {
    return result.message
  }

  return fallback
}

export async function authRequest(path, options = {}, fallbackError = 'Une erreur est survenue.') {
  const token = sessionStorage.getItem(TOKEN_KEY)
  const headers = {
    Accept: 'application/json',
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  }

  // Authentication must never silently fall back to cookies or CSRF tokens.
  // A token obtained from login/register is reused for all protected endpoints.
  let response
  try {
    response = await fetch(`${API_URL}${path}`, {
      ...options,
      credentials: 'omit',
      headers,
    })
  } catch {
    throw new Error('Connexion au serveur impossible. Réessayez.')
  }

  let result = {}
  const responseText = await response.text()

  if (responseText.trim()) {
    try {
      result = JSON.parse(responseText)
    } catch {
      throw new Error(fallbackError)
    }
  }

  if (response.status === 401 && token) {
    clearAccessToken()
    window.dispatchEvent(new Event('ffactura:unauthorized'))
    throw new Error('Votre session a expiré. Veuillez vous reconnecter.')
  }

  if (!response.ok) {
    throw new Error(getReadableError(result, fallbackError))
  }

  if (path === '/api/register' || path === '/api/login') {
    if (typeof result.token !== 'string' || !result.token) {
      throw new Error('Le serveur n’a pas retourné de jeton de connexion.')
    }

    sessionStorage.setItem(TOKEN_KEY, result.token)
  }

  if (path === '/api/logout') {
    clearAccessToken()
  }

  return result
}
