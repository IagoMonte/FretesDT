import { mount, flushPromises } from '@vue/test-utils'
import MoneyInput from '../../../src/components/MoneyInput.vue'
import CotacaoAnterior from '../../../src/components/CotacaoAnterior.vue'
import RespondModal from '../../../src/components/RespondModal.vue'
import QuotesView from '../../../src/views/QuotesView.vue'
import LoadsView from '../../../src/views/LoadsView.vue'
import UsersView from '../../../src/views/UsersView.vue'
import ForwardModal from '../../../src/components/ForwardModal.vue'
import ItemsEditor from '../../../src/components/ItemsEditor.vue'
import AppTopbar from '../../../src/layouts/AppTopbar.vue'
import { createRouter, createMemoryHistory } from 'vue-router'
import { db, resetDb } from '../../../src/stores/db'
import { logout, loginAs } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, nav } from '../../../src/stores/ui'
import { createQuote } from '../../../src/services/quoteFlow'
import { validateQuote } from '../../../src/utils/quoteForm'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, parte, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const products = [product(), product({ id: 'p-hcl', nome: 'HCL', embalagens: ['GRANEL_LIQUIDO', 'BOMBONA'] })]
const stubs = { global: { stubs: { teleport: true } } }
const toastText = () => toasts.map((t) => t.parts.map((p) => p.text).join('')).join(' | ')

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok(products))
  api.on('GET /cotacoes', ok([]))
  api.on('GET /programacoes', ok([]))
  api.on('GET /transportadoras', ok([carrier()]))
  api.on('GET /vendedores', ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  nav.open = false
})
afterEach(() => vi.useRealTimers())

describe('1. MoneyInput: entrada natural pt-BR', () => {
  async function type(w, text) {
    const el = w.find('input')
    el.element.value = text
    await el.trigger('input')
    return el
  }
  it.each([
    ['250', '250,00'],
    ['250,5', '250,50'],
    ['1.250,75', '1.250,75'],
    ['1250,75', '1.250,75'],
    ['1.250', '1.250,00'],
  ])('"%s" vira "%s" ao sair do campo', async (typed, shown) => {
    const w = mount(MoneyInput, { props: { modelValue: '' } })
    const el = await type(w, typed)
    await el.trigger('blur')
    expect(el.element.value).toBe(shown)
    expect(w.emitted('update:modelValue').at(-1)[0]).toBe(shown)
  })
  it('não reformata enquanto digita (250 continua 250)', async () => {
    const w = mount(MoneyInput, { props: { modelValue: '' } })
    const el = await type(w, '250')
    expect(el.element.value).toBe('250')
    expect(w.emitted('update:modelValue').at(-1)[0]).toBe('250')
  })
  it('vazio e inválido: ficam vazios', async () => {
    const w = mount(MoneyInput, { props: { modelValue: '' } })
    let el = await type(w, '')
    await el.trigger('blur')
    expect(el.element.value).toBe('')
    el = await type(w, 'abc')
    expect(el.element.value).toBe('')
    await el.trigger('blur')
    expect(w.emitted('update:modelValue').at(-1)[0]).toBe('')
    el = await type(w, ',')
    await el.trigger('blur')
    expect(el.element.value).toBe('')
  })
  it('placeholder coerente e teclado decimal', () => {
    const el = mount(MoneyInput, { props: { modelValue: '' } }).find('input')
    expect(el.attributes('placeholder')).toBe('0,00')
    expect(el.attributes('inputmode')).toBe('decimal')
  })
  it('RespondModal envia 250,5 como "250.50"', async () => {
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote()))
    api.on('GET /cotacoes/:id', ok(quote()))
    loginAs(createSession(meCarrier))
    const w = mount(RespondModal, { props: { quote: quote(), resposta: resposta() }, ...stubs })
    const el = w.find('#rt-r-1')
    el.element.value = '250,5'
    await el.trigger('input')
    await el.trigger('blur')
    expect(el.element.value).toBe('250,50')
    await w.find('#rp-r-1').setValue('3')
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body.valor_tonelada).toBe('250.50')
  })
})

