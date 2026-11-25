import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { defineComponent, h } from 'vue'
import ItemsEditor from '../../../src/components/ItemsEditor.vue'
import RespondModal from '../../../src/components/RespondModal.vue'
import ValidadeTag from '../../../src/components/ValidadeTag.vue'
import QuoteModal from '../../../src/components/QuoteModal.vue'
import QuotesView from '../../../src/views/QuotesView.vue'
import LoadsView from '../../../src/views/LoadsView.vue'
import AppShell from '../../../src/layouts/AppShell.vue'
import { usePolling } from '../../../src/composables/usePolling'
import { db, resetDb, answerQuote, addQuote } from '../../../src/stores/db'
import { authenticate } from '../../../src/services/auth'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, openModal, nav, closeNav } from '../../../src/stores/ui'
import { today, isPast, fmtD, freightValue, sumKg } from '../../../src/utils/format'
import { isGranel, itemFromApi, blankItem, kgToVolumes, volumesToKg } from '../../../src/utils/items'
import { api, ok, err } from '../../helpers/fakeApi'
import { digitaParte } from '../../helpers/digita'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, parte, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const products = [product(), product({ id: 'p-hcl', nome: 'HCL', embalagens: ['GRANEL_LIQUIDO', 'GRANEL_SOLIDO', 'BOMBONA'] })]
const stubs = { global: { stubs: { teleport: true } } }

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
  closeNav()
})
afterEach(() => vi.useRealTimers())

const neg = (respostas, o = {}) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null, ...o })

describe('1. granel não tem volumes', () => {
  it('isGranel e itemFromApi', () => {
    expect(isGranel('GRANEL_LIQUIDO')).toBe(true)
    expect(isGranel('GRANEL_SOLIDO')).toBe(true)
    expect(isGranel('BOMBONA')).toBe(false)
    expect(itemFromApi({ produto: { id: 'p' }, embalagem_codigo: 'GRANEL_SOLIDO', quantidade_kg: '1000.000', volumes: 5 }).volumes).toBe('')
  })
  it('ItemsEditor esconde volumes em granel e limpa ao trocar de embalagem', async () => {
    db.products = products
    db.domains = domains
    const w = mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), produto_id: 'p-hcl', embalagem: 'BOMBONA', volumes: '10' }] } })
    expect(w.find('#it-vol-0').exists()).toBe(true)
    await w.find('#it-emb-0').setValue('GRANEL_SOLIDO')
    const emitted = w.emitted('update:modelValue').at(-1)[0][0]
    expect(emitted).toMatchObject({ embalagem: 'GRANEL_SOLIDO', volumes: '' })
    await w.setProps({ modelValue: [emitted] })
    expect(w.find('#it-vol-0').exists()).toBe(false)
    expect(w.find('.item-row').classes()).toContain('granel')
  })
  it('POST envia volumes null para granel mesmo se o formulário tinha valor', async () => {
    api.on('POST /cotacoes', ok(quote(), {}, 201))
    await addQuote({
      operacao: 'CARREGAMENTO', parte: parte(), veiculo: 'TRUCK', valorNF: '100,00', origem: 'A - SP', destino: 'B - SP',
      itens: [{ produto_id: 'p-hcl', embalagem: 'GRANEL_LIQUIDO', qtd: '1.000', volumes: '7' }],
    })
    expect(api.called('POST', '/cotacoes')[0].body.itens[0].volumes).toBeNull()
  })
})

