import { mount, flushPromises } from '@vue/test-utils'
import QuotesView from '../../../src/views/QuotesView.vue'
import QuoteSearchView from '../../../src/views/QuoteSearchView.vue'
import LoadsView from '../../../src/views/LoadsView.vue'
import ProductsView from '../../../src/views/ProductsView.vue'
import { db, resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, domains, quote, load, product } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const ERRO = 'Erro no servidor. Tente novamente.'

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

const montar = async (View, rota, lista) => {
  api.on(`GET ${rota}`, err(500, ERRO))
  loginAs(createSession(meAdmin))
  const w = mount(View, { global: { stubs: { teleport: true } } })
  await flushPromises()
  api.on(`GET ${rota}`, ok(lista))
  return w
}

describe.each([
  ['Cotações geradas', QuotesView, '/cotacoes', [quote({ id: 'q-1' })], 'Nenhuma cotação por aqui ainda'],
  ['Consultar cotação', QuoteSearchView, '/cotacoes', [quote({ id: 'q-1' })], 'Nenhuma cotação.'],
  ['Cargas programadas', LoadsView, '/programacoes', [load({ id: 'l-1' })], 'Nenhuma carga programada'],
  ['Produtos', ProductsView, '/produtos', [product({ id: 'p-1' })], 'Nenhum produto cadastrado'],
])('C9: %s', (_nome, View, rota, lista, vazio) => {
  it('mostra o erro com "Tentar de novo" no lugar do estado vazio, e o botão recarrega', async () => {
    const w = await montar(View, rota, lista)
    expect(w.text()).not.toContain(vazio)
    expect(w.text()).not.toContain('0 registros')
    expect(w.find('[data-act="retry-list"]').exists()).toBe(true)
    expect(w.text()).toContain('Não foi possível carregar')

    const antes = api.called('GET', rota).length
    await w.find('[data-act="retry-list"]').trigger('click')
    await flushPromises()
    expect(api.called('GET', rota).length).toBe(antes + 1)
    expect(w.find('[data-act="retry-list"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Não foi possível carregar')
  })
})
