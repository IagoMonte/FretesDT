const BASE = '/api'
const RT_KEY = 'fretesdt.refresh_token'

let accessToken = null
let onUnauthorized = null
let refreshing = null
let motivoRefresh = null

const DEFAULT_ERRORS = {
  0: 'Não foi possível conectar ao servidor. Verifique sua conexão.',
  401: 'Sessão expirada. Entre novamente.',
  403: 'Você não tem permissão para esta ação.',
  404: 'Registro não encontrado.',
  409: 'O registro foi alterado por outra pessoa. Atualize e tente novamente.',
  422: 'Dados inválidos. Revise os campos.',
  429: 'Muitas tentativas. Aguarde um instante e tente de novo.',
}

const MSG_PADRAO_EN = [/^Server Error\.?$/i, /^This action is unauthorized\.?$/i, /^The given data was invalid\.?$/i, /^No query results/i, /^Too Many Attempts/i, /^Unauthenticated\.?$/i, /^Not Found\.?$/i, /^Page Expired/i, /^Method .* is not supported/i]
function mensagemDoBack(json, status) {
  const m = json && typeof json.message === 'string' ? json.message.trim() : ''
  if (!m) return null
  if (MSG_PADRAO_EN.some((re) => re.test(m))) return null
  if (status >= 500 && /^[ -~]+$/.test(m)) return null
  return m
}

export function getRefreshToken() {
  try { return localStorage.getItem(RT_KEY) } catch { return null }
}

export function setTokens({ access_token, refresh_token } = {}) {
  accessToken = access_token ?? null
  if (refresh_token) {
    try { localStorage.setItem(RT_KEY, refresh_token) } catch { }
  }
}

export function clearTokens() {
  accessToken = null
  try { localStorage.removeItem(RT_KEY) } catch { }
}

export function setUnauthorizedHandler(fn) { onUnauthorized = fn }

function queryString(query) {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(query || {})) {
    if (v === undefined || v === null || v === '') continue
    p.append(k, Array.isArray(v) ? v.join(',') : typeof v === 'boolean' ? (v ? 1 : 0) : v)
  }
  const s = p.toString()
  return s ? '?' + s : ''
}

async function raw(method, path, { body, query, auth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth && accessToken) headers.Authorization = 'Bearer ' + accessToken
  let res
  try {
    res = await fetch(BASE + path + queryString(query), {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch {
    return { ok: false, error: DEFAULT_ERRORS[0], errors: {}, status: 0 }
  }
  let json = null
  try {
    const text = await res.text()
    json = text ? JSON.parse(text) : null
  } catch { }

  const status = res.status
  if (status >= 200 && status < 300) {
    const data = json && 'data' in json ? json.data : json
    return { ok: true, data: data ?? null, meta: (json && json.meta) || {}, status }
  }
  return {
    ok: false,
    error: mensagemDoBack(json, status) || DEFAULT_ERRORS[status] || (status >= 500 ? 'Erro no servidor. Tente novamente.' : 'Não foi possível concluir a operação.'),
    errors: (json && json.errors) || {},
    code: (json && json.code) || null,
    existente: (json && json.existente) || null,
    status,
  }
}

function refresh() {
  const rt = getRefreshToken()
  if (!rt) return Promise.resolve(false)
  if (!refreshing) {
    refreshing = raw('POST', '/auth/refresh', { body: { refresh_token: rt }, auth: false })
      .then((r) => {
        if (r.ok) { setTokens(r.data); return true }
        if (r.status === 401 || r.status === 403) { motivoRefresh = r.error; clearTokens() }
        return false
      })
      .finally(() => { refreshing = null })
  }
  return refreshing
}

async function request(method, path, opts = {}) {
  if (opts.auth !== false && !accessToken && getRefreshToken()) await refresh()
  let r = await raw(method, path, opts)
  if (r.status === 401 && opts.auth !== false) {
    if (await refresh()) r = await raw(method, path, opts)
    if (r.status === 401 && onUnauthorized) { const motivo = motivoRefresh; motivoRefresh = null; onUnauthorized(motivo || undefined) }
  }
  return r
}

export const http = {
  get: (path, query) => request('GET', path, { query }),
  post: (path, body = {}, opts = {}) => request('POST', path, { body, ...opts }),
  patch: (path, body = {}) => request('PATCH', path, { body }),
}
