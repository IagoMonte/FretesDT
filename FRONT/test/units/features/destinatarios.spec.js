import { mount, flushPromises } from '@vue/test-utils'
import UsersView from '../../../src/views/UsersView.vue'
import NotificationsView from '../../../src/views/NotificationsView.vue'
import { db, resetDb, saveUser } from '../../../src/stores/db'
import { GROUPS, GROUP_LABELS } from '../../../src/utils/notifications'
import { logout, loginAs } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok } from '../../helpers/fakeApi'
import { meAdmin } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const stubs = { global: { stubs: { teleport: true } } }
const usuario = (o = {}) => ({ id: 'u-1', nome: 'Ana', login: 'ana', email: 'ana@t.test', telefone: '19999990001', grupos: ['VENDEDOR'], transportadora_id: null, situacao: 'ATIVO', versao: 0, ...o })

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /usuarios', ok([usuario()]))
  api.on('GET /grupos', ok([{ codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' }]))
  api.on('GET /transportadoras', ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('destinatários — telefone do usuário e grupo Gestor', () => {
  it('a matriz de notificações tem o grupo Gestor', () => {
    expect(GROUPS).toContain('GESTOR')
    expect(GROUP_LABELS.GESTOR).toBe('Gestor')
  })

  it('saveUser envia o telefone só com dígitos (ou null quando vazio)', async () => {
    api.on('POST /usuarios', ({ body }) => ok(usuario({ ...body, id: 'u-2' }), {}, 201))
    db.groups = [{ codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' }]

    await saveUser({ nome: 'Bia', login: 'bia', senha: 'senha1234', grupos: ['VENDEDOR'], telefone: '(19) 99999-0002' })
    await saveUser({ nome: 'Caio', login: 'caio', senha: 'senha1234', grupos: ['VENDEDOR'], telefone: '' })

    const [a, b] = api.called('POST', '/usuarios')
    expect(a.body.telefone).toBe('19999990002')
    expect(b.body.telefone).toBeNull()
  })

  it('saveUser recusa telefone sem DDD antes de chamar o back', async () => {
    db.groups = [{ codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' }]

    const r = await saveUser({ nome: 'Bia', login: 'bia', senha: 'senha1234', grupos: ['VENDEDOR'], telefone: '9999' })

    expect(r.ok).toBe(false)
    expect(r.error).toContain('telefone')
    expect(api.called('POST', '/usuarios')).toHaveLength(0)
  })

  it('/users: a edição mostra o telefone formatado e a tabela lista a coluna Telefone', async () => {
    loginAs(createSession(meAdmin))
    const w = mount(UsersView, stubs)
    await flushPromises()

    expect(w.find('thead').text()).toContain('Telefone')
    expect(w.find('tbody').text()).toContain('(19) 99999-0001')
    await w.find('.ibtn.ed').trigger('click')
    expect(w.find('#us-tel').element.value).toBe('(19) 99999-0001')
    w.unmount()
  })

  it('/users: digitar no campo aplica a máscara e salvar manda só dígitos', async () => {
    loginAs(createSession(meAdmin))
    api.on('PATCH /usuarios/u-1', ({ body }) => ok(usuario({ ...body })))
    const w = mount(UsersView, stubs)
    await flushPromises()
    await w.find('.ibtn.ed').trigger('click')

    const tel = w.find('#us-tel')
    tel.element.value = '19988887777'
    await tel.trigger('input')
    await flushPromises()
    expect(w.find('#us-tel').element.value).toBe('(19) 98888-7777')
    await w.find('[data-act="save-user"]').trigger('click')
    await flushPromises()

    expect(api.called('PATCH', '/usuarios/u-1')[0].body.telefone).toBe('19988887777')
    w.unmount()
  })

  it('/notifications: o cabeçalho traz a coluna Gestor para cada canal', async () => {
    loginAs(createSession({ ...meAdmin, permissoes: { ...meAdmin.permissoes, NOTIFICACAO: { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' } } }))
    api.on('GET /notificacoes/preferencias', ok([{ evento: 'NEGOCIACAO_FECHADA', canal: 'EMAIL', grupo_codigo: 'GESTOR', ativo: true }]))
    api.on('GET /notificacoes/historico', ok([], { total: 0 }))
    const w = mount(NotificationsView, stubs)
    await flushPromises()

    expect(w.text().match(/Gestor/g)).toHaveLength(2)
    w.unmount()
  })
})
