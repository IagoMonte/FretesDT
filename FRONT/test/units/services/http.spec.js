import { http, setTokens, clearTokens, getRefreshToken, setUnauthorizedHandler } from '../../../src/services/http'

const res = (status, body) => new Response(body === undefined ? null : JSON.stringify(body), { status })
let fetchMock

beforeEach(() => {
  clearTokens()
  setUnauthorizedHandler(null)
  localStorage.clear()
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => vi.unstubAllGlobals())

describe('http — sucesso', () => {
  it('GET usa base /api, monta query (ignora vazios, junta arrays) e devolve data/meta', async () => {
    fetchMock.mockResolvedValue(res(200, { data: [{ id: 1 }], meta: { total: 1 } }))
    const r = await http.get('/cotacoes', { status: ['ABERTA', 'EM_NEGOCIACAO'], termo: '', ativo: true, x: null })
    expect(fetchMock.mock.calls[0][0]).toBe('/api/cotacoes?status=ABERTA%2CEM_NEGOCIACAO&ativo=1')
    expect(fetchMock.mock.calls[0][1].method).toBe('GET')
    expect(r).toEqual({ ok: true, data: [{ id: 1 }], meta: { total: 1 }, status: 200 })
  })
  it('POST envia JSON e cabeçalhos; corpo sem "data" (login) volta inteiro', async () => {
    fetchMock.mockResolvedValue(res(200, { access_token: 'a', refresh_token: 'r' }))
    const r = await http.post('/auth/login', { login: 'x', senha: 'y' }, { auth: false })
    const [, init] = fetchMock.mock.calls[0]
    expect(init.method).toBe('POST')
    expect(init.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(init.body)).toEqual({ login: 'x', senha: 'y' })
    expect(r.data).toEqual({ access_token: 'a', refresh_token: 'r' })
    expect(r.meta).toEqual({})
  })
  it('204 devolve data nula', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))
    expect(await http.post('/auth/logout', {})).toMatchObject({ ok: true, data: null, status: 204 })
  })
  it('envia o Bearer quando há access token', async () => {
    setTokens({ access_token: 'tok' })
    fetchMock.mockResolvedValue(res(200, { data: {} }))
    await http.get('/me')
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer tok')
  })
})

describe('http — erros do contrato', () => {
  it.each([
    [422, { message: 'Dados inválidos.', errors: { nome: ['obrigatório'] } }, 'Dados inválidos.'],
    [409, { message: 'Versão desatualizada.' }, 'Versão desatualizada.'],
    [404, { message: 'Não encontrado.' }, 'Não encontrado.'],
    [403, {}, 'Você não tem permissão para esta ação.'],
    [429, undefined, 'Muitas tentativas. Aguarde um instante e tente de novo.'],
    [500, undefined, 'Erro no servidor. Tente novamente.'],
  ])('%i vira { ok:false, error, errors, status }', async (status, body, msg) => {
    fetchMock.mockResolvedValue(res(status, body))
    const r = await http.post('/x', {})
    expect(r).toMatchObject({ ok: false, status, error: msg })
    expect(r.errors).toEqual(body?.errors ?? {})
  })
  it.each([
    [500, 'Server Error', 'Erro no servidor. Tente novamente.'],
    [403, 'This action is unauthorized.', 'Você não tem permissão para esta ação.'],
    [422, 'The given data was invalid.', 'Dados inválidos. Revise os campos.'],
    [404, 'No query results for model [App\Models\Cotacao].', 'Registro não encontrado.'],
    [429, 'Too Many Attempts.', 'Muitas tentativas. Aguarde um instante e tente de novo.'],
    [401, 'Unauthenticated.', 'Sessão expirada. Entre novamente.'],
    [500, 'Call to undefined method App\Foo::bar()', 'Erro no servidor. Tente novamente.'],
  ])('C9: %i com mensagem padrão em inglês (%s) vira texto em português', async (status, message, esperado) => {
    fetchMock.mockResolvedValue(res(status, { message }))
    const r = await http.get('/x')
    expect(r.error).toBe(esperado)
  })
  it('C9: mensagem em português do back é preservada, inclusive nos erros 5xx', async () => {
    fetchMock.mockResolvedValue(res(500, { message: 'Não foi possível enviar o e-mail agora.' }))
    expect((await http.get('/x')).error).toBe('Não foi possível enviar o e-mail agora.')
  })
  it('falha de rede vira status 0', async () => {
    fetchMock.mockRejectedValue(new TypeError('failed'))
    expect(await http.get('/x')).toMatchObject({ ok: false, status: 0, error: expect.stringContaining('conectar') })
  })
})

