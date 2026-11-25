import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import SignupView from '../../../src/views/SignupView.vue'
import LoginView from '../../../src/views/LoginView.vue'
import RegistrationsView from '../../../src/views/RegistrationsView.vue'
import AppSidebar from '../../../src/layouts/AppSidebar.vue'
import { createAppRouter } from '../../../src/router'
import { auth, loginAs, logout } from '../../../src/stores/session'
import { db, resetDb } from '../../../src/stores/db'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { isValidCNPJ } from '../../../src/utils/format'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller, carrier } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const CNPJ_OK = '11.222.333/0001-81'
const stub = { template: '<i />' }
const makeRouter = () => createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/login', name: 'login', component: stub },
    { path: '/cadastro', name: 'signup', component: stub },
    { path: '/registrations', name: 'registrations', component: stub },
  ],
})

const pending = (o = {}) => ({
  id: 'c-1', nome: 'Maria Souza', login: 'maria', email: 'maria@x.com', situacao: 'PENDENTE', tipo_solicitado: 'VENDEDOR',
  transportadora_dados: null, transportadora_sugerida: null, transportadora: null, grupos: [], motivo_recusa: null,
  solicitado_em: '2026-10-06T09:00:00.000-03:00', decidido_em: null, decidido_por: null, ...o,
})
const pendingCarrier = (o = {}) => pending({
  id: 'c-2', nome: 'Rui Lima', login: 'rui', tipo_solicitado: 'TRANSPORTADORA',
  transportadora_dados: { razao_social: 'RODO RAPIDO LTDA', nome_fantasia: 'RODO', cnpj: '11222333000181' }, ...o,
})

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('isValidCNPJ', () => {
  it('confere os dígitos verificadores', () => {
    expect(isValidCNPJ(CNPJ_OK)).toBe(true)
    expect(isValidCNPJ('11.222.333/0001-82')).toBe(false)
    expect(isValidCNPJ('11111111111111')).toBe(false)
    expect(isValidCNPJ('123')).toBe(false)
  })
})

