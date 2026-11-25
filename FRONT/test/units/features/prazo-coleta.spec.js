import { mount, flushPromises } from '@vue/test-utils'
import RespondModal from '../../../src/components/RespondModal.vue'
import CloseModal from '../../../src/components/CloseModal.vue'
import QuoteCard from '../../../src/components/QuoteCard.vue'
import LoadsView from '../../../src/views/LoadsView.vue'
import { db, resetDb, answerQuote, closeQuote, saveLoad } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { digitaParte } from '../../helpers/digita'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, parte, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const neg = (respostas, o = {}) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null, ...o })
const toastText = () => toasts.map((t) => t.parts.map((p) => p.text).join(''))
const digitar = (sel, v) => { const el = document.querySelector(sel); el.value = v; el.dispatchEvent(new Event('input')) }

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok([product()]))
  api.on('GET /transportadoras', ok([carrier()]))
  api.on('GET /vendedores', ok([{ id: 'u-marcos', nome: 'MARCOS' }]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  db.domains = domains
})

describe('store: prazo de coleta em answerQuote / closeQuote / saveLoad', () => {
  beforeEach(() => {
    api.on('POST /cotacoes/:id/respostas/:r/responder', ({ params }) => ok(quote({ id: params.id })))
    api.on('POST /cotacoes/:id/fechar', ({ params }) => ok(quote({ id: params.id, status: 'PROGRAMADA' }), { programacao: load() }))
    api.on('POST /programacoes', ok(load(), {}, 201))
    api.on('PATCH /programacoes/:id', ({ params }) => ok(load({ id: params.id })))
  })

  it('answerQuote envia prazo_coleta_dias como número (0 vale) ou null quando vazio', async () => {
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', prazoColeta: '1' })
    expect(api.calls[0].body).toMatchObject({ prazo_dias: 3, prazo_coleta_dias: 1 })
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', prazoColeta: '0' })
    expect(api.calls[1].body.prazo_coleta_dias).toBe(0)
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', prazoColeta: '' })
    expect(api.calls[2].body.prazo_coleta_dias).toBeNull()
  })
  it('answerQuote recusa prazo de coleta fora de 0..90 ou não inteiro, sem chamar a API', async () => {
    for (const prazoColeta of ['-1', '91', '1.5', 'abc']) {
      const r = await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', prazoColeta })
      expect(r.ok, prazoColeta).toBe(false)
      expect(r.error).toContain('prazo de coleta')
    }
    expect(api.calls).toHaveLength(0)
  })
  it('fracionada: o prazo de coleta é obrigatório na resposta e no fechamento', async () => {
    const r = await answerQuote('q-1', 'r-1', { valorFrete: '2500,00', veiculo: 'TRUCK', prazo: '3', prazoColeta: '' }, true)
    expect(r.ok).toBe(false)
    expect(r.error).toContain('prazo de coleta')
    const f = await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorFrete: '2400,00', veiculo: 'TRUCK', prazo: '3', prazoColeta: '' }, true)
    expect(f.ok).toBe(false)
    expect(api.calls).toHaveLength(0)
    expect((await answerQuote('q-1', 'r-1', { valorFrete: '2500,00', veiculo: 'TRUCK', prazo: '3', prazoColeta: '0' }, true)).ok).toBe(true)
  })
  it('closeQuote envia prazo_coleta_dias e valida 0..90', async () => {
    await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '175,00', prazo: '3', prazoColeta: '2' })
    expect(api.calls[0].body).toMatchObject({ prazo_dias: 3, prazo_coleta_dias: 2 })
    await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '175,00', prazo: '3' })
    expect(api.calls[1].body.prazo_coleta_dias).toBeNull()
    expect((await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '175,00', prazo: '3', prazoColeta: '91' })).ok).toBe(false)
    expect(api.calls).toHaveLength(2)
  })
  it('saveLoad envia prazo_coleta_dias (número ou null) e valida 0..90', async () => {
    const base = { parte: { tipo: 'CLIENTE', id: 'c-1' }, pedidoVenda: 'PV-1', operacao: 'CARREGAMENTO', deposito: 'VIRTUAL', modalidade: 'CIF', itens: [{ produto_id: 'p-soda', embalagem: 'BIG_BAG', qtd: '20000' }] }
    db.loads = [load()]
    await saveLoad({ ...base, prazo: '3', prazoColeta: '1' })
    expect(api.called('POST', '/programacoes')[0].body).toMatchObject({ prazo_dias: 3, prazo_coleta_dias: 1 })
    await saveLoad({ ...base, prazo: '3', prazoColeta: '' })
    expect(api.called('POST', '/programacoes')[1].body.prazo_coleta_dias).toBeNull()
  })
})

