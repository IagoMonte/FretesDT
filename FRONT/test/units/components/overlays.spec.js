import { mount, flushPromises } from '@vue/test-utils'
import ToastHost from '../../../src/components/ToastHost.vue'
import ModalHost from '../../../src/components/ModalHost.vue'
import FormModal from '../../../src/components/FormModal.vue'
import ForwardModal from '../../../src/components/ForwardModal.vue'
import { toasts, pushToast, modal, openModal, closeModal, confirmDelete, confirmAction } from '../../../src/stores/ui'
import { db, resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, carrier, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  api.on('GET /dominios', ok(domains))
})

describe('ToastHost', () => {
  it('renderiza toasts com tipo, ícone e negrito (sem v-html)', () => {
    pushToast('ok', 'Cotação **COT-1** <i>ok</i>')
    const w = mount(ToastHost)
    const t = w.find('.toast')
    expect(t.classes()).toContain('ok')
    expect(t.find('.ti svg').attributes('aria-hidden')).toBe('true')
    expect(t.attributes('role')).toBe('status')
    expect(t.find('b').text()).toBe('COT-1')
    expect(t.text()).toContain('<i>ok</i>')
    expect(w.find('.toasts').exists()).toBe(true)
  })
  it('marca saída com .out', async () => {
    const t = pushToast('err', 'x')
    const w = mount(ToastHost)
    t.out = true
    await w.vm.$nextTick()
    expect(w.find('.toast').classes()).toContain('out')
  })
})

describe('ModalHost — confirmação', () => {
  it('fechado não renderiza', () => {
    expect(mount(ModalHost).find('.modal-ov').exists()).toBe(false)
  })
  it('mostra mensagem, Cancelar e Excluir vermelho; Excluir executa callback', async () => {
    const yes = vi.fn()
    confirmDelete('Excluir o produto **HCL**?', yes)
    const w = mount(ModalHost)
    expect(w.find('.modal-h h3').text()).toBe('Confirmar exclusão')
    expect(w.find('.confirm-body b').text()).toBe('HCL')
    expect(w.find('.confirm-body .big svg').exists()).toBe(true)
    const btns = w.findAll('.modal-f button')
    expect(btns.map((b) => b.text())).toEqual(['Cancelar', 'Excluir'])
    expect(btns[1].classes()).toContain('btn-danger')
    await btns[1].trigger('click')
    expect(yes).toHaveBeenCalled()
    expect(modal.open).toBe(false)
  })
  it('confirmAction personaliza título, rótulo e ícone (ex.: inativar)', () => {
    confirmAction('Inativar **HCL**?', () => {}, { title: 'Confirmar inativação', label: 'Inativar', icon: 'pause' })
    const w = mount(ModalHost)
    expect(w.find('.modal-h h3').text()).toBe('Confirmar inativação')
    expect(w.find('.confirm-body .big svg path,.confirm-body .big svg rect').exists()).toBe(true)
    expect(w.findAll('.modal-f button')[1].text()).toBe('Inativar')
  })
  it('fecha por × e Esc; clique no overlay não fecha', async () => {
    confirmDelete('x', () => {})
    const w = mount(ModalHost, { attachTo: document.body })
    await w.find('.modal-h .x').trigger('click')
    expect(modal.open).toBe(false)
    confirmDelete('x', () => {})
    await w.vm.$nextTick()
    await w.find('.modal-ov').trigger('click')
    expect(modal.open).toBe(true)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(modal.open).toBe(false)
    w.unmount()
  })
  it('clicar dentro da caixa não fecha', async () => {
    confirmDelete('x', () => {})
    const w = mount(ModalHost)
    await w.find('.modal').trigger('click')
    expect(modal.open).toBe(true)
  })
})