describe('SignupView — cadastro público', () => {
  const setup = () => mount(SignupView, { global: { plugins: [makeRouter()] } })
  async function fillCommon(w, o = {}) {
    await w.find('#sg-nome').setValue(o.nome ?? 'Maria Souza')
    await w.find('#sg-login').setValue(o.login ?? '  Maria.Souza ')
    await w.find('#sg-email').setValue(o.email ?? 'Maria@Exemplo.com')
    await w.find('#sg-senha').setValue(o.senha ?? 'segredo123')
    await w.find('#sg-conf').setValue(o.conf ?? 'segredo123')
  }

  it('todos os campos têm label associado e o tipo é um grupo de rádios', () => {
    const w = setup()
    for (const id of ['sg-nome', 'sg-login', 'sg-email', 'sg-senha', 'sg-conf']) {
      expect(w.find(`label[for="${id}"]`).exists()).toBe(true)
    }
    expect(w.findAll('input[type="radio"]').map((r) => r.element.value)).toEqual(['VENDEDOR', 'TRANSPORTADORA'])
    expect(w.find('legend').text()).toContain('Tipo de acesso')
    expect(w.find('#sg-cnpj').exists()).toBe(false)
  })

  it('transportadora pede razão social, fantasia e CNPJ com máscara', async () => {
    const w = setup()
    await w.find('input[value="TRANSPORTADORA"]').setValue()
    expect(w.find('#sg-razao').exists()).toBe(true)
    expect(w.find('#sg-fantasia').exists()).toBe(true)
    await w.find('#sg-cnpj').setValue('11222333000181')
    expect(w.find('#sg-cnpj').element.value).toBe(CNPJ_OK)
  })

  it('valida no campo, com mensagem e aria-invalid, e não chama a API', async () => {
    const w = setup()
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('#sg-nome').attributes('aria-invalid')).toBe('true')
    expect(w.find('#sg-nome-err').text()).toBe('Informe seu nome.')
    expect(w.find('#sg-nome').attributes('aria-describedby')).toBe('sg-nome-err')
    await fillCommon(w, { senha: 'curta', conf: 'outra' })
    await w.find('form').trigger('submit')
    expect(w.find('#sg-senha-err').text()).toContain('pelo menos 8')
    expect(w.find('#sg-conf-err').text()).toContain('não confere')
    expect(api.called('POST', '/auth/cadastro')).toHaveLength(0)
  })

  it('transportadora com CNPJ de dígitos errados é barrada no campo', async () => {
    const w = setup()
    await w.find('input[value="TRANSPORTADORA"]').setValue()
    await fillCommon(w)
    await w.find('#sg-razao').setValue('Rodo Rapido Ltda')
    await w.find('#sg-cnpj').setValue('11222333000182')
    await w.find('form').trigger('submit')
    expect(w.find('#sg-cnpj-err').text()).toBe('CNPJ inválido. Confira os dígitos.')
    expect(api.called('POST', '/auth/cadastro')).toHaveLength(0)
  })

  it('envia o cadastro normalizado e mostra a confirmação', async () => {
    api.on('POST /auth/cadastro', ok({ id: 'u1', situacao: 'PENDENTE' }, {}, 201))
    const w = setup()
    await w.find('input[value="TRANSPORTADORA"]').setValue()
    await fillCommon(w)
    await w.find('#sg-razao').setValue(' Rodo Rapido Ltda ')
    await w.find('#sg-fantasia').setValue('Rodo')
    await w.find('#sg-cnpj').setValue(CNPJ_OK)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.called('POST', '/auth/cadastro')[0].body).toEqual({
      tipo: 'TRANSPORTADORA', nome: 'Maria Souza', login: 'maria.souza', email: 'maria@exemplo.com',
      senha: 'segredo123', senha_confirmation: 'segredo123',
      razao_social: 'Rodo Rapido Ltda', nome_fantasia: 'Rodo', cnpj: '11222333000181',
    })
    expect(w.find('[data-signup-done]').exists()).toBe(true)
    expect(w.find('[role="status"]').text()).toBe('Cadastro enviado. Aguarde a aprovação do administrador.')
    expect(w.find('[data-act="back-login"]').exists()).toBe(true)
  })

  it('vendedor não envia dados de transportadora', async () => {
    api.on('POST /auth/cadastro', ok({ id: 'u1', situacao: 'PENDENTE' }, {}, 201))
    const w = setup()
    await fillCommon(w)
    await w.find('form').trigger('submit')
    await flushPromises()
    const body = api.called('POST', '/auth/cadastro')[0].body
    expect(body.tipo).toBe('VENDEDOR')
    expect(body).not.toHaveProperty('cnpj')
  })

  it('erro do back (login indisponível) aparece no campo e some ao editar', async () => {
    api.on('POST /auth/cadastro', err(422, 'Login indisponível.', { login: ['Login indisponível.'] }))
    const w = setup()
    await fillCommon(w)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('#sg-login-err').text()).toBe('Login indisponível.')
    expect(w.find('#sg-login').attributes('aria-invalid')).toBe('true')
    expect(w.find('[data-signup-done]').exists()).toBe(false)
    await w.find('#sg-login').setValue('outro')
    expect(w.find('#sg-login-err').exists()).toBe(false)
  })

  it('429 aparece como aviso do formulário', async () => {
    api.on('POST /auth/cadastro', err(429, 'Muitas tentativas. Aguarde um instante e tente novamente.'))
    const w = setup()
    await fillCommon(w)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('.lg-err').text()).toContain('Muitas tentativas')
  })
})

