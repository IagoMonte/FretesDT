import { mount, flushPromises } from '@vue/test-utils'
import App from '../../../src/App.vue'
import { createAppRouter } from '../../../src/router'
import { auth, logout, restore } from '../../../src/stores/session'
import { resetDb } from '../../../src/stores/db'
import { toasts, closeModal, modal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

beforeAll(() => Promise.all([
  import('../../../src/views/LoginView.vue'), import('../../../src/layouts/AppShell.vue'), import('../../../src/views/DashView.vue'),
  import('../../../src/views/QuotesView.vue'), import('../../../src/views/LoadsView.vue'), import('../../../src/views/ProductsView.vue'),
  import('../../../src/views/CarriersView.vue'), import('../../../src/views/UsersView.vue'),
]))

const meVia = { ...meCarrier, id: 'u-via', nome: 'Operador VIA', login: 'via', transportadora: { id: 't-via', nome_fantasia: 'ROTA SUL' } }
const PASSWORDS = { admin: ['admin', meAdmin], marcos: ['vendas', meSeller], arco: ['arc123', meCarrier], via: ['rot123', meVia] }

function startBackend() {
  const state = { me: null, quotes: [quote()], loads: [] }
  const carriers = [carrier(), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL', razao_social: 'ROTA SUL LTDA' })]
  const copy = (x) => JSON.parse(JSON.stringify(x))
  const seenBy = (q) => {
    const tid = state.me?.transportadora?.id
    const c = copy(q)
    if (tid) c.negociacao.respostas = c.negociacao.respostas.filter((r) => r.transportadora.id === tid)
    return c
  }
  const find = (id) => state.quotes.find((q) => q.id === id)

  api.on('POST /auth/login', ({ body }) => {
    const u = PASSWORDS[body.login]
    if (!u || u[0] !== body.senha) return err(401, 'Usuário ou senha inválidos.')
    state.me = u[1]
    return ok({ access_token: 'acc', token_type: 'Bearer', expires_in: 900, refresh_token: 'ref', usuario: u[1] })
  })
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok([product()]))
  api.on('GET /transportadoras', ok(carriers))
  api.on('GET /painel', ok({ cotacoes_abertas: 1, programacoes: 0, em_transito: 0, entregues: 0, entregues_no_prazo: 0, pct_no_prazo: 0, total_frete: '0.00', por_status: {}, feed: [] }))
  api.on('GET /cotacoes', () => {
    const tid = state.me?.transportadora?.id
    const mine = state.quotes.filter((q) => !tid || q.negociacao.respostas.some((r) => r.transportadora.id === tid))
    return ok(mine.map(seenBy), { total: mine.length })
  })
  api.on('GET /cotacoes/:id', ({ params }) => ok(seenBy(find(params.id))))
  api.on('POST /cotacoes/:id/encaminhar', ({ params, body }) => {
    const q = find(params.id)
    body.transportadora_ids.forEach((tid, i) => {
      if (!q.negociacao.respostas.some((r) => r.transportadora.id === tid)) {
        const c = carriers.find((x) => x.id === tid)
        q.negociacao.respostas.push(resposta({ id: 'r-' + (q.negociacao.respostas.length + 1), transportadora: { id: c.id, nome_fantasia: c.nome_fantasia } }))
      }
    })
    q.status = 'EM_NEGOCIACAO'
    return ok(seenBy(q))
  })
  api.on('POST /cotacoes/:id/respostas/:r/responder', ({ params, body }) => {
    const q = find(params.id)
    const r = q.negociacao.respostas.find((x) => x.id === params.r)
    Object.assign(r, { status: 'RESPONDIDA', valor_tonelada: body.valor_tonelada, prazo_dias: body.prazo_dias, valor_frete: (Number(body.valor_tonelada) * 27).toFixed(2) })
    return ok(seenBy(q))
  })
  api.on('POST /cotacoes/:id/respostas/:r/declinar', ({ params }) => {
    const q = find(params.id)
    q.negociacao.respostas.find((x) => x.id === params.r).status = 'DECLINADA'
    return ok(seenBy(q))
  })
  api.on('POST /cotacoes/:id/fechar', ({ params, body }) => {
    const q = find(params.id)
    const c = carriers.find((x) => x.id === body.transportadora_id)
    q.status = 'PROGRAMADA'
    q.negociacao.status = 'FECHADA'
    q.negociacao.fechamento = { transportadora: { id: c.id, nome_fantasia: c.nome_fantasia }, valor_tonelada: body.valor_tonelada, valor_frete: (Number(body.valor_tonelada) * 27).toFixed(2), prazo_dias: body.prazo_dias, observacao: body.observacao }
    const prog = load({ id: 'l-1', codigo: 'CRG-000001', cotacao: { id: q.id, codigo: q.codigo }, transportadora: { id: c.id, nome_fantasia: c.nome_fantasia }, valor_tonelada: body.valor_tonelada })
    state.loads.push(prog)
    return ok(seenBy(q), { programacao: copy(prog) })
  })
  api.on('GET /programacoes', () => {
    const tid = state.me?.transportadora?.id
    return ok(copy(state.loads.filter((l) => !tid || l.transportadora?.id === tid)))
  })
  return state
}

async function boot(path = '/') {
  const router = createAppRouter(true)
  router.push(path)
  await router.isReady()
  const w = mount(App, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}
async function signIn(w, user, pass) {
  await w.find('#lgUser').setValue(user)
  await w.find('#lgPass').setValue(pass)
  await w.find('.lg-right .btn-primary').trigger('click')
  await flushPromises()
}
async function signOut(w) {
  await w.find('.btn-out').trigger('click')
  await flushPromises()
}
const nav = async (w, text) => {
  await w.findAll('.nav-item').find((n) => n.text().includes(text)).trigger('click')
  await flushPromises()
}

beforeEach(async () => {
  api.reset()
  resetDb()
  await logout()
  closeModal()
  toasts.splice(0)
})

describe('App (integração ponta a ponta com a API mockada)', () => {
  it('deslogado cai no login', async () => {
    startBackend()
    const { w, router } = await boot('/dash')
    expect(router.currentRoute.value.name).toBe('login')
    expect(w.find('.login-screen').exists()).toBe(true)
    expect(w.find('.side').exists()).toBe(false)
    w.unmount()
  })

  it('login sem atalhos de demonstração', async () => {
    startBackend()
    const { w } = await boot('/login')
    expect(w.find('.qbtn').exists()).toBe(false)
    expect(w.find('.carrier-pick').exists()).toBe(false)
    expect(w.text()).not.toContain('admin/admin')
    w.unmount()
  })

  it('credencial inválida mostra o erro da API e não entra', async () => {
    startBackend()
    const { w, router } = await boot('/login')
    await signIn(w, 'admin', 'errada')
    expect(w.find('.lg-err').text()).toBe('Usuário ou senha inválidos.')
    expect(router.currentRoute.value.name).toBe('login')
    w.unmount()
  })

  it('fluxo completo: encaminha para 2, cada uma vê só a sua, admin compara e fecha, carga aparece', async () => {
    startBackend()
    const { w, router } = await boot('/login')

    await signIn(w, 'admin', 'admin')
    expect(router.currentRoute.value.name).toBe('dash')
    expect(w.find('.topbar h1').text()).toBe('Painel Operacional')
    expect(w.find('.toast').text()).toContain('Bem-vindo(a), Administrador')
    await nav(w, 'Solicitar Cotação')
    expect(w.find('[data-group="ABERTA"]').exists()).toBe(true)
    await w.find('[data-act="def-quote"]').trigger('click')
    expect(w.find('.fw-list').exists()).toBe(true)
    for (const i of w.findAll('.fw-list input')) await i.setValue(true)
    await w.find('[data-act="forward-selected"]').trigger('click')
    await flushPromises()
    expect(w.find('.fw-list').exists()).toBe(false)
    expect(w.find('[data-group="EM_NEGOCIACAO"]').exists()).toBe(true)
    expect(w.findAll('[data-resp]')).toHaveLength(2)

    await signOut(w)
    await signIn(w, 'arco', 'arc123')
    expect(router.currentRoute.value.name).toBe('dash')
    await nav(w, 'Minhas Cotações')
    expect(w.findAll('.qcard')).toHaveLength(1)
    expect(w.findAll('[data-resp]')).toHaveLength(1)
    expect(w.find('[data-resp]').text()).not.toContain('ARCO')
    expect(w.find('[data-resp]').text()).not.toContain('ROTA SUL')
    await w.find('[data-act="answer-quote"]').trigger('click')
    await w.find('#rt-r-1').setValue('180')
    await w.find('#rp-r-1').setValue('3')
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-resp]').text()).toContain('R$ 4.860,00')

    await signOut(w)
    await signIn(w, 'via', 'rot123')
    await nav(w, 'Minhas Cotações')
    expect(w.find('[data-resp]').text()).not.toContain('ARCO')
    await w.find('[data-act="decline-quote"]').trigger('click')
    modal.payload.onYes()
    await flushPromises()
    expect(w.find('[data-resp]').text()).toContain('declinou')

    await signOut(w)
    await signIn(w, 'admin', 'admin')
    await nav(w, 'Solicitar Cotação')
    const card = w.findAll('.qcard')[0]
    await card.findAll('.qfoot button').find((b) => b.text().includes('Visualizar')).trigger('click')
    await flushPromises()
    expect(w.findAll('.resp-list [data-resp]')).toHaveLength(2)
    await w.find('.modal-f [data-act="close-quote"]').trigger('click')
    await flushPromises()
    expect(w.find('#fc-transp').element.value).toBe('t-arco')
    expect(w.find('#fc-ton').element.value).toBe('180,00')
    await w.find('#fc-obs').setValue('negociado por telefone')
    await w.find('#fc-pedido').setValue('PV-8001')
    await w.find('[data-act="close-negotiation"]').trigger('click')
    await flushPromises()
    expect(w.find('.modal-ov').exists()).toBe(false)
    expect(w.find('[data-group="PROGRAMADA"]').exists()).toBe(true)
    expect(toasts.map((t) => t.parts.map((p) => p.text).join('')).some((m) => m.includes('CRG-000001'))).toBe(true)

    await nav(w, 'Programação de Cargas')
    expect(w.findAll('#loadRows tr')).toHaveLength(1)
    expect(w.find('#loadRows tr').text()).toContain('CRG-000001')
    expect(w.find('#loadRows tr').text()).toContain('ARCO')
    w.unmount()
  })

  it('transportadora não vê o menu de cadastros e não acessa rotas administrativas', async () => {
    startBackend()
    const { w, router } = await boot('/login')
    await signIn(w, 'arco', 'arc123')
    expect(w.findAll('.nav-item').map((n) => n.text())).toEqual(['Painel Operacional', 'Programação de Cargas', 'Minhas Cotações', 'Consultar Cotação'])
    await router.push('/users')
    expect(router.currentRoute.value.name).toBe('dash')
    w.unmount()
  })

  it('admin vê o item Usuários e Acessos', async () => {
    startBackend()
    const { w } = await boot('/login')
    await signIn(w, 'admin', 'admin')
    expect(w.findAll('.nav-item').map((n) => n.text())).toContain('Usuários e Acessos')
    w.unmount()
  })

  it('sair do painel volta ao login e limpa a sessão', async () => {
    startBackend()
    const { w, router } = await boot('/login')
    await signIn(w, 'marcos', 'vendas')
    expect(router.currentRoute.value.name).toBe('quotes')
    await signOut(w)
    expect(router.currentRoute.value.name).toBe('login')
    expect(auth.session).toBeNull()
    expect(api.tokens.refresh).toBeNull()
    w.unmount()
  })

  it('recarregar a página retoma a sessão pelo refresh token', async () => {
    startBackend()
    const { w } = await boot('/login')
    await signIn(w, 'marcos', 'vendas')
    w.unmount()
    auth.session = null
    api.on('GET /me', ok(meSeller))
    expect(await restore()).toBe(true)
    const { w: w2, router } = await boot('/')
    expect(router.currentRoute.value.name).toBe('quotes')
    expect(w2.find('.side').exists()).toBe(true)
    w2.unmount()
  })
})