describe('Esc com modais empilhados — só o topo fecha', () => {
  const esc = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
  const montados = []
  const abrir = (C, opts) => { const w = mount(C, { attachTo: document.body, ...opts }); montados.push(w); return w }
  afterEach(() => { while (montados.length) montados.pop().unmount() })

  it('confirmação aberta sobre um formulário: Esc fecha só a confirmação', async () => {
    const form = abrir(FormModal, { props: { title: 'Novo' } })
    abrir(ModalHost)
    confirmDelete('x', () => {})
    await form.vm.$nextTick()
    esc()
    expect(modal.open).toBe(false)
    expect(form.emitted('close')).toBeUndefined()
  })
  it('segundo Esc fecha o formulário', async () => {
    const form = abrir(FormModal, { props: { title: 'Novo' } })
    abrir(ModalHost)
    confirmDelete('x', () => {})
    await form.vm.$nextTick()
    esc()
    esc()
    expect(form.emitted('close')).toHaveLength(1)
  })
  it('dois formulários empilhados: Esc fecha só o de cima', async () => {
    const baixo = abrir(FormModal, { props: { title: 'A' } })
    const cima = abrir(FormModal, { props: { title: 'B' } })
    esc()
    expect(cima.emitted('close')).toHaveLength(1)
    expect(baixo.emitted('close')).toBeUndefined()
  })
  it('formulário aberto depois do modal global: Esc fecha só o formulário', async () => {
    abrir(ModalHost)
    confirmDelete('x', () => {})
    const form = abrir(FormModal, { props: { title: 'Novo' } })
    esc()
    expect(form.emitted('close')).toHaveLength(1)
    expect(modal.open).toBe(true)
  })
  it('modal único continua fechando com Esc', () => {
    const form = abrir(FormModal, { props: { title: 'Novo' } })
    esc()
    expect(form.emitted('close')).toHaveLength(1)
  })
})

