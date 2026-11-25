import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import QuoteCard from '../../../src/components/QuoteCard.vue'
import QuotesView from '../../../src/views/QuotesView.vue'
import AnttInfo from '../../../src/components/AnttInfo.vue'
import ConfirmModal from '../../../src/components/ConfirmModal.vue'
import AppSidebar from '../../../src/layouts/AppSidebar.vue'
import AnttView from '../../../src/views/AnttView.vue'
import { useMenuOrder } from '../../../src/composables/useMenuOrder'
import { db, resetDb, findFreteMinimo } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, confirmAction, modal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, quote, resposta } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const neg = (respostas, o = {}) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null, ...o })

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  localStorage.clear()
})

describe('QuoteCard — resposta com status e sem nome da transportadora (vendedor/transportadora)', () => {
  const emNegociacao = () => quote({
    status: 'EM_NEGOCIACAO',
    negociacao: neg([
      resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '4860.00', prazo_dias: 3, validade: '2099-12-31' }),
      resposta({ id: 'r-2', status: 'AGUARDANDO', transportadora: { id: 't-v', nome_fantasia: 'ROTA SUL' } }),
      resposta({ id: 'r-3', status: 'DECLINADA', transportadora: { id: 't-m', nome_fantasia: 'MOVE LOG' } }),
    ]),
  })
  const card = (me, q = emNegociacao()) => {
    loginAs(createSession(me))
    return mount(QuoteCard, { props: { quote: q } })
  }

  it('vendedor: só valor, prazo e validade, com o status de cada resposta; nenhum nome de transportadora', () => {
    const lines = card(meSeller).findAll('[data-resp]')
    expect(lines).toHaveLength(3)
    const t = lines.map((l) => l.text()).join(' | ')
    expect(t).not.toContain('ARCO')
    expect(t).not.toContain('ROTA SUL')
    expect(t).not.toContain('MOVE LOG')
    expect(lines[0].text()).toContain('R$ 4.860,00')
    expect(lines[0].text()).toContain('3 dias')
    expect(lines[0].text()).toContain('31/12/2099')
    expect(lines[0].find('.pill').text()).toBe('RESPONDIDA')
    expect(lines[1].find('.pill').text()).toBe('AGUARDANDO')
    expect(lines[2].find('.pill').text()).toBe('DECLINADA')
  })
  it('transportadora: a própria resposta também aparece sem o nome, com o status', () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '4860.00', prazo_dias: 3 })]) })
    const line = card(meCarrier, q).find('[data-resp]')
    expect(line.text()).not.toContain('ARCO')
    expect(line.text()).toContain('R$ 4.860,00')
    expect(line.find('.pill').text()).toBe('RESPONDIDA')
  })
  it('admin continua vendo o nome de cada transportadora, agora com o status', () => {
    const lines = card(meAdmin).findAll('[data-resp]')
    expect(lines[0].text()).toContain('ARCO')
    expect(lines[1].text()).toContain('ROTA SUL')
    expect(lines.map((l) => l.find('.pill').text())).toEqual(['RESPONDIDA', 'AGUARDANDO', 'DECLINADA'])
  })
  it('cotação fechada: vendedor não vê com quem fechou', () => {
    const fechada = quote({
      status: 'FECHADA',
      negociacao: neg([], { status: 'FECHADA', fechamento: { transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, valor_frete: '4860.00', valor_tonelada: '180.00', prazo_dias: 3 } }),
    })
    const l = card(meSeller, fechada).find('.resp-line')
    expect(l.text()).not.toContain('ARCO')
    expect(l.text()).toContain('R$ 4.860,00')
    expect(l.text()).toContain('entrega 3 dias')
    expect(card(meAdmin, fechada).find('.resp-line').text()).toContain('ARCO')
  })
  it('mostra o frete mínimo e a diferença na linha da resposta (a API só envia `antt` a gestão e admin)', () => {
    const q = quote({
      status: 'EM_NEGOCIACAO',
      negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '80.00', valor_frete: '960.00', prazo_dias: 3, antt: { piso: '1000.00', km: '100.0', eixos: 5, diferenca: '-40.00', abaixo: true } })]),
    })
    const t = card(meAdmin, q).find('[data-resp] [data-antt-card]').text()
    expect(t).toContain('frete mínimo R$ 1.000,00')
    expect(t).toMatch(/−R\$\s40,00 \(4%\)/)
  })
  it('sem `antt` (vendedor) a linha não fala de frete mínimo', () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '80.00', valor_frete: '960.00', prazo_dias: 3, antt: null })]) })
    expect(card(meSeller, q).find('[data-antt-card]').exists()).toBe(false)
  })
  it('a vencedora vê PROGRAMADO no card (rótulo da transportadora); os demais perfis veem o status real', () => {
    const programada = quote({ status: 'PROGRAMADA', negociacao: neg([], { status: 'FECHADA' }) })
    expect(card(meCarrier, programada).find('.qhead .pill').text()).toBe('PROGRAMADO')
    expect(card(meAdmin, programada).find('.qhead .pill').text()).toBe('PROGRAMADA')
    expect(card(meSeller, programada).find('.qhead .pill').text()).toBe('PROGRAMADA')
  })
})

