const API_URL = import.meta.env.VITE_API_URL
let csrfReady = false
let csrfHeaderToken = ''

export function csrfToken() {
  const cookie = document.cookie
    .split('; ')
    .find((value) => value.startsWith('XSRF-TOKEN='))

  return cookie ? decodeURIComponent(cookie.substring('XSRF-TOKEN='.length)) : ''
}

export async function ensureCsrfCookie() {
  if (csrfReady) return

  const response = await fetch(`${API_URL}/api/csrf-token`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw new Error('Impossible de sécuriser la connexion.')
  }

  const result = await response.json()

  if (typeof result.token !== 'string' || !result.token) {
    throw new Error('Impossible de sécuriser la connexion.')
  }

  csrfHeaderToken = result.token
  csrfReady = true
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

  const knownMessages = [
    'Adresse e-mail ou mot de passe incorrect.',
    'Le lien est invalide ou a expiré.',
    'Impossible de créer le compte.',
  ]

  return knownMessages.includes(result?.message) ? result.message : fallback
}

export async function authRequest(path, options, fallbackError) {
  let response
  let result = {}

  try {
    if (options?.method && options.method !== 'GET') {
      await ensureCsrfCookie()
    }

    const request = () => fetch(`${API_URL}${path}`, {
      ...options,
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(csrfHeaderToken || csrfToken()
          ? { 'X-CSRF-TOKEN': csrfHeaderToken || csrfToken() }
          : {}),
        ...options.headers,
      },
    })

    response = await request()

    if (response.status === 419) {
      csrfReady = false
      csrfHeaderToken = ''
      await ensureCsrfCookie()
      response = await request()
    }

    const responseText = await response.text()

    if (responseText.trim()) {
      try {
        result = JSON.parse(responseText)
      } catch {
        throw new Error(fallbackError)
      }
    }
  } catch {
    throw new Error(fallbackError)
  }

  if (!response.ok) {
    throw new Error(getReadableError(result, fallbackError))
  }

  return result
}
