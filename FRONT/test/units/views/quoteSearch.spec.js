import { mount, flushPromises } from '@vue/test-utils'
import QuoteSearchView from '../../../src/views/QuoteSearchView.vue'
import { resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, modal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, parte, quote } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const quotes = () => [
  quote({ id: 'q-1', codigo: 'COT-000001' }),
  quote({ id: 'q-2', codigo: 'COT-000002', status: 'EM_NEGOCIACAO', parte: parte({ razao_social: 'BETA PAULÍNIA' }), vendedor: { id: 'u-lucia', nome: 'LUCIA' }, observacao: 'urgente' }),
  quote({ id: 'q-3', codigo: 'COT-000003', status: 'CANCELADA' }),
]

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

async function mountAs(me, list = quotes()) {
  api.on('GET /cotacoes', ok(list))
  loginAs(createSession(me))
  const w = mount(QuoteSearchView, { global: { stubs: { teleport: true } } })
  await flushPromises()
  return w
}
const rows = (w) => w.findAll('#quoteRows tr[data-quote-row]')

describe('Consultar cotação', () => {
  it('lista todas as cotações visíveis em tabela, com as colunas da cotação', async () => {
    const w = await mountAs(meAdmin)
    expect(api.called('GET', '/cotacoes')).toHaveLength(1)
    expect(rows(w)).toHaveLength(3)
    const heads = w.findAll('#quoteTable thead th').map((th) => th.text())
    for (const col of ['Código', 'Data', 'Status', 'Operação', 'Cliente / Fornecedor', 'Vendedor', 'Produtos', 'Qtd (kg)', 'Tipo de carro', 'Origem', 'Destino', 'Valor NF', 'Observação']) {
      expect(heads).toContain(col)
    }
    expect(w.find('.card-h .cnt').text()).toBe('3 registros')
  })

  it('filtra por texto e por status, e o contador acompanha', async () => {
    const w = await mountAs(meAdmin)
    await w.find('#flt-txt').setValue('beta')
    expect(rows(w)).toHaveLength(1)
    expect(rows(w)[0].text()).toContain('COT-000002')
    expect(w.find('.card-h .cnt').text()).toBe('1 de 3 registros')
    await w.find('#flt-txt').setValue('')
    await w.find('#flt-status').setValue('CANCELADA')
    expect(rows(w).map((r) => r.text()).join(' ')).toContain('COT-000003')
    expect(rows(w)).toHaveLength(1)
    await w.find('#flt-txt').setValue('xyz-nada')
    expect(rows(w)).toHaveLength(0)
    expect(w.text()).toContain('Nenhuma cotação com esse filtro.')
  })

  it('filtra por produto, origem e destino, combináveis entre si e com o status', async () => {
    const lista = [
      quote({ id: 'q-1', codigo: 'COT-000001', itens: [{ id: 'i-1', produto: { id: 'p-1', nome: 'SODA CÁUSTICA' }, embalagem_codigo: 'BIG_BAG', quantidade_kg: '1000' }], origem: { ibge: null, cidade: 'PAULÍNIA', uf: 'SP' }, destino: { ibge: null, cidade: 'CAMPINAS', uf: 'SP' } }),
      quote({ id: 'q-2', codigo: 'COT-000002', status: 'EM_NEGOCIACAO', itens: [{ id: 'i-2', produto: { id: 'p-2', nome: 'HCL' }, embalagem_codigo: 'BOMBONA', quantidade_kg: '2000' }], origem: { ibge: null, cidade: 'PAULÍNIA', uf: 'SP' }, destino: { ibge: null, cidade: 'CURITIBA', uf: 'PR' } }),
      quote({ id: 'q-3', codigo: 'COT-000003', itens: [{ id: 'i-3', produto: { id: 'p-2', nome: 'HCL' }, embalagem_codigo: 'BOMBONA', quantidade_kg: '3000' }], origem: { ibge: null, cidade: 'SÃO PAULO', uf: 'SP' }, destino: { ibge: null, cidade: 'CAMPINAS', uf: 'SP' } }),
    ]
    const w = await mountAs(meAdmin, lista)
    const codigos = () => rows(w).map((r) => r.find('td.mono').text())
    await w.find('#flt-produto').setValue('hcl')
    expect(codigos()).toEqual(['COT-000003', 'COT-000002'])
    expect(w.find('.card-h .cnt').text()).toBe('2 de 3 registros')
    await w.find('#flt-origem').setValue('paulinia')
    expect(codigos()).toEqual(['COT-000002'])
    await w.find('#flt-produto').setValue('')
    expect(codigos()).toEqual(['COT-000002', 'COT-000001'])
    await w.find('#flt-destino').setValue('campinas')
    expect(codigos()).toEqual(['COT-000001'])
    await w.find('#flt-status').setValue('EM_NEGOCIACAO')
    expect(rows(w)).toHaveLength(0)
    expect(w.text()).toContain('Nenhuma cotação com esse filtro.')
  })

  it('a única ação é o olho, que abre o modal da cotação', async () => {
    const w = await mountAs(meAdmin)
    const botoes = rows(w)[0].findAll('button')
    expect(botoes).toHaveLength(1)
    expect(botoes[0].attributes('data-act')).toBe('view-quote')
    await botoes[0].trigger('click')
    expect(modal.open).toBe(true)
    expect(modal.kind).toBe('quote')
    expect(modal.payload).toMatchObject({ id: expect.any(String), readOnly: true })
  })

  it.each([['vendedor', meSeller], ['transportadora', meCarrier]])('%s também consulta, sem botões de ação além do olho', async (_n, me) => {
    const w = await mountAs(me)
    expect(rows(w)).toHaveLength(3)
    expect(w.findAll('#quoteTable button').every((b) => b.attributes('data-act') === 'view-quote')).toBe(true)
    expect(w.find('[data-act="new-quote"]').exists()).toBe(false)
  })

  describe('transportadora: mesma regra dos cards (status derivado, nunca o real)', () => {
    const lista = () => [
      quote({ id: 'q-1', codigo: 'COT-000001', status: 'EM_NEGOCIACAO' }),
      quote({ id: 'q-2', codigo: 'COT-000002', status: 'RESPONDIDA' }),
      quote({ id: 'q-3', codigo: 'COT-000003', status: 'PROGRAMADA' }),
      quote({ id: 'q-4', codigo: 'COT-000004', status: 'ENCERRADA' }),
    ]
    it('a coluna Status mostra os rótulos da transportadora', async () => {
      const w = await mountAs(meCarrier, lista())
      const pill = (c) => rows(w).find((r) => r.text().includes(c)).find('td .pill').text()
      expect(pill('COT-000001')).toBe('EM NEGOCIAÇÃO')
      expect(pill('COT-000002')).toBe('RESPONDIDO')
      expect(pill('COT-000003')).toBe('PROGRAMADO')
      expect(pill('COT-000004')).toBe('ENCERRADO')
    })
    it('o filtro de status oferece só os 4 status dela', async () => {
      const w = await mountAs(meCarrier, lista())
      const opts = w.findAll('#flt-status option').map((o) => o.element.value)
      expect(opts).toEqual(['', 'EM_NEGOCIACAO', 'RESPONDIDA', 'PROGRAMADA', 'ENCERRADA'])
      await w.find('#flt-status').setValue('ENCERRADA')
      expect(rows(w)).toHaveLength(1)
      expect(rows(w)[0].text()).toContain('COT-000004')
    })
    it('a busca por texto usa o rótulo exibido', async () => {
      const w = await mountAs(meCarrier, lista())
      await w.find('#flt-txt').setValue('programado')
      expect(rows(w)).toHaveLength(1)
      expect(rows(w)[0].text()).toContain('COT-000003')
    })
    it('o admin mantém o filtro completo e os rótulos reais', async () => {
      const w = await mountAs(meAdmin, quotes())
      expect(w.findAll('#flt-status option').map((o) => o.element.value)).toEqual(['', 'ABERTA', 'EM_NEGOCIACAO', 'FECHADA', 'PROGRAMADA', 'ENCERRADA', 'CANCELADA'])
    })
  })

  it('sem cotações: estado vazio', async () => {
    const w = await mountAs(meAdmin, [])
    expect(w.text()).toContain('Nenhuma cotação')
  })
})