describe('3/6. validade e valor = preço/t × quantidade', () => {
  it('today usa a data local e isPast compara texto (sem deslocar o dia por UTC)', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 9, 5, 23, 30))
    expect(today()).toBe('2026-10-05')
    expect(isPast('2026-10-04')).toBe(true)
    expect(isPast('2026-10-05')).toBe(false)
    expect(isPast('2026-10-06')).toBe(false)
    expect(isPast(null)).toBe(false)
    expect(fmtD('2026-10-05')).toBe('05/10/2026')
  })
  it('freightValue e sumKg', () => {
    expect(freightValue('180,00', 20000)).toBe(3600)
    expect(sumKg([{ quantidade_kg: '12000.000' }, { quantidade_kg: '8000.500' }])).toBe(20000.5)
  })
  it('ValidadeTag: dd/mm/aaaa e selo "vencida" só quando passou', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 9, 5, 12))
    const old = mount(ValidadeTag, { props: { value: '2026-10-01' } })
    expect(old.text()).toContain('01/10/2026')
    expect(old.find('[data-vencida]').exists()).toBe(true)
    const okTag = mount(ValidadeTag, { props: { value: '2026-10-05' } })
    expect(okTag.find('[data-vencida]').exists()).toBe(false)
    expect(mount(ValidadeTag, { props: { value: null } }).text()).toBe('')
  })
  it('RespondModal: input date com min=hoje, prévia sem capacidade do veículo, envia YYYY-MM-DD', async () => {
    db.domains = domains
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })))
    api.on('GET /cotacoes/:id', ok(quote()))
    loginAs(createSession(meCarrier))
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = mount(RespondModal, { props: { quote: q, resposta: resposta() }, ...stubs })
    const v = w.find('#rv-r-1')
    expect(v.attributes('type')).toBe('date')
    expect(v.attributes('min')).toBe(today())
    await w.find('#rt-r-1').setValue('100')
    expect(w.find('#rf-r-1').text()).toBe('R$ 2.000,00')
    await w.find('#rp-r-1').setValue('3')
    const validade = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)
    await v.setValue(validade)
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).toMatchObject({ valor_tonelada: '100.00', validade })
  })
  it('answerQuote recusa validade no passado sem chamar a API', async () => {
    const r = await answerQuote('q-1', 'r-1', { valorTon: '100,00', prazo: '3', validade: '2000-01-01' })
    expect(r.ok).toBe(false)
    expect(api.calls).toHaveLength(0)
  })
  it('card mostra validade com selo vencida', async () => {
    api.on('GET /cotacoes', ok([quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3, validade: '2000-01-01' })]) })]))
    loginAs(createSession(meAdmin))
    const w = mount(QuotesView, stubs)
    await flushPromises()
    expect(w.find('[data-resp]').text()).toContain('01/01/2000')
    expect(w.find('[data-vencida]').exists()).toBe(true)
    w.unmount()
  })
})

describe('6b. LoadsView: valor total', () => {
  it('prévia = R$/t × Σ kg ÷ 1000; editando sem mexer mostra o valor da API', async () => {
    api.on('GET /programacoes', ok([load({ valor_total: '3500.00', valor_tonelada: '175.00' })]))
    loginAs(createSession(meAdmin))
    const w = mount(LoadsView, stubs)
    await flushPromises()
    await w.find('button.ibtn.ed').trigger('click')
    expect(w.find('#lp-vtotal').element.value).toBe('R$ 3.500,00')
    await w.find('#lp-vton').setValue('200')
    expect(w.find('#lp-vtotal').element.value).toBe('R$ 4.000,00')
    w.unmount()
  })
})

