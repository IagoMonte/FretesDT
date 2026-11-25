import { createAppRouter } from '../../../src/router/index'
import { loginAs, logout } from '../../../src/stores/session'
import { toasts } from '../../../src/stores/ui'
import { createSession } from '../../../src/factories/sessionFactory'
import { meAdmin, meSeller, meCarrier } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

let router
beforeEach(async () => {
  await logout()
  router = createAppRouter(true)
  await router.push('/login')
})

describe('guards (por permissão do /me)', () => {
  it('deslogado é enviado ao login', async () => {
    await router.push('/loads')
    expect(router.currentRoute.value.name).toBe('login')
  })
  it('admin entra no painel e em usuários', async () => {
    loginAs(createSession(meAdmin))
    await router.push('/dash')
    expect(router.currentRoute.value.name).toBe('dash')
    await router.push('/users')
    expect(router.currentRoute.value.name).toBe('users')
  })
  it('vendedor não acessa produtos nem usuários: cai na home do perfil', async () => {
    loginAs(createSession(meSeller))
    await router.push('/products')
    expect(router.currentRoute.value.name).toBe('quotes')
    await router.push('/users')
    expect(router.currentRoute.value.name).toBe('quotes')
  })
  it('transportadora acessa o Painel Operacional', async () => {
    loginAs(createSession(meCarrier))
    await router.push('/dash')
    expect(router.currentRoute.value.name).toBe('dash')
  })
  it('logado que vai ao login é redirecionado para a home', async () => {
    loginAs(createSession(meSeller))
    const fresh = createAppRouter(true)
    await fresh.push('/login')
    expect(fresh.currentRoute.value.name).toBe('quotes')
  })
  it('raiz redireciona para a home do perfil', async () => {
    loginAs(createSession(meAdmin))
    await router.push('/')
    expect(router.currentRoute.value.name).toBe('dash')
  })
})

describe('T14: rotas proibidas ou inexistentes corrigem a URL e avisam', () => {
  const texto = () => toasts.map((t) => t.parts.map((p) => p.text).join('')).join(' | ')
  beforeEach(() => toasts.splice(0))

  it.each([
    ['vendedor', meSeller, '/quotes'],
    ['transportadora', meCarrier, '/dash'],
    ['admin', meAdmin, '/dash'],
  ])('%s: /xpto, /quotes/123 e /loads/abc terminam na tela inicial, com a URL corrigida e aviso', async (_nome, me, home) => {
    loginAs(createSession(me))
    for (const caminho of ['/xpto', '/quotes/123', '/loads/abc', '/dash/x/y']) {
      toasts.splice(0)
      await router.push(caminho)
      expect(router.currentRoute.value.fullPath, caminho).toBe(home)
      expect(texto(), caminho).toContain('Página não encontrada')
    }
  })
  it('rota proibida do perfil também corrige a URL e avisa o motivo', async () => {
    loginAs(createSession(meSeller))
    for (const caminho of ['/users', '/dash']) {
      toasts.splice(0)
      await router.push(caminho)
      expect(router.currentRoute.value.fullPath, caminho).toBe('/quotes')
      expect(texto(), caminho).toContain('não tem acesso')
    }
  })
  it('rotas permitidas seguem normais, sem aviso', async () => {
    loginAs(createSession(meAdmin))
    toasts.splice(0)
    await router.push('/users')
    expect(router.currentRoute.value.fullPath).toBe('/users')
    expect(toasts.filter((t) => t.type === 'warn')).toHaveLength(0)
  })
})