describe('AnttInfo — frete mínimo e insight da diferença', () => {
  const antt = (o = {}) => ({ piso: '1000.00', km: '100.0', eixos: 5, diferenca: '-40.00', abaixo: true, ...o })

  it('abaixo: destaca o frete mínimo e explica a diferença', () => {
    const w = mount(AnttInfo, { props: { antt: antt() } })
    expect(w.text()).toContain('Abaixo do frete mínimo ANTT')
    expect(w.text()).toContain('frete mínimo R$ 1.000,00')
    expect(w.find('[data-antt-insight]').text()).toMatch(/R\$\s40,00 \(4%\) abaixo do frete mínimo/)
    expect(w.classes()).toContain('abaixo')
  })
  it('acima: insight com a folga sobre o frete mínimo', () => {
    const w = mount(AnttInfo, { props: { antt: antt({ diferenca: '120.00', abaixo: false }) } })
    expect(w.text()).toContain('Acima do frete mínimo ANTT')
    expect(w.find('[data-antt-insight]').text()).toMatch(/R\$\s120,00 \(12%\) acima do frete mínimo/)
    expect(w.classes()).toContain('dentro')
  })
  it('igual ao frete mínimo', () => {
    const w = mount(AnttInfo, { props: { antt: antt({ diferenca: '0.00', abaixo: false }) } })
    expect(w.text()).toContain('No frete mínimo ANTT')
  })
})

describe('confirmAction — tom do botão', () => {
  it('tone "primary" troca o botão vermelho de exclusão', async () => {
    confirmAction('Mudar?', () => {}, { title: 'Confirmar', label: 'Seguir', icon: 'truck', tone: 'primary' })
    const w = mount(ConfirmModal)
    const btn = w.findAll('.modal-f button').at(-1)
    expect(btn.text()).toBe('Seguir')
    expect(btn.classes()).toContain('btn-primary')
    expect(btn.classes()).not.toContain('btn-danger')
    w.unmount()
  })
  it('sem tone mantém o vermelho', () => {
    confirmAction('Excluir?', () => {})
    const w = mount(ConfirmModal)
    expect(w.findAll('.modal-f button').at(-1).classes()).toContain('btn-danger')
    w.unmount()
  })
})