describe('RespondModal: campo de prazo de coleta', () => {
  const abrir = (q, r = resposta()) => {
    loginAs(createSession(meCarrier))
    return mount(RespondModal, { props: { quote: q, resposta: r }, attachTo: document.body })
  }

  it('mostra "Prazo de entrega (dias)" e "Prazo de coleta (dias)" e envia os dois', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(q))
    db.quotes = [q]
    const w = abrir(q)
    expect(document.querySelector('label[for="rp-r-1"]').textContent).toContain('Prazo de entrega (dias)')
    expect(document.querySelector('label[for="rpc-r-1"]').textContent).toContain('Prazo de coleta (dias)')
    expect(document.querySelector('#rpc-r-1').getAttribute('min')).toBe('0')
    digitar('#rt-r-1', '180')
    digitar('#rp-r-1', '3')
    digitar('#rpc-r-1', '1')
    await flushPromises()
    document.querySelector('[data-act="respond-quote"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).toMatchObject({ prazo_dias: 3, prazo_coleta_dias: 1 })
    w.unmount()
  })
  it('em cotação comum o campo é opcional (vazio envia null)', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(q))
    db.quotes = [q]
    const w = abrir(q)
    digitar('#rt-r-1', '180')
    digitar('#rp-r-1', '3')
    await flushPromises()
    document.querySelector('[data-act="respond-quote"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body.prazo_coleta_dias).toBeNull()
    w.unmount()
  })
  it('fracionada: sem prazo de coleta não envia e avisa', async () => {
    const q = quote({ fracionada: true, tipo_veiculo_codigo: null, status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(q))
    db.quotes = [q]
    const w = abrir(q)
    digitar('#rt-r-1', '2500')
    const sel = document.querySelector('#rc-r-1'); sel.value = 'TRUCK'; sel.dispatchEvent(new Event('change'))
    digitar('#rp-r-1', '4')
    await flushPromises()
    document.querySelector('[data-act="respond-quote"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')).toHaveLength(0)
    expect(toastText().join(' | ')).toContain('prazo de coleta')
    w.unmount()
  })
  it('pré-preenche com o prazo de coleta da resposta anterior (0 aparece como 0)', () => {
    const q = quote({ status: 'EM_NEGOCIACAO' })
    const w = abrir(q, resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', prazo_dias: 3, prazo_coleta_dias: 0 }))
    expect(document.querySelector('#rpc-r-1').value).toBe('0')
    w.unmount()
  })
})

describe('CloseModal: campo de prazo de coleta', () => {
  it('rótulos novos, pré-preenche da resposta escolhida e envia no fechamento', async () => {
    loginAs(createSession(meAdmin))
    const q = quote({
      status: 'EM_NEGOCIACAO',
      negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3, prazo_coleta_dias: 1 })]),
    })
    db.quotes = [q]
    api.on('POST /cotacoes/:id/fechar', ok(quote({ status: 'PROGRAMADA' }), { programacao: load() }))
    const w = mount(CloseModal, { props: { quote: q }, attachTo: document.body })
    await flushPromises()
    expect(document.querySelector('label[for="fc-prazo"]').textContent).toContain('Prazo de entrega (dias)')
    expect(document.querySelector('label[for="fc-prazo-coleta"]').textContent).toContain('Prazo de coleta (dias)')
    expect(document.querySelector('#fc-prazo-coleta').value).toBe('1')
    digitar('#fc-prazo-coleta', '2')
    digitar('#fc-pedido', 'PV-1')
    document.querySelector('[data-act="close-negotiation"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/fechar')[0].body).toMatchObject({ prazo_dias: 3, prazo_coleta_dias: 2 })
    w.unmount()
  })
  it('resposta legada sem prazo de coleta: campo vazio e envia null', async () => {
    loginAs(createSession(meAdmin))
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })]) })
    db.quotes = [q]
    api.on('POST /cotacoes/:id/fechar', ok(quote({ status: 'PROGRAMADA' }), { programacao: load() }))
    const w = mount(CloseModal, { props: { quote: q }, attachTo: document.body })
    await flushPromises()
    expect(document.querySelector('#fc-prazo-coleta').value).toBe('')
    digitar('#fc-pedido', 'PV-1')
    document.querySelector('[data-act="close-negotiation"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/fechar')[0].body.prazo_coleta_dias).toBeNull()
    w.unmount()
  })
})

