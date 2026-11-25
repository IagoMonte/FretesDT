import { mount, flushPromises } from '@vue/test-utils'
import QuotesView from '../../../src/views/QuotesView.vue'
import { db, resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, modal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { digitaParte } from '../../helpers/digita'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, parte, quote, resposta } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const neg = (respostas, o = {}) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null, ...o })
const products = [product(), product({ id: 'p-hcl', nome: 'HCL', embalagens: ['GRANEL_LIQUIDO', 'BOMBONA'] })]

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok(products))
  api.on('GET /cotacoes', ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

async function mountAs(me, quotes = []) {
  api.on('GET /cotacoes', ok(quotes))
  loginAs(createSession(me))
  const w = mount(QuotesView, { global: { stubs: { teleport: true } } })
  await flushPromises()
  return w
}

describe('QuotesView — lista agrupada por status', () => {
  const quotes = () => [
    quote({ id: 'q-1', codigo: 'COT-000001' }),
    quote({ id: 'q-2', codigo: 'COT-000002', status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '180.00', valor_frete: '4860.00', prazo_dias: 3 }), resposta({ id: 'r-2', transportadora: { id: 't-v', nome_fantasia: 'ROTA SUL' } })]) }),
    quote({ id: 'q-3', codigo: 'COT-000003', status: 'PROGRAMADA', negociacao: neg([], { status: 'FECHADA', fechamento: { transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, valor_tonelada: '175.00', valor_frete: '4725.00', prazo_dias: 3 } }) }),
    quote({ id: 'q-4', codigo: 'COT-000004', status: 'CANCELADA' }),
    quote({ id: 'q-5', codigo: 'COT-000005' }),
  ]

  it('carrega do back e agrupa pelos status novos, mais novas primeiro', async () => {
    const w = await mountAs(meAdmin, quotes())
    expect(api.called('GET', '/cotacoes')).toHaveLength(1)
    const groups = w.findAll('[data-group]')
    expect(groups.map((g) => g.attributes('data-group'))).toEqual(['ABERTA', 'EM_NEGOCIACAO', 'PROGRAMADA', 'CANCELADA'])
    expect(groups[0].find('.grp-h').text()).toContain('ABERTA')
    expect(groups[1].find('.grp-h').text()).toContain('EM NEGOCIAÇÃO')
    expect(groups[0].findAll('.qc').map((c) => c.text())).toEqual(['COT-000005', 'COT-000001'])
    expect(w.find('.card-h .cnt').text()).toBe('5 registros')
  })
  it('card: rota, parte, itens, valores formatados (decimais string) e respostas', async () => {
    const w = await mountAs(meAdmin, quotes())
    const card = w.findAll('.qcard').find((c) => c.text().includes('COT-000002'))
    expect(card.find('.route').text()).toContain('PAULÍNIA - SP')
    expect(card.text()).toContain('ALFAQUIM INDÚSTRIA QUÍMICA')
    expect(card.text()).toContain('SODA CÁUSTICA +1')
    expect(card.text()).toContain('20.000 kg')
    expect(card.text()).toContain('R$ 15.000,00')
    expect(card.text()).toContain('Carreta')
    const lines = card.findAll('[data-resp]')
    expect(lines[0].text()).toContain('respondeu')
    expect(lines[0].text()).toContain('R$ 4.860,00')
    expect(lines[1].text()).toContain('aguardando resposta')
    expect(card.find('.pill').text()).toBe('EM NEGOCIAÇÃO')
  })
  describe('transportadora: só vê Em negociação, Respondido, Programado e Encerrado (o back já manda derivado)', () => {
    const pillDe = (w, codigo) => w.findAll('.qcard').find((c) => c.text().includes(codigo)).find('.qhead .pill').text()

    it('rótulos dos 4 status no card e no título do grupo', async () => {
      const w = await mountAs(meCarrier, [
        quote({ id: 'q-1', codigo: 'COT-000001', status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) }),
        quote({ id: 'q-2', codigo: 'COT-000002', status: 'RESPONDIDA', negociacao: neg([resposta({ status: 'RESPONDIDA' })]) }),
        quote({ id: 'q-3', codigo: 'COT-000003', status: 'PROGRAMADA', negociacao: neg([resposta({ status: 'RESPONDIDA' })], { status: 'FECHADA' }) }),
        quote({ id: 'q-4', codigo: 'COT-000004', status: 'ENCERRADA', negociacao: neg([resposta({ status: 'RESPONDIDA' })], { status: 'FECHADA' }) }),
      ])
      expect(pillDe(w, 'COT-000001')).toBe('EM NEGOCIAÇÃO')
      expect(pillDe(w, 'COT-000002')).toBe('RESPONDIDO')
      expect(pillDe(w, 'COT-000003')).toBe('PROGRAMADO')
      expect(pillDe(w, 'COT-000004')).toBe('ENCERRADO')
      expect(w.find('[data-group="PROGRAMADA"] .grp-h').text()).toContain('PROGRAMADO')
      expect(w.find('[data-group="ENCERRADA"] .grp-h').text()).toContain('ENCERRADO')
    })
    it('sem resposta e negociação aberta: continua "aguardando resposta"', async () => {
      const w = await mountAs(meCarrier, [quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })])
      expect(w.find('[data-resp]').text()).toContain('aguardando resposta')
      expect(w.find('[data-resp-nao-enviada]').exists()).toBe(false)
    })
    it('encerrada sem ter respondido: "aguardando resposta" vira o alerta "Resposta não enviada"', async () => {
      const w = await mountAs(meCarrier, [quote({ status: 'ENCERRADA', negociacao: neg([resposta()], { status: 'FECHADA' }) })])
      const linha = w.find('[data-resp]')
      expect(linha.find('[data-resp-nao-enviada]').text()).toContain('Resposta não enviada')
      expect(linha.text()).not.toContain('aguardando resposta')
    })
    it('encerrada tendo respondido: mostra a resposta, sem alerta e sem a nota "Encerrada sem fechamento"', async () => {
      const w = await mountAs(meCarrier, [quote({ status: 'ENCERRADA', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_frete: '2160.00', prazo_dias: 3 })], { status: 'FECHADA' }) })])
      expect(w.find('[data-resp]').text()).toContain('respondeu')
      expect(w.find('[data-resp-nao-enviada]').exists()).toBe(false)
      expect(w.find('[data-encerrada]').exists()).toBe(false)
    })
    it('declinou: Encerrado, e a linha segue dizendo "declinou" (sem alerta)', async () => {
      const w = await mountAs(meCarrier, [quote({ status: 'ENCERRADA', negociacao: neg([resposta({ status: 'DECLINADA' })]) })])
      expect(w.find('[data-resp]').text()).toContain('declinou')
      expect(w.find('[data-resp-nao-enviada]').exists()).toBe(false)
    })
    it('o admin segue vendo os status reais e "aguardando resposta" em cotação encerrada', async () => {
      const w = await mountAs(meAdmin, [quote({ status: 'ENCERRADA', negociacao: neg([resposta()]) })])
      expect(w.find('.qhead .pill').text()).toBe('ENCERRADA')
      expect(w.find('[data-resp]').text()).toContain('aguardando resposta')
      expect(w.find('[data-resp-nao-enviada]').exists()).toBe(false)
    })
  })
  it('card fechado mostra a vencedora', async () => {
    const w = await mountAs(meAdmin, quotes())
    const card = w.findAll('.qcard').find((c) => c.text().includes('COT-000003'))
    expect(card.find('.resp-line').text()).toContain('Fechada com ARCO')
  })
  it('botões do admin por status: Encaminhar (aberta/negociação), Fechar (negociação), Cancelar (antes de programada)', async () => {
    const w = await mountAs(meAdmin, quotes())
    const btns = (code) => w.findAll('.qcard').find((c) => c.text().includes(code)).findAll('.qfoot button').map((b) => b.attributes('data-act'))
    expect(btns('COT-000001')).toEqual(['def-quote', 'edit-quote', 'view-quote', 'end-quote', 'cancel-quote'])
    expect(btns('COT-000002')).toEqual(['def-quote', 'view-quote', 'close-quote', 'end-quote', 'cancel-quote'])
    expect(btns('COT-000003')).toEqual(['view-quote'])
    expect(btns('COT-000004')).toEqual(['view-quote'])
  })
  it('Encaminhar abre o modal de seleção múltipla (sem popover)', async () => {
    const w = await mountAs(meAdmin, quotes())
    expect(w.find('.fw-list').exists()).toBe(false)
    await w.find('[data-act="def-quote"]').trigger('click')
    expect(w.find('.modal-ov').exists()).toBe(true)
    expect(w.find('.modal-h h3').text()).toContain('Encaminhar')
    expect(w.find('.fw-list').exists()).toBe(true)
  })
  it('Visualizar abre o modal da cotação', async () => {
    const w = await mountAs(meAdmin, quotes())
    await w.find('[data-act="view-quote"]').trigger('click')
    expect(modal).toMatchObject({ open: true, kind: 'quote' })
  })
  it('Fechar no card abre o modal de fechamento e Cancelar pede motivo', async () => {
    const w = await mountAs(meAdmin, quotes())
    const card = w.findAll('.qcard').find((c) => c.text().includes('COT-000002'))
    await card.find('[data-act="close-quote"]').trigger('click')
    await flushPromises()
    expect(card.find('#fc-transp').exists()).toBe(true)
    await card.find('[data-act="cancel-quote"]').trigger('click')
    expect(card.find('#mt-motivo').exists()).toBe(true)
  })
  it('Encerrar no card pede motivo opcional e encerra sem fechar', async () => {
    const w = await mountAs(meAdmin, quotes())
    const card = w.findAll('.qcard').find((c) => c.text().includes('COT-000002'))
    api.on('POST /cotacoes/:id/encerrar', ({ params }) => ok(quote({ id: params.id, codigo: 'COT-000002', status: 'ENCERRADA' })))
    await card.find('[data-act="end-quote"]').trigger('click')
    expect(card.find('#mt-motivo').exists()).toBe(true)
    await card.find('[data-act="confirm-motivo"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-2/encerrar')).toHaveLength(1)
  })
  it('cotação encerrada: sem Encaminhar, Fechar, Encerrar nem Cancelar (só Visualizar), respostas continuam à vista', async () => {
    const w = await mountAs(meAdmin, [quote({ id: 'q-9', codigo: 'COT-000009', status: 'ENCERRADA', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_frete: '2160.00', prazo_dias: 3 })]) })])
    const card = w.find('.qcard')
    expect(card.findAll('.qfoot button').map((b) => b.attributes('data-act'))).toEqual(['view-quote'])
    expect(card.find('[data-resp]').text()).toContain('respondeu')
  })
  it('lista vazia mostra o estado vazio', async () => {
    const w = await mountAs(meAdmin, [])
    expect(w.find('.empty').text()).toContain('Nenhuma cotação')
  })
})