describe('db.findFreteMinimo', () => {
  it('envia origem, destino, eixos e tipo de carga; devolve o resultado', async () => {
    api.on('GET /antt/frete-minimo', ok({ km: '100.0', eixos: 5, ccd: '5.0000', cc: '500.00', frete_minimo: '1000.00' }))
    const r = await findFreteMinimo('Paulínia - SP', 'Campinas - SP', { eixos: '5', tipoCarga: 'CARGA_GERAL' })
    expect(r.frete_minimo).toBe('1000.00')
    const q = api.calls[0].query
    expect(q).toMatchObject({ eixos: 5, tipo_carga_codigo: 'CARGA_GERAL' })
    expect(q).toHaveProperty('origem_uf', 'SP')
    expect(q).toHaveProperty('destino_uf', 'SP')
    expect(q).not.toHaveProperty('ccd')
  })
  it('sem cidade válida nem chama a API; sem par devolve null', async () => {
    expect(await findFreteMinimo('Paulínia', 'Campinas - SP', { eixos: '5', tipoCarga: 'CARGA_GERAL' })).toBeNull()
    expect(api.calls).toHaveLength(0)
    api.on('GET /antt/frete-minimo', ok(null))
    expect(await findFreteMinimo('Paulínia - SP', 'Outra - SP', { eixos: '5', tipoCarga: 'CARGA_GERAL' })).toBeNull()
  })
})

describe('AnttView — consulta de frete mínimo', () => {
  const ALL = { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' }
  const adminAntt = { ...meAdmin, permissoes: { ...meAdmin.permissoes, ANTT: ALL } }
  const tipos = [{ codigo: 'CARGA_GERAL', descricao: 'Carga geral' }]

  async function montar() {
    api.on('GET /dominios', ok({ ...domains, tipos_carga_antt: tipos }))
    api.on('GET /antt/coeficientes', ok([]))
    loginAs(createSession(adminAntt))
    const w = mount(AnttView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }

  it('origem + destino + eixos + tipo de carga: mostra km e frete mínimo; CCD e CC ficam ocultos', async () => {
    api.on('GET /antt/frete-minimo', ok({ km: '100.0', eixos: 5, ccd: '5.0000', cc: '500.00', frete_minimo: '1000.00' }))
    const w = await montar()
    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Campinas - SP')
    await w.find('#an-q-eixos').setValue('5')
    await w.find('#an-q-tipo').setValue('CARGA_GERAL')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    const t = w.find('[data-frete-minimo]').text()
    expect(t).toContain('R$ 1.000,00')
    expect(t).toContain('100 km')
    expect(t).not.toContain('5,0000')
    expect(t).not.toContain('R$ 500,00')
    expect(w.find('#an-q-ccd').exists()).toBe(false)
    expect(w.find('#an-q-cc').exists()).toBe(false)
    expect(api.calls.at(-1).query).toMatchObject({ eixos: 5, tipo_carga_codigo: 'CARGA_GERAL' })
  })
  it('com o peso em ton: mostra o valor cheio e o valor por ton', async () => {
    api.on('GET /antt/frete-minimo', ok({ km: '100.0', eixos: 5, ccd: '5.0000', cc: '500.00', frete_minimo: '1000.00' }))
    const w = await montar()
    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Campinas - SP')
    await w.find('#an-q-eixos').setValue('5')
    await w.find('#an-q-tipo').setValue('CARGA_GERAL')
    await w.find('#an-q-peso').setValue('20')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    const t = w.find('[data-frete-minimo]').text()
    expect(t).toContain('R$ 1.000,00')
    expect(w.find('[data-frete-minimo-ton]').text()).toContain('R$ 50,00 por ton')
    expect(api.calls.at(-1).query).not.toHaveProperty('peso')
  })
  it('peso com vírgula e milhar (pt-BR); peso vazio ou zero não mostra o valor por ton', async () => {
    api.on('GET /antt/frete-minimo', ok({ km: '100.0', eixos: 5, ccd: '5.0000', cc: '500.00', frete_minimo: '1000.00' }))
    const w = await montar()
    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Campinas - SP')
    await w.find('#an-q-eixos').setValue('5')
    await w.find('#an-q-tipo').setValue('CARGA_GERAL')
    await w.find('#an-q-peso').setValue('32,5')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-frete-minimo-ton]').text()).toContain('R$ 30,77 por ton')
    await w.find('#an-q-peso').setValue('0')
    expect(w.find('[data-frete-minimo-ton]').exists()).toBe(false)
    await w.find('#an-q-peso').setValue('')
    expect(w.find('[data-frete-minimo-ton]').exists()).toBe(false)
    expect(w.find('[data-frete-minimo]').text()).toContain('R$ 1.000,00')
  })
  it('o campo do tipo de carga é largo (metade da linha)', async () => {
    const w = await montar()
    expect(w.find('#an-q-tipo').element.closest('.field').classList.contains('c6')).toBe(true)
  })
  it('sem tipo de carga: avisa por toast e não consulta', async () => {
    const w = await montar()
    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Campinas - SP')
    await w.find('#an-q-eixos').setValue('5')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    expect(api.called('GET', '/antt/frete-minimo')).toHaveLength(0)
    expect(toasts.at(-1).type).toBe('err')
  })
  it('sem eixos: avisa por toast e não consulta', async () => {
    const w = await montar()
    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Campinas - SP')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    expect(api.called('GET', '/antt/frete-minimo')).toHaveLength(0)
    expect(toasts.at(-1).type).toBe('err')
  })
  it('sem resultado: explica o que faltou', async () => {
    api.on('GET /antt/frete-minimo', ok(null))
    const w = await montar()
    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Outra - SP')
    await w.find('#an-q-eixos').setValue('5')
    await w.find('#an-q-tipo').setValue('CARGA_GERAL')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-frete-minimo-vazio]').text()).toMatch(/distância|coeficiente/i)
  })
})