describe('4. polling', () => {
  const Host = defineComponent({
    props: { fn: Function, paused: Function },
    setup(p) { usePolling(p.fn, { interval: 15000, paused: p.paused }); return () => h('i') },
  })
  const setVisibility = (v) => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => v })
    document.dispatchEvent(new Event('visibilitychange'))
  }
  afterEach(() => setVisibility('visible'))

  it('dispara a cada 15 s, para ao ocultar a aba, retoma ao voltar e para ao desmontar', async () => {
    vi.useFakeTimers()
    const fn = vi.fn().mockResolvedValue()
    const w = mount(Host, { props: { fn } })
    await vi.advanceTimersByTimeAsync(15000)
    expect(fn).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(15000)
    expect(fn).toHaveBeenCalledTimes(2)
    setVisibility('hidden')
    await vi.advanceTimersByTimeAsync(60000)
    expect(fn).toHaveBeenCalledTimes(2)
    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(1)
    expect(fn).toHaveBeenCalledTimes(3)
    w.unmount()
    await vi.advanceTimersByTimeAsync(60000)
    expect(fn).toHaveBeenCalledTimes(3)
  })
  it('não empilha requisições: espera a anterior terminar', async () => {
    vi.useFakeTimers()
    let release
    const fn = vi.fn(() => new Promise((r) => { release = r }))
    const w = mount(Host, { props: { fn } })
    await vi.advanceTimersByTimeAsync(15000)
    await vi.advanceTimersByTimeAsync(45000)
    expect(fn).toHaveBeenCalledTimes(1)
    release()
    await vi.advanceTimersByTimeAsync(15000)
    expect(fn).toHaveBeenCalledTimes(2)
    w.unmount()
  })
  it('paused() pula o ciclo', async () => {
    vi.useFakeTimers()
    const fn = vi.fn().mockResolvedValue()
    const w = mount(Host, { props: { fn, paused: () => true } })
    await vi.advanceTimersByTimeAsync(45000)
    expect(fn).not.toHaveBeenCalled()
    w.unmount()
  })
  it('QuotesView recarrega a lista em silêncio (sem loading nem toast de erro) mantendo os objetos', async () => {
    api.on('GET /cotacoes', ok([quote()]))
    loginAs(createSession(meAdmin))
    vi.useFakeTimers()
    const w = mount(QuotesView, stubs)
    await vi.advanceTimersByTimeAsync(1)
    const first = db.quotes[0]
    const before = api.called('GET', '/cotacoes').length
    await vi.advanceTimersByTimeAsync(15000)
    expect(api.called('GET', '/cotacoes').length).toBe(before + 1)
    expect(db.loading.quotes).toBeFalsy()
    expect(db.quotes[0]).toBe(first)
    api.on('GET /cotacoes', err(500, 'falhou'))
    const n = toasts.length
    await vi.advanceTimersByTimeAsync(15000)
    expect(toasts.length).toBe(n)
    w.unmount()
  })
  it('LoadsView pausa a recarga com o formulário de edição aberto', async () => {
    api.on('GET /programacoes', ok([load()]))
    loginAs(createSession(meAdmin))
    vi.useFakeTimers()
    const w = mount(LoadsView, stubs)
    await vi.advanceTimersByTimeAsync(15000)
    const n = api.called('GET', '/programacoes').length
    expect(n).toBeGreaterThanOrEqual(2)
    await w.find('button.ibtn.ed').trigger('click')
    await vi.advanceTimersByTimeAsync(45000)
    expect(api.called('GET', '/programacoes').length).toBe(n)
    w.unmount()
  })
})

describe('7. login', () => {
  it('envia login com trim, sem forçar caixa', async () => {
    api.on('POST /auth/login', ok({ access_token: 'a', refresh_token: 'r', usuario: meAdmin }))
    await authenticate('  Admin ', 'Senha')
    expect(api.called('POST', '/auth/login')[0].body).toEqual({ login: 'Admin', senha: 'Senha' })
  })
})

