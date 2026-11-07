import test, { afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { authRequest } from '../src/services/auth.js'

const originals = { fetch: globalThis.fetch, window: globalThis.window, sessionStorage: globalThis.sessionStorage }
afterEach(() => {
  for (const [key, value] of Object.entries(originals)) {
    if (value === undefined) delete globalThis[key]
    else globalThis[key] = value
  }
})

function setup(status, body) {
  const tokens = new Map([['ffactura_access_token', 'existing-token']])
  const events = []
  globalThis.sessionStorage = { getItem: (key) => tokens.get(key), removeItem: (key) => tokens.delete(key) }
  globalThis.window = { dispatchEvent: (event) => events.push(event.type) }
  let request
  globalThis.fetch = async (url, options) => {
    request = { url, options }
    return new Response(JSON.stringify(body), { status })
  }
  return { tokens, events, request: () => request }
}

test('403 displays an authorization error without revoking the Sanctum token', async () => {
  const state = setup(403, { message: 'Action interdite.' })
  await assert.rejects(authRequest('/api/payments'), (error) => error.status === 403 && error.message.includes('Accès refusé'))
  assert.equal(state.tokens.get('ffactura_access_token'), 'existing-token')
  assert.deepEqual(state.events, [])
  assert.equal(state.request().options.headers.Authorization, 'Bearer existing-token')
  assert.equal(state.request().options.credentials, 'omit')
})

test('401 preserves the existing expiration and reconnect behavior', async () => {
  const state = setup(401, { message: 'Unauthenticated.' })
  await assert.rejects(authRequest('/api/user'), /session a expiré/)
  assert.equal(state.tokens.has('ffactura_access_token'), false)
  assert.deepEqual(state.events, ['ffactura:unauthorized'])
})

test('validation errors remain available to forms', async () => {
  setup(422, { errors: { role: ['Champ interdit.'] } })
  await assert.rejects(authRequest('/api/settings/profile', { method: 'PATCH', body: '{}' }), (error) => (
    error.status === 422 && error.errors.role[0] === 'Champ interdit.'
  ))
})

for (const status of [401, 403, 404, 409, 422, 429, 500]) {
  test(`non JSON HTTP ${status} preserves its status and French fallback`, async () => {
    const state = setup(status, {})
    globalThis.fetch = async () => new Response('<html>upstream error</html>', { status })
    await assert.rejects(authRequest('/api/clients'), (error) => error.status === status && !error.message.includes('<html>'))
    assert.equal(state.tokens.has('ffactura_access_token'), status !== 401)
  })
}

test('server errors do not expose SQL or infrastructure details', async () => {
  setup(500, { message: 'SQLSTATE private-host secret', errors: {} })
  await assert.rejects(authRequest('/api/clients'), (error) => error.status === 500 && !error.message.includes('SQLSTATE'))
})

test('password change clears the current token and asks the app to reconnect', async () => {
  const state = setup(200, { message: 'Mot de passe modifié.', reauthenticate: true })
  const original = globalThis.CustomEvent
  globalThis.CustomEvent = class extends Event { constructor(type, options) { super(type); this.detail = options.detail } }
  try {
    await authRequest('/api/settings/security', { method: 'PATCH', body: '{}' })
    assert.equal(state.tokens.has('ffactura_access_token'), false)
    assert.deepEqual(state.events, ['ffactura:reauthenticate'])
  } finally {
    if (original === undefined) delete globalThis.CustomEvent
    else globalThis.CustomEvent = original
  }
})
