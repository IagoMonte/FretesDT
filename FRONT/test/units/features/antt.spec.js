import { mount, flushPromises } from '@vue/test-utils'
import { setFracionadaCodes } from '../../../src/utils/items'
import AnttInfo from '../../../src/components/AnttInfo.vue'
import AnttView from '../../../src/views/AnttView.vue'
import { db, resetDb, answerQuote, addQuote, closeQuote, findDistance, saveAnttCoef } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, meSeller, domains, quote } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const ALL = { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' }
const adminAntt = { ...meAdmin, permissoes: { ...meAdmin.permissoes, ANTT: ALL } }
const tipos = [{ codigo: 'CARGA_GERAL', descricao: 'Carga geral' }, { codigo: 'PERIGOSA', descricao: 'Carga perigosa' }]

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('AnttInfo — comparação com o frete mínimo', () => {
  const antt = (o = {}) => ({ piso: '1000.00', km: '100.0', eixos: 5, diferenca: '-40.00', abaixo: true, ...o })

  it('mostra "abaixo do piso" com a diferença em R$ e %', () => {
    const w = mount(AnttInfo, { props: { antt: antt() } })
    expect(w.text()).toContain('Abaixo do frete mínimo ANTT')
    expect(w.find('[data-antt-dif]').text()).toMatch(/−R\$\s40,00 \(4%\)/)
    expect(w.classes()).toContain('abaixo')
  })
  it('mostra "dentro do piso" quando a diferença é positiva', () => {
    const w = mount(AnttInfo, { props: { antt: antt({ diferenca: '120.00', abaixo: false }) } })
    expect(w.text()).toContain('Acima do frete mínimo ANTT')
    expect(w.find('[data-antt-dif]').text()).toMatch(/^\+R\$\s120,00/)
  })
  it('sem `antt` (transportadora ou resposta sem eixos/km) não renderiza nada', () => {
    expect(mount(AnttInfo, { props: { antt: null } }).find('[data-antt]').exists()).toBe(false)
  })
})

describe('db — payloads do piso ANTT', () => {
  it('answerQuote envia os eixos só quando informados', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/respostas/:r/responder', ({ params }) => ok(quote({ id: params.id })))
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', eixos: '5' })
    expect(api.calls[0].body.eixos).toBe(5)
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3' })
    expect(api.calls[1].body).not.toHaveProperty('eixos')
  })
  it('answerQuote envia o tipo de carga escolhido', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/respostas/:r/responder', ({ params }) => ok(quote({ id: params.id })))
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', eixos: '5', tipoCarga: 'PERIGOSA' })
    expect(api.calls[0].body.tipo_carga_antt_codigo).toBe('PERIGOSA')
  })
  it('answerQuote envia dedicado só quando marcado', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/respostas/:r/responder', ({ params }) => ok(quote({ id: params.id })))
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', dedicado: true })
    expect(api.calls[0].body.dedicado).toBe(true)
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3' })
    expect(api.calls[1].body).not.toHaveProperty('dedicado')
  })
  it('addQuote nunca envia dedicado (é da resposta da transportadora)', async () => {
    api.on('POST /cotacoes', ok(quote()))
    setFracionadaCodes([{ codigo: 'BOMBONA', fracionada: true, granel: false }])
    const base = { operacao: 'CARREGAMENTO', parte: { tipo: 'CLIENTE', id: 'c-1' }, veiculo: 'CARRETA', valorNF: '1.000,00', origem: 'Paulínia - SP', destino: 'Campinas - SP', itens: [{ produto_id: 'p', embalagem: 'BOMBONA', qtd: '1.000' }] }
    await addQuote({ ...base, dedicado: true })
    expect(api.calls[0].body).not.toHaveProperty('dedicado')
    setFracionadaCodes([])
  })
  it('closeQuote envia os eixos', async () => {
    db.quotes = [quote({ status: 'EM_NEGOCIACAO' })]
    api.on('POST /cotacoes/:id/fechar', ({ params }) => ok(quote({ id: params.id }), { programacao: {} }))
    await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '1', prazo: '1', eixos: '6' })
    expect(api.calls[0].body.eixos).toBe(6)
  })
  it('addQuote envia tipo de carga e km (pt-BR -> decimal)', async () => {
    api.on('POST /cotacoes', ok(quote()))
    await addQuote({
      operacao: 'CARREGAMENTO', parte: { tipo: 'CLIENTE', id: 'c-1' }, veiculo: 'CARRETA', valorNF: '1.000,00',
      origem: 'Paulínia - SP', destino: 'Campinas - SP', itens: [{ produto_id: 'p', embalagem: 'BOMBONA', qtd: '1.000' }],
      tipoCarga: 'CARGA_GERAL', km: '1.234,5',
    })
    expect(api.calls[0].body).toMatchObject({ tipo_carga_antt_codigo: 'CARGA_GERAL', distancia_km: '1234.5' })
  })
  it('findDistance devolve o km, ou null sem par/erro e sem cidade válida', async () => {
    api.on('GET /distancias/consulta', ({ query }) => ok(query.destino_cidade === 'CAMPINAS' ? { km: '23.4' } : null))
    expect(await findDistance('Paulínia - SP', 'Campinas - SP')).toBe('23.4')
    expect(await findDistance('Paulínia - SP', 'Outra - SP')).toBeNull()
    expect(await findDistance('Paulínia', 'Campinas - SP')).toBeNull()
    expect(api.calls).toHaveLength(2)
  })
  it('saveAnttCoef lê o CCD com ponto ou vírgula e valida', async () => {
    api.on('POST /antt/coeficientes', ({ body }) => ok({ id: 'a-1', ...body, ativo: true, versao: 0 }, {}, 201))
    expect((await saveAnttCoef({ tipo_carga_codigo: '', eixos: '', ccd: '1' })).ok).toBe(false)
    await saveAnttCoef({ tipo_carga_codigo: 'CARGA_GERAL', eixos: '5', ccd: '3.9826', cc: '451,84', vigencia_inicio: '2026-01-01' })
    expect(api.calls[0].body).toMatchObject({ eixos: 5, ccd: '3.9826', cc: '451.84', vigencia_inicio: '2026-01-01', vigencia_fim: null })
    await saveAnttCoef({ tipo_carga_codigo: 'CARGA_GERAL', eixos: '5', ccd: '4,5', vigencia_inicio: '2026-07-01' })
    expect(api.calls[1].body.ccd).toBe('4.5000')
  })
})

