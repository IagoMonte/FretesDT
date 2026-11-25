import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, reactive } from 'vue'
import { usePreviousQuote } from '../../../src/composables/usePreviousQuote'

const { find } = vi.hoisted(() => ({ find: vi.fn() }))
vi.mock('../../../src/stores/db', () => ({ findPreviousQuote: find }))

const novo = (itens) => reactive({
  veiculo: 'CARRETA', origem: 'Paulínia - SP', destino: 'Campinas - SP', usarAnterior: false, itens,
})
const item = (produto_id, embalagem = 'GRANEL_LIQUIDO') => ({ produto_id, embalagem })

function monta(form) {
  let api
  mount(defineComponent({ setup() { api = usePreviousQuote(form, { delay: 0 }); return () => h('div') } }))
  return api
}
const espera = async () => { await nextTick(); await new Promise((r) => setTimeout(r, 5)); await nextTick() }

beforeEach(() => { find.mockReset(); find.mockResolvedValue({ id: 'c1' }) })

describe('usePreviousQuote', () => {
  it('envia os ids dos produtos na consulta', async () => {
    const form = novo([item('p1')])
    const { previous } = monta(form)
    form.itens.push(item('p2'))
    await espera()
    expect(find).toHaveBeenLastCalledWith(expect.objectContaining({ produtoIds: ['p1', 'p2'] }))
    expect(previous.value).toEqual({ id: 'c1' })
    expect(form.usarAnterior).toBe(true)
  })

  it('trocar o produto refaz a consulta', async () => {
    const form = novo([item('p1')])
    monta(form)
    form.itens[0].embalagem = 'GRANEL_LIQUIDO'
    form.veiculo = 'TRUCK'
    await espera()
    form.veiculo = 'CARRETA'
    form.itens[0].produto_id = 'p9'
    await espera()
    expect(find).toHaveBeenLastCalledWith(expect.objectContaining({ produtoIds: ['p9'] }))
  })

  it('item sem produto não consulta', async () => {
    const form = novo([item('')])
    const { previous } = monta(form)
    form.veiculo = 'TRUCK'
    await espera()
    expect(find).not.toHaveBeenCalled()
    expect(previous.value).toBeNull()
  })
})
