import { mount, flushPromises } from '@vue/test-utils'
import QuotesView from '../../../src/views/QuotesView.vue'
import { resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, product, quote, resposta } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const umItem = [{ id: 'i-1', sequencia: 1, produto: { id: 'p-soda', nome: 'SODA CÁUSTICA' }, embalagem_codigo: 'BIG_BAG', quantidade_kg: '12000.000', volumes: 10, paletizado: false, remontado: false }]
const aberta = (o = {}) => quote({ id: 'q-1', codigo: 'COT-000001', itens: umItem, observacao: 'antiga', versao: 3, ...o })
const texto = (t) => t.parts.map((p) => p.text).join('')
const neg = (respostas) => ({ id: 'n-1', status: 'ABERTA', respostas, fechamento: null })

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok([product()]))
  api.on('GET /distancias', ok(null))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

async function mountAs(me, quotes) {
  api.on('GET /cotacoes', ok(quotes))
  loginAs(createSession(me))
  const w = mount(QuotesView, { global: { stubs: { teleport: true } } })
  await flushPromises()
  return w
}

describe('editar cotação', () => {
  it('só a cotação ABERTA tem o botão Editar (depois de encaminhada não edita)', async () => {
    const w = await mountAs(meAdmin, [
      aberta(),
      quote({ id: 'q-2', codigo: 'COT-000002', status: 'EM_NEGOCIACAO', negociacao: neg([resposta()]) }),
      quote({ id: 'q-3', codigo: 'COT-000003', status: 'CANCELADA' }),
    ])
    const tem = (code) => w.findAll('.qcard').find((c) => c.text().includes(code)).find('[data-act="edit-quote"]').exists()
    expect(tem('COT-000001')).toBe(true)
    expect(tem('COT-000002')).toBe(false)
    expect(tem('COT-000003')).toBe(false)
  })

  it('segue a matriz do grupo: sem COTACAO.alterar não aparece; transportadora nunca vê', async () => {
    const semAlterar = { ...meSeller, permissoes: { ...meSeller.permissoes, COTACAO: { ver: true, inserir: true, alterar: false, cancelar: true, escopo: 'PROPRIOS' } } }
    const w1 = await mountAs(semAlterar, [aberta()])
    expect(w1.find('[data-act="edit-quote"]').exists()).toBe(false)
    const w2 = await mountAs(meSeller, [aberta()])
    expect(w2.find('[data-act="edit-quote"]').exists()).toBe(true)
    const w3 = await mountAs(meCarrier, [aberta({ status: 'EM_NEGOCIACAO' })])
    expect(w3.find('[data-act="edit-quote"]').exists()).toBe(false)
  })

  it('abre o formulário preenchido com os dados da cotação', async () => {
    const w = await mountAs(meAdmin, [aberta()])
    await w.find('[data-act="edit-quote"]').trigger('click')
    await flushPromises()
    expect(w.find('.modal-h h3').text()).toContain('COT-000001')
    expect(w.find('#qc-obs').element.value).toBe('antiga')
    expect(w.find('#qc-origem').element.value).toBe('PAULÍNIA - SP')
    expect(w.find('#qc-destino').element.value).toBe('CAMPINAS - SP')
    expect(w.find('#qc-veiculo').element.value).toBe('CARRETA')
    expect(w.find('[data-use-prev]').exists()).toBe(false)
  })

  it('salva por PATCH com a versão, sem usar_anterior, e avisa por toast', async () => {
    const w = await mountAs(meAdmin, [aberta()])
    api.on('PATCH /cotacoes/:id', ({ params, body }) => ok(aberta({ id: params.id, observacao: body.observacao, versao: 4 })))
    await w.find('[data-act="edit-quote"]').trigger('click')
    await flushPromises()
    await w.find('#qc-obs').setValue('nova obs')
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    const [c] = api.called('PATCH', '/cotacoes/q-1')
    expect(c.body).toMatchObject({ versao: 3, observacao: 'nova obs', operacao: 'CARREGAMENTO', tipo_veiculo_codigo: 'CARRETA' })
    expect(c.body.usar_anterior).toBeUndefined()
    expect(api.called('POST', '/cotacoes')).toHaveLength(0)
    expect(toasts.some((t) => t.type === 'ok' && texto(t).includes('COT-000001'))).toBe(true)
    expect(w.find('[role="dialog"]').exists()).toBe(false)
  })

  it('erro da API (ex.: já encaminhada) vira toast e mantém o formulário aberto', async () => {
    const w = await mountAs(meAdmin, [aberta()])
    api.on('PATCH /cotacoes/:id', err(422, 'Só é possível editar cotações que ainda não foram encaminhadas.'))
    await w.find('[data-act="edit-quote"]').trigger('click')
    await flushPromises()
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(toasts.some((t) => t.type === 'err' && texto(t).includes('não foram encaminhadas'))).toBe(true)
    expect(w.find('[role="dialog"]').exists()).toBe(true)
  })

  it('409 (versão velha) recarrega a cotação', async () => {
    const w = await mountAs(meAdmin, [aberta()])
    api.on('PATCH /cotacoes/:id', err(409, 'Registro alterado por outra pessoa.'))
    await w.find('[data-act="edit-quote"]').trigger('click')
    await flushPromises()
    await w.find('[data-act="save-quote"]').trigger('click')
    await flushPromises()
    expect(api.called('GET', '/cotacoes/q-1').length).toBeGreaterThan(0)
  })
})
