import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount, flushPromises } from '@vue/test-utils'
import ModalHost from '../../../src/components/ModalHost.vue'
import { modal, openModal, closeModal } from '../../../src/stores/ui'
import { db, resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, meCarrier, domains, quote, resposta } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  resetDb()
  await logout()
  closeModal()
  api.on('GET /dominios', ok(domains))
})

const negociacao = (respostas, extra = {}) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null, ...extra })
const answered = (id, nome, frete, ton, prazo, extra = {}) => resposta({
  id, transportadora: { id: 't-' + id, nome_fantasia: nome }, status: 'RESPONDIDA', valor_tonelada: ton, valor_frete: frete, prazo_dias: prazo, ...extra,
})
const pending = (id, nome, extra = {}) => resposta({ id, transportadora: { id: 't-' + id, nome_fantasia: nome }, ...extra })

async function open(me, q, opts = {}) {
  loginAs(createSession(me))
  db.domains = domains
  db.carriers = []
  api.on('GET /cotacoes/:id', ok(q))
  api.on('GET /transportadoras', ok([]))
  db.quotes = [q]
  openModal('quote', { id: q.id, ...opts })
  const w = mount(ModalHost, { attachTo: document.body })
  await flushPromises()
  return w
}

const tres = () => quote({
  status: 'EM_NEGOCIACAO',
  negociacao: negociacao([
    pending('c', 'TELX'),
    answered('a', 'ARCO', '4860.00', '180.00', 3, { prazo_coleta_dias: 1, tipo_veiculo_codigo: 'VUC_VAN' }),
    answered('b', 'ROTA SUL', '4590.00', '170.00', 4, { prazo_coleta_dias: 2 }),
    pending('d', 'RAPIDO', { status: 'DECLINADA' }),
  ]),
})