describe('2. CotacaoAnterior recalcula o frete com a quantidade atual', () => {
  const anterior = {
    id: 'q-0', codigo: 'COT-000004', data_solicitacao: '2026-10-01', status: 'EM_NEGOCIACAO',
    respostas: [resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })],
  }
  it('mostra R$/t em destaque e "frete estimado p/ X t" com o Σkg do formulário', () => {
    const w = mount(CotacaoAnterior, { props: { anterior, kg: 12500 } })
    const r = w.find('[data-prev-resp]')
    expect(r.find('[data-pq-ton]').text()).toBe('R$ 180,00/t')
    expect(r.find('[data-pq-est]').text()).toContain('frete estimado p/ 12,5 t')
    expect(r.find('[data-pq-est]').text()).toContain('R$ 2.250,00')
    expect(r.text()).not.toContain('3.600,00')
  })
  it('acompanha a quantidade e, sem quantidade, só pede a QTD', async () => {
    const w = mount(CotacaoAnterior, { props: { anterior, kg: 1000 } })
    expect(w.find('[data-pq-est]').text()).toContain('R$ 180,00')
    await w.setProps({ kg: 27000 })
    expect(w.find('[data-pq-est]').text()).toContain('R$ 4.860,00')
    await w.setProps({ kg: 0 })
    expect(w.find('[data-pq-est]').exists()).toBe(false)
    expect(w.find('[data-pq-ton]').text()).toBe('R$ 180,00/t')
  })
})

describe('3. toast de cotação vinculada', () => {
  const form = (o = {}) => ({
    operacao: 'CARREGAMENTO', parte: { tipo: 'CLIENTE', id: 'c-1' }, veiculo: 'CARRETA', valorNF: '15.000,00', origem: 'Paulínia - SP', destino: 'Campinas - SP',
    itens: [{ produto_id: 'p-hcl', embalagem: 'GRANEL_LIQUIDO', qtd: '20.000', volumes: '' }], observacao: '', usarAnterior: true, ...o,
  })
  it('vinculada: "criada com as respostas da COT-yyy" (e sem alerta duplicado)', async () => {
    const origem = { id: 'q-0', codigo: 'COT-000004', data_solicitacao: '2026-10-01', status: 'EM_NEGOCIACAO' }
    api.on('POST /cotacoes', ok(quote({ id: 'q-9', codigo: 'COT-000010', status: 'EM_NEGOCIACAO', cotacao_origem: origem }), { alertas: ['Cotação repetida: usando as respostas da COT-000004.'] }, 201))
    await createQuote(form())
    const t = toastText()
    expect(t).toContain('Cotação COT-000010 criada com as respostas da COT-000004')
    expect(t).not.toContain('aguardando')
    expect(t).not.toContain('Cotação repetida')
  })
  it('sem vínculo mantém o aviso de Encaminhar', async () => {
    api.on('POST /cotacoes', ok(quote({ id: 'q-9', codigo: 'COT-000011' }), {}, 201))
    await createQuote(form())
    expect(toastText()).toContain('aguardando ação Encaminhar')
  })
})