describe('useMenuOrder', () => {
  const menu = [{ key: 'a' }, { key: 'b' }, { key: 'c' }, { key: 'd' }]

  it('sem ordem salva mantém a ordem original', () => {
    const { ordered } = useMenuOrder(() => menu, 'u1')
    expect(ordered.value.map((m) => m.key)).toEqual(['a', 'b', 'c', 'd'])
  })
  it('move um item para a posição de outro e grava no localStorage', () => {
    const { ordered, move } = useMenuOrder(() => menu, 'u1')
    move('d', 'a')
    expect(ordered.value.map((m) => m.key)).toEqual(['d', 'a', 'b', 'c'])
    expect(JSON.parse(localStorage.getItem('fretesdt.menu-ordem.u1'))).toEqual(['d', 'a', 'b', 'c'])
    move('a', 'c')
    expect(ordered.value.map((m) => m.key)).toEqual(['d', 'b', 'c', 'a'])
  })
  it('lê a ordem salva; chaves que não existem mais somem e itens novos vão para o fim', () => {
    localStorage.setItem('fretesdt.menu-ordem.u1', JSON.stringify(['c', 'x', 'a']))
    const { ordered } = useMenuOrder(() => menu, 'u1')
    expect(ordered.value.map((m) => m.key)).toEqual(['c', 'a', 'b', 'd'])
  })
  it('a ordem é de cada usuário', () => {
    useMenuOrder(() => menu, 'u1').move('d', 'a')
    expect(useMenuOrder(() => menu, 'u2').ordered.value.map((m) => m.key)).toEqual(['a', 'b', 'c', 'd'])
  })
  it('localStorage corrompido ou indisponível não derruba o menu', () => {
    localStorage.setItem('fretesdt.menu-ordem.u1', '{lixo')
    expect(useMenuOrder(() => menu, 'u1').ordered.value.map((m) => m.key)).toEqual(['a', 'b', 'c', 'd'])
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('cheio') })
    const { ordered, move } = useMenuOrder(() => menu, 'u1')
    expect(() => move('d', 'a')).not.toThrow()
    expect(ordered.value[0].key).toBe('d')
    spy.mockRestore()
  })
  it('mover para o próprio lugar ou para chave desconhecida não faz nada', () => {
    const { ordered, move } = useMenuOrder(() => menu, 'u1')
    move('a', 'a')
    move('a', 'zz')
    expect(ordered.value.map((m) => m.key)).toEqual(['a', 'b', 'c', 'd'])
    expect(localStorage.getItem('fretesdt.menu-ordem.u1')).toBeNull()
  })
  it('reset volta à ordem original e apaga a chave', () => {
    const { ordered, move, reset } = useMenuOrder(() => menu, 'u1')
    move('d', 'a')
    reset()
    expect(ordered.value.map((m) => m.key)).toEqual(['a', 'b', 'c', 'd'])
    expect(localStorage.getItem('fretesdt.menu-ordem.u1')).toBeNull()
  })
})

