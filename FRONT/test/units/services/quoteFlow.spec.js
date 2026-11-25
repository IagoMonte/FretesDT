import { createQuote, forwardQuote, respondQuote, declineQuote, closeNegotiation, cancelQuote, encerrarQuote } from '../../../src/services/quoteFlow'
import { db, resetDb } from '../../../src/stores/db'
import { toasts, modal, openModal, closeModal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { parte, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const text = (t) => t.parts.map((p) => p.text).join('')
const last = () => toasts.at(-1)

const form = () => ({
  operacao: 'CARREGAMENTO', parte: parte(), veiculo: 'CARRETA', valorNF: '15.000,00', origem: 'Paulínia - SP', destino: 'Campinas - SP',
  itens: [{ produto_id: 'p-soda', embalagem: 'BIG_BAG', qtd: '12.000', volumes: '' }],
})

beforeEach(() => {
  api.reset()
  resetDb()
  toasts.splice(0)
  closeModal()
})

describe('quoteFlow (toasts de feedback)', () => {
  it('createQuote: toast com código, kg e itens', async () => {
    api.on('POST /cotacoes', ok(quote(), {}, 201))
    const r = await createQuote(form())
    expect(r.ok).toBe(true)
    expect(last().type).toBe('ok')
    expect(text(last())).toContain('COT-000001')
    expect(text(last())).toContain('20.000 kg')
    expect(text(last())).toContain('2 itens')
    expect(text(last())).toContain('Encaminhar')
    expect(last().parts.find((p) => p.bold).text).toBe('COT-000001')
  })
  it('createQuote com erro de validação: toast de erro e nada no cache', async () => {
    const r = await createQuote({ ...form(), parte: null })
    expect(r.ok).toBe(false)
    expect(last().type).toBe('err')
    expect(db.quotes).toHaveLength(0)
  })
  it('createQuote com 422 da API mostra a mensagem do back', async () => {
    api.on('POST /cotacoes', err(422, 'CARREGAMENTO exige cliente.'))
    await createQuote(form())
    expect(text(last())).toBe('CARREGAMENTO exige cliente.')
  })

  it('forwardQuote: várias transportadoras', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO' })))
    const r = await forwardQuote('q-1', ['a', 'b', 'c'], ['A', 'B', 'C'])
    expect(r.ok).toBe(true)
    expect(last().type).toBe('info')
    expect(text(last())).toBe('Cotação COT-000001 encaminhada para 3 transportadoras.')
  })
  it('forwardQuote: uma transportadora cita o nome; nenhuma selecionada avisa sem chamar a API', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO' })))
    await forwardQuote('q-1', ['t-arco'], ['ARCO'])
    expect(text(last())).toContain('para ARCO')
    const n = api.calls.length
    const r = await forwardQuote('q-1', [])
    expect(r.ok).toBe(false)
    expect(last().type).toBe('err')
    expect(api.calls.length).toBe(n)
  })

  it('respondQuote: toast com o frete calculado pelo back', async () => {
    db.quotes = [quote({ negociacao: { id: 'n', status: 'ABERTA', respostas: [resposta()], fechamento: null } })]
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({
      status: 'EM_NEGOCIACAO',
      negociacao: { id: 'n', status: 'ABERTA', fechamento: null, respostas: [resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '4860.00', prazo_dias: 3 })] },
    })))
    const r = await respondQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3' })
    expect(r.ok).toBe(true)
    expect(text(last())).toContain('R$')
    expect(text(last())).toContain('4.860,00')
  })
  it('respondQuote sem valor: erro e sem chamada', async () => {
    const r = await respondQuote('q-1', 'r-1', { valorTon: '', prazo: '' })
    expect(r.ok).toBe(false)
    expect(last().type).toBe('err')
    expect(api.calls).toHaveLength(0)
  })

  it('declineQuote: toast de aviso', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/respostas/:r/declinar', ok(quote()))
    await declineQuote('q-1', 'r-1')
    expect(last().type).toBe('warn')
    expect(text(last())).toContain('declinada')
  })

  it('closeNegotiation: fecha o modal e cita vencedora e carga criada', async () => {
    db.quotes = [quote({ status: 'EM_NEGOCIACAO' })]
    openModal('quote', { id: 'q-1' })
    api.on('POST /cotacoes/:id/fechar', ok(quote({
      status: 'PROGRAMADA',
      negociacao: { id: 'n', status: 'FECHADA', respostas: [], fechamento: { transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, valor_tonelada: '175.00', valor_frete: '4725.00', prazo_dias: 3 } },
    }), { programacao: load() }))
    const r = await closeNegotiation('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '175,00', prazo: '3' })
    expect(r.ok).toBe(true)
    expect(modal.open).toBe(false)
    expect(text(last())).toBe('Negociação COT-000001 fechada com ARCO! Carga CRG-000001 adicionada à Programação de Cargas.')
  })
  it('closeNegotiation com erro mantém o modal aberto', async () => {
    db.quotes = [quote()]
    openModal('quote', { id: 'q-1' })
    api.on('POST /cotacoes/:id/fechar', err(422, 'Transição inválida.'))
    const r = await closeNegotiation('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '175,00', prazo: '3' })
    expect(r.ok).toBe(false)
    expect(modal.open).toBe(true)
    expect(text(last())).toBe('Transição inválida.')
  })

  it('cancelQuote: toast e fecha o modal', async () => {
    db.quotes = [quote()]
    openModal('quote', { id: 'q-1' })
    api.on('POST /cotacoes/:id/cancelar', ok(quote({ status: 'CANCELADA' })))
    await cancelQuote('q-1', 'cliente desistiu')
    expect(text(last())).toContain('cancelada')
    expect(modal.open).toBe(false)
  })

  it('encerrarQuote: toast e fecha o modal', async () => {
    db.quotes = [quote()]
    openModal('quote', { id: 'q-1' })
    api.on('POST /cotacoes/:id/encerrar', ok(quote({ status: 'ENCERRADA' })))
    await encerrarQuote('q-1', '')
    expect(last().type).toBe('info')
    expect(text(last())).toContain('encerrada')
    expect(modal.open).toBe(false)
  })
  it('encerrarQuote: erro da API vira toast de erro', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/encerrar', err(422, 'Esta cotação foi encerrada e não pode mais mudar de situação.'))
    await encerrarQuote('q-1', '')
    expect(last().type).toBe('err')
  })
})
