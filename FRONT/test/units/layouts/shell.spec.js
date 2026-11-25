import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import AppSidebar from '../../../src/layouts/AppSidebar.vue'
import AppTopbar from '../../../src/layouts/AppTopbar.vue'
import AppShell from '../../../src/layouts/AppShell.vue'
import { nav } from '../../../src/stores/ui'
import LoginView from '../../../src/views/LoginView.vue'
import { auth, loginAs, logout } from '../../../src/stores/session'
import { resetDb } from '../../../src/stores/db'
import { createSession } from '../../../src/factories/sessionFactory'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const USERS = { admin: ['admin', meAdmin], marcos: ['vendas', meSeller], arco: ['arc123', meCarrier] }
function mockLogin() {
  api.on('POST /auth/login', ({ body }) => {
    const u = USERS[body.login]
    return u && u[0] === body.senha ? ok({ access_token: 'a', refresh_token: 'r', usuario: u[1] }) : err(401, 'Usuário ou senha inválidos.')
  })
}

const stub = { template: '<i />' }
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: stub },
      { path: '/cadastro', name: 'signup', component: stub },
      { path: '/dash', name: 'dash', component: stub, meta: { title: ['Painel', 'Operacional'] } },
      { path: '/quotes', name: 'quotes', component: stub, meta: { title: ['Solicitar', 'Cotação'] } },
      { path: '/products', name: 'products', component: stub, meta: { title: ['Cadastro', 'Produtos'] } },
      { path: '/carriers', name: 'carriers', component: stub, meta: { title: ['Cadastro de', 'Transportadoras'] } },
      { path: '/users', name: 'users', component: stub, meta: { title: ['Usuários e', 'Acessos'] } },
      { path: '/registrations', name: 'registrations', component: stub, meta: { title: ['Aprovação de', 'Cadastros'] } },
      { path: '/loads', name: 'loads', component: stub, meta: { title: ['Programação de', 'Cargas'] } },
    ],
  })
}

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  mockLogin()
  resetDb()
  await logout()
})

describe('AppSidebar', () => {
  it('lista o menu do perfil e marca o item ativo (branco + borda vermelha via .on)', async () => {
    loginAs(createSession(meAdmin))
    const router = makeRouter()
    router.push('/quotes')
    await router.isReady()
    const w = mount(AppSidebar, { global: { plugins: [router] } })
    const items = w.findAll('.nav-item')
    expect(items.map((i) => i.text())).toEqual(['Painel Operacional', 'Solicitar Cotação', 'Cadastro Produtos', 'Cadastro de Transportadoras', 'Usuários e Acessos', 'Cadastros', 'Programação de Cargas', 'Consultar Cotação'])
    expect(items[1].classes()).toContain('on')
    expect(items[0].classes()).not.toContain('on')
    expect(w.find('.nav-cap').text()).toBe('Módulos')
  })
  it('navega ao clicar', async () => {
    loginAs(createSession(meAdmin))
    const router = makeRouter()
    router.push('/dash')
    await router.isReady()
    const w = mount(AppSidebar, { global: { plugins: [router] } })
    await w.findAll('.nav-item')[6].trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('loads')
  })
  it('mostra usuário (avatar, nome, papel) e sai da sessão', async () => {
    loginAs(createSession(meSeller))
    const router = makeRouter()
    router.push('/quotes')
    await router.isReady()
    const w = mount(AppSidebar, { global: { plugins: [router] } })
    expect(w.find('.av').text()).toBe('M')
    expect(w.find('.nm').text()).toBe('MARCOS')
    expect(w.find('.rl').text()).toBe('VENDEDOR')
    expect(w.findAll('.nav-item')).toHaveLength(3)
    await w.find('.btn-out').trigger('click')
    await flushPromises()
    expect(auth.session).toBeNull()
    expect(router.currentRoute.value.name).toBe('login')
  })
  it('logo compacto: FretesDT + GESTÃO DE FRETES', () => {
    loginAs(createSession(meAdmin))
    const w = mount(AppSidebar, { global: { plugins: [makeRouter()] } })
    expect(w.find('.side-brand b').text()).toBe('FRETESDT')
    expect(w.find('.side-brand small').text()).toBe('GESTÃO DE FRETES')
  })
})

describe('AppTopbar', () => {
  it('C10: a transportadora vê "Minhas Cotações" no título e no breadcrumb, igual ao menu', async () => {
    loginAs(createSession(meCarrier))
    const router = makeRouter()
    router.push('/quotes')
    await router.isReady()
    const w = mount(AppTopbar, { global: { plugins: [router] } })
    expect(w.find('h1').text()).toBe('Minhas Cotações')
    expect(w.find('h1 b').text()).toBe('Cotações')
    expect(w.find('.crumb').text()).toBe('FretesDT // MINHAS COTAÇÕES')
  })
  it('breadcrumb mono, H1 com destaque vermelho e chip de perfil', async () => {
    loginAs(createSession(meSeller))
    const router = makeRouter()
    router.push('/quotes')
    await router.isReady()
    const w = mount(AppTopbar, { global: { plugins: [router] } })
    expect(w.find('.crumb').text()).toBe('FretesDT // SOLICITAR COTAÇÃO')
    expect(w.find('h1').text()).toBe('Solicitar Cotação')
    expect(w.find('h1 b').text()).toBe('Cotação')
    const chip = w.find('.rolechip')
    expect(chip.text()).toBe('VENDEDOR · MARCOS')
    expect(chip.classes()).toContain('role-VENDEDOR')
  })
  it('chip separa perfil e nome da empresa (o nome some no celular) e guarda o rótulo completo no title', async () => {
    loginAs(createSession(meCarrier))
    const router = makeRouter()
    router.push('/loads')
    await router.isReady()
    const w = mount(AppTopbar, { global: { plugins: [router] } })
    const chip = w.find('.rolechip')
    expect(chip.find('.rc-role').text()).toBe('TRANSPORTADORA')
    expect(chip.find('.rc-name').text()).toContain(meCarrier.transportadora.nome_fantasia)
    expect(chip.attributes('title')).toBe(chip.text())
  })
  it('administrador não tem nome de empresa no chip', async () => {
    loginAs(createSession(meAdmin))
    const router = makeRouter()
    router.push('/dash')
    await router.isReady()
    const w = mount(AppTopbar, { global: { plugins: [router] } })
    expect(w.find('.rolechip .rc-role').text()).toBe('ADMINISTRADOR')
    expect(w.find('.rolechip .rc-name').exists()).toBe(false)
  })
  it('relógio atualiza a cada segundo', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-01-01T10:00:00'))
    loginAs(createSession(meAdmin))
    const router = makeRouter()
    router.push('/dash')
    await router.isReady()
    const w = mount(AppTopbar, { global: { plugins: [router] } })
    expect(w.find('.clock').text()).toBe('10:00:00')
    vi.advanceTimersByTime(1000)
    await w.vm.$nextTick()
    expect(w.find('.clock').text()).toBe('10:00:01')
    w.unmount()
    vi.useRealTimers()
  })
})