describe('AppSidebar — reordenar o menu', () => {
  const stub = { template: '<i />' }
  const makeRouter = () => createRouter({
    history: createMemoryHistory(),
    routes: ['dash', 'quotes', 'products', 'carriers', 'users', 'registrations', 'loads'].map((n) => ({ path: '/' + n, name: n, component: stub })),
  })
  async function montar(me = meAdmin) {
    loginAs(createSession(me))
    const router = makeRouter()
    router.push('/dash')
    await router.isReady()
    return mount(AppSidebar, { global: { plugins: [router] } })
  }
  const labels = (w) => w.findAll('.nav-item').map((i) => i.text())

  it('os itens são arrastáveis', async () => {
    const w = await montar()
    expect(w.findAll('.nav-item').every((i) => i.attributes('draggable') === 'true')).toBe(true)
  })
  it('arrastar um item sobre outro reordena e salva no localStorage do usuário', async () => {
    const w = await montar()
    const antes = labels(w)
    const itens = w.findAll('.nav-item')
    await itens[3].trigger('dragstart')
    await itens[0].trigger('dragover')
    await itens[0].trigger('drop')
    await itens[3].trigger('dragend')
    expect(labels(w)).toEqual([antes[3], antes[0], antes[1], antes[2], ...antes.slice(4)])
    expect(JSON.parse(localStorage.getItem('fretesdt.menu-ordem.admin'))).toEqual(['carriers', 'dash', 'quotes', 'products', 'users', 'registrations', 'loads', 'quote-search'])
  })
  it('a ordem salva vale ao montar de novo', async () => {
    localStorage.setItem('fretesdt.menu-ordem.admin', JSON.stringify(['loads', 'dash']))
    const w = await montar()
    expect(labels(w).slice(0, 2)).toEqual(['Programação de Cargas', 'Painel Operacional'])
    expect(labels(w)).toHaveLength(8)
  })
  it('teclado: Alt+↑ / Alt+↓ move o item focado (alternativa ao arrastar)', async () => {
    const w = await montar()
    const antes = labels(w)
    await w.findAll('.nav-item')[1].trigger('keydown', { key: 'ArrowUp', altKey: true })
    expect(labels(w).slice(0, 2)).toEqual([antes[1], antes[0]])
    await w.findAll('.nav-item')[0].trigger('keydown', { key: 'ArrowDown', altKey: true })
    expect(labels(w).slice(0, 2)).toEqual([antes[0], antes[1]])
  })
  it('clicar continua navegando', async () => {
    const w = await montar()
    await w.findAll('.nav-item')[6].trigger('click')
    await flushPromises()
    expect(w.vm.$router.currentRoute.value.name).toBe('loads')
  })
})