describe('4. validação do formulário de nova cotação', () => {
  async function open(opts = {}) {
    loginAs(createSession(meSeller))
    const w = mount(QuotesView, { ...stubs, ...opts })
    await flushPromises()
    await w.find('[data-act="new-quote"]').trigger('click')
    return w
  }
  it('validateQuote lista cada campo faltante', () => {
    expect(Object.keys(validateQuote({ operacao: 'CARREGAMENTO', parte: null, itens: [{ produto_id: '', embalagem: '', qtd: '' }], veiculo: '', valorNF: '', origem: '', destino: '' })))
      .toEqual(['parte', 'itens', 'veiculo', 'valorNF', 'origem', 'destino'])
    expect(validateQuote({ operacao: 'RECEBIMENTO', parte: null, itens: [], veiculo: 'X', valorNF: '1', origem: 'A - SP', destino: 'B - SP' }).parte).toContain('fornecedor')
    expect(validateQuote({ parte: {}, itens: [{ produto_id: 'p', embalagem: 'BIG_BAG', qtd: '1.000' }], veiculo: 'X', valorNF: '1,00', origem: 'A - SP', destino: 'B - SP' })).toEqual({})
  })
  it('formulário vazio: borda vermelha + mensagem por campo, toast resumido, sem POST', async () => {
    api.on('POST /cotacoes', ok(quote(), {}, 201))
    const w = await open()
    expect(w.find('.ferr').exists()).toBe(false)
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes')).toHaveLength(0)
    for (const f of ['parte', 'itens', 'valorNF', 'origem', 'destino']) expect(w.find(`[data-err="${f}"]`).exists()).toBe(true)
    for (const id of ['#qc-parte', '#qc-valor', '#qc-origem', '#qc-destino', '#qc-prod-0']) expect(w.find(id).classes()).toContain('invalid')
    expect(w.find('#qc-valor').attributes('aria-invalid')).toBe('true')
    expect(toastText()).toContain('Revise os campos')
    expect(toastText()).toContain('valor da NF')
  })
  it('foca o primeiro campo inválido', async () => {
    const w = await open({ attachTo: document.body })
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(document.activeElement?.id).toBe('qc-parte')
    w.unmount()
  })
  it('o erro some quando o campo é corrigido', async () => {
    const w = await open()
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-err="origem"]').exists()).toBe(true)
    await w.find('#qc-origem').setValue('Paulínia - SP')
    expect(w.find('[data-err="origem"]').exists()).toBe(false)
    expect(w.find('#qc-origem').classes()).not.toContain('invalid')
    expect(w.find('[data-err="destino"]').exists()).toBe(true)
  })
})

describe('5. Responder mostra a transportadora', () => {
  it('título com COT e transportadora', () => {
    const w = mount(RespondModal, { props: { quote: quote(), resposta: resposta({ transportadora: { id: 't-trd', nome_fantasia: 'NOVA LOG' } }) }, ...stubs })
    expect(w.find('h3').text()).toBe('Responder COT-000001 · NOVA LOG')
  })
})

describe('6. carregamento discreto da prévia', () => {
  it('mostra o indicador durante o debounce e a busca, e some ao chegar', async () => {
    let release
    api.on('GET /cotacoes/anterior', () => new Promise((res) => { release = () => res({ ok: true, status: 200, data: null, meta: {} }) }))
    loginAs(createSession(meSeller))
    const w = mount(QuotesView, stubs)
    await flushPromises()
    await w.find('[data-act="new-quote"]').trigger('click')
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await w.find('#qc-prod-0').setValue('p-hcl')
    await w.find('#qc-emb-0').setValue('GRANEL_LIQUIDO')
    await w.find('#qc-origem').setValue('Paulínia - SP')
    await w.find('#qc-destino').setValue('Campinas - SP')
    expect(w.find('[data-prev-loading]').exists()).toBe(true)
    await vi.advanceTimersByTimeAsync(600)
    vi.useRealTimers()
    expect(w.find('[data-prev-loading]').exists()).toBe(true)
    release?.()
    await flushPromises()
    expect(w.find('[data-prev-loading]').exists()).toBe(false)
  })
})