describe('8. cotação repetida (vinculada)', () => {
  const previa = {
    id: 'q-0', codigo: 'COT-000004', data_solicitacao: '2026-10-01', status: 'EM_NEGOCIACAO',
    respostas: [resposta({ id: 'r-9', status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3, validade: '2030-01-01' })],
  }
  async function openForm() {
    loginAs(createSession(meSeller))
    const w = mount(QuotesView, stubs)
    await flushPromises()
    await w.find('[data-act="new-quote"]').trigger('click')
    return w
  }
  const fillRoute = async (w, emb = 'GRANEL_LIQUIDO') => {
    await w.find('#qc-prod-0').setValue('p-hcl')
    await w.find('#qc-emb-0').setValue(emb)
    await w.find('#qc-qtd-0').setValue('27000')
    await w.find('#qc-origem').setValue('Paulínia - SP')
    await w.find('#qc-destino').setValue('Campinas - SP')
  }
  const pickParte = async (w) => {
    await digitaParte(w, '#qc-parte', 'super')
    await flushPromises()
    await w.find('[data-parte]').trigger('mousedown')
  }
  const finish = async (w) => {
    await w.find('#qc-qtd-0').setValue('1000')
    await w.find('#qc-valor').setValue('100000')
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
  }

  it('prévia com debounce (só granel líquido); checkbox marcado por padrão; POST envia usar_anterior; alertas em toast', async () => {
    api.on('GET /cotacoes/anterior', ok(previa))
    api.on('POST /cotacoes', ok(quote({ id: 'q-new', codigo: 'COT-000010', status: 'EM_NEGOCIACAO' }), { alertas: ['Cotação repetida: usando as respostas da COT-000004.'] }, 201))
    api.on('GET /partes', ok([parte()]))
    const w = await openForm()
    await pickParte(w)
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await fillRoute(w)
    expect(api.called('GET', '/cotacoes/anterior')).toHaveLength(0)
    await vi.advanceTimersByTimeAsync(600)
    vi.useRealTimers()
    await flushPromises()
    const call = api.called('GET', '/cotacoes/anterior')
    expect(call).toHaveLength(1)
    expect(call[0].query).toEqual({ tipo_veiculo_codigo: 'TRUCK', origem_cidade: 'PAULÍNIA', origem_uf: 'SP', destino_cidade: 'CAMPINAS', destino_uf: 'SP', produto_ids: 'p-hcl' })
    const box = w.find('[data-prev-quote]')
    expect(box.text()).toContain('COT-000004')
    expect(box.text()).toContain('01/10/2026')
    expect(box.find('[data-prev-resp]').text()).toContain('ARCO')
    expect(box.find('[data-prev-resp]').text()).toContain('R$ 180,00/t')
    expect(box.find('[data-prev-resp]').text()).toContain('frete estimado p/ 27 t')
    expect(box.find('[data-prev-resp]').text()).toContain('R$ 4.860,00')
    expect(box.find('[data-prev-resp]').text()).toContain('01/01/2030')
    expect(w.find('[data-use-prev] input').element.checked).toBe(true)
    expect(w.find('[data-use-prev]').text()).toContain('COT-000004')
    await finish(w)
    expect(api.called('POST', '/cotacoes')[0].body.usar_anterior).toBe(true)
    expect(toasts.some((t) => t.type === 'warn' && t.parts.map((p) => p.text).join('').includes('Cotação repetida'))).toBe(true)
    w.unmount()
  })
  it('desmarcar o checkbox envia usar_anterior false', async () => {
    api.on('GET /cotacoes/anterior', ok(previa))
    api.on('POST /cotacoes', ok(quote({ id: 'q-new' }), {}, 201))
    api.on('GET /partes', ok([parte()]))
    const w = await openForm()
    await pickParte(w)
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await fillRoute(w)
    await vi.advanceTimersByTimeAsync(600)
    vi.useRealTimers()
    await flushPromises()
    await w.find('[data-use-prev] input').setValue(false)
    await finish(w)
    expect(api.called('POST', '/cotacoes')[0].body.usar_anterior).toBe(false)
    w.unmount()
  })
  it('não consulta quando algum item não é granel líquido', async () => {
    api.on('GET /cotacoes/anterior', ok(previa))
    const w = await openForm()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await fillRoute(w, 'BOMBONA')
    await vi.advanceTimersByTimeAsync(1000)
    vi.useRealTimers()
    expect(api.called('GET', '/cotacoes/anterior')).toHaveLength(0)
    expect(w.find('[data-prev-quote]').exists()).toBe(false)
    w.unmount()
  })
  it('card e modal: vinculada, respostas referenciadas com selo e sem Responder/Declinar', async () => {
    const q = quote({
      status: 'EM_NEGOCIACAO',
      cotacao_origem: { id: 'q-0', codigo: 'COT-000004', data_solicitacao: '2026-10-01', status: 'EM_NEGOCIACAO' },
      negociacao: neg([resposta({ id: 'r-9', origem: 'REFERENCIADA', cotacao_codigo: 'COT-000004', status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })]),
    })
    api.on('GET /cotacoes', ok([q]))
    loginAs(createSession(meAdmin))
    const w = mount(QuotesView, stubs)
    await flushPromises()
    expect(w.find('[data-origem]').text()).toContain('Vinculada à COT-000004')
    expect(w.find('[data-resp] [data-ref]').text()).toContain('COT-000004')
    expect(w.find('[data-resp]').text()).toContain('R$ 3.600,00')
    w.unmount()
    api.on('GET /cotacoes/:id', ok(q))
    db.quotes = [q]
    openModal('quote', { id: 'q-1' })
    const m = mount(QuoteModal, stubs)
    await flushPromises()
    expect(m.find('[data-origem]').text()).toContain('COT-000004')
    expect(m.find('[data-resp] [data-ref]').exists()).toBe(true)
    expect(m.find('[data-resp] [data-act="answer-quote"]').exists()).toBe(false)
    m.unmount()
  })
})