describe('QuotesView — transportadora', () => {
  it('vê a faixa, não cria cotação e só responde/declina a própria', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = await mountAs(meCarrier, [q])
    expect(w.find('.note-strip').text()).toContain('ARCO')
    expect(w.find('[data-act="new-quote"]').exists()).toBe(false)
    expect(w.find('.card-h h2').text()).toBe('Cotações recebidas')
    const acts = w.findAll('.qfoot button').map((b) => b.attributes('data-act'))
    expect(acts).toEqual(['answer-quote', 'decline-quote', 'view-quote'])
    expect(w.findAll('[data-resp]')).toHaveLength(1)
  })
  it('responder: calcula o frete estimado por preço/t × quantidade e envia a resposta', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = await mountAs(meCarrier, [q])
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '250.00', valor_frete: '6750.00', prazo_dias: 4 })]) })))
    await w.find('[data-act="answer-quote"]').trigger('click')
    await w.find('#rt-r-1').setValue('250')
    await w.find('#rp-r-1').setValue('4')
    expect(w.find('#rf-r-1').text().replace(/\s/g, ' ')).toBe('R$ 5.000,00')
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).toEqual({ valor_tonelada: '250.00', prazo_dias: 4, prazo_coleta_dias: null, validade: null, observacao: null })
    expect(w.find('#rt-r-1').exists()).toBe(false)
    expect(w.find('[data-resp]').text()).toContain('R$ 6.750,00')
    expect(toasts.at(-1).type).toBe('ok')
  })
  it('fracionada: a transportadora informa o frete cheio e o tipo de carro', async () => {
    const q = quote({ fracionada: true, tipo_veiculo_codigo: null, status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = await mountAs(meCarrier, [q])
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({ fracionada: true, status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_tonelada: '125.00', valor_frete: '2500.00', tipo_veiculo_codigo: 'TRUCK', prazo_dias: 4 })]) })))
    await w.find('[data-act="answer-quote"]').trigger('click')
    expect(w.find('#rt-r-1').element.closest('.field').textContent).toContain('Valor do frete')
    await w.find('#rt-r-1').setValue('2500')
    await w.find('#rp-r-1').setValue('4')
    await w.find('#rpc-r-1').setValue('1')
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')).toHaveLength(0)
    await w.find('#rc-r-1').setValue('TRUCK')
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).toEqual({ valor_frete: '2500.00', tipo_veiculo_codigo: 'TRUCK', prazo_dias: 4, prazo_coleta_dias: 1, validade: null, observacao: null })
  })
  describe('tipo de carga e eixos na resposta (frete mínimo)', () => {
    const tipos = [{ codigo: 'CARGA_GERAL', descricao: 'Carga geral' }, { codigo: 'PERIGOSA', descricao: 'Carga perigosa' }]
    const abrir = async (extra) => {
      api.on('GET /dominios', ok({ ...domains, tipos_carga_antt: tipos }))
      const q = quote({ status: 'EM_NEGOCIACAO', tipo_carga_antt_codigo: 'CARGA_GERAL', negociacao: neg([resposta()]), ...extra })
      const w = await mountAs(meCarrier, [q])
      await w.find('[data-act="answer-quote"]').trigger('click')
      return w
    }
    it('carga comum: mostra tipo de carga (da cotação) e eixos', async () => {
      const w = await abrir({})
      expect(w.find('#rg-r-1').exists()).toBe(true)
      expect(w.find('#rg-r-1').element.value).toBe('CARGA_GERAL')
      expect(w.find('#re-r-1').exists()).toBe(true)
    })
    it('carga comum: não mostra o checkbox dedicado', async () => {
      const w = await abrir({})
      expect(w.find('#rd-r-1').exists()).toBe(false)
    })
    it('fracionada dedicada: marcar dedicado libera tipo de carga e eixos e envia dedicado + o tipo escolhido', async () => {
      const w = await abrir({ fracionada: true, tipo_veiculo_codigo: null })
      api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({ fracionada: true, status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_frete: '2500.00', dedicado: true })]) })))
      expect(w.find('#rd-r-1').exists()).toBe(true)
      expect(w.find('#rg-r-1').exists()).toBe(false)
      await w.find('#rd-r-1').setValue(true)
      await w.find('#rt-r-1').setValue('2500')
      await w.find('#rp-r-1').setValue('4')
      await w.find('#rpc-r-1').setValue('1')
      await w.find('#rc-r-1').setValue('TRUCK')
      await w.find('#rg-r-1').setValue('PERIGOSA')
      await w.find('#re-r-1').setValue('5')
      await w.find('[data-act="respond-quote"]').trigger('click')
      await flushPromises()
      expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).toMatchObject({ dedicado: true, tipo_carga_antt_codigo: 'PERIGOSA', eixos: 5 })
    })
    it('fracionada não dedicada: sem tipo de carga nem eixos, e não envia dedicado', async () => {
      const w = await abrir({ fracionada: true, tipo_veiculo_codigo: null })
      api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({ fracionada: true, status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'RESPONDIDA', valor_frete: '2500.00' })]) })))
      expect(w.find('#rg-r-1').exists()).toBe(false)
      expect(w.find('#re-r-1').exists()).toBe(false)
      await w.find('#rt-r-1').setValue('2500')
      await w.find('#rp-r-1').setValue('4')
      await w.find('#rpc-r-1').setValue('1')
      await w.find('#rc-r-1').setValue('TRUCK')
      await w.find('[data-act="respond-quote"]').trigger('click')
      await flushPromises()
      expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).not.toHaveProperty('dedicado')
    })
  })
  it('responder sem valor mostra erro e mantém o modal', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = await mountAs(meCarrier, [q])
    await w.find('[data-act="answer-quote"]').trigger('click')
    await w.find('[data-act="respond-quote"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(w.find('#rt-r-1').exists()).toBe(true)
  })
  it('T11: declinar pede confirmação antes de enviar', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ versao: 2 })]) })
    const w = await mountAs(meCarrier, [q])
    api.on('POST /cotacoes/:id/respostas/:r/declinar', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'DECLINADA' })]) })))
    await w.find('[data-act="decline-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/declinar')).toHaveLength(0)
    expect(modal.kind).toBe('confirm')
    modal.payload.onYes()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/declinar')[0].body.versao).toBe(2)
  })
  it('declinar envia o POST e atualiza o card', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = await mountAs(meCarrier, [q])
    api.on('POST /cotacoes/:id/respostas/:r/declinar', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta({ status: 'DECLINADA' })]) })))
    await w.find('[data-act="decline-quote"]').trigger('click')
    modal.payload.onYes()
    await flushPromises()
    expect(w.find('[data-resp]').text()).toContain('declinou')
    expect(w.find('[data-act="answer-quote"]').exists()).toBe(false)
  })
})

