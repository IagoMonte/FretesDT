import { mount, flushPromises } from '@vue/test-utils'
import App from '../../../src/App.vue'
import { createAppRouter } from '../../../src/router'
import { logout } from '../../../src/stores/session'
import { resetDb } from '../../../src/stores/db'
import { toasts, closeModal, modal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

beforeAll(() => Promise.all([
  import('../../../src/views/LoginView.vue'), import('../../../src/layouts/AppShell.vue'), import('../../../src/views/DashView.vue'),
  import('../../../src/views/QuotesView.vue'), import('../../../src/views/LoadsView.vue'), import('../../../src/views/ProductsView.vue'),
]))

const meVia = { ...meCarrier, id: 'u-via', nome: 'Operador VIA', login: 'via', transportadora: { id: 't-via', nome_fantasia: 'ROTA SUL' } }
const SENHAS = { admin: ['admin', meAdmin], marcos: ['vendas', meSeller], arco: ['arc123', meCarrier], via: ['rot123', meVia] }

function backend({ status = 'EM_NEGOCIACAO' } = {}) {
  const state = { me: null }
  const q = quote({ status, negociacao: { id: 'n-1', status: 'ABERTA', respostas: [resposta({ transportadora: { id: 't-arco', nome_fantasia: 'ARCO' } })], fechamento: null } })
  const l = load({ id: 'l-1', codigo: 'CRG-000001', transportadora: { id: 't-arco', nome_fantasia: 'ARCO' } })
  const tid = () => state.me?.transportadora?.id
  const quotes = () => [q].filter((x) => !tid() || x.negociacao.respostas.some((r) => r.transportadora.id === tid()))
  const loads = () => [l].filter((x) => !tid() || x.transportadora?.id === tid())
  const por = (lista, codigo) => (codigo ? lista.filter((x) => x.codigo === codigo) : lista)

  api.on('POST /auth/login', ({ body }) => {
    const u = SENHAS[body.login]
    if (!u || u[0] !== body.senha) return { ok: false, status: 401, error: 'Usuário ou senha inválidos.', errors: {} }
    state.me = u[1]
    return ok({ access_token: 'acc', token_type: 'Bearer', expires_in: 900, refresh_token: 'ref', usuario: u[1] })
  })
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok([product()]))
  api.on('GET /transportadoras', ok([carrier()]))
  api.on('GET /vendedores', ok([]))
  api.on('GET /painel', ok({ cotacoes_abertas: 1, programacoes: 0, em_transito: 0, entregues: 0, entregues_no_prazo: 0, pct_no_prazo: 0, total_frete: '0.00', por_status: {}, feed: [] }))
  api.on('GET /cotacoes', ({ query }) => ok(JSON.parse(JSON.stringify(por(quotes(), query?.codigo)))))
  api.on('GET /cotacoes/:id', ({ params }) => {
    const x = quotes().find((c) => c.id === params.id)
    return x ? ok(JSON.parse(JSON.stringify(x))) : { ok: false, status: 404, error: 'Não encontrado.', errors: {} }
  })
  api.on('GET /programacoes', ({ query }) => ok(JSON.parse(JSON.stringify(por(loads(), query?.codigo)))))
  api.on('GET /programacoes/:id', ({ params }) => {
    const x = loads().find((c) => c.id === params.id)
    return x ? ok(JSON.parse(JSON.stringify(x))) : { ok: false, status: 404, error: 'Não encontrado.', errors: {} }
  })
  return state
}

async function boot(path) {
  const router = createAppRouter(true)
  router.push(path)
  await router.isReady()
  const w = mount(App, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}
async function entrar(w, user, pass) {
  await w.find('#lgUser').setValue(user)
  await w.find('#lgPass').setValue(pass)
  await w.find('.lg-right .btn-primary').trigger('click')
  await flushPromises()
  await flushPromises()
}
const avisos = () => toasts.map((t) => t.parts.map((p) => p.text ?? p).join(''))

beforeEach(async () => {
  api.reset()
  resetDb()
  await logout()
  closeModal()
  toasts.splice(0)
})

describe('guard — destino preservado através do login', () => {
  it('deslogado: vai ao login com ?next=<destino completo>', async () => {
    backend()
    const { w, router } = await boot('/quotes?abrir=COT-000001')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.next).toBe('/quotes?abrir=COT-000001')
    w.unmount()
  })

  it('login ok volta ao destino e abre o modal da cotação (e limpa o ?abrir)', async () => {
    backend()
    const { w, router } = await boot('/quotes?abrir=COT-000001')
    await entrar(w, 'arco', 'arc123')
    expect(router.currentRoute.value.path).toBe('/quotes')
    expect(router.currentRoute.value.query.abrir).toBeUndefined()
    expect(modal.open).toBe(true)
    expect(modal.kind).toBe('quote')
    expect(w.find('#quote-title').text()).toContain('COT-000001')
    w.unmount()
  })

  it('next hostil é ignorado: vai para a home do perfil', async () => {
    backend()
    const { w, router } = await boot('/login?next=//evil.com')
    await entrar(w, 'marcos', 'vendas')
    expect(router.currentRoute.value.name).toBe('quotes')
    expect(modal.open).toBe(false)
    w.unmount()
  })

  it('sem next, o login segue para a home (comportamento antigo)', async () => {
    backend()
    const { w, router } = await boot('/login')
    await entrar(w, 'admin', 'admin')
    expect(router.currentRoute.value.name).toBe('dash')
    w.unmount()
  })
})