describe('LoginView — cadastro pendente e recusado', () => {
  async function submitWith(res) {
    api.on('POST /auth/login', res)
    const router = makeRouter()
    router.push('/login')
    await router.isReady()
    const w = mount(LoginView, { global: { plugins: [router] } })
    await w.find('#lgUser').setValue('maria')
    await w.find('#lgPass').setValue('segredo123')
    await w.find('.btn-primary').trigger('click')
    await flushPromises()
    return w
  }

  it('tem o link Criar conta para o formulário público', async () => {
    const router = makeRouter()
    router.push('/login')
    await router.isReady()
    const w = mount(LoginView, { global: { plugins: [router] } })
    const a = w.find('a[data-act="signup"]')
    expect(a.text()).toBe('Criar conta')
    expect(a.attributes('href')).toBe('/cadastro')
  })

  it('pendente: mostra "Seu cadastro está em análise." como aviso (não como erro)', async () => {
    const w = await submitWith({ ok: false, error: 'Seu cadastro está em análise.', errors: {}, code: 'CADASTRO_PENDENTE', status: 403 })
    const box = w.find('.lg-err')
    expect(box.text()).toBe('Seu cadastro está em análise.')
    expect(box.classes()).toContain('info')
    expect(box.attributes('role')).toBe('status')
    expect(auth.session).toBeNull()
  })

  it('recusado: mostra o motivo como erro', async () => {
    const w = await submitWith({ ok: false, error: 'Cadastro recusado: CNPJ não confere', errors: {}, code: 'CADASTRO_RECUSADO', status: 403 })
    const box = w.find('.lg-err')
    expect(box.text()).toBe('Cadastro recusado: CNPJ não confere')
    expect(box.classes()).not.toContain('info')
    expect(box.attributes('role')).toBe('alert')
  })
})

describe('router — rota pública e rota do admin', () => {
  it('cadastro é público; deslogado não entra em /registrations; só admin com a ação entra', async () => {
    const router = createAppRouter(true)
    await router.push('/cadastro')
    expect(router.currentRoute.value.name).toBe('signup')
    await router.push('/registrations')
    expect(router.currentRoute.value.name).toBe('login')

    loginAs(createSession(meSeller))
    await router.push('/registrations')
    expect(router.currentRoute.value.name).not.toBe('registrations')

    await logout()
    loginAs(createSession(meAdmin))
    await router.push('/registrations')
    expect(router.currentRoute.value.name).toBe('registrations')
    await router.push('/cadastro')
    expect(router.currentRoute.value.name).toBe('dash')
  })
})

describe('menu "Cadastros" com selo de pendentes', () => {
  it('só aparece com a ação do admin', () => {
    expect(createSession(meAdmin).menu.map((m) => m.key)).toContain('registrations')
    expect(createSession(meSeller).menu.map((m) => m.key)).not.toContain('registrations')
    const semAcao = { ...meAdmin, acoes: meAdmin.acoes.filter((a) => a !== 'USUARIO.APROVAR_CADASTRO') }
    expect(createSession(semAcao).menu.map((m) => m.key)).not.toContain('registrations')
  })

  it('mostra a quantidade e atualiza por polling', async () => {
    vi.useFakeTimers()
    try {
      let total = 3
      api.on('GET /cadastros', () => ok(Array.from({ length: total }, (_, i) => pending({ id: `c${i}` })), { total }))
      loginAs(createSession(meAdmin))
      const router = makeRouter()
      router.push('/registrations')
      await router.isReady()
      const w = mount(AppSidebar, { global: { plugins: [router] } })
      await flushPromises()
      const item = () => w.findAll('.nav-item').find((i) => i.text().includes('Cadastros'))
      expect(item().find('.nav-badge').text()).toBe('3')
      expect(item().attributes('aria-label')).toBe('Cadastros, 3 pendentes')
      expect(api.calls.find((c) => c.path === '/cadastros').query).toEqual({ status: 'PENDENTE' })

      total = 1
      await vi.advanceTimersByTimeAsync(20500)
      await flushPromises()
      expect(item().find('.nav-badge').text()).toBe('1')
      expect(item().attributes('aria-label')).toBe('Cadastros, 1 pendente')
      total = 0
      await vi.advanceTimersByTimeAsync(20500)
      await flushPromises()
      expect(item().find('.nav-badge').exists()).toBe(false)
      w.unmount()
    } finally {
      vi.useRealTimers()
    }
  })

  it('quem não aprova cadastros não consulta a fila', async () => {
    loginAs(createSession(meSeller))
    const router = makeRouter()
    router.push('/login')
    await router.isReady()
    mount(AppSidebar, { global: { plugins: [router] } })
    await flushPromises()
    expect(api.called('GET', '/cadastros')).toHaveLength(0)
  })
})

