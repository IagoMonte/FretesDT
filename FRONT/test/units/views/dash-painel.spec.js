import { mount, flushPromises } from '@vue/test-utils'
import DashView from '../../../src/views/DashView.vue'
import { resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, modal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meCarrier, domains, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const linha = (o = {}) => ({
  id: 'l-1', codigo: 'CRG-000001', data: '2026-11-20', deposito: 'FISICO', pedido: '25880', vendedor: 'LAUMER', produto: 'SODA 32%', kg: '35000.000',
  embalagem: 'GRANEL_LIQUIDO', cliente: 'CAMBUI', origem: 'Dumont - SP', destino: 'Santa Helena de Goiás - GO', frete: 'CIF',
  valor_tonelada: '300.00', valor_total: '10500.00', transportadora: 'TRDLOG', status: 'AGUARDANDO_CARREGAMENTO', prazo: 'ok', ...o,
})
const base = (extra = {}) => ({
  kpis: { cargas: 5, aguardando_carregamento: 1, em_transito: 1, entregues: 2, devolvidas: 1, no_prazo: 2, atrasadas: 2, pct_no_prazo: 50, cif: 4, fob: 1, total_frete: '15000.00', ticket_medio: '3000.00', volume_kg: '50000.000' },
  por_status: { EM_COTACAO: 0, AGUARDANDO_CARREGAMENTO: 1, EM_TRANSITO: 1, ENTREGUE: 2, DEVOLVIDO: 1 },
  cargas: { total: 2, pagina: 1, por_pagina: 25, total_kg: '45000.000', total_frete: '13500.00', itens: [linha(), linha({ id: 'l-2', codigo: 'CRG-000002', pedido: '25881', status: 'ENTREGUE', prazo: 'bad', kg: '10000.000', valor_total: '3000.00', transportadora: 'VIDEIRA' })] },
  opcoes: { anos: [2025, 2026], produtos: [{ id: 'p-1', nome: 'SODA 32%' }], embalagens: ['GRANEL_LIQUIDO'], origens: ['Dumont - SP'], destinos: ['Santa Helena de Goiás - GO'], transportadoras: [{ id: 't-1', nome: 'TRDLOG' }] },
  ...extra,
})
const painelOp = base({ ranking_transportadoras: [{ id: 't-1', nome: 'TRDLOG', pedidos: 3, total: '9000.00' }, { id: 't-2', nome: 'VIDEIRA', pedidos: 1, total: '5000.00' }] })
const painelTr = base({
  ranking_destinos: [{ destino: 'Campinas - SP', cargas: 2, total: '6000.00' }, { destino: 'Limeira - SP', cargas: 1, total: '3000.00' }],
  opcoes: { ...base().opcoes, transportadoras: undefined },
})

const mountView = async () => {
  const w = mount(DashView, { global: { stubs: { teleport: true } } })
  await flushPromises()
  return w
}
const lastToast = () => toasts.at(-1).parts.map((p) => p.text).join('')
const queryDaUltima = () => api.called('GET', '/painel').at(-1).query

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /cotacoes', ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('Painel Operacional — operador/administrador', () => {
  beforeEach(() => {
    loginAs(createSession(meAdmin))
    api.on('GET /painel', ok(painelOp))
  })

  it('mostra carregando e depois 5 KPIs com os valores da API', async () => {
    const w = mount(DashView)
    await w.vm.$nextTick()
    expect(w.find('.empty').text()).toContain('Carregando')
    await flushPromises()
    expect(w.findAll('.kpis .kpi')).toHaveLength(5)
    const t = w.find('.kpis').text()
    expect(t).toContain('R$ 15.000,00')
    expect(t).toContain('ticket médio R$ 3.000,00')
    expect(t).toContain('1 aguardando carregamento')
    expect(t).toContain('50 t')
    w.unmount()
  })

  it('tem os 8 filtros e cada mudança refaz a consulta com a query certa', async () => {
    const w = await mountView()
    expect(w.findAll('[data-filters] select').map((s) => s.attributes('data-flt'))).toEqual(['ano', 'mes', 'produto_id', 'embalagem', 'frete', 'transportadora_id', 'origem', 'destino'])
    await w.find('[data-flt="frete"]').setValue('FOB')
    await flushPromises()
    expect(queryDaUltima()).toMatchObject({ frete: 'FOB' })
    await w.find('[data-flt="ano"]').setValue('2026')
    await flushPromises()
    expect(queryDaUltima()).toMatchObject({ frete: 'FOB', ano: '2026' })
    await w.find('[data-act="limpar"]').trigger('click')
    await flushPromises()
    expect(queryDaUltima().frete).toBeFalsy()
    expect(lastToast()).toContain('Filtros')
  })

  it('pizza de status: legenda com contagens e clique filtra por status (de novo, limpa)', async () => {
    const w = await mountView()
    expect(w.findAll('[data-leg]').map((l) => l.attributes('data-leg'))).toEqual(['ENTREGUE', 'AGUARDANDO_CARREGAMENTO', 'EM_COTACAO', 'EM_TRANSITO', 'DEVOLVIDO'])
    expect(w.find('[data-leg="ENTREGUE"]').text()).toContain('2')
    await w.find('[data-leg="ENTREGUE"]').trigger('click')
    await flushPromises()
    expect(queryDaUltima()).toMatchObject({ status: 'ENTREGUE' })
    expect(w.find('[data-chip-status]').text()).toContain('ENTREGUE')
    await w.find('[data-leg="ENTREGUE"]').trigger('click')
    await flushPromises()
    expect(queryDaUltima().status).toBeFalsy()
  })

  it('pontualidade, CIF×FOB e ranking de transportadoras', async () => {
    const w = await mountView()
    const p = w.find('[data-pontualidade]').text()
    expect(p).toContain('50%')
    expect(p).toContain('2 no prazo · 2 atrasadas')
    expect(p).toContain('CIF 80%')
    const rows = w.findAll('[data-rank-transp]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('TRDLOG')
    expect(rows[1].text()).toContain('VIDEIRA')
    expect(rows[0].text()).toContain('R$ 9.000,00')
  })

  it('ranking de transportadoras: top 5 em lista (sem tabela nem rolagem horizontal), com posição, valor e clique que filtra', async () => {
    const sete = Array.from({ length: 7 }, (_, i) => ({ id: `t-${i + 1}`, nome: `TRANSP ${i + 1}`, pedidos: i + 1, total: `${(7 - i) * 1000}.00` }))
    const painel = base({ ranking_transportadoras: sete })
    api.on('GET /painel', ok(painel))
    const w = await mountView()
    const card = w.findAll('.card').find((c) => c.text().includes('Ranking transportadoras'))
    expect(card.find('table').exists()).toBe(false)
    expect(card.find('.tbl-wrap').exists()).toBe(false)
    const rows = card.findAll('[data-rank-transp]')
    expect(rows).toHaveLength(5)
    expect(rows[0].text()).toContain('1')
    expect(rows[0].text()).toContain('TRANSP 1')
    expect(rows[0].text()).toContain('R$ 7.000,00')
    expect(rows[0].text()).toContain('1 pedido')
    expect(rows[1].text()).toContain('2 pedidos')
    expect(card.find('.cnt').text()).toBe('top 5')
    api.on('GET /painel', ok(painel))
    await rows[2].trigger('click')
    await flushPromises()
    expect(api.called('GET', '/painel').at(-1).query?.transportadora_id ?? api.called('GET', '/painel').at(-1).url).toContain('t-3')
  })

  it('"Aguardando encaminhamento" lista só cotações ABERTA, com as ações do QuoteCard', async () => {
    api.on('GET /cotacoes', ok([quote({ id: 'q1', codigo: 'COT-000010' }), quote({ id: 'q2', codigo: 'COT-000011', status: 'EM_NEGOCIACAO' })]))
    const w = await mountView()
    const cards = w.findAll('[data-cotacoes] .qcard')
    expect(cards).toHaveLength(1)
    expect(cards[0].text()).toContain('COT-000010')
    expect(w.find('[data-cotacoes]').text()).not.toContain('COT-000011')
    expect(w.find('[data-cotacoes]').text()).toMatch(/Encaminhar/)
  })

  it('sem cotações abertas mostra estado vazio', async () => {
    const w = await mountView()
    expect(w.find('[data-cotacoes] .empty').text()).toContain('Nenhuma cotação aguardando encaminhamento')
  })

  it('tabela de cargas com totais, prazo e "Ver mais" pedindo mais linhas', async () => {
    api.on('GET /painel', ok(base({ ...painelOp, cargas: { ...painelOp.cargas, total: 60 } })))
    const w = await mountView()
    const rows = w.findAll('#dashRows tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('CRG-000001')
    expect(rows[0].text()).toContain('TRDLOG')
    expect(rows[1].text()).toContain('ATRASADO')
    expect(w.find('#dashFoot').text()).toContain('R$ 13.500,00')
    await w.find('[data-act="ver-mais"]').trigger('click')
    await flushPromises()
    expect(queryDaUltima()).toMatchObject({ por_pagina: 50 })
  })

  it('"Ver" abre o detalhe da carga', async () => {
    api.on('GET /programacoes', ok([load({ id: 'l-9', codigo: 'CRG-000001' })]))
    const w = await mountView()
    await w.find('[data-act="ver-carga"]').trigger('click')
    await flushPromises()
    expect(modal).toMatchObject({ open: true, kind: 'load', payload: { id: 'l-9' } })
  })

  it('falha mostra toast e estado indisponível', async () => {
    api.on('GET /painel', err(500, 'Falhou.'))
    const w = mount(DashView)
    await flushPromises()
    expect(w.find('.empty').text()).toContain('indisponível')
    expect(lastToast()).toBe('Falhou.')
  })
})

describe('Painel Operacional — transportadora', () => {
  beforeEach(() => {
    loginAs(createSession(meCarrier))
    api.on('GET /painel', ok(painelTr))
  })

  it('6 KPIs (inclui CIF/FOB) e tabela sem vendedor/transportadora', async () => {
    const w = await mountView()
    expect(w.findAll('.kpis .kpi')).toHaveLength(6)
    expect(w.find('.kpis').text()).toContain('1 devolvida')
    expect(w.find('table.tbl thead').text()).not.toContain('Vendedor')
    expect(w.find('table.tbl thead').text()).not.toContain('Transportadora')
  })

  it('filtros próprios: ano, mês, frete, produto, embalagem, origem, destino e status', async () => {
    const w = await mountView()
    expect(w.findAll('[data-filters] select').map((s) => s.attributes('data-flt'))).toEqual(['ano', 'mes', 'frete', 'produto_id', 'embalagem', 'origem', 'destino', 'status'])
    const status = w.find('[data-flt="status"]').findAll('option').map((o) => o.attributes('value'))
    expect(status).not.toContain('EM_COTACAO')
  })

  it('ranking de cidades: clique filtra pelo destino', async () => {
    const w = await mountView()
    const rows = w.findAll('[data-rank-dest]')
    expect(rows).toHaveLength(2)
    await rows[0].trigger('click')
    await flushPromises()
    expect(queryDaUltima()).toMatchObject({ destino: 'Campinas - SP' })
  })

  it('"Cotações aguardando resposta": só as que têm resposta AGUARDANDO dela, com Responder', async () => {
    const aguardando = quote({ id: 'q1', codigo: 'COT-000020', status: 'EM_NEGOCIACAO', negociacao: { id: 'n1', status: 'ABERTA', respostas: [resposta()], fechamento: null } })
    const respondida = quote({ id: 'q2', codigo: 'COT-000021', status: 'EM_NEGOCIACAO', negociacao: { id: 'n2', status: 'ABERTA', respostas: [resposta({ id: 'r2', status: 'RESPONDIDA', valor_tonelada: '100', valor_frete: '1000', prazo_dias: 2 })], fechamento: null } })
    api.on('GET /cotacoes', ok([aguardando, respondida]))
    const w = await mountView()
    const cards = w.findAll('[data-cotacoes] .qcard')
    expect(cards).toHaveLength(1)
    expect(cards[0].text()).toContain('COT-000020')
    expect(cards[0].text()).toMatch(/Responder/)
  })
})
