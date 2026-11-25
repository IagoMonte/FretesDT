import { mount, flushPromises } from '@vue/test-utils'
import NotificationsView from '../../../src/views/NotificationsView.vue'
import {
  db, resetDb, loadNotifPrefs, saveNotifPrefs, loadNotifHistory, resendNotif, sendNotifTest,
} from '../../../src/stores/db'
import { e164, GROUPS, GROUP_LABELS } from '../../../src/utils/notifications'
import { createAppRouter } from '../../../src/router/index'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const ALL = { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' }
const adminNotif = {
  ...meAdmin,
  permissoes: { ...meAdmin.permissoes, NOTIFICACAO: ALL },
  acoes: [...meAdmin.acoes, 'NOTIFICACAO.REENVIAR', 'NOTIFICACAO.ENVIAR_TESTE'],
}

const matriz = () => [
  { evento: 'NEGOCIACAO_ENCAMINHADA', canal: 'WHATSAPP', grupo_codigo: 'TRANSPORTADORA', ativo: true },
  { evento: 'NEGOCIACAO_ENCAMINHADA', canal: 'EMAIL', grupo_codigo: 'TRANSPORTADORA', ativo: true },
  { evento: 'COTACAO_CANCELADA', canal: 'WHATSAPP', grupo_codigo: 'VENDEDOR', ativo: false },
  { evento: 'COTACAO_CANCELADA', canal: 'EMAIL', grupo_codigo: 'VENDEDOR', ativo: true },
]
const nt = (o = {}) => ({
  id: 'n-1', evento: 'NEGOCIACAO_ENCAMINHADA', entidade: 'NEGOCIACAO', registro_id: 'q-1', registro_codigo: 'COT-000001', canal: 'EMAIL',
  grupo_codigo: 'TRANSPORTADORA', destino_nome: 'TRANSLOG', endereco: 'translog@t.test', template: 'cotacao_disponivel', status: 'ENVIADO',
  tentativas: 1, erro: null, enviado_em: '2026-10-19T10:00:00.000-03:00', criado_em: '2026-10-19T10:00:00.000-03:00', ...o,
})
const carrier = (o = {}) => ({ id: 't-1', nome_fantasia: 'TRANSLOG', email: 'translog@t.test', telefone: '(19) 99999-0001', ativo: true, versao: 0, ...o })

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /notificacoes/preferencias', ok(matriz()))
  api.on('GET /notificacoes/historico', ok([nt()], { total: 1 }))
  api.on('GET /transportadoras', ok([carrier()]))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('utils — e164', () => {
  it('normaliza telefone brasileiro como o back-end', () => {
    expect(e164('(19) 99999-0001')).toBe('5519999990001')
    expect(e164('+55 19 99999-0001')).toBe('5519999990001')
    expect(e164('019 3333-4444')).toBe('551933334444')
    expect(e164('99999-0001')).toBeNull()
    expect(e164('')).toBeNull()
    expect(e164(null)).toBeNull()
  })
})

describe('db — notificações', () => {
  it('carrega a matriz e o histórico (com filtros, ignorando os vazios)', async () => {
    await loadNotifPrefs()
    expect(db.notifPrefs).toHaveLength(4)
    await loadNotifHistory({ status: 'FALHA', canal: '', evento: '' })
    expect(db.notifHistory).toHaveLength(1)
    expect(api.calls.at(-1).query).toEqual({ status: 'FALHA' })
  })

  it('saveNotifPrefs manda só o que mudou e substitui a matriz pela devolvida', async () => {
    await loadNotifPrefs()
    expect((await saveNotifPrefs([])).ok).toBe(false)
    const nova = matriz().map((l) => (l.evento === 'COTACAO_CANCELADA' && l.canal === 'WHATSAPP' ? { ...l, ativo: true } : l))
    api.on('POST /notificacoes/preferencias', ok(nova, { alertas: [] }))
    const r = await saveNotifPrefs([{ evento: 'COTACAO_CANCELADA', canal: 'WHATSAPP', grupo_codigo: 'VENDEDOR', ativo: true }])
    expect(r.ok).toBe(true)
    expect(api.calls.at(-1).body).toEqual({ preferencias: [{ evento: 'COTACAO_CANCELADA', canal: 'WHATSAPP', grupo_codigo: 'VENDEDOR', ativo: true }] })
    expect(db.notifPrefs.find((l) => l.evento === 'COTACAO_CANCELADA' && l.canal === 'WHATSAPP').ativo).toBe(true)
  })

  it('sendNotifTest valida o destino antes de chamar a API e avisa o modo simulado', async () => {
    expect((await sendNotifTest({ canal: 'EMAIL', destino: 'sem-arroba' })).ok).toBe(false)
    expect((await sendNotifTest({ canal: 'WHATSAPP', destino: '123' })).ok).toBe(false)
    expect(api.calls).toHaveLength(0)

    api.on('POST /notificacoes/teste', ok(nt({ evento: 'TESTE', canal: 'WHATSAPP' }), { alertas: ['WhatsApp em modo simulado (driver log): nada foi enviado de verdade.'] }))
    const r = await sendNotifTest({ canal: 'WHATSAPP', destino: '(19) 99999-0001' })
    expect(r.ok).toBe(true)
    expect(api.calls[0].body).toEqual({ canal: 'WHATSAPP', destino: '(19) 99999-0001' })
    expect(toasts.at(-1).type).toBe('warn')
    expect(db.notifHistory.some((n) => n.evento === 'TESTE')).toBe(true)
  })

  it('resendNotif troca a linha do histórico pela devolvida', async () => {
    db.notifHistory = [nt({ status: 'FALHA', erro: 'smtp fora' })]
    api.on('POST /notificacoes/:id/reenviar', ({ params }) => ok(nt({ id: params.id, status: 'ENVIADO' })))
    const r = await resendNotif('n-1')
    expect(r.ok).toBe(true)
    expect(db.notifHistory[0].status).toBe('ENVIADO')
  })
})

describe('menu e rota', () => {
  it('só quem tem o recurso NOTIFICACAO vê o menu e acessa /notificacoes', async () => {
    expect(createSession(adminNotif).menu.map((m) => m.key)).toContain('notifications')
    expect(createSession(meAdmin).menu.map((m) => m.key)).not.toContain('notifications')
    expect(createSession(meSeller).menu.map((m) => m.key)).not.toContain('notifications')

    loginAs(createSession(adminNotif))
    const router = createAppRouter(true)
    await router.push('/notificacoes')
    expect(router.currentRoute.value.name).toBe('notifications')

    loginAs(createSession(meSeller))
    await router.push('/quotes')
    await router.push('/notificacoes')
    expect(router.currentRoute.value.name).not.toBe('notifications')
  })
})

describe('NotificationsView', () => {
  const montar = async () => {
    loginAs(createSession(adminNotif))
    const w = mount(NotificationsView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }

  it('matriz: mostra só as combinações da API e salva apenas o que mudou, com toast de sucesso', async () => {
    const w = await montar()
    expect(w.findAll('[data-pref]')).toHaveLength(4)
    const box = w.find('[data-pref="COTACAO_CANCELADA|WHATSAPP|VENDEDOR"]')
    expect(box.element.checked).toBe(false)
    expect(w.find('[data-act="save-prefs"]').attributes('disabled')).toBeDefined()

    await box.setValue(true)
    api.on('POST /notificacoes/preferencias', ok(matriz().map((l) => (l.evento === 'COTACAO_CANCELADA' && l.canal === 'WHATSAPP' ? { ...l, ativo: true } : l)), { alertas: [] }))
    await w.find('[data-act="save-prefs"]').trigger('click')
    await flushPromises()

    expect(api.calls.at(-1).body.preferencias).toEqual([{ evento: 'COTACAO_CANCELADA', canal: 'WHATSAPP', grupo_codigo: 'VENDEDOR', ativo: true }])
    expect(toasts.at(-1).type).toBe('ok')
    expect(toasts.at(-1).parts.some((p) => p.text.includes('Preferências salvas'))).toBe(true)
  })

  it('matriz: erro do servidor vira toast de erro e mantém a alteração na tela', async () => {
    const w = await montar()
    await w.find('[data-pref="COTACAO_CANCELADA|WHATSAPP|VENDEDOR"]').setValue(true)
    api.on('POST /notificacoes/preferencias', err(422, 'Combinação inválida.'))
    await w.find('[data-act="save-prefs"]').trigger('click')
    await flushPromises()

    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
    expect(w.find('[data-pref="COTACAO_CANCELADA|WHATSAPP|VENDEDOR"]').element.checked).toBe(true)
  })

  it('histórico: lista, filtra por status e avisa por toast quando há falhas', async () => {
    api.on('GET /notificacoes/historico', ({ query }) => ok(query.status === 'FALHA'
      ? [nt({ id: 'n-2', status: 'FALHA', erro: 'smtp fora' })]
      : [nt(), nt({ id: 'n-2', status: 'FALHA', erro: 'smtp fora' })], { total: 2 }))
    const w = await montar()
    await w.find('[data-tab="historico"]').trigger('click')
    await flushPromises()

    expect(w.findAll('[data-notif]')).toHaveLength(2)
    expect(w.find('[data-notif="n-2"]').text()).toContain('smtp fora')
    expect(toasts.some((t) => t.type === 'warn' && t.parts.some((p) => p.text.includes('falha')))).toBe(true)

    await w.find('[data-flt="status"]').setValue('FALHA')
    await flushPromises()
    expect(api.calls.at(-1).query).toMatchObject({ status: 'FALHA' })
    expect(w.findAll('[data-notif]')).toHaveLength(1)
  })

  it('histórico: reenviar uma falha mostra toast de sucesso; se continuar falhando, toast de erro', async () => {
    api.on('GET /notificacoes/historico', ok([nt({ id: 'n-2', status: 'FALHA', erro: 'smtp fora' })], { total: 1 }))
    const w = await montar()
    await w.find('[data-tab="historico"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-notif="n-2"] [data-act="resend"]').exists()).toBe(true)

    api.on('POST /notificacoes/:id/reenviar', ({ params }) => ok(nt({ id: params.id, status: 'ENVIADO' })))
    await w.find('[data-notif="n-2"] [data-act="resend"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1)).toMatchObject({ type: 'ok' })
    expect(w.find('[data-notif="n-2"] [data-act="resend"]').exists()).toBe(false)

    db.notifHistory = [nt({ id: 'n-3', status: 'FALHA', erro: 'smtp fora' })]
    await flushPromises()
    api.on('POST /notificacoes/:id/reenviar', ({ params }) => ok(nt({ id: params.id, status: 'FALHA', erro: 'ainda fora' })))
    await w.find('[data-notif="n-3"] [data-act="resend"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
  })

  it('histórico: quem enviou com sucesso não tem botão de reenviar', async () => {
    const w = await montar()
    await w.find('[data-tab="historico"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-act="resend"]').exists()).toBe(false)
  })

  it('teste: e-mail enviado mostra toast ok; erro de validação mostra toast de erro sem chamar a API', async () => {
    const w = await montar()
    await w.find('[data-tab="historico"]').trigger('click')
    await w.find('#nt-canal').setValue('EMAIL')
    await w.find('#nt-destino').setValue('invalido')
    await w.find('[data-act="send-test"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
    expect(api.called('POST', '/notificacoes/teste')).toHaveLength(0)

    api.on('POST /notificacoes/teste', ok(nt({ id: 'n-9', evento: 'TESTE', status: 'ENVIADO' }), { alertas: [] }))
    await w.find('#nt-destino').setValue('quem@example.test')
    await w.find('[data-act="send-test"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1)).toMatchObject({ type: 'ok' })
  })

  it('teste: WhatsApp simulado avisa (warn) e falha de envio vira toast de erro', async () => {
    const w = await montar()
    await w.find('[data-tab="historico"]').trigger('click')
    await w.find('#nt-canal').setValue('WHATSAPP')
    await w.find('#nt-destino').setValue('(19) 99999-0001')
    api.on('POST /notificacoes/teste', ok(nt({ id: 'n-9', evento: 'TESTE', canal: 'WHATSAPP', status: 'ENVIADO' }), { alertas: ['WhatsApp em modo simulado (driver log): nada foi enviado de verdade.'] }))
    await w.find('[data-act="send-test"]').trigger('click')
    await flushPromises()
    expect(toasts.some((t) => t.type === 'warn' && t.parts.some((p) => p.text.includes('simulado')))).toBe(true)

    api.on('POST /notificacoes/teste', ok(nt({ id: 'n-10', evento: 'TESTE', canal: 'WHATSAPP', status: 'FALHA', erro: 'token inválido' }), { alertas: [] }))
    await w.find('[data-act="send-test"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
    expect(toasts.at(-1).parts.some((p) => p.text.includes('token inválido'))).toBe(true)
  })

  it('contatos: mostra se cada transportadora tem WhatsApp válido e e-mail', async () => {
    api.on('GET /transportadoras', ok([carrier(), carrier({ id: 't-2', nome_fantasia: 'ROTA SUL', telefone: '123', email: null })]))
    const w = await montar()
    await w.find('[data-tab="contatos"]').trigger('click')
    await flushPromises()

    const ok1 = w.find('[data-carrier="t-1"]').text()
    expect(ok1).toContain('5519999990001')
    expect(ok1).toContain('translog@t.test')
    const ruim = w.find('[data-carrier="t-2"]')
    expect(ruim.text()).toContain('Sem WhatsApp válido')
    expect(ruim.text()).toContain('Sem e-mail')
  })

  it('matriz: o grupo de WhatsApp da transportadora aparece só na coluna de WhatsApp', async () => {
    api.on('GET /notificacoes/preferencias', ok([
      ...matriz(),
      { evento: 'COTACAO_CANCELADA', canal: 'WHATSAPP', grupo_codigo: 'GRUPO_TRANSPORTADORA', ativo: true },
    ]))
    const w = await montar()

    expect(GROUPS).toContain('GRUPO_TRANSPORTADORA')
    expect(GROUP_LABELS.GRUPO_TRANSPORTADORA).toBe('Grupo da transportadora')
    expect(w.find('[data-pref="COTACAO_CANCELADA|WHATSAPP|GRUPO_TRANSPORTADORA"]').exists()).toBe(true)
    expect(w.findAll('thead th').filter((th) => th.text() === 'Grupo da transportadora')).toHaveLength(1)
  })

  it('contatos: mostra o ID do grupo de WhatsApp de cada transportadora', async () => {
    api.on('GET /transportadoras', ok([carrier({ whatsapp_grupo_id: '120363123456789012@g.us' }), carrier({ id: 't-2', nome_fantasia: 'ROTA SUL' })]))
    const w = await montar()
    await w.find('[data-tab="contatos"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-carrier="t-1"]').text()).toContain('120363123456789012@g.us')
    expect(w.find('[data-carrier="t-2"]').text()).toContain('Sem grupo')
  })
})