describe('?abrir= já logado', () => {
  it('cotação: abre o modal direto, e o F5 (query limpa) não reabre', async () => {
    backend()
    const { w, router } = await boot('/login')
    await entrar(w, 'marcos', 'vendas')
    await router.push('/quotes?abrir=COT-000001')
    await flushPromises()
    expect(modal.kind).toBe('quote')
    expect(router.currentRoute.value.fullPath).toBe('/quotes')
    closeModal()
    await flushPromises()
    expect(modal.open).toBe(false)
    w.unmount()
  })

  it('carga: abre o detalhe somente leitura com o código', async () => {
    backend()
    const { w, router } = await boot('/login')
    await entrar(w, 'admin', 'admin')
    await router.push('/loads?abrir=CRG-000001')
    await flushPromises()
    expect(modal.kind).toBe('load')
    expect(w.find('#load-title').text()).toContain('CRG-000001')
    w.unmount()
  })

  it('transportadora sem acesso à cotação: toast e nenhum dado no modal', async () => {
    backend()
    const { w, router } = await boot('/login')
    await entrar(w, 'via', 'rot123')
    await router.push('/quotes?abrir=COT-000001')
    await flushPromises()
    expect(modal.open).toBe(false)
    expect(avisos().some((t) => t.includes('COT-000001') && /não foi encontrada ou você não tem acesso/i.test(t))).toBe(true)
    expect(w.text()).not.toContain('SODA CÁUSTICA')
    expect(router.currentRoute.value.fullPath).toBe('/quotes')
    w.unmount()
  })

  it('código inexistente: toast claro, tela segue normal', async () => {
    backend()
    const { w, router } = await boot('/login')
    await entrar(w, 'admin', 'admin')
    await router.push('/quotes?abrir=COT-999999')
    await flushPromises()
    expect(modal.open).toBe(false)
    expect(avisos().some((t) => t.includes('COT-999999'))).toBe(true)
    w.unmount()
  })

  it('cotação cancelada: abre o modal e avisa que está cancelada', async () => {
    backend({ status: 'CANCELADA' })
    const { w, router } = await boot('/login')
    await entrar(w, 'marcos', 'vendas')
    await router.push('/quotes?abrir=COT-000001')
    await flushPromises()
    expect(modal.kind).toBe('quote')
    expect(avisos().some((t) => /cancelad/i.test(t))).toBe(true)
    w.unmount()
  })

  it('transportadora da carga abre a carga; outra transportadora recebe toast', async () => {
    backend()
    let r = await boot('/login')
    await entrar(r.w, 'arco', 'arc123')
    await r.router.push('/loads?abrir=CRG-000001')
    await flushPromises()
    expect(modal.kind).toBe('load')
    r.w.unmount()

    api.reset(); resetDb(); await logout(); closeModal(); toasts.splice(0)
    backend()
    r = await boot('/login')
    await entrar(r.w, 'via', 'rot123')
    await r.router.push('/loads?abrir=CRG-000001')
    await flushPromises()
    expect(modal.open).toBe(false)
    expect(avisos().some((t) => t.includes('CRG-000001'))).toBe(true)
    r.w.unmount()
  })
})

describe('guard — permissão', () => {
  it('perfil sem acesso à tela: vai à home do perfil com toast (antes era silencioso)', async () => {
    backend()
    const { w, router } = await boot('/login')
    await entrar(w, 'marcos', 'vendas')
    toasts.splice(0)
    await router.push('/products')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('quotes')
    expect(avisos().some((t) => /acesso/i.test(t))).toBe(true)
    w.unmount()
  })
})