describe('http — refresh automático', () => {
  it('401 renova com o refresh token (rotativo) e repete a chamada uma vez', async () => {
    setTokens({ access_token: 'velho', refresh_token: 'rt1' })
    fetchMock
      .mockResolvedValueOnce(res(401, { message: 'expirado' }))
      .mockResolvedValueOnce(res(200, { access_token: 'novo', refresh_token: 'rt2' }))
      .mockResolvedValueOnce(res(200, { data: { id: 'u' } }))
    const r = await http.get('/me')
    expect(r).toMatchObject({ ok: true, data: { id: 'u' } })
    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[1][0]).toBe('/api/auth/refresh')
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ refresh_token: 'rt1' })
    expect(fetchMock.mock.calls[2][1].headers.Authorization).toBe('Bearer novo')
    expect(getRefreshToken()).toBe('rt2')
  })
  it('refresh recusado: limpa os tokens e avisa a sessão', async () => {
    const onOut = vi.fn()
    setUnauthorizedHandler(onOut)
    setTokens({ access_token: 'velho', refresh_token: 'rt1' })
    fetchMock
      .mockResolvedValueOnce(res(401, {}))
      .mockResolvedValueOnce(res(401, { message: 'revogado' }))
    const r = await http.get('/me')
    expect(r.status).toBe(401)
    expect(getRefreshToken()).toBeNull()
    expect(onOut).toHaveBeenCalledTimes(1)
  })
  it('T15: sessão substituída por outro login repassa a mensagem do back ao handler', async () => {
    const onOut = vi.fn()
    setUnauthorizedHandler(onOut)
    setTokens({ access_token: 'velho', refresh_token: 'rt1' })
    fetchMock
      .mockResolvedValueOnce(res(401, {}))
      .mockResolvedValueOnce(res(401, { message: 'Sua sessão foi encerrada porque este usuário entrou em outro lugar.' }))
    await http.get('/me')
    expect(onOut).toHaveBeenCalledWith('Sua sessão foi encerrada porque este usuário entrou em outro lugar.')
  })
  it('sem refresh token: 401 vai direto ao handler, sem tentar renovar', async () => {
    const onOut = vi.fn()
    setUnauthorizedHandler(onOut)
    fetchMock.mockResolvedValue(res(401, {}))
    await http.get('/me')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(onOut).toHaveBeenCalled()
  })
  it('só repete uma vez: 401 depois da renovação não entra em laço', async () => {
    setTokens({ access_token: 'a', refresh_token: 'rt1' })
    fetchMock
      .mockResolvedValueOnce(res(401, {}))
      .mockResolvedValueOnce(res(200, { access_token: 'b', refresh_token: 'rt2' }))
      .mockResolvedValueOnce(res(401, {}))
    const r = await http.get('/me')
    expect(r.status).toBe(401)
    expect(fetchMock).toHaveBeenCalledTimes(3)
  })
  it('chamadas simultâneas em 401 compartilham uma única renovação', async () => {
    setTokens({ access_token: 'a', refresh_token: 'rt1' })
    fetchMock.mockImplementation(async (url, init) => {
      if (url === '/api/auth/refresh') return res(200, { access_token: 'b', refresh_token: 'rt2' })
      return init.headers.Authorization === 'Bearer b' ? res(200, { data: 1 }) : res(401, {})
    })
    const [x, y] = await Promise.all([http.get('/a'), http.get('/b')])
    expect(x.ok && y.ok).toBe(true)
    expect(fetchMock.mock.calls.filter((c) => c[0] === '/api/auth/refresh')).toHaveLength(1)
  })
  it('login com senha errada (auth:false) não tenta renovar', async () => {
    setTokens({ access_token: 'a', refresh_token: 'rt1' })
    fetchMock.mockResolvedValue(res(401, { message: 'Usuário ou senha inválidos.' }))
    const r = await http.post('/auth/login', { login: 'x', senha: 'y' }, { auth: false })
    expect(r.error).toBe('Usuário ou senha inválidos.')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
  it('refresh token fica no localStorage; access token só em memória', () => {
    setTokens({ access_token: 'a', refresh_token: 'rt' })
    expect(localStorage.getItem('fretesdt.refresh_token')).toBe('rt')
    expect(JSON.stringify({ ...localStorage })).not.toContain('"a"')
    clearTokens()
    expect(localStorage.getItem('fretesdt.refresh_token')).toBeNull()
  })
})