describe('ModalHost — detalhe da cotação (N transportadoras)', () => {
  const negociacao = (respostas, extra = {}) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null, ...extra })
  const answered = (id, nome, frete, ton, prazo) => resposta({
    id, transportadora: { id: 't-' + id, nome_fantasia: nome }, status: 'RESPONDIDA', valor_tonelada: ton, valor_frete: frete, prazo_dias: prazo,
  })

  async function open(me, q, { carriers = [] } = {}) {
    loginAs(createSession(me))
    db.domains = domains
    db.carriers = carriers
    api.on('GET /cotacoes/:id', ok(q))
    api.on('GET /transportadoras', ok(carriers))
    db.quotes = [q]
    openModal('quote', { id: q.id })
    const w = mount(ModalHost, { attachTo: document.body })
    await flushPromises()
    return w
  }

  it('mostra rota, partes, itens e, sem respostas, oferece Encaminhar com as transportadoras ativas', async () => {
    const w = await open(meAdmin, quote(), { carriers: [carrier(), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL' }), carrier({ id: 't-off', nome_fantasia: 'INATIVA', ativo: false })] })
    expect(w.find('.modal.wide').exists()).toBe(true)
    expect(w.find('.modal-h h3').text()).toContain('COT-000001')
    expect(w.find('.route-banner').text()).toContain('PAULÍNIA - SP')
    expect(w.find('.dgrid').text()).toContain('ALFAQUIM')
    expect(w.findAll('[data-item]')).toHaveLength(2)
    expect(w.find('.await').text()).toContain('Nenhuma transportadora')
    expect(w.find('.chipz').exists()).toBe(false)
    expect(w.find('[data-act="forward-quote"]').exists()).toBe(true)
    await w.find('[data-act="forward-quote"]').trigger('click')
    await flushPromises()
    expect([...document.querySelectorAll('.fw-list label')].map((l) => l.textContent.trim())).toEqual(['ARCO', 'ROTA SUL'])
    w.unmount()
  })
  it('Encaminhar pelo modal envia as transportadoras marcadas', async () => {
    const w = await open(meAdmin, quote(), { carriers: [carrier(), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL' })] })
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([resposta(), resposta({ id: 'r-2', transportadora: { id: 't-via', nome_fantasia: 'ROTA SUL' } })]) })))
    await w.find('[data-act="forward-quote"]').trigger('click')
    await flushPromises()
    const btn = () => document.querySelector('[data-act="forward-selected"]')
    expect(btn().disabled).toBe(true)
    for (const i of document.querySelectorAll('.fw-list input')) { i.checked = true; i.dispatchEvent(new Event('change')) }
    await flushPromises()
    btn().click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/encaminhar')[0].body).toEqual({ transportadora_ids: ['t-arco', 't-via'] })
    expect(db.quotes[0].negociacao.respostas).toHaveLength(2)
    w.unmount()
  })
  it('respostas lado a lado com destaque no menor valor', async () => {
    const q = quote({
      status: 'EM_NEGOCIACAO',
      negociacao: negociacao([answered('a', 'ARCO', '4860.00', '180.00', 3), answered('b', 'ROTA SUL', '4590.00', '170.00', 4), resposta({ id: 'c', transportadora: { id: 't-c', nome_fantasia: 'TELX' } })]),
    })
    const w = await open(meAdmin, q)
    const cards = w.findAll('[data-resp]')
    expect(cards).toHaveLength(3)
    expect(cards[0].classes()).toContain('best')
    expect(cards[0].text()).toContain('MENOR VALOR')
    expect(cards[1].text()).toContain('4.860,00')
    expect(cards[1].classes()).not.toContain('best')
    expect(cards[2].classes()).toContain('dim')
    expect(cards[2].text()).toContain('AGUARDANDO')
    w.unmount()
  })
  it('admin fecha negociação: escolhe vencedora (pré-preenche com a resposta) e cria a carga', async () => {
    const q = quote({
      status: 'EM_NEGOCIACAO',
      negociacao: negociacao([answered('a', 'ARCO', '4860.00', '180.00', 3), answered('b', 'ROTA SUL', '4590.00', '170.00', 4)]),
    })
    const w = await open(meAdmin, q)
    await w.find('.modal-f [data-act="close-quote"]').trigger('click')
    await flushPromises()
    const sel = document.querySelector('#fc-transp')
    expect(sel.value).toBe('t-b')
    expect(document.querySelector('#fc-ton').value).toBe('170,00')
    expect(document.querySelector('#fc-prazo').value).toBe('4')
    expect(document.querySelector('#fc-total').textContent).toContain('3.400,00')
    sel.value = 't-a'
    sel.dispatchEvent(new Event('change'))
    await flushPromises()
    expect(document.querySelector('#fc-ton').value).toBe('180,00')

    api.on('POST /cotacoes/:id/fechar', ok(quote({ status: 'PROGRAMADA' }), { programacao: load() }))
    document.querySelector('#fc-obs').value = 'negociado por telefone'
    document.querySelector('#fc-obs').dispatchEvent(new Event('input'))
    document.querySelector('#fc-pedido').value = 'PV-123'
    document.querySelector('#fc-pedido').dispatchEvent(new Event('input'))
    document.querySelector('[data-act="close-negotiation"]').click()
    await flushPromises()
    const body = api.called('POST', '/cotacoes/q-1/fechar')[0].body
    expect(body).toMatchObject({ transportadora_id: 't-a', valor_tonelada: '180.00', prazo_dias: 3, observacao: 'negociado por telefone' })
    expect(body.programacao).toMatchObject({ pedido_venda: 'PV-123', deposito: 'VIRTUAL', modalidade_frete: 'CIF' })
    expect(modal.open).toBe(false)
    expect(db.quotes[0].status).toBe('PROGRAMADA')
    w.unmount()
  })
  it('fechamento aceita transportadora que não respondeu (qualquer ativa)', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([resposta()]) })
    const w = await open(meAdmin, q, { carriers: [carrier(), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL' })] })
    await w.find('.modal-f [data-act="close-quote"]').trigger('click')
    await flushPromises()
    const opts = [...document.querySelectorAll('#fc-transp option')].map((o) => o.textContent)
    expect(opts).toEqual(['— selecionar —', 'ARCO', 'ROTA SUL'])
    w.unmount()
  })
  describe('T05: fechamento', () => {
    const digitar = (sel, v) => { const el = document.querySelector(sel); el.value = v; el.dispatchEvent(new Event('input')) }
    const escolher = (id) => { const el = document.querySelector('#fc-transp'); el.value = id; el.dispatchEvent(new Event('change')) }
    async function abrirFechar(respostas, carriers = []) {
      const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao(respostas) })
      const w = await open(meAdmin, q, { carriers })
      await w.find('.modal-f [data-act="close-quote"]').trigger('click')
      await flushPromises()
      api.on('POST /cotacoes/:id/fechar', ok(quote({ status: 'PROGRAMADA' }), { programacao: load() }))
      digitar('#fc-pedido', 'PV-9')
      return w
    }
    const fechar = async () => { document.querySelector('[data-act="close-negotiation"]').click(); await flushPromises() }

    it('transportadora que não respondeu: exige marcar "Negociado por fora" e avisa por toast', async () => {
      const w = await abrirFechar([answered('a', 'ARCO', '4860.00', '180.00', 3)], [carrier({ id: 't-a', nome_fantasia: 'ARCO' }), carrier({ id: 't-via', nome_fantasia: 'ROTA SUL' })])
      expect(document.querySelector('#fc-fora')).toBeNull()
      escolher('t-via')
      await flushPromises()
      expect(document.querySelector('#fc-fora')).not.toBeNull()
      digitar('#fc-ton', '200,00')
      digitar('#fc-prazo', '2')
      await fechar()
      expect(api.called('POST', '/cotacoes/q-1/fechar')).toHaveLength(0)
      expect(toasts.at(-1)).toMatchObject({ type: 'err' })
      expect(toasts.at(-1).parts.map((p) => p.text).join('')).toContain('Negociado por fora')
      document.querySelector('#fc-fora').click()
      await flushPromises()
      await fechar()
      expect(api.called('POST', '/cotacoes/q-1/fechar')[0].body).toMatchObject({ transportadora_id: 't-via', negociado_por_fora: true })
      w.unmount()
    })
    it('valor abaixo de 50% da proposta pede confirmação antes de fechar', async () => {
      const w = await abrirFechar([answered('a', 'ARCO', '4860.00', '180.00', 3)])
      digitar('#fc-ton', '5,00')
      await fechar()
      expect(api.called('POST', '/cotacoes/q-1/fechar')).toHaveLength(0)
      expect(modal.kind).toBe('confirm')
      expect(modal.payload.message).toContain('metade')
      modal.payload.onYes()
      await flushPromises()
      expect(api.called('POST', '/cotacoes/q-1/fechar')[0].body).toMatchObject({ transportadora_id: 't-a', valor_tonelada: '5.00' })
      w.unmount()
    })
    it('valor abaixo do piso ANTT da resposta pede confirmação', async () => {
      const r = { ...answered('a', 'ARCO', '4860.00', '180.00', 3), antt: { piso: '6000.00', km: '100', eixos: 5, diferenca: '-1140.00' } }
      const w = await abrirFechar([r])
      digitar('#fc-ton', '150,00')
      await fechar()
      expect(api.called('POST', '/cotacoes/q-1/fechar')).toHaveLength(0)
      expect(modal.kind).toBe('confirm')
      expect(modal.payload.message).toContain('piso')
      w.unmount()
    })
    it('valor normal fecha direto, sem confirmação', async () => {
      const w = await abrirFechar([answered('a', 'ARCO', '4860.00', '180.00', 3)])
      digitar('#fc-ton', '175,00')
      await fechar()
      expect(modal.kind).not.toBe('confirm')
      expect(api.called('POST', '/cotacoes/q-1/fechar')).toHaveLength(1)
      w.unmount()
    })
  })
  it('T16: Visualizar cotação é um diálogo modal nomeado pelo código da cotação', async () => {
    const w = await open(meAdmin, quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([resposta()]) }))
    const dlg = document.querySelector('[role="dialog"]')
    expect(dlg).not.toBeNull()
    expect(dlg.getAttribute('aria-modal')).toBe('true')
    expect(document.getElementById(dlg.getAttribute('aria-labelledby')).textContent).toContain('COT-000001')
    w.unmount()
  })
  it('cancelar cotação pede motivo', async () => {
    const w = await open(meAdmin, quote())
    api.on('POST /cotacoes/:id/cancelar', ok(quote({ status: 'CANCELADA' })))
    await w.find('.modal-f [data-act="cancel-quote"]').trigger('click')
    document.querySelector('[data-act="confirm-motivo"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/cancelar')).toHaveLength(0)
    const ta = document.querySelector('#mt-motivo')
    ta.value = 'cliente desistiu'
    ta.dispatchEvent(new Event('input'))
    document.querySelector('[data-act="confirm-motivo"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/cancelar')[0].body).toEqual({ motivo: 'cliente desistiu' })
    expect(db.quotes[0].status).toBe('CANCELADA')
    w.unmount()
  })
  it('vendedor só vê o acompanhamento: sem Encaminhar, Fechar nem Responder', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([answered('a', 'ARCO', '4860.00', '180.00', 3)]) })
    const w = await open(meSeller, q)
    expect(w.find('[data-act="forward-quote"]').exists()).toBe(false)
    expect(w.find('[data-act="close-quote"]').exists()).toBe(false)
    expect(w.find('[data-act="answer-quote"]').exists()).toBe(false)
    expect(w.find('[data-act="cancel-quote"]').exists()).toBe(true)
    w.unmount()
  })
  it('transportadora vê só a própria resposta e pode responder pelo modal', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([resposta()]) })
    const w = await open(meCarrier, q)
    expect(w.findAll('[data-resp]')).toHaveLength(1)
    expect(w.find('[data-act="cancel-quote"]').exists()).toBe(false)
    api.on('POST /cotacoes/:id/respostas/:r/responder', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([answered('r-1', 'ARCO', '4860.00', '180.00', 3)]) })))
    await w.find('[data-act="answer-quote"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('#rf-r-1').textContent).toContain('R$ 0,00')
    const ton = document.querySelector('#rt-r-1')
    ton.value = '180'
    ton.dispatchEvent(new Event('input'))
    document.querySelector('#rp-r-1').value = '3'
    document.querySelector('#rp-r-1').dispatchEvent(new Event('input'))
    await flushPromises()
    expect(document.querySelector('#rf-r-1').textContent).toContain('3.600,00')
    document.querySelector('[data-act="respond-quote"]').click()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body).toEqual({ valor_tonelada: '180.00', prazo_dias: 3, prazo_coleta_dias: null, validade: null, observacao: null })
    w.unmount()
  })
  it('transportadora que já respondeu (status RESPONDIDA) ainda pode corrigir, com o formulário preenchido', async () => {
    const q = quote({ status: 'RESPONDIDA', negociacao: negociacao([answered('r-1', 'ARCO', '3600.00', '180.00', 3)]) })
    const w = await open(meCarrier, q)
    expect(w.find('[data-act="answer-quote"]').exists()).toBe(true)
    await w.find('[data-act="answer-quote"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('#rt-r-1').value).toBe('180,00')
    w.unmount()
  })
  it('transportadora com cotação encerrada (status ENCERRADA) não corrige', async () => {
    const q = quote({ status: 'ENCERRADA', negociacao: negociacao([answered('r-1', 'ARCO', '3600.00', '180.00', 3)]) })
    const w = await open(meCarrier, q)
    expect(w.find('[data-act="answer-quote"]').exists()).toBe(false)
    w.unmount()
  })
  it('transportadora declina', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([resposta()]) })
    const w = await open(meCarrier, q)
    const declinada = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([resposta({ status: 'DECLINADA' })]) })
    api.on('POST /cotacoes/:id/respostas/:r/declinar', ok(declinada))
    api.on('GET /cotacoes/:id', ok(declinada))
    await w.find('[data-act="decline-quote"]').trigger('click')
    modal.payload.onYes()
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/declinar')).toHaveLength(1)
    expect(db.quotes[0].negociacao.respostas[0].status).toBe('DECLINADA')
    w.unmount()
  })
  it('cotação fechada mostra o fechamento (vencedora)', async () => {
    const q = quote({
      status: 'PROGRAMADA',
      negociacao: negociacao([], { status: 'FECHADA', fechamento: { transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, valor_tonelada: '175.00', valor_frete: '4725.00', prazo_dias: 3, observacao: 'telefone', fechado_em: '2026-10-05T15:00:00.000-03:00' } }),
    })
    const w = await open(meAdmin, q)
    expect(w.find('.resp-card .rc-h').text()).toContain('ARCO')
    expect(w.find('.resp-card').text()).toContain('4.725,00')
    expect(w.find('[data-act="close-quote"]').exists()).toBe(false)
    expect(w.find('[data-act="forward-quote"]').exists()).toBe(false)
    w.unmount()
  })
})