describe('QuotesView — perfil gestor', () => {
  const P = (ver, inserir = false, alterar = false, cancelar = false) => ({ ver, inserir, alterar, cancelar, escopo: 'TODOS' })
  const meGestor = {
    id: 'u-gestor', nome: 'Gestor', login: 'gestor', email: null, tipo_usuario: 'INTERNO', transportadora: null,
    grupos: ['GESTAO_ADMIN'],
    permissoes: {
      PAINEL: P(true), COTACAO: P(true, true, true), NEGOCIACAO: P(true), PROGRAMACAO: P(true, true, true), ANTT: P(true),
      TRANSPORTADORA: P(false), USUARIO: P(false), EMBALAGEM: P(false),
    },
    acoes: ['NEGOCIACAO.ENCAMINHAR', 'NEGOCIACAO.FECHAR'],
  }
  const montar = async (quotes) => {
    api.on('GET /produtos', ok([product()]))
    api.on('GET /cotacoes', ok(quotes))
    api.on('GET /transportadoras', ok([carrier(), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL' })]))
    loginAs(createSession(meGestor))
    const w = mount(QuotesView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }

  it('menu sem cadastro de transportadora/usuário, com cotação e programação', () => {
    const keys = createSession(meGestor).menu.map((m) => m.key)
    expect(keys).toEqual(expect.arrayContaining(['dash', 'quotes', 'loads']))
    expect(keys).not.toContain('carriers')
    expect(keys).not.toContain('users')
    expect(keys).not.toContain('packagings')
  })
  it('card: Encaminhar, Fechar e Encerrar liberados; Cancelar não; cria cotação', async () => {
    const w = await montar([
      quote({ id: 'q-1', codigo: 'COT-000001' }),
      quote({ id: 'q-2', codigo: 'COT-000002', status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })]) }),
    ])
    const btns = (code) => w.findAll('.qcard').find((c) => c.text().includes(code)).findAll('.qfoot button').map((b) => b.attributes('data-act'))
    expect(btns('COT-000001')).toEqual(['def-quote', 'edit-quote', 'view-quote', 'end-quote'])
    expect(btns('COT-000002')).toEqual(['def-quote', 'view-quote', 'close-quote', 'end-quote'])
    expect(w.find('[data-act="new-quote"]').exists()).toBe(true)
  })
  it('encaminha: lista as transportadoras, envia o POST e a cotação entra em negociação', async () => {
    const w = await montar([quote()])
    await w.find('[data-act="def-quote"]').trigger('click')
    await flushPromises()
    expect(w.findAll('.fw-list input')).toHaveLength(2)
    for (const i of w.findAll('.fw-list input')) await i.setValue(true)
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta(), resposta({ id: 'r-2', transportadora: { id: 't-via', nome_fantasia: 'ROTA SUL' } })]) })))
    await w.find('[data-act="forward-selected"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/encaminhar')).toHaveLength(1)
    expect(w.find('[data-group="EM_NEGOCIACAO"]').exists()).toBe(true)
    expect(toasts.at(-1).parts.map((p) => p.text).join('')).toContain('2 transportadoras')
  })
  it('fecha: escolhe a transportadora, envia o fechamento e a cotação vira PROGRAMADA', async () => {
    const w = await montar([quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })]) })])
    await w.find('[data-act="close-quote"]').trigger('click')
    await flushPromises()
    expect(w.find('#fc-transp').element.value).toBe('t-arco')
    api.on('POST /cotacoes/:id/fechar', ok(quote({ status: 'PROGRAMADA' }), { programacao: {} }))
    await w.find('#fc-pedido').setValue('PV-1')
    await w.find('[data-act="close-negotiation"]').trigger('click')
    await flushPromises()
    const call = api.called('POST', '/cotacoes/q-1/fechar')[0]
    expect(call.body).toMatchObject({ transportadora_id: 't-arco', prazo_dias: 3 })
    expect(w.find('[data-group="PROGRAMADA"]').exists()).toBe(true)
  })
  it('a API recusa (403): vira toast de erro e a cotação não muda', async () => {
    const w = await montar([quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })]) })])
    await w.find('[data-act="close-quote"]').trigger('click')
    await flushPromises()
    api.on('POST /cotacoes/:id/fechar', err(403, 'Sem permissão.'))
    await w.find('#fc-pedido').setValue('PV-1')
    await w.find('[data-act="close-negotiation"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(w.find('[data-group="EM_NEGOCIACAO"]').exists()).toBe(true)
  })
})
