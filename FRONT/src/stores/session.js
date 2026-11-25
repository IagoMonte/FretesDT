import { reactive } from 'vue'
import { authenticate, restoreSession, endSession } from '../services/auth'
import { setUnauthorizedHandler, clearTokens } from '../services/http'
import { resetDb } from './db'
import { pushToast, closeModal, closeNav } from './ui'

export const auth = reactive({ session: null, loading: false })

export function loginAs(session) {
  auth.session = session
  pushToast('ok', `Bem-vindo(a), **${session.name}**! Sessão iniciada na FretesDT.`)
}

export async function login(user, pass) {
  auth.loading = true
  try {
    const r = await authenticate(user, pass)
    if (!r.ok) return r
    loginAs(r.data)
    return r
  } finally {
    auth.loading = false
  }
}

export async function restore() {
  const session = await restoreSession()
  if (session) auth.session = session
  return !!session
}

function clearLocal() {
  auth.session = null
  resetDb()
  closeModal()
  closeNav()
}

export async function logout() {
  clearLocal()
  await endSession().catch(() => clearTokens())
}

setUnauthorizedHandler((motivo) => {
  if (!auth.session) return
  clearTokens()
  clearLocal()
  pushToast('warn', motivo || 'Sua sessão expirou. Entre novamente.')
})