describe('T15: carregamento das cotações', () => {
  it('enquanto a API não responde mostra "Carregando…" em vez de "0 registros" falso', async () => {
    let soltar
    api.on('GET /cotacoes', () => new Promise((res) => { soltar = () => res(ok([quote()])) }))
    loginAs(createSession(meAdmin))
    const w = mount(QuotesView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(w.find('.card-h .cnt').text()).toBe('Carregando…')
    soltar()
    await flushPromises()
    expect(w.find('.card-h .cnt').text()).toBe('1 registro')
    w.unmount()
  })
})

describe('QuotesView — nova cotação (vendedor)', () => {
  const pick = async (w, termo = 'super') => {
    await digitaParte(w, '#qc-parte', termo)
    await flushPromises()
    await w.find('[data-parte]').trigger('mousedown')
  }

  async function open() {
    const w = await mountAs(meSeller, [])
    api.on('GET /partes', ok([parte()]))
    await w.find('[data-act="new-quote"]').trigger('click')
    return w
  }

  it('T16: Nova cotação é um diálogo modal nomeado pelo título, e Esc fecha', async () => {
    const w = await open()
    const dlg = w.find('[role="dialog"]')
    expect(dlg.exists()).toBe(true)
    expect(dlg.attributes('aria-modal')).toBe('true')
    expect(w.find('#' + dlg.attributes('aria-labelledby')).text()).toBe('Nova cotação de frete')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(w.find('[role="dialog"]').exists()).toBe(false)
    w.unmount()
  })
  describe('T18: seleção de cliente pelo teclado e feedback da busca', () => {
    const digitar = async (w, texto) => {
      await digitaParte(w, '#qc-parte', texto)
      await flushPromises()
    }
    it('ArrowDown + Enter seleciona o resultado; aria-activedescendant acompanha', async () => {
      const w = await open()
      await digitar(w, 'sup')
      const inp = w.find('#qc-parte')
      expect(inp.attributes('aria-expanded')).toBe('true')
      await inp.trigger('keydown', { key: 'ArrowDown' })
      expect(inp.attributes('aria-activedescendant')).toBe(w.find('[role=option][aria-selected=true]').attributes('id'))
      await inp.trigger('keydown', { key: 'Enter' })
      expect(w.find('.chosen-tag').text()).toContain('11.222.333/0001-81')
      expect(w.find('[role=listbox]').exists()).toBe(false)
      w.unmount()
    })
    it('ArrowUp volta ao anterior, o item "+ Cadastrar novo" é alcançável e Esc só fecha a lista', async () => {
      const w = await open()
      await digitar(w, 'sup')
      const inp = w.find('#qc-parte')
      await inp.trigger('keydown', { key: 'ArrowDown' })
      await inp.trigger('keydown', { key: 'ArrowDown' })
      await inp.trigger('keydown', { key: 'Enter' })
      expect(w.find('#pp-razao').exists()).toBe(true)
      const w2 = await open()
      await digitar(w2, 'sup')
      await w2.find('#qc-parte').trigger('keydown', { key: 'Escape' })
      expect(w2.find('[role=listbox]').exists()).toBe(false)
      expect(w2.find('[role=dialog]').exists()).toBe(true)
      w.unmount()
      w2.unmount()
    })
    it('mostra "Buscando…" enquanto a busca não volta', async () => {
      const w = await open()
      let soltar
      api.on('GET /partes', () => new Promise((res) => { soltar = () => res(ok([parte()])) }))
      await digitaParte(w, '#qc-parte', 'sup')
      await flushPromises()
      expect(w.find('[role=listbox]').text()).toContain('Buscando')
      soltar()
      await flushPromises()
      expect(w.find('[role=listbox]').text()).not.toContain('Buscando')
      expect(w.find('[data-parte]').exists()).toBe(true)
      w.unmount()
    })
    it('sem resultado mostra "Nenhum cliente encontrado" e mantém o cadastro rápido', async () => {
      const w = await open()
      api.on('GET /partes', ok([]))
      await digitar(w, 'zzzxyz')
      expect(w.find('[role=listbox]').text()).toContain('Nenhum cliente encontrado')
      expect(w.find('[data-act="new-parte"]').exists()).toBe(true)
      w.unmount()
    })
  })
  it('T19: observação respeita o limite da API (2000) e o botão de item explica o granel', async () => {
    const w = await open()
    expect(w.find('#qc-obs').attributes('maxlength')).toBe('2000')
    expect(w.find('[data-act="add-item"]').attributes('title')).toBeUndefined()
    w.unmount()
  })
  it('vendedor vê o botão e não vê Encaminhar/Fechar', async () => {
    const w = await mountAs(meSeller, [quote({ status: 'EM_NEGOCIACAO', negociacao: neg([]) })])
    expect(w.find('[data-act="new-quote"]').exists()).toBe(true)
    expect(w.findAll('.qfoot button').map((b) => b.attributes('data-act'))).toEqual(['view-quote', 'cancel-quote'])
  })
  it('tipo de carro: #qc-veiculo lista os 7 rótulos novos/atuais e envia o escolhido (VUC_VAN)', async () => {
    const w = await open()
    api.on('POST /cotacoes', ok(quote({ id: 'q-new', codigo: 'COT-000010', tipo_veiculo_codigo: 'VUC_VAN' }), {}, 201))
    await pick(w)
    await w.find('#qc-prod-0').setValue('p-soda')
    await w.find('#qc-qtd-0').setValue('12000')
    await w.find('#qc-vol-0').setValue('40')

    const rotulos = w.findAll('#qc-veiculo option').map((o) => o.text())
    expect(rotulos).toEqual(expect.arrayContaining(['Picape', 'Vuc/Van', 'Toco', 'Toco 3/4', 'Carreta LS', 'Rodotrem', 'Prancha']))

    await w.find('#qc-veiculo').setValue('VUC_VAN')
    await w.find('#qc-valor').setValue('15000')
    await w.find('#qc-origem').setValue('Paulínia - SP')
    await w.find('#qc-destino').setValue('Campinas - SP')
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()

    expect(api.called('POST', '/cotacoes')[0].body.tipo_veiculo_codigo).toBe('VUC_VAN')
  })

  it('gera cotação com operação, parte buscada, vários itens, veículo e rota', async () => {
    const w = await open()
    api.on('POST /cotacoes', ok(quote({ id: 'q-new', codigo: 'COT-000009' }), {}, 201))
    await pick(w)
    expect(api.called('GET', '/partes')[0].query).toEqual({ termo: 'super', tipo: 'CLIENTE' })
    expect(w.find('.chosen-tag').text()).toContain('11.222.333/0001-81')

    await w.find('#qc-prod-0').setValue('p-soda')
    expect(w.find('#qc-emb-0').element.value).toBe('BIG_BAG')
    await w.find('#qc-qtd-0').setValue('12000')
    expect(w.find('#qc-qtd-0').element.value).toBe('12.000')
    await w.find('[data-act="add-item"]').trigger('click')
    await w.find('#qc-prod-1').setValue('p-hcl')
    expect(w.findAll('#qc-emb-1 option').map((o) => o.element.value)).toEqual(['GRANEL_LIQUIDO', 'BOMBONA'])
    expect(w.find('#qc-emb-1').element.value).toBe('BOMBONA')
    await w.find('#qc-qtd-1').setValue('8000')
    await w.find('#qc-vol-1').setValue('20')
    await w.find('#qc-vol-0').setValue('40')

    await w.find('#qc-veiculo').setValue('CARRETA')
    await w.find('#qc-valor').setValue('15000')
    await w.find('#qc-origem').setValue('Paulínia - SP')
    await w.find('#qc-destino').setValue('Campinas - SP')
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()

    expect(api.called('POST', '/cotacoes')[0].body).toEqual({
      operacao: 'CARREGAMENTO', parte: { tipo: 'CLIENTE', id: 'c-1' }, tipo_veiculo_codigo: 'CARRETA',
      origem: { cidade: 'PAULÍNIA', uf: 'SP', ibge: null }, destino: { cidade: 'CAMPINAS', uf: 'SP', ibge: null },
      valor_nf: '15000.00', observacao: null,
      itens: [
        { produto_id: 'p-soda', embalagem_codigo: 'BIG_BAG', quantidade_kg: '12000.000', volumes: 40, paletizado: false, remontado: false },
        { produto_id: 'p-hcl', embalagem_codigo: 'BOMBONA', quantidade_kg: '8000.000', volumes: 20, paletizado: false, remontado: false },
      ],
      usar_anterior: true,
    })
    expect(w.find('#qc-valor').exists()).toBe(false)
    expect(db.quotes[0].id).toBe('q-new')
    expect(toasts.at(-1).parts.find((p) => p.bold).text).toBe('COT-000009')
  })
  it('cidade do cliente trava o destino, km é só leitura e tipo de carga não aparece para o vendedor', async () => {
    const w = await open()
    await pick(w)
    expect(w.find('#qc-destino').element.value).toBe('CAMPINAS - SP')
    expect(w.find('#qc-destino').attributes('readonly')).toBeDefined()
    expect(w.find('#qc-origem').attributes('readonly')).toBeUndefined()
    expect(w.find('#qc-km').attributes('readonly')).toBeDefined()
    expect(w.find('#qc-carga').exists()).toBe(false)
    await w.find('#qc-operacao').setValue('RECEBIMENTO')
    api.on('GET /partes', ok([parte({ tipo: 'FORNECEDOR', id: 'f-1', cidade: 'PAULÍNIA', uf: 'SP' })]))
    await pick(w)
    expect(w.find('#qc-origem').element.value).toBe('PAULÍNIA - SP')
    expect(w.find('#qc-origem').attributes('readonly')).toBeDefined()
  })
  it('fracionada: tipo de carro some e a cotação sai sem carro', async () => {
    api.on('GET /dominios', ok({ ...domains, embalagens: domains.embalagens.map((e) => (e.codigo === 'BOMBONA' ? { ...e, fracionada: true } : e)) }))
    const w = await open()
    api.on('POST /cotacoes', ok(quote({ id: 'q-new', codigo: 'COT-000010', tipo_veiculo_codigo: null, fracionada: true }), {}, 201))
    await pick(w)
    await w.find('#qc-prod-0').setValue('p-hcl')
    await w.find('#qc-emb-0').setValue('BOMBONA')
    expect(w.find('#qc-veiculo').exists()).toBe(false)
    expect(w.find('[data-fracionada]').text()).toContain('A definir pela transportadora')
    await w.find('#qc-qtd-0').setValue('1000')
    await w.find('#qc-valor').setValue('1000')
    await w.find('#qc-origem').setValue('Paulínia - SP')
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes')[0].body.tipo_veiculo_codigo).toBeNull()
  })
  it('dedicado não é campo da criação: nem na fracionada aparece, nem vai no payload', async () => {
    api.on('GET /dominios', ok({ ...domains, embalagens: domains.embalagens.map((e) => (e.codigo === 'BOMBONA' ? { ...e, fracionada: true } : e)) }))
    const w = await open()
    api.on('POST /cotacoes', ok(quote({ id: 'q-new', codigo: 'COT-000010', tipo_veiculo_codigo: null, fracionada: true }), {}, 201))
    await pick(w)
    await w.find('#qc-prod-0').setValue('p-hcl')
    await w.find('#qc-emb-0').setValue('BOMBONA')
    expect(w.find('#qc-dedicado').exists()).toBe(false)
    await w.find('#qc-qtd-0').setValue('1000')
    await w.find('#qc-valor').setValue('1000')
    await w.find('#qc-origem').setValue('Paulínia - SP')
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes')[0].body).not.toHaveProperty('dedicado')
  })
  it('granel é um produto só: escolher granel pede confirmação, tira os outros itens e bloqueia "Adicionar item"', async () => {
    const w = await open()
    await w.find('#qc-prod-0').setValue('p-soda')
    await w.find('[data-act="add-item"]').trigger('click')
    await w.find('#qc-prod-1').setValue('p-hcl')
    await w.find('#qc-emb-1').setValue('GRANEL_LIQUIDO')
    expect(modal.kind).toBe('confirm')
    expect(w.find('#qc-prod-1').exists()).toBe(true)
    modal.payload.onYes()
    await flushPromises()
    expect(w.find('#qc-prod-1').exists()).toBe(false)
    expect(w.find('[data-act="add-item"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-granel-hint]').exists()).toBe(true)
  })
  it('sem os campos obrigatórios: toast de erro e o formulário continua aberto', async () => {
    const w = await open()
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(api.called('POST', '/cotacoes')).toHaveLength(0)
    expect(w.find('#qc-valor').exists()).toBe(true)
  })
  it('RECEBIMENTO busca fornecedor e limpa a parte escolhida', async () => {
    const w = await open()
    await pick(w)
    expect(w.find('.chosen-tag').exists()).toBe(true)
    await w.find('#qc-operacao').setValue('RECEBIMENTO')
    expect(w.find('.chosen-tag').exists()).toBe(false)
    await digitaParte(w, '#qc-parte', 'forn')
    await flushPromises()
    expect(api.called('GET', '/partes').at(-1).query).toEqual({ termo: 'forn', tipo: 'FORNECEDOR' })
  })
  it('remove item (mantém ao menos um)', async () => {
    const w = await open()
    expect(w.find('[title="Remover item"]').attributes('disabled')).toBeDefined()
    await w.find('[data-act="add-item"]').trigger('click')
    expect(w.findAll('.item-row')).toHaveLength(2)
    await w.findAll('[title="Remover item"]')[0].trigger('click')
    expect(w.findAll('.item-row')).toHaveLength(1)
  })
  it('cadastro rápido de cliente: cria na API e já seleciona', async () => {
    const w = await open()
    api.on('GET /partes', ok([]))
    api.on('POST /clientes', ({ body }) => ok(parte({ id: 'c-new', razao_social: body.razao_social, cnpj: body.cnpj }), { alertas: ['CNPJ já cadastrado para outro cliente ativo.'] }, 201))
    await digitaParte(w, '#qc-parte', 'Novo Cliente')
    await flushPromises()
    await w.find('[data-act="new-parte"]').trigger('mousedown')
    expect(w.find('#pp-razao').element.value).toBe('Novo Cliente')
    await w.find('#pp-cnpj').setValue('11444777000161')
    await w.find('#pp-cidade').setValue('Campinas - SP')
    await w.find('[data-act="save-parte"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/clientes')[0].body).toEqual({ razao_social: 'Novo Cliente', nome_fantasia: null, cnpj: '11444777000161', cidade: 'CAMPINAS', uf: 'SP' })
    expect(w.find('.chosen-tag').text()).toContain('11.444.777/0001-61')
    expect(w.find('#pp-razao').exists()).toBe(false)
    const msgs = toasts.map((t) => t.parts.map((p) => p.text).join(''))
    expect(msgs.some((m) => m.includes('CNPJ já cadastrado'))).toBe(true)
    expect(msgs.some((m) => m.includes('criado'))).toBe(true)
  })
  it('cadastro rápido com CNPJ inválido mostra erro', async () => {
    const w = await open()
    api.on('GET /partes', ok([]))
    await digitaParte(w, '#qc-parte', 'Novo')
    await flushPromises()
    await w.find('[data-act="new-parte"]').trigger('mousedown')
    await w.find('[data-act="save-parte"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(api.called('POST', '/clientes')).toHaveLength(0)
  })
})

describe('Modal Encaminhar dentro da tela', () => {
  it('encaminha para várias e a cotação passa a EM NEGOCIAÇÃO', async () => {
    db.carriers = [carrier(), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL' })]
    api.on('GET /transportadoras', ok(db.carriers))
    const w = await mountAs(meAdmin, [quote()])
    await w.find('[data-act="def-quote"]').trigger('click')
    await flushPromises()
    for (const i of w.findAll('.fw-list input')) await i.setValue(true)
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta(), resposta({ id: 'r-2', transportadora: { id: 't-via', nome_fantasia: 'ROTA SUL' } })]) })))
    await w.find('[data-act="forward-selected"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-group="EM_NEGOCIACAO"]').exists()).toBe(true)
    expect(w.findAll('[data-resp]')).toHaveLength(2)
    expect(toasts.at(-1).parts.map((p) => p.text).join('')).toContain('2 transportadoras')
  })
})

