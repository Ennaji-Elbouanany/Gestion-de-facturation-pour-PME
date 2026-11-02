import { authRequest } from './auth'

export async function apiRequest(path, options = {}) {
  return authRequest(path, options, 'Une erreur est survenue.')
}