describe('ForwardModal (seleção múltipla)', () => {
  const mk = () => mount(ForwardModal, { props: { quote: db.quotes[0] }, global: { stubs: { teleport: true } } })
  beforeEach(() => {
    loginAs(createSession(meAdmin))
    db.carriers = [carrier({ id: 'b', nome_fantasia: 'ROTA SUL' }), carrier({ id: 'a', nome_fantasia: 'ARCO' }), carrier({ id: 'c', nome_fantasia: 'ABACO' }), carrier({ id: 'd', nome_fantasia: 'ACRE' }), carrier({ id: 'x', nome_fantasia: 'INATIVA', ativo: false })]
    db.quotes = [quote()]
    api.on('GET /transportadoras', ok(db.carriers))
  })

  it('lista as ativas em ordem alfabética, com título da cotação', () => {
    const w = mk()
    expect(w.findAll('.fw-list label').map((d) => d.text())).toEqual(['ABACO', 'ACRE', 'ARCO', 'ROTA SUL'])
    expect(w.find('.modal-sub').text()).toContain('COT-000001')
  })
  it('busca filtra a lista', async () => {
    const w = mk()
    await w.find('#fwSearch').setValue('ac')
    expect(w.findAll('.fw-list label').map((d) => d.text())).toEqual(['ABACO', 'ACRE'])
  })
  it('marca várias e encaminha de uma vez; emite close', async () => {
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO' })))
    const w = mk()
    const send = w.find('[data-act="forward-selected"]')
    expect(send.attributes('disabled')).toBeDefined()
    await w.findAll('.fw-list input')[2].setValue(true)
    await w.findAll('.fw-list input')[3].setValue(true)
    expect(w.find('[data-act="forward-selected"]').text()).toContain('(2)')
    await w.find('[data-act="forward-selected"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/cotacoes/q-1/encaminhar')[0].body).toEqual({ transportadora_ids: ['a', 'b'] })
    expect(db.quotes[0].status).toBe('EM_NEGOCIACAO')
    expect(w.emitted('close')).toBeTruthy()
  })
  it('transportadoras já consultadas (próprias ou referenciadas) aparecem marcadas e travadas', () => {
    db.quotes = [quote({ status: 'EM_NEGOCIACAO', negociacao: { id: 'n', status: 'ABERTA', fechamento: null, respostas: [resposta({ transportadora: { id: 'a', nome_fantasia: 'ARCO' } }), resposta({ id: 'r-9', origem: 'REFERENCIADA', cotacao_codigo: 'COT-000009', transportadora: { id: 'b', nome_fantasia: 'ROTA SUL' } })] } })]
    const w = mk()
    for (const nome of ['ARCO', 'ROTA SUL']) {
      const l = w.findAll('.fw-list label').find((x) => x.text().includes(nome))
      expect(l.text()).toContain('JÁ CONSULTADA')
      expect(l.find('input').element.checked).toBe(true)
      expect(l.find('input').attributes('disabled')).toBeDefined()
    }
  })
})

describe('LoadModal — valor proposto (T05)', () => {
  it('T20: carga entregue mostra o dia e a hora da entrega', async () => {
    db.loads = [load({ status: 'ENTREGUE', data_entrega_realizada: '2026-10-08', entregue_em: '2026-10-08T14:05:00.000-03:00' })]
    openModal('load', { id: 'l-1' })
    const w = mount(ModalHost, { attachTo: document.body })
    await flushPromises()
    expect(w.text()).toContain('Entregue em')
    expect(w.text()).toContain('08/10/2026 14:05')
    w.unmount(); closeModal()
  })
  it('mostra o valor proposto só quando o fechamento saiu com outro valor', async () => {
    const mostrar = async (o) => {
      db.loads = [load(o)]
      openModal('load', { id: 'l-1' })
      const w = mount(ModalHost, { attachTo: document.body })
      await flushPromises()
      return w
    }
    let w = await mostrar({ valor_proposto: '250.00' })
    expect(w.text()).toContain('Valor proposto')
    expect(w.text()).toContain('250,00')
    w.unmount(); closeModal()
    w = await mostrar({ valor_proposto: null })
    expect(w.text()).not.toContain('Valor proposto')
    w.unmount()
  })
})
