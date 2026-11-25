import { mount, flushPromises } from '@vue/test-utils'
import GroupsView from '../../../src/views/GroupsView.vue'
import { createAppRouter } from '../../../src/router/index'
import { createSession } from '../../../src/factories/sessionFactory'
import { db, resetDb, saveGroup, setGroupActive, loadGroupsAdmin, loadGroupCatalog } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { toasts, modal, closeModal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const ALL = { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' }
const adminGrupos = { ...meAdmin, permissoes: { ...meAdmin.permissoes, GRUPO: ALL } }

const catalogo = {
  recursos: [
    { codigo: 'COTACAO', nome: 'Cotação', modulo: 'OPERACAO', usa_escopo: true, acoes: [] },
    { codigo: 'NEGOCIACAO', nome: 'Negociação', modulo: 'OPERACAO', usa_escopo: true, acoes: [{ codigo: 'FECHAR', nome: 'Fechar negociação' }, { codigo: 'RESPONDER', nome: 'Responder (transportadora)' }] },
    { codigo: 'PROGRAMACAO', nome: 'Programação de cargas', modulo: 'OPERACAO', usa_escopo: true, acoes: [{ codigo: 'CONFIRMAR_ENTREGA', nome: 'Confirmar entrega' }] },
    { codigo: 'PRODUTO', nome: 'Produtos', modulo: 'CADASTRO', usa_escopo: false, acoes: [] },
  ],
  tipos_usuario: ['INTERNO', 'TRANSPORTADORA'],
  escopos: { INTERNO: ['PROPRIOS', 'TODOS'], TRANSPORTADORA: ['TRANSPORTADORA'] },
  recursos_transportadora: ['COTACAO', 'NEGOCIACAO', 'PROGRAMACAO'],
  acoes_transportadora: ['NEGOCIACAO.RESPONDER', 'PROGRAMACAO.CONFIRMAR_ENTREGA'],
}
const grupo = (o = {}) => ({
  id: 'g-1', codigo: 'CONSULTA', nome: 'Consulta de cotações', descricao: null, tipo_usuario: 'INTERNO', sistema: false, ativo: true, usuarios: 0,
  permissoes: { COTACAO: { ver: true, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' } }, acoes: [], versao: 0, ...o,
})
const sistema = grupo({ id: 'g-sis', codigo: 'VENDEDOR', nome: 'Vendedor', sistema: true, usuarios: 3 })

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /grupos/catalogo', ok(catalogo))
  api.on('GET /grupos/detalhes', ok([sistema, grupo()]))
  api.on('GET /grupos', ok([]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('T24: store', () => {
  it('saveGroup valida antes de chamar a API', async () => {
    const base = { codigo: 'LEITOR', nome: 'Leitor', descricao: '', tipo_usuario: 'INTERNO', perms: { COTACAO: { ver: true, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' } }, acoes: [] }
    for (const codigo of ['ab', 'com espaco', 'minusculo']) {
      const r = await saveGroup({ ...base, codigo })
      expect(r.ok, codigo).toBe(false)
      expect(r.error).toContain('código')
    }
    expect((await saveGroup({ ...base, nome: 'x' })).error).toContain('nome')
    expect((await saveGroup({ ...base, perms: { COTACAO: { ver: false, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' } } })).error).toContain('permissão')
    expect(api.calls).toHaveLength(0)
  })

  it('saveGroup envia só o que foi marcado (escopo apenas onde existe) e recarrega a lista de grupos dos usuários', async () => {
    db.groupCatalog = catalogo
    api.on('POST /grupos', ({ body }) => ok(grupo({ ...body, id: 'g-novo' }), {}, 201))
    const r = await saveGroup({
      codigo: 'LEITOR', nome: ' Leitor ', descricao: ' só lê ', tipo_usuario: 'INTERNO',
      perms: {
        COTACAO: { ver: true, inserir: true, alterar: false, cancelar: false, escopo: 'PROPRIOS' },
        PRODUTO: { ver: true, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' },
        NEGOCIACAO: { ver: false, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' },
      },
      acoes: ['NEGOCIACAO.FECHAR'],
    })
    expect(r.ok).toBe(true)
    expect(api.called('POST', '/grupos')[0].body).toEqual({
      codigo: 'LEITOR', nome: 'Leitor', descricao: 'só lê', tipo_usuario: 'INTERNO',
      permissoes: {
        COTACAO: { ver: true, inserir: true, alterar: false, cancelar: false, escopo: 'PROPRIOS' },
        PRODUTO: { ver: true, inserir: false, alterar: false, cancelar: false },
      },
      acoes: [],
    })
    expect(db.groupsAdmin.some((g) => g.id === 'g-novo')).toBe(true)
  })

  it('edição envia a versão e não manda código nem tipo', async () => {
    db.groupsAdmin = [grupo({ versao: 4 })]
    api.on('PATCH /grupos/:id', ({ body }) => ok(grupo({ ...body, versao: 5 })))
    await saveGroup({ codigo: 'CONSULTA', nome: 'Novo nome', descricao: '', tipo_usuario: 'INTERNO', perms: { COTACAO: { ver: true, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' } }, acoes: [] }, 'g-1')
    const body = api.called('PATCH', '/grupos/g-1')[0].body
    expect(body.versao).toBe(4)
    expect(body).not.toHaveProperty('codigo')
    expect(body).not.toHaveProperty('tipo_usuario')
    expect(db.groupsAdmin[0].versao).toBe(5)
  })

  it('setGroupActive e carregamentos', async () => {
    api.on('POST /grupos/:id/inativar', ({ params }) => ok(grupo({ id: params.id, ativo: false })))
    await loadGroupsAdmin()
    await loadGroupCatalog()
    expect(db.groupsAdmin).toHaveLength(2)
    expect(db.groupCatalog.recursos).toHaveLength(4)
    await setGroupActive('g-1', false)
    expect(db.groupsAdmin.find((g) => g.id === 'g-1').ativo).toBe(false)
  })
})

describe('T24: menu e rota', () => {
  it('"Grupos de Acesso" só aparece e só abre com a permissão GRUPO', async () => {
    expect(createSession(adminGrupos).menu.map((m) => m.key)).toContain('groups')
    expect(createSession(meAdmin).menu.map((m) => m.key)).not.toContain('groups')
    const router = createAppRouter(true)
    loginAs(createSession(meSeller))
    await router.push('/groups')
    expect(router.currentRoute.value.name).toBe('quotes')
    await logout()
    loginAs(createSession(adminGrupos))
    await router.push('/groups')
    expect(router.currentRoute.value.name).toBe('groups')
  })
})

describe('T24: GroupsView', () => {
  async function mountView() {
    loginAs(createSession(adminGrupos))
    const w = mount(GroupsView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }
  const lastToast = () => toasts.at(-1).parts.map((p) => p.text).join('')

  it('lista grupos de sistema (somente leitura) e grupos criados', async () => {
    const w = await mountView()
    const linhas = w.findAll('tbody tr')
    expect(linhas).toHaveLength(2)
    expect(linhas[0].text()).toContain('Vendedor')
    expect(linhas[0].text()).toContain('SISTEMA')
    expect(linhas[0].find('[data-act="edit-group"]').exists()).toBe(false)
    expect(linhas[0].find('[data-act="toggle-group"]').exists()).toBe(false)
    expect(linhas[0].find('[data-act="view-group"]').exists()).toBe(true)
    expect(linhas[1].find('[data-act="edit-group"]').exists()).toBe(true)
    expect(w.find('.card-h .cnt').text()).toBe('2 grupos')
  })

  it('cria um grupo marcando a matriz: inserir marca ver; desmarcar ver limpa o resto', async () => {
    api.on('POST /grupos', ({ body }) => ok(grupo({ ...body, id: 'g-novo', codigo: body.codigo }), {}, 201))
    const w = await mountView()
    await w.find('[data-act="new-group"]').trigger('click')
    await w.find('#gr-codigo').setValue('LEITOR')
    await w.find('#gr-nome').setValue('Leitor')

    await w.find('#gr-COTACAO-inserir').setValue(true)
    expect(w.find('#gr-COTACAO-ver').element.checked).toBe(true)
    await w.find('#gr-COTACAO-ver').setValue(false)
    expect(w.find('#gr-COTACAO-inserir').element.checked).toBe(false)

    await w.find('#gr-COTACAO-ver').setValue(true)
    await w.find('#gr-COTACAO-escopo').setValue('TODOS')
    await w.find('#gr-PRODUTO-ver').setValue(true)
    expect(w.find('#gr-PRODUTO-escopo').exists()).toBe(false)
    await w.find('[data-act="save-group"]').trigger('click')
    await flushPromises()

    const body = api.called('POST', '/grupos')[0].body
    expect(body.codigo).toBe('LEITOR')
    expect(body.permissoes.COTACAO).toEqual({ ver: true, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' })
    expect(body.permissoes.PRODUTO.ver).toBe(true)
    expect(lastToast()).toContain('Leitor')
    expect(toasts.at(-1).type).toBe('ok')
    expect(w.find('#gr-codigo').exists()).toBe(false)
  })

  it('ação só pode ser marcada com o recurso liberado para ver', async () => {
    const w = await mountView()
    await w.find('[data-act="new-group"]').trigger('click')
    const acao = w.find('#gr-NEGOCIACAO-ac-FECHAR')
    expect(acao.element.disabled).toBe(true)
    await w.find('#gr-NEGOCIACAO-ver').setValue(true)
    expect(w.find('#gr-NEGOCIACAO-ac-FECHAR').element.disabled).toBe(false)
    await w.find('#gr-NEGOCIACAO-ac-FECHAR').setValue(true)
    await w.find('#gr-NEGOCIACAO-ver').setValue(false)
    expect(w.find('#gr-NEGOCIACAO-ac-FECHAR').element.checked).toBe(false)
  })

  it('grupo de transportadora mostra só os recursos e o escopo dela', async () => {
    const w = await mountView()
    await w.find('[data-act="new-group"]').trigger('click')
    expect(w.find('#gr-PRODUTO-ver').exists()).toBe(true)
    await w.find('#gr-tipo').setValue('TRANSPORTADORA')
    expect(w.find('#gr-PRODUTO-ver').exists()).toBe(false)
    expect(w.find('#gr-COTACAO-ver').exists()).toBe(true)
    expect(w.find('#gr-COTACAO-escopo').exists()).toBe(false)
    expect(w.find('#gr-NEGOCIACAO-ac-FECHAR').exists()).toBe(false)
    expect(w.find('#gr-NEGOCIACAO-ac-RESPONDER').exists()).toBe(true)
  })

  it('erro da API vira toast e o modal continua aberto', async () => {
    api.on('POST /grupos', err(422, 'Já existe um grupo com este código.', { codigo: ['Já existe um grupo com este código.'] }))
    const w = await mountView()
    await w.find('[data-act="new-group"]').trigger('click')
    await w.find('#gr-codigo').setValue('LEITOR')
    await w.find('#gr-nome').setValue('Leitor')
    await w.find('#gr-COTACAO-ver').setValue(true)
    await w.find('[data-act="save-group"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(lastToast()).toContain('Já existe um grupo')
    expect(w.find('#gr-codigo').exists()).toBe(true)
  })

  it('validação do front mostra toast sem chamar a API', async () => {
    const w = await mountView()
    await w.find('[data-act="new-group"]').trigger('click')
    await w.find('#gr-codigo').setValue('ab')
    await w.find('[data-act="save-group"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(api.called('POST', '/grupos')).toHaveLength(0)
  })

  it('editar carrega a matriz do grupo, bloqueia código/tipo e envia a versão', async () => {
    api.on('PATCH /grupos/:id', ({ body }) => ok(grupo({ ...body, versao: 1 })))
    const w = await mountView()
    await w.findAll('tbody tr')[1].find('[data-act="edit-group"]').trigger('click')
    expect(w.find('#gr-codigo').element.value).toBe('CONSULTA')
    expect(w.find('#gr-codigo').element.disabled).toBe(true)
    expect(w.find('#gr-tipo').element.disabled).toBe(true)
    expect(w.find('#gr-COTACAO-ver').element.checked).toBe(true)
    await w.find('#gr-nome').setValue('Consulta geral')
    await w.find('[data-act="save-group"]').trigger('click')
    await flushPromises()
    expect(api.called('PATCH', '/grupos/g-1')[0].body).toMatchObject({ nome: 'Consulta geral', versao: 0 })
  })

  it('grupo de sistema abre só para leitura', async () => {
    const w = await mountView()
    await w.findAll('tbody tr')[0].find('[data-act="view-group"]').trigger('click')
    expect(w.find('#gr-nome').element.disabled).toBe(true)
    expect(w.find('[data-act="save-group"]').exists()).toBe(false)
  })

  it('inativar pede confirmação e mostra o erro quando há usuários no grupo', async () => {
    api.on('POST /grupos/:id/inativar', err(422, 'Há 2 usuários neste grupo. Mude o grupo deles antes de inativar.'))
    const w = await mountView()
    await w.findAll('tbody tr')[1].find('[data-act="toggle-group"]').trigger('click')
    expect(modal.kind).toBe('confirm')
    expect(api.called('POST', '/grupos/g-1/inativar')).toHaveLength(0)
    modal.payload.onYes()
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(lastToast()).toContain('2 usuários')
  })
})
