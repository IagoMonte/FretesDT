import { auth, login, loginAs, logout, restore } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts } from '../../../src/stores/ui'
import { db, resetDb } from '../../../src/stores/db'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const loginRes = (me) => ok({ access_token: 'a', refresh_token: 'r', usuario: me })

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  resetDb()
  await logout()
  toasts.splice(0)
})

describe('sessão', () => {
  it('começa deslogado', () => {
    expect(auth.session).toBeNull()
  })
  it('login válido (async) inicia sessão e mostra toast de boas-vindas', async () => {
    api.on('POST /auth/login', loginRes(meAdmin))
    const p = login('admin', 'admin')
    expect(auth.loading).toBe(true)
    const r = await p
    expect(r.ok).toBe(true)
    expect(auth.loading).toBe(false)
    expect(auth.session.role).toBe('ADMINISTRADOR')
    expect(toasts[0].parts.some((p) => p.bold && p.text === 'Administrador')).toBe(true)
  })
  it('login inválido devolve erro e não inicia sessão', async () => {
    api.on('POST /auth/login', err(401, 'Usuário ou senha inválidos.'))
    const r = await login('admin', 'x')
    expect(r).toMatchObject({ ok: false, error: 'Usuário ou senha inválidos.' })
    expect(auth.session).toBeNull()
    expect(auth.loading).toBe(false)
  })
  it('loginAs aceita uma sessão pronta', () => {
    loginAs(createSession(meSeller))
    expect(auth.session.name).toBe('MARCOS')
  })
  it('logout limpa a sessão e o cache de dados na hora', async () => {
    api.on('POST /auth/login', loginRes(meAdmin))
    await login('admin', 'admin')
    db.products.push({ id: 'x' })
    const p = logout()
    expect(auth.session).toBeNull()
    expect(db.products).toEqual([])
    await p
    expect(api.called('POST', '/auth/logout')).toHaveLength(1)
  })
  it('restore retoma a sessão pelo refresh token', async () => {
    api.tokens.refresh = 'r'
    api.on('GET /me', ok(meSeller))
    expect(await restore()).toBe(true)
    expect(auth.session.role).toBe('VENDEDOR')
  })
  it('restore sem token não loga', async () => {
    expect(await restore()).toBe(false)
    expect(auth.session).toBeNull()
  })
  it('T15: sessão encerrada por outro login mostra o motivo, não só "expirou"', async () => {
    api.on('POST /auth/login', loginRes(meAdmin))
    await login('admin', 'admin')
    toasts.splice(0)
    api.unauthorized('Sua sessão foi encerrada porque este usuário entrou em outro lugar.')
    expect(auth.session).toBeNull()
    expect(toasts[0].parts.map((p) => p.text).join('')).toContain('entrou em outro lugar')
  })
  it('sessão que não renova mais: encerra e avisa', async () => {
    api.on('POST /auth/login', loginRes(meAdmin))
    await login('admin', 'admin')
    toasts.splice(0)
    api.unauthorized()
    expect(auth.session).toBeNull()
    expect(toasts[0].parts.map((p) => p.text).join('')).toContain('expirou')
  })
})