describe('QuoteCard: coleta e entrega juntas', () => {
  const card = (me, q) => {
    loginAs(createSession(me))
    return mount(QuoteCard, { props: { quote: q } })
  }
  it('resposta: "coleta 1 dia · entrega 3 dias"; nulo vira "—" e nunca "null"', () => {
    const q = quote({
      status: 'EM_NEGOCIACAO',
      negociacao: neg([
        resposta({ id: 'r-1', status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3, prazo_coleta_dias: 1 }),
        resposta({ id: 'r-2', transportadora: { id: 't-v', nome_fantasia: 'VIA' }, status: 'RESPONDIDA', valor_tonelada: '170.00', valor_frete: '3400.00', prazo_dias: 2 }),
      ]),
    })
    const l = card(meAdmin, q).findAll('[data-resp]')
    expect(l[0].text()).toMatch(/coleta 1 dia\b/)
    expect(l[0].text()).toMatch(/entrega 3 dias/)
    expect(l[1].text()).toMatch(/coleta —/)
    expect(l[1].text()).not.toContain('null')
  })
  it('fechamento mostra coleta e entrega (singular/plural e zero)', () => {
    const f = (c) => quote({
      status: 'FECHADA',
      negociacao: neg([], { status: 'FECHADA', fechamento: { transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, valor_frete: '4860.00', valor_tonelada: '180.00', prazo_dias: 3, prazo_coleta_dias: c } }),
    })
    expect(card(meSeller, f(0)).find('.resp-line').text()).toMatch(/coleta 0 dias · entrega 3 dias/)
    expect(card(meSeller, f(2)).find('.resp-line').text()).toMatch(/coleta 2 dias · entrega 3 dias/)
    expect(card(meSeller, f(null)).find('.resp-line').text()).toMatch(/coleta — · entrega 3 dias/)
  })
})

describe('LoadsView: prazo de coleta no formulário da carga', () => {
  const mountAs = async (list) => {
    api.on('GET /programacoes', ok(list))
    loginAs(createSession(meAdmin))
    const w = mount(LoadsView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }
  it('nova carga: rótulos e payload com prazo_coleta_dias', async () => {
    const w = await mountAs([])
    api.on('GET /partes', ok([parte()]))
    await w.find('[data-act="new-load"]').trigger('click')
    expect(w.find('label[for="lp-prazo"]').text()).toBe('Prazo de entrega (dias)')
    expect(w.find('label[for="lp-prazo-coleta"]').text()).toBe('Prazo de coleta (dias)')
    await digitaParte(w, '#lp-parte', 'super')
    await flushPromises()
    await w.find('[data-parte]').trigger('mousedown')
    await w.find('#lp-prod-0').setValue('p-soda')
    await w.find('#lp-qtd-0').setValue('20000')
    await w.find('#lp-prazo').setValue('3')
    await w.find('#lp-prazo-coleta').setValue('1')
    await w.find('#lp-cepO').setValue('Paulínia - SP')
    await w.find('#lp-cepD').setValue('Campinas - SP')
    await w.find('#lp-pedido').setValue('PV-1')
    api.on('POST /programacoes', ok(load({ id: 'l-new' }), {}, 201))
    await w.find('[data-act="save-load"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/programacoes')[0].body).toMatchObject({ prazo_dias: 3, prazo_coleta_dias: 1 })
  })
  it('edição: carrega o prazo de coleta da carga', async () => {
    const w = await mountAs([load({ prazo_coleta_dias: 2 })])
    await w.find('.ibtn.ed').trigger('click')
    await flushPromises()
    expect(w.find('#lp-prazo-coleta').element.value).toBe('2')
  })
})
