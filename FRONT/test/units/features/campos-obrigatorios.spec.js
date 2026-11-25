import { mount, flushPromises } from '@vue/test-utils'
import ProductsView from '../../../src/views/ProductsView.vue'
import PackagingView from '../../../src/views/PackagingView.vue'
import CarriersView from '../../../src/views/CarriersView.vue'
import UsersView from '../../../src/views/UsersView.vue'
import { resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin, domains } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const stubs = { global: { stubs: { teleport: true } } }

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  for (const r of ['/produtos', '/embalagens', '/transportadoras', '/usuarios', '/grupos']) api.on(`GET ${r}`, ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  loginAs(createSession({ ...meAdmin, permissoes: { ...meAdmin.permissoes, EMBALAGEM: { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' } } }))
})

describe.each([
  ['produto', ProductsView, 'new-product', 'save-product', ['#pr-name']],
  ['embalagem', PackagingView, 'new-packaging', 'save-packaging', ['#pk-code', '#pk-desc']],
  ['transportadora', CarriersView, 'new-carrier', 'save-carrier', ['#cr-fantasia', '#cr-razao']],
  ['usuário', UsersView, 'new-user', 'save-user', ['#us-nome', '#us-login']],
])('C10: modal de %s', (_n, View, abrir, salvar, campos) => {
  it('nada marcado ao abrir; após tentar salvar vazio, os obrigatórios ficam inválidos; preencher limpa a marca', async () => {
    const w = mount(View, stubs)
    await flushPromises()
    await w.find(`[data-act="${abrir}"]`).trigger('click')
    for (const c of campos) expect(w.find(c).attributes('aria-invalid'), c).toBeUndefined()

    await w.find(`[data-act="${salvar}"]`).trigger('click')
    await flushPromises()
    for (const c of campos) {
      expect(w.find(c).attributes('aria-invalid'), c).toBe('true')
      expect(w.find(c).classes(), c).toContain('invalid')
    }

    await w.find(campos[0]).setValue('QA preenchido')
    expect(w.find(campos[0]).attributes('aria-invalid')).toBeUndefined()
  })
})