describe('menu e rota', () => {
  it('só quem tem o recurso ANTT vê o menu', () => {
    expect(createSession(adminAntt).menu.map((m) => m.key)).toContain('antt')
    expect(createSession(meSeller).menu.map((m) => m.key)).not.toContain('antt')
  })
})

describe('AnttView — admin', () => {
  it('lista os coeficientes e consulta o frete mínimo', async () => {
    api.on('GET /dominios', ok({ ...domains, tipos_carga_antt: tipos }))
    api.on('GET /antt/coeficientes', ok([{ id: 'a-1', tipo_carga_codigo: 'CARGA_GERAL', eixos: 5, ccd: '3.9826', cc: '451.84', vigencia_inicio: '2026-01-01', vigencia_fim: null, ativo: true, versao: 0 }]))
    api.on('GET /antt/frete-minimo', ok({ km: '23.4', eixos: 5, ccd: '3.9826', cc: '451.84', frete_minimo: '545.03' }))
    loginAs(createSession(adminAntt))
    const w = mount(AnttView, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const row = w.find('[data-coef]')
    expect(row.text()).toContain('Carga geral')
    expect(row.text()).toContain('3,9826')
    expect(row.text()).toContain('em aberto')

    await w.find('#an-o').setValue('Paulínia - SP')
    await w.find('#an-d').setValue('Campinas - SP')
    await w.find('#an-q-eixos').setValue('5')
    await w.find('#an-q-tipo').setValue('CARGA_GERAL')
    await w.find('[data-act="consulta-frete"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-frete-minimo]').text()).toContain('23,4 km')
  })
})
