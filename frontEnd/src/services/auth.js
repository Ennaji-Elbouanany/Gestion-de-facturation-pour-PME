const API_URL = import.meta.env.VITE_API_URL

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
    response = await fetch(`${API_URL}${path}`, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...options.headers,
      },
    })
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