describe('RegistrationsView — fila do administrador', () => {
  async function mountView(list = [pending(), pendingCarrier()], extra = {}) {
    api.on('GET /cadastros', ({ query }) => (query.status === 'PENDENTE' ? ok(list, { total: list.length }) : ok(extra.history || [], { total: (extra.history || []).length })))
    api.on('GET /transportadoras', ok([carrier(), carrier({ id: 't-v', nome_fantasia: 'ROTA SUL', cnpj: '99888777000166' })]))
    loginAs(createSession(meAdmin))
    const w = mount(RegistrationsView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }
  const card = (w, login) => w.find(`[data-reg="${login}"]`)

  it('lista os pendentes com os dados informados e Aprovar/Recusar', async () => {
    const w = await mountView()
    expect(w.findAll('.reg-card')).toHaveLength(2)
    const c = card(w, 'rui')
    expect(c.text()).toContain('Rui Lima')
    expect(c.text()).toContain('RODO RAPIDO LTDA')
    expect(c.text()).toContain('11.222.333/0001-81')
    expect(c.find('[data-act="approve"]').attributes('aria-label')).toBe('Aprovar cadastro de Rui Lima')
    expect(c.find('[data-act="reject"]').exists()).toBe(true)
    expect(w.find('.card-h .cnt').text()).toBe('2 pendentes')
  })

  it('fila vazia mostra o estado vazio', async () => {
    const w = await mountView([])
    expect(w.find('.empty').text()).toContain('Nenhum cadastro aguardando aprovação.')
  })

  it('aprovar vendedor envia só o grupo e tira o cadastro da fila', async () => {
    api.on('POST /cadastros/:id/aprovar', ok(pending({ situacao: 'ATIVO' })))
    const w = await mountView()
    await card(w, 'maria').find('[data-act="approve"]').trigger('click')
    expect(w.find('[role="dialog"]').exists()).toBe(true)
    expect(w.find('input[name="ap-grupo"][value="VENDEDOR"]').element.checked).toBe(true)
    expect(w.find('input[name="ap-modo"]').exists()).toBe(false)
    await w.find('[data-act="confirm-approve"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cadastros/c-1/aprovar')[0].body).toEqual({ grupo: 'VENDEDOR' })
    expect(w.find('[data-reg="maria"]').exists()).toBe(false)
    expect(db.pendingCount).toBe(1)
    expect(toasts.some((t) => t.type === 'ok')).toBe(true)
    expect(w.find('[role="dialog"]').exists()).toBe(false)
  })

  it('aprovar transportadora: sugere a existente pelo mesmo CNPJ e vincula', async () => {
    api.on('POST /cadastros/:id/aprovar', ok(pendingCarrier({ situacao: 'ATIVO' })))
    const w = await mountView([pendingCarrier({ transportadora_sugerida: { id: 't-arco', nome_fantasia: 'ARCO' } })])
    expect(card(w, 'rui').find('.reg-hint').text()).toContain('ARCO')
    await card(w, 'rui').find('[data-act="approve"]').trigger('click')
    expect(w.find('input[name="ap-modo"][value="vincular"]').element.checked).toBe(true)
    expect(w.find('#ap-transp').element.value).toBe('t-arco')
    expect(w.find('#ap-transp').text()).toContain('(mesmo CNPJ)')
    await w.find('[data-act="confirm-approve"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cadastros/c-2/aprovar')[0].body).toEqual({ grupo: 'TRANSPORTADORA', transportadora_id: 't-arco' })
  })

  it('aprovar transportadora sem sugestão: cria nova com os dados do cadastro', async () => {
    api.on('POST /cadastros/:id/aprovar', ok(pendingCarrier({ situacao: 'ATIVO' })))
    const w = await mountView()
    await card(w, 'rui').find('[data-act="approve"]').trigger('click')
    expect(w.find('input[name="ap-modo"][value="criar"]').element.checked).toBe(true)
    expect(w.find('#ap-transp').exists()).toBe(false)
    await w.find('[data-act="confirm-approve"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cadastros/c-2/aprovar')[0].body).toEqual({ grupo: 'TRANSPORTADORA', criar_transportadora: true })
  })

  it('vincular sem escolher a transportadora não chama a API e avisa', async () => {
    const w = await mountView([pendingCarrier()])
    await card(w, 'rui').find('[data-act="approve"]').trigger('click')
    await w.find('input[name="ap-modo"][value="vincular"]').setValue()
    await w.find('[data-act="confirm-approve"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cadastros/c-2/aprovar')).toHaveLength(0)
    expect(w.find('#ap-err').text()).toBe('Selecione a transportadora.')
    expect(w.find('#ap-transp').attributes('aria-invalid')).toBe('true')
  })

  it('cadastro de vendedor aprovado como transportadora não oferece criar', async () => {
    const w = await mountView()
    await card(w, 'maria').find('[data-act="approve"]').trigger('click')
    await w.find('input[name="ap-grupo"][value="TRANSPORTADORA"]').setValue()
    const criar = w.find('input[name="ap-modo"][value="criar"]')
    expect(criar.attributes('disabled')).toBeDefined()
    expect(w.find('input[name="ap-modo"][value="vincular"]').element.checked).toBe(true)
  })

  it('recusar exige motivo, envia e tira da fila', async () => {
    api.on('POST /cadastros/:id/recusar', ok(pending({ situacao: 'RECUSADO' })))
    const w = await mountView()
    await card(w, 'maria').find('[data-act="reject"]').trigger('click')
    await w.find('[data-act="confirm-motivo"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cadastros/c-1/recusar')).toHaveLength(0)
    expect(w.find('#mt-motivo-err').text()).toBe('Informe o motivo da recusa.')
    await w.find('#mt-motivo').setValue('  Não conhecemos a empresa ')
    await w.find('[data-act="confirm-motivo"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cadastros/c-1/recusar')[0].body).toEqual({ motivo: 'Não conhecemos a empresa' })
    expect(w.find('[data-reg="maria"]').exists()).toBe(false)
    expect(db.pendingCount).toBe(1)
  })

  it('409 (outro admin já decidiu) avisa e recarrega a fila', async () => {
    api.on('POST /cadastros/:id/recusar', err(409, 'Este cadastro já foi decidido.'))
    const w = await mountView()
    await card(w, 'maria').find('[data-act="reject"]').trigger('click')
    await w.find('#mt-motivo').setValue('motivo qualquer')
    await w.find('[data-act="confirm-motivo"]').trigger('click')
    await flushPromises()
    expect(toasts.some((t) => t.type === 'err')).toBe(true)
    expect(w.find('[role="dialog"]').exists()).toBe(false)
    expect(api.called('GET', '/cadastros').length).toBeGreaterThanOrEqual(2)
  })

  it('aba Histórico lista aprovados e recusados com decisão, vínculo e motivo', async () => {
    const history = [
      pending({ id: 'h1', nome: 'Ana', login: 'ana', situacao: 'ATIVO', grupos: ['TRANSPORTADORA'], transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, decidido_em: '2026-10-06T10:00:00.000-03:00', decidido_por: { id: 'a', nome: 'Administrador' } }),
      pending({ id: 'h2', nome: 'Beto', login: 'beto', situacao: 'RECUSADO', motivo_recusa: 'CNPJ não confere', decidido_em: '2026-10-06T11:00:00.000-03:00', decidido_por: { id: 'a', nome: 'Administrador' } }),
    ]
    const w = await mountView([pending()], { history })
    await w.find('[data-tab="history"]').trigger('click')
    await flushPromises()
    expect(api.calls.some((c) => c.path === '/cadastros' && c.query.status === 'ATIVO,RECUSADO')).toBe(true)
    const ana = w.find('[data-reg="ana"]')
    expect(ana.find('.pill').text()).toBe('Aprovado')
    expect(ana.text()).toContain('ARCO')
    expect(ana.text()).toContain('Administrador')
    const beto = w.find('[data-reg="beto"]')
    expect(beto.find('.pill').text()).toBe('Recusado')
    expect(beto.text()).toContain('CNPJ não confere')
    expect(w.find('[data-act="approve"]').exists()).toBe(false)
    expect(w.find('[data-tab="history"]').attributes('aria-selected')).toBe('true')
  })
})