describe('5. mobile: menu em drawer', () => {
  it('hambúrguer abre o menu; backdrop e navegação fecham', async () => {
    loginAs(createSession(meAdmin))
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/dash', name: 'dash', component: { template: '<i />' }, meta: { title: ['Painel', 'Operacional'] } },
        { path: '/quotes', name: 'quotes', component: { template: '<i />' }, meta: { title: ['Cotações', ''] } },
      ],
    })
    router.push('/dash')
    await router.isReady()
    const w = mount(AppShell, { global: { plugins: [router] } })
    expect(w.find('.side').classes()).not.toContain('open')
    await w.find('[data-act="toggle-nav"]').trigger('click')
    expect(nav.open).toBe(true)
    expect(w.find('.side').classes()).toContain('open')
    expect(w.find('[data-act="toggle-nav"]').attributes('aria-expanded')).toBe('true')
    await w.find('[data-nav-backdrop]').trigger('click')
    expect(nav.open).toBe(false)
    await w.find('[data-act="toggle-nav"]').trigger('click')
    await router.push('/quotes')
    await flushPromises()
    expect(nav.open).toBe(false)
    w.unmount()
  })
})

describe('conversor kg <-> volumes', () => {
  const pf = () => [product({ id: 'p-hcl', nome: 'HCL', embalagens: ['BOMBONA', 'GRANEL_LIQUIDO'], fatores: { BOMBONA: '50.000' } }), product({ id: 'p-x', nome: 'X', embalagens: ['BOMBONA'] })]
  const mountEd = (item) => mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), ...item }] } })

  it('funções puras: arredonda volumes para cima, fator ausente e kg exato', () => {
    expect(kgToVolumes('1.000', '50.000')).toBe(20)
    expect(kgToVolumes('1.010', 50)).toBe(21)
    expect(kgToVolumes('1.000', null)).toBe(0)
    expect(kgToVolumes('', 50)).toBe(0)
    expect(volumesToKg('7', '50.000')).toBe(350)
    expect(volumesToKg('3', 12.5)).toBe(37.5)
    expect(volumesToKg('7', undefined)).toBe(0)
  })
  it('digitar kg preenche volumes e digitar volumes preenche kg', async () => {
    db.products = pf(); db.domains = domains
    const w = mountEd({ produto_id: 'p-hcl', embalagem: 'BOMBONA' })
    await w.find('#it-qtd-0').setValue('1.000')
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ qtd: '1.000', volumes: '20' })
    await w.find('#it-vol-0').setValue('7')
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ volumes: '7', qtd: '350' })
  })
  it('sem fator os campos são independentes', async () => {
    db.products = pf(); db.domains = domains
    const w = mountEd({ produto_id: 'p-x', embalagem: 'BOMBONA', volumes: '9' })
    await w.find('#it-qtd-0').setValue('1.000')
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ qtd: '1.000', volumes: '9' })
  })
  it('trocar a embalagem recalcula volumes a partir da QTD', async () => {
    db.products = pf(); db.domains = domains
    const w = mountEd({ produto_id: 'p-hcl', embalagem: 'GRANEL_LIQUIDO', qtd: '1.000' })
    await w.find('#it-emb-0').setValue('BOMBONA')
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ embalagem: 'BOMBONA', volumes: '20' })
  })
})