describe('LoginView', () => {
  async function setup() {
    const router = makeRouter()
    router.push('/login')
    await router.isReady()
    const w = mount(LoginView, { global: { plugins: [router] } })
    return { w, router }
  }

  it('mostra marca, tagline e rodapé de status', async () => {
    const { w } = await setup()
    expect(w.find('.word').text()).toBe('FRETES')
    expect(w.find('.tagline').text()).toBe('Gestão de Fretes')
    expect(w.find('.lg-foot').text()).toContain('Sistema operacional')
    expect(w.find('.lg-right h2 span').text()).toBe('painel')
  })
  it('credenciais inválidas mostram erro inline e não entram', async () => {
    const { w } = await setup()
    await w.find('#lgUser').setValue('x')
    await w.find('#lgPass').setValue('y')
    await w.find('.btn-primary').trigger('click')
    await flushPromises()
    expect(w.find('.lg-err').text()).toContain('inválidos')
    expect(auth.session).toBeNull()
  })
  it('admin/admin entra e vai para o painel; Enter na senha submete', async () => {
    const { w, router } = await setup()
    await w.find('#lgUser').setValue('admin')
    await w.find('#lgPass').setValue('admin')
    await w.find('#lgPass').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(auth.session.role).toBe('ADMINISTRADOR')
    expect(router.currentRoute.value.name).toBe('dash')
  })
  it('mostra "Entrando…" e bloqueia o botão enquanto aguarda a API', async () => {
    let release
    api.on('POST /auth/login', () => new Promise((res) => { release = () => res(ok({ access_token: 'a', refresh_token: 'r', usuario: meAdmin })) }))
    const { w, router } = await setup()
    await w.find('#lgUser').setValue('admin')
    await w.find('#lgPass').setValue('admin')
    await w.find('.btn-primary').trigger('click')
    expect(w.find('.btn-primary').text()).toContain('Entrando')
    expect(w.find('.btn-primary').attributes('disabled')).toBeDefined()
    release()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('dash')
  })
  it('erro da API (ex.: 429) aparece inline', async () => {
    api.on('POST /auth/login', err(429, 'Muitas tentativas. Aguarde.'))
    const { w } = await setup()
    await w.find('#lgUser').setValue('admin')
    await w.find('#lgPass').setValue('x')
    await w.find('.btn-primary').trigger('click')
    await flushPromises()
    expect(w.find('.lg-err').text()).toBe('Muitas tentativas. Aguarde.')
  })
  it('vendedor e transportadora entram pelo formulário na rota inicial do perfil', async () => {
    let { w, router } = await setup()
    await w.find('#lgUser').setValue('marcos')
    await w.find('#lgPass').setValue('vendas')
    await w.find('.btn-primary').trigger('click')
    await flushPromises()
    expect(auth.session).toMatchObject({ role: 'VENDEDOR', name: 'MARCOS' })
    expect(router.currentRoute.value.name).toBe('quotes')

    await logout()
    ;({ w, router } = await setup())
    await w.find('#lgUser').setValue('arco')
    await w.find('#lgPass').setValue('arc123')
    await w.find('.btn-primary').trigger('click')
    await flushPromises()
    expect(auth.session).toMatchObject({ role: 'TRANSPORTADORA', name: 'Operador ARCO' })
    expect(router.currentRoute.value.name).toBe('dash')
  })
  it('não exibe atalhos de acesso rápido nem credenciais', async () => {
    const { w } = await setup()
    expect(w.find('.qbtn').exists()).toBe(false)
    expect(w.find('#cpSel').exists()).toBe(false)
    expect(w.find('#lgUser').attributes('placeholder')).toBe('seu usuário')
  })
})

describe('T21: menu do celular fecha com Esc em todos os perfis', () => {
  it.each([['admin', meAdmin], ['vendedor', meSeller], ['transportadora', meCarrier]])('%s', async (_n, me) => {
    loginAs(createSession(me))
    const router = makeRouter()
    router.push('/' + (me === meSeller ? 'quotes' : 'dash'))
    await router.isReady()
    const w = mount(AppShell, { global: { plugins: [router], stubs: { 'router-view': true } }, attachTo: document.body })
    nav.open = true
    await flushPromises()
    expect(w.find('[data-nav-backdrop]').exists()).toBe(true)
    document.body.focus()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(nav.open).toBe(false)
    expect(w.find('[data-nav-backdrop]').exists()).toBe(false)
    w.unmount()
  })
})