describe('QuoteModal — respostas em linhas', () => {
  it('(1) usa ul.resp-list com um li[data-resp] por resposta e sem .resp-cards', async () => {
    const w = await open(meAdmin, tres())
    expect(w.find('ul.resp-list').exists()).toBe(true)
    expect(w.find('ul.resp-list').attributes('aria-label')).toBeTruthy()
    expect(w.findAll('ul.resp-list > li[data-resp]')).toHaveLength(4)
    expect(w.find('.resp-cards').exists()).toBe(false)
    w.unmount()
  })

  it('(2) ordena por menor valor; AGUARDANDO/DECLINADA por último, na ordem da API', async () => {
    const w = await open(meAdmin, tres())
    const nomes = w.findAll('[data-resp]').map((l) => l.find('.resp-nome').text())
    expect(nomes).toEqual(['ROTA SUL', 'ARCO', 'TELX', 'RAPIDO'])
    w.unmount()
  })

  it('(3) mostra coleta antes de entrega, com "—" quando nulo', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([
      answered('a', 'ARCO', '4860.00', '180.00', 3, { prazo_coleta_dias: 1 }),
      answered('b', 'ROTA SUL', '4900.00', '181.00', 4, { prazo_coleta_dias: null }),
    ]) })
    const w = await open(meAdmin, q)
    const [l1, l2] = w.findAll('[data-resp]')
    const t = l1.text()
    expect(t).toContain('Prazo de coleta')
    expect(t).toContain('Prazo de entrega')
    expect(t.indexOf('Prazo de coleta')).toBeLessThan(t.indexOf('Prazo de entrega'))
    expect(l1.find('[data-prazo-coleta]').text()).toContain('1')
    expect(l1.find('[data-prazo-entrega]').text()).toContain('3')
    expect(l2.find('[data-prazo-coleta]').text()).toContain('—')
    expect(l2.find('[data-prazo-entrega]').text()).toContain('4')
    expect(t).not.toMatch(/Prazo(?! de)/)
    w.unmount()
  })

  it('(4) valores: frete, por tonelada só se !fracionada, veículo novo', async () => {
    const w = await open(meAdmin, tres())
    const arco = w.findAll('[data-resp]')[1]
    expect(arco.text()).toContain('4.860,00')
    expect(arco.text()).toContain('por tonelada')
    expect(arco.text()).toContain('180,00')
    expect(arco.find('[data-veiculo]').exists()).toBe(true)
    expect(arco.find('[data-veiculo]').text().length).toBeGreaterThan(3)
    expect(arco.find('[data-veiculo]').text()).not.toContain('VUC_VAN')
    w.unmount()
    closeModal()
    const frac = tres()
    frac.fracionada = true
    const w2 = await open(meAdmin, frac)
    expect(w2.findAll('[data-resp]')[0].text()).not.toContain('por tonelada')
    w2.unmount()
  })

  it('(5) só a primeira RESPONDIDA é best/MENOR VALOR; DECLINADA fica dim', async () => {
    const w = await open(meAdmin, tres())
    const l = w.findAll('[data-resp]')
    expect(l[0].classes()).toContain('best')
    expect(l[0].text()).toContain('MENOR VALOR')
    expect(l[1].classes()).not.toContain('best')
    expect(l[1].text()).not.toContain('MENOR VALOR')
    expect(l[2].classes()).toContain('dim')
    expect(l[3].classes()).toContain('dim')
    expect(l[3].text()).toContain('DECLINADA')
    w.unmount()
  })

  it('(6) AGUARDANDO/DECLINADA mostram status e "encaminhada em", sem valores', async () => {
    const w = await open(meAdmin, tres())
    const ag = w.findAll('[data-resp]')[2]
    expect(ag.text()).toContain('AGUARDANDO')
    expect(ag.text()).toContain('encaminhada em 05/10')
    expect(ag.text()).not.toContain('R$')
    expect(ag.find('[data-veiculo]').exists()).toBe(false)
    const dec = w.findAll('[data-resp]')[3]
    expect(dec.text()).not.toContain('R$')
    w.unmount()
  })

  it('(7) ANTT na sublinha; piso_valido:false não esconde a linha', async () => {
    const antt = { piso: '4000.00', km: '120.0', eixos: 5, abaixo: false }
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([
      answered('a', 'ARCO', '4860.00', '180.00', 3, { antt, validade: '2030-01-01', observacao: 'obs x' }),
      answered('b', 'ROTA SUL', '4900.00', '181.00', 4, { antt, piso_valido: false }),
    ]) })
    const w = await open(meAdmin, q)
    const [a, b] = w.findAll('[data-resp]')
    expect(a.find('.resp-sub [data-antt]').exists()).toBe(true)
    expect(a.find('.resp-sub').text()).toContain('obs x')
    expect(b.find('[data-antt]').exists()).toBe(false)
    expect(b.text()).toContain('4.900,00')
    w.unmount()
  })

  it('(8) botões com aria-label nominal abrem o RespondModal certo; ausentes em REFERENCIADA/DECLINADA/readOnly', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([
      answered('a', 'ARCO', '4860.00', '180.00', 3),
      answered('b', 'ROTA SUL', '4590.00', '170.00', 4),
      answered('r', 'REF', '4000.00', '150.00', 4, { origem: 'REFERENCIADA', cotacao_codigo: 'COT-000009' }),
      pending('d', 'RAPIDO', { status: 'DECLINADA' }),
    ]) })
    const w = await open(meAdmin, q)
    const l = w.findAll('[data-resp]')
    const nomes = l.map((x) => x.find('.resp-nome').text())
    const ref = l[nomes.indexOf('REF')]
    const dec = l[nomes.indexOf('RAPIDO')]
    expect(ref.find('[data-act]').exists()).toBe(false)
    expect(dec.find('[data-act]').exists()).toBe(false)
    const via = l[nomes.indexOf('ROTA SUL')]
    expect(via.find('[data-act="answer-quote"]').attributes('aria-label')).toContain('ROTA SUL')
    expect(via.find('[data-act="decline-quote"]').attributes('aria-label')).toContain('ROTA SUL')
    await via.find('[data-act="answer-quote"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('#rf-b')).not.toBeNull()
    expect(document.querySelector('#rf-a')).toBeNull()
    w.unmount()
    closeModal()
    const w2 = await open(meAdmin, q, { readOnly: true })
    expect(w2.findAll('[data-resp] [data-act]')).toHaveLength(0)
    w2.unmount()
  })

  it('(9) transportadora não vê pill de referência; admin vê', async () => {
    const q = quote({ status: 'EM_NEGOCIACAO', negociacao: negociacao([
      answered('r', 'ARCO', '4000.00', '150.00', 4, { origem: 'REFERENCIADA', cotacao_codigo: 'COT-000009' }),
    ]) })
    const wa = await open(meAdmin, q)
    expect(wa.find('[data-resp] [data-ref]').text()).toContain('COT-000009')
    wa.unmount()
    closeModal()
    const wc = await open(meCarrier, q)
    expect(wc.findAll('[data-resp]')).toHaveLength(1)
    expect(wc.find('[data-ref]').exists()).toBe(false)
    wc.unmount()
  })

  it('(10) Fechamento continua .resp-card, com Prazo de coleta e de entrega', async () => {
    const q = quote({
      status: 'PROGRAMADA',
      negociacao: negociacao([], { status: 'FECHADA', fechamento: { transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, valor_tonelada: '175.00', valor_frete: '4725.00', prazo_dias: 3, prazo_coleta_dias: 2, observacao: null, fechado_em: '2026-10-05T15:00:00.000-03:00' } }),
    })
    const w = await open(meAdmin, q)
    const t = w.find('.resp-card').text()
    expect(t).toContain('Prazo de coleta')
    expect(t).toContain('Prazo de entrega')
    expect(t.indexOf('Prazo de coleta')).toBeLessThan(t.indexOf('Prazo de entrega'))
    expect(w.find('ul.resp-list').exists()).toBe(false)
    w.unmount()
  })

  it('(11) contrato de CSS', () => {
    const css = readFileSync(resolve(process.cwd(), 'src/styles/main.css'), 'utf8')
    expect(css).toContain('.resp-list')
    expect(css).not.toContain('.resp-cards')
  })
})