describe('7. cargas no mobile', () => {
  const setMobile = (matches) => {
    window.matchMedia = (q) => ({ matches: matches && q.includes('768'), media: q, addEventListener() {}, removeEventListener() {} })
  }
  afterEach(() => { delete window.matchMedia })
  it('até 768 px: cartões com os campos principais e ações; sem tabela', async () => {
    setMobile(true)
    api.on('GET /programacoes', ok([load({ valor_total: '3500.00' })]))
    loginAs(createSession(meAdmin))
    const w = mount(LoadsView, stubs)
    await flushPromises()
    expect(w.find('#loadTable').exists()).toBe(false)
    const c = w.find('[data-load-card]')
    const t = c.text()
    for (const s of ['CRG-000001', 'PAULÍNIA - SP → CAMPINAS - SP', 'ARCO', 'R$ 3.500,00', '06/10/2026', '08/10/2026', 'ALFAQUIM']) expect(t).toContain(s)
    expect(c.find('[data-act="transit-load"]').exists()).toBe(true)
    expect(c.find('[data-act="cancel-load"]').exists()).toBe(true)
    w.unmount()
  })
  it('desktop mantém a tabela', async () => {
    setMobile(false)
    api.on('GET /programacoes', ok([load()]))
    loginAs(createSession(meAdmin))
    const w = mount(LoadsView, stubs)
    await flushPromises()
    expect(w.find('#loadTable').exists()).toBe(true)
    expect(w.find('[data-load-card]').exists()).toBe(false)
    w.unmount()
  })
})

describe('8. itens menores', () => {
  it('burger alterna Abrir/Fechar menu', async () => {
    loginAs(createSession(meAdmin))
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<i/>' }, meta: { title: ['A', 'B'] } }] })
    await router.push('/')
    const w = mount(AppTopbar, { global: { plugins: [router] } })
    const b = w.find('.nav-toggle')
    expect(b.attributes('aria-label')).toBe('Abrir menu')
    nav.open = true
    await w.vm.$nextTick()
    expect(b.attributes('aria-label')).toBe('Fechar menu')
  })
  it('select de embalagem desabilitado com "Escolha o produto" até escolher o produto', async () => {
    db.products = products
    db.domains = domains
    const w = mount(ItemsEditor, { props: { modelValue: [{ produto_id: '', embalagem: '', qtd: '', volumes: '' }] } })
    const s = w.find('#it-emb-0')
    expect(s.attributes('disabled')).toBeDefined()
    expect(s.text()).toBe('Escolha o produto')
    await w.setProps({ modelValue: [{ produto_id: 'p-hcl', embalagem: 'GRANEL_LIQUIDO', qtd: '', volumes: '' }] })
    expect(w.find('#it-emb-0').attributes('disabled')).toBeUndefined()
  })
  it('ForwardModal não foca a busca no mobile; foca no desktop', async () => {
    window.matchMedia = (q) => ({ matches: q.includes('768'), media: q })
    const w = mount(ForwardModal, { props: { quote: quote() }, attachTo: document.body, ...stubs })
    await flushPromises()
    expect(document.activeElement?.id).not.toBe('fwSearch')
    w.unmount()
    window.matchMedia = (q) => ({ matches: false, media: q })
    const w2 = mount(ForwardModal, { props: { quote: quote() }, attachTo: document.body, ...stubs })
    await flushPromises()
    expect(document.activeElement?.id).toBe('fwSearch')
    w2.unmount()
    delete window.matchMedia
  })
  it('modal devolve o foco ao botão de origem', async () => {
    const btn = document.createElement('button')
    document.body.appendChild(btn)
    btn.focus()
    const w = mount(ForwardModal, { props: { quote: quote() }, attachTo: document.body, ...stubs })
    await flushPromises()
    expect(document.activeElement).not.toBe(btn)
    w.unmount()
    expect(document.activeElement).toBe(btn)
    btn.remove()
  })
  it('/users: coluna Transportadora mostra Carregando enquanto as transportadoras carregam', async () => {
    loginAs(createSession(meAdmin))
    api.on('GET /usuarios', ok([{ id: 'u-1', nome: 'Zé', login: 'ze', grupos: [], transportadora_id: 't-arco', situacao: 'ATIVO', versao: 0 }]))
    api.on('GET /grupos', ok([]))
    api.on('GET /transportadoras', () => new Promise(() => {}))
    const w = mount(UsersView, stubs)
    await flushPromises()
    expect(w.find('[data-carrier-loading]').exists()).toBe(true)
    w.unmount()
  })
})
