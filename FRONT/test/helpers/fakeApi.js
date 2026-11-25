export const ok = (data, meta = {}, status = 200) => ({ ok: true, data, meta, status })
export const err = (status, error, errors = {}) => ({ ok: false, error, errors, status })

export const api = {
  routes: [],
  calls: [],
  tokens: { access: null, refresh: null },
  unauthorized: null,
  /** api.on('GET /produtos/:id', ({ params, body, query }) => ok(...))  ou valor fixo */
  on(key, handler) {
    const [method, path] = key.split(' ')
    const keys = []
    const regex = new RegExp('^' + path.replace(/:[a-z_]+/g, (m) => (keys.push(m.slice(1)), '([^/]+)')) + '$')
    this.routes.unshift({ method, regex, keys, handler })
    return this
  },
  reset() {
    this.routes = []
    this.calls = []
    this.tokens = { access: null, refresh: null }
  },
  called(method, path) {
    return this.calls.filter((c) => c.method === method && c.path === path)
  },
}

async function handle(method, path, body, query) {
  api.calls.push({ method, path, body, query })
  for (const r of api.routes) {
    const m = r.method === method && r.regex.exec(path)
    if (!m) continue
    const params = Object.fromEntries(r.keys.map((k, i) => [k, m[i + 1]]))
    return typeof r.handler === 'function' ? r.handler({ params, body, query }) : r.handler
  }
  return err(404, `sem rota no mock: ${method} ${path}`)
}

export function fakeHttpModule() {
  return {
    http: {
      get: (path, query) => handle('GET', path, undefined, query),
      post: (path, body = {}) => handle('POST', path, body),
      patch: (path, body = {}) => handle('PATCH', path, body),
    },
    setTokens: ({ access_token, refresh_token } = {}) => {
      api.tokens.access = access_token ?? null
      if (refresh_token) api.tokens.refresh = refresh_token
    },
    clearTokens: () => { api.tokens = { access: null, refresh: null } },
    getRefreshToken: () => api.tokens.refresh,
    setUnauthorizedHandler: (fn) => { api.unauthorized = fn },
  }
}
