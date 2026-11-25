import { mount, flushPromises } from '@vue/test-utils'
import CloseModal from '../../../src/components/CloseModal.vue'
import { db, resetDb, closeQuote, saveLoad } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, domains, product, carrier, parte, quote, resposta, load } from '../../helpers/fixtures'

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
  api.on('POST /cotacoes/:id/fechar', ({ params }) => ok(quote({ id: params.id, status: 'PROGRAMADA' }), { programacao: load() }))
  api.on('POST /programacoes', ok(load(), {}, 201))
  api.on('PATCH /programacoes/:id', ({ params }) => ok(load({ id: params.id })))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  db.domains = domains
})

describe('número do pedido no fechamento (store)', () => {
  const fechamento = { transportadoraId: 't-arco', valorTon: '175,00', prazo: '3' }

  it('sem pedido (vazio ou só espaços) não chama a API e explica', async () => {
    for (const pedidoVenda of [undefined, '', '   ']) {
      const r = await closeQuote('q-1', { ...fechamento, pedidoVenda })
      expect(r.ok).toBe(false)
      expect(r.error).toContain('número do pedido')
    }
    expect(api.calls).toHaveLength(0)
  })
  it('pedido com mais de 30 caracteres é recusado antes de chamar a API', async () => {
    const r = await closeQuote('q-1', { ...fechamento, pedidoVenda: '9'.repeat(31) })
    expect(r.ok).toBe(false)
    expect(r.error).toContain('30')
    expect(api.calls).toHaveLength(0)
  })
  it('com pedido, envia programacao.pedido_venda sem espaços nas pontas', async () => {
    const r = await closeQuote('q-1', { ...fechamento, pedidoVenda: '  PV-1001 ', deposito: 'FISICO', modalidade: 'FOB' })
    expect(r.ok).toBe(true)
    expect(api.called('POST', '/cotacoes/q-1/fechar')[0].body.programacao).toMatchObject({ pedido_venda: 'PV-1001', deposito: 'FISICO', modalidade_frete: 'FOB' })
  })
})

describe('CloseModal: campo Número do pedido', () => {
  async function abrir() {
    loginAs(createSession(meAdmin))
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '3600.00', prazo_dias: 3 })]) })
    db.quotes = [q]
    const w = mount(CloseModal, { props: { quote: q }, attachTo: document.body })
    await flushPromises()
    return w
  }
  const fechar = async () => { document.querySelector('[data-act="close-negotiation"]').click(); await flushPromises() }

  it('campo obrigatório, com limite de 30 caracteres', async () => {
    const w = await abrir()
    expect(document.querySelector('label[for="fc-pedido"]').textContent).toContain('Número do pedido')
    expect(document.querySelector('label[for="fc-pedido"] i').textContent).toBe('*')
    expect(document.querySelector('#fc-pedido').getAttribute('maxlength')).toBe('30')
    w.unmount()
  })
  it('sem pedido não fecha e avisa por toast; com pedido envia', async () => {
    const w = await abrir()
    await fechar()
    expect(api.called('POST', '/cotacoes/q-1/fechar')).toHaveLength(0)
    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
    expect(toastText().at(-1)).toContain('número do pedido')
    digitar('#fc-pedido', 'PV-77')
    await fechar()
    expect(api.called('POST', '/cotacoes/q-1/fechar')[0].body.programacao).toMatchObject({ pedido_venda: 'PV-77' })
    w.unmount()
  })
})

describe('carga avulsa: pedido obrigatório só na criação', () => {
  const base = {
    parte: parte(), operacao: 'CARREGAMENTO', deposito: 'VIRTUAL', modalidade: 'CIF', transportadoraId: 't-arco', veiculo: 'CARRETA',
    origem: 'Paulínia - SP', destino: 'Campinas - SP', valorTon: '175,00', prazo: '3',
    itens: [{ produto_id: 'p-soda', embalagem: 'BIG_BAG', qtd: '20.000', volumes: '' }],
  }

  it('criar sem pedido não chama a API', async () => {
    const r = await saveLoad({ ...base, pedidoVenda: '  ' })
    expect(r.ok).toBe(false)
    expect(r.error).toContain('pedido')
    expect(api.calls).toHaveLength(0)
  })
  it('criar com pedido envia pedido_venda', async () => {
    await saveLoad({ ...base, pedidoVenda: 'PV-5' })
    expect(api.called('POST', '/programacoes')[0].body.pedido_venda).toBe('PV-5')
  })
  it('editar carga antiga sem pedido continua possível', async () => {
    db.loads = [load()]
    const r = await saveLoad({ ...base, pedidoVenda: '' }, 'l-1')
    expect(r.ok).toBe(true)
    expect(api.called('PATCH', '/programacoes/l-1')[0].body.pedido_venda).toBeNull()
  })
})

describe('C12: carga avulsa com prazo >= 1 e coleta de hoje em diante', () => {
  const hoje = () => new Date().toISOString().slice(0, 10)
  const dia = (n) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10)
  const base = {
    parte: parte(), operacao: 'CARREGAMENTO', deposito: 'VIRTUAL', modalidade: 'CIF', transportadoraId: 't-arco', veiculo: 'CARRETA',
    origem: 'Paulínia - SP', destino: 'Campinas - SP', valorTon: '175,00', prazo: '3', pedidoVenda: 'PV-1',
    itens: [{ produto_id: 'p-soda', embalagem: 'BIG_BAG', qtd: '20.000', volumes: '' }],
  }

  it('prazo 0 ou negativo não chama a API', async () => {
    for (const prazo of ['0', '-2']) {
      const r = await saveLoad({ ...base, prazo })
      expect(r.ok, prazo).toBe(false)
      expect(r.error).toContain('prazo')
    }
    expect(api.calls).toHaveLength(0)
  })
  it('coleta prevista no passado não chama a API ao criar; hoje e futuro passam', async () => {
    const r = await saveLoad({ ...base, coleta: dia(-2) })
    expect(r.ok).toBe(false)
    expect(r.error).toContain('coleta')
    expect(api.calls).toHaveLength(0)
    expect((await saveLoad({ ...base, coleta: hoje() })).ok).toBe(true)
    expect((await saveLoad({ ...base, coleta: dia(4) })).ok).toBe(true)
  })
  it('ao editar, a coleta já passada de uma carga antiga continua aceita', async () => {
    db.loads = [load()]
    expect((await saveLoad({ ...base, coleta: dia(-30) }, 'l-1')).ok).toBe(true)
  })
})
