import { http, setTokens, clearTokens, getRefreshToken } from './http'
import { createSession } from '../factories/sessionFactory'

export async function authenticate(login, senha) {
  const l = String(login ?? '').trim()
  if (!l || !senha) return { ok: false, error: 'Informe usuário e senha.' }
  const r = await http.post('/auth/login', { login: l, senha }, { auth: false })
  if (!r.ok) return r
  setTokens(r.data)
  return { ok: true, data: createSession(r.data.usuario) }
}

export function register(body) {
  return http.post('/auth/cadastro', body, { auth: false })
}

export async function restoreSession() {
  if (!getRefreshToken()) return null
  const r = await http.get('/me')
  return r.ok ? createSession(r.data) : null
}

export async function endSession() {
  const refresh_token = getRefreshToken()
  if (refresh_token) await http.post('/auth/logout', { refresh_token })
  clearTokens()
}
