import { authenticate, restoreSession, endSession } from '../../../src/services/auth'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

beforeEach(() => api.reset())

const loginOk = { access_token: 'acc', token_type: 'Bearer', expires_in: 900, refresh_token: 'ref', usuario: meAdmin }

describe('authenticate (login local)', () => {
  it('envia login/senha, guarda os tokens e devolve a sessão', async () => {
    api.on('POST /auth/login', ok(loginOk))
    const r = await authenticate(' admin ', 'admin')
    expect(api.called('POST', '/auth/login')[0].body).toEqual({ login: 'admin', senha: 'admin' })
    expect(r.ok).toBe(true)
    expect(r.data).toMatchObject({ role: 'ADMINISTRADOR', home: 'dash' })
    expect(api.tokens).toEqual({ access: 'acc', refresh: 'ref' })
  })
  it('credencial inválida devolve a mensagem da API e não guarda tokens', async () => {
    api.on('POST /auth/login', err(401, 'Usuário ou senha inválidos.'))
    const r = await authenticate('admin', 'x')
    expect(r).toMatchObject({ ok: false, error: 'Usuário ou senha inválidos.' })
    expect(api.tokens.refresh).toBeNull()
  })
  it('campos vazios nem chamam a API', async () => {
    expect((await authenticate('', '')).ok).toBe(false)
    expect(api.calls).toHaveLength(0)
  })
})

describe('restoreSession / endSession', () => {
  it('sem refresh token não há sessão (nem chama /me)', async () => {
    expect(await restoreSession()).toBeNull()
    expect(api.calls).toHaveLength(0)
  })
  it('com refresh token busca o /me e monta a sessão', async () => {
    api.tokens.refresh = 'ref'
    api.on('GET /me', ok(meAdmin))
    expect(await restoreSession()).toMatchObject({ role: 'ADMINISTRADOR' })
  })
  it('/me recusado: sem sessão', async () => {
    api.tokens.refresh = 'ref'
    api.on('GET /me', err(401, 'x'))
    expect(await restoreSession()).toBeNull()
  })
  it('logout revoga o refresh token na API e limpa os tokens', async () => {
    api.tokens = { access: 'a', refresh: 'ref' }
    api.on('POST /auth/logout', ok(null, {}, 204))
    await endSession()
    expect(api.called('POST', '/auth/logout')[0].body).toEqual({ refresh_token: 'ref' })
    expect(api.tokens).toEqual({ access: null, refresh: null })
  })
})