describe('QuotesView — filtros (status, cliente, destino)', () => {
  const lista = () => [
    quote({ id: 'q-1', codigo: 'COT-000001' }),
    quote({ id: 'q-2', codigo: 'COT-000002', status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]), parte: parte({ id: 'c-2', razao_social: 'USINA MODELO LTDA', nome_fantasia: 'USINA MODELO' }), destino: { ibge: null, cidade: 'LIMEIRA', uf: 'SP' } }),
    quote({ id: 'q-3', codigo: 'COT-000003', parte: parte({ id: 'c-2', razao_social: 'USINA MODELO LTDA', nome_fantasia: 'USINA MODELO' }) }),
  ]
  const codigos = (w) => w.findAll('.qc').map((c) => c.text()).sort()

  it('mostra busca e os três selects, com opções vindas das cotações carregadas', async () => {
    const w = await mountAs(meAdmin, lista())
    expect(w.findAll('[data-filters] [data-flt]').map((e) => e.attributes('data-flt'))).toEqual(['text', 'status', 'cliente', 'destino'])
    expect(w.find('[data-flt="cliente"]').findAll('option').map((o) => o.text())).toEqual(['Todos os clientes', 'ALFAQUIM', 'USINA MODELO'])
    expect(w.find('[data-flt="destino"]').findAll('option').map((o) => o.text())).toEqual(['Todos os destinos', 'CAMPINAS - SP', 'LIMEIRA - SP'])
  })
  it('filtra por status, cliente e destino, e a contagem mostra "x de y"', async () => {
    const w = await mountAs(meAdmin, lista())
    await w.find('[data-flt="status"]').setValue('EM_NEGOCIACAO')
    expect(codigos(w)).toEqual(['COT-000002'])
    expect(w.find('.card-h .cnt').text()).toBe('1 de 3 registros')
    await w.find('[data-flt="status"]').setValue('')
    await w.find('[data-flt="cliente"]').setValue('c-2')
    expect(codigos(w)).toEqual(['COT-000002', 'COT-000003'])
    await w.find('[data-flt="destino"]').setValue('LIMEIRA - SP')
    expect(codigos(w)).toEqual(['COT-000002'])
  })
  it('busca por texto e estado vazio quando nada confere', async () => {
    const w = await mountAs(meAdmin, lista())
    await w.find('[data-flt="text"]').setValue('cot-000003')
    expect(codigos(w)).toEqual(['COT-000003'])
    await w.find('[data-flt="text"]').setValue('zzz')
    expect(w.find('.empty').text()).toContain('Nenhuma cotação com esse filtro.')
  })
  it('a transportadora filtra só pelos status dela', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) })
    const w = await mountAs(meCarrier, [q])
    const valores = w.find('[data-flt="status"]').findAll('option').map((o) => o.attributes('value'))
    expect(valores).toEqual(['', 'EM_NEGOCIACAO', 'RESPONDIDA', 'PROGRAMADA', 'ENCERRADA'])
  })
})
