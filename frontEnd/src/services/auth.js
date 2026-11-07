const API_URL = (import.meta.env?.VITE_API_URL || '').replace(/\/$/, '')
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
  const publicPath = ['/api/login', '/api/register', '/api/forgot-password', '/api/reset-password'].includes(path)
  const token = publicPath ? null : sessionStorage.getItem(TOKEN_KEY)
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

  if (response.status === 401 && token && !publicPath) {
    clearAccessToken()
    window.dispatchEvent(new Event('ffactura:unauthorized'))
    const error = new Error('Votre session a expiré. Veuillez vous reconnecter.')
    error.status = 401
    error.errors = {}
    throw error
  }

  let result = {}
  let invalidBody = false
  try {
    const text = await response.text()
    if (text.trim()) result = JSON.parse(text)
    if (!result || typeof result !== 'object') { result = {}; invalidBody = true }
  } catch { invalidBody = true }

  if (!response.ok) {
    const defaults = { 401: 'Adresse e-mail ou mot de passe incorrect.', 404: 'Ressource introuvable ou inaccessible.',
      409: 'Les données ont changé ou cette action est impossible. Rechargez la page.',
      422: 'Vérifiez les champs du formulaire.', 429: 'Trop de tentatives. Veuillez patienter avant de réessayer.' }
    const message = response.status >= 500 ? 'Le serveur est temporairement indisponible. Réessayez ultérieurement.'
      : response.status === 403 ? `Accès refusé : ${getReadableError(result, 'vous ne disposez pas des permissions nécessaires.')}`
        : getReadableError(result, defaults[response.status] || fallbackError)
    const error = new Error(message)
    error.status = response.status
    error.errors = result?.errors || {}
    error.code = result?.code
    throw error
  }
  if (invalidBody) throw new Error('Réponse du serveur invalide. Rechargez les données avant de réessayer.')

  if (path === '/api/register' || path === '/api/login') {
    if (typeof result.token !== 'string' || !result.token) {
      throw new Error('Le serveur n’a pas retourné de jeton de connexion.')
    }

    sessionStorage.setItem(TOKEN_KEY, result.token)
  }

  if (path === '/api/settings/security' && result.reauthenticate) {
    clearAccessToken()
    window.dispatchEvent(new CustomEvent('ffactura:reauthenticate', { detail: result.message }))
  }

  if (path === '/api/logout') {
    clearAccessToken()
  }

  return result
}