describe('QuoteModal somente leitura (aberto por Consultar cotação)', () => {
  it('não mostra Encaminhar, Cancelar, Fechar negociação, Responder nem Declinar', async () => {
    const { default: QuoteModal } = await import('../../../src/components/QuoteModal.vue')
    const { resposta } = await import('../../helpers/fixtures')
    const { openModal } = await import('../../../src/stores/ui')
    const q = quote({ id: 'q-9', status: 'EM_NEGOCIACAO', negociacao: { id: 'n-1', status: 'ABERTA', fechamento: null, respostas: [resposta()] } })
    api.on('GET /cotacoes/:id', ok(q))
    api.on('GET /cotacoes', ok([q]))
    const acoes = '[data-act="forward-quote"], [data-act="cancel-quote"], [data-act="close-quote"], [data-act="answer-quote"], [data-act="decline-quote"]'
    for (const me of [meAdmin, meCarrier]) {
      loginAs(createSession(me))
      const { db } = await import('../../../src/stores/db')
      db.quotes = [q]
      openModal('quote', { id: 'q-9', readOnly: true })
      const w = mount(QuoteModal, { global: { stubs: { teleport: true } } })
      await flushPromises()
      expect(w.find('#quote-title').exists()).toBe(true)
      expect(w.findAll(acoes)).toHaveLength(0)
      openModal('quote', { id: 'q-9' })
      const w2 = mount(QuoteModal, { global: { stubs: { teleport: true } } })
      await flushPromises()
      expect(w2.findAll(acoes).length).toBeGreaterThan(0)
    }
  })
})

describe('C8: busca só com espaços equivale a busca vazia', () => {
  it('lista tudo e o contador não aponta filtro', async () => {
    const w = await mountAs(meAdmin)
    for (const campo of ['#flt-txt', '#flt-produto', '#flt-origem', '#flt-destino']) {
      await w.find(campo).setValue('   ')
      expect(rows(w), campo).toHaveLength(3)
      expect(w.find('.card-h .cnt').text(), campo).toBe('3 registros')
    }
  })
})
