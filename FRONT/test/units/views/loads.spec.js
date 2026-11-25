import { mount, flushPromises } from '@vue/test-utils'
import LoadsView from '../../../src/views/LoadsView.vue'
import { db, resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, modal } from '../../../src/stores/ui'
import { api, ok, err } from '../../helpers/fakeApi'
import { digitaParte } from '../../helpers/digita'
import { meAdmin, meSeller, meCarrier, domains, product, carrier, parte, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const loads = () => [
  load({ id: 'l-1', codigo: 'CRG-000001', status: 'AGUARDANDO_CARREGAMENTO' }),
  load({ id: 'l-2', codigo: 'CRG-000002', status: 'EM_TRANSITO', pedido_venda: 'PV-1002', transportadora: { id: 't-v', nome_fantasia: 'ROTA SUL' }, representante: { id: 'u-lucia', nome: 'LUCIA' }, parte: parte({ razao_social: 'BETA PAULÍNIA' }) }),
  load({ id: 'l-3', codigo: 'CRG-000003', status: 'ENTREGUE', data_entrega_realizada: '2026-10-09', entregue_no_prazo: false }),
  load({ id: 'l-4', codigo: 'CRG-000004', status: 'CANCELADO', transportadora: null, valor_tonelada: null, valor_total: null }),
]

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  api.on('GET /produtos', ok([product(), product({ id: 'p-hcl', nome: 'HCL', embalagens: ['GRANEL_LIQUIDO'] })]))
  api.on('GET /transportadoras', ok([carrier(), carrier({ id: 't-v', nome_fantasia: 'ROTA SUL' })]))
  api.on('GET /vendedores', ok([{ id: 'u-marcos', nome: 'MARCOS' }, { id: 'u-lucia', nome: 'LUCIA' }]))
  api.on('GET /programacoes', ok(loads()))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

async function mountAs(me, list = loads()) {
  api.on('GET /programacoes', ok(list))
  loginAs(createSession(me))
  const w = mount(LoadsView, { global: { stubs: { teleport: true } } })
  await flushPromises()
  return w
}
const rowOf = (w, code) => w.findAll('#loadRows tr').find((r) => r.text().includes(code))
const acts = (row) => row.findAll('.act button').map((b) => b.attributes('data-act') || b.attributes('title'))

describe('T21: contador de /loads acompanha o filtro', () => {
  it('sem filtro "4 registros"; filtrando por status "N de 4 registros"; singular com 1', async () => {
    const w = await mountAs(meAdmin)
    expect(w.find('.card-h .cnt').text()).toBe('4 registros')
    await w.find('#flt-status').setValue('EM_TRANSITO')
    expect(w.find('.card-h .cnt').text()).toMatch(/^\d+ de 4 registros$|^1 de 4 registros$/)
    await w.find('#flt-txt').setValue('CRG-000001')
    expect(w.find('.card-h .cnt').text()).toMatch(/de 4 registros$/)
  })
})

describe('T15: carregamento da lista', () => {
  it('enquanto a API não responde mostra "Carregando…" em vez de "0 registros" falso', async () => {
    let soltar
    api.on('GET /programacoes', () => new Promise((res) => { soltar = () => res(ok(loads())) }))
    loginAs(createSession(meAdmin))
    const w = mount(LoadsView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(w.find('.card-h .cnt').text()).toBe('Carregando…')
    expect(w.text()).not.toContain('0 registros')
    soltar()
    await flushPromises()
    expect(w.find('.card-h .cnt').text()).toBe('4 registros')
  })
})

describe('LoadsView — lista (admin)', () => {
  it('carrega /programacoes, mostra mais novas primeiro com campos do contrato formatados', async () => {
    const w = await mountAs(meAdmin)
    const rows = w.findAll('#loadRows tr')
    expect(rows).toHaveLength(4)
    expect(rows[0].text()).toContain('CRG-000004')
    const r = rowOf(w, 'CRG-000001')
    expect(r.text()).toContain('PV-1001')
    expect(r.text()).toContain('ALFAQUIM INDÚSTRIA QUÍMICA')
    expect(r.text()).toContain('SODA CÁUSTICA')
    expect(r.text()).toContain('20.000')
    expect(r.text()).toContain('R$ 175,00')
    expect(r.text()).toContain('R$ 3.500,00')
    expect(r.text()).toContain('06/10/2026')
    expect(r.text()).toContain('PAULÍNIA - SP')
    expect(r.find('.pill').text()).toBe('CARREGAMENTO')
    expect(w.find('.card-h .cnt').text()).toBe('4 registros')
  })
  it('status com rótulo, prazo calculado pelo back e transportadora a definir', async () => {
    const w = await mountAs(meAdmin)
    expect(rowOf(w, 'CRG-000002').text()).toContain('EM TRÂNSITO')
    expect(rowOf(w, 'CRG-000003').find('.prz-bad').text()).toBe('ATRASADO')
    expect(rowOf(w, 'CRG-000001').find('.prz-no').exists()).toBe(true)
    expect(rowOf(w, 'CRG-000004').text()).toContain('a definir')
  })
  it('filtros por texto, status e transportadora', async () => {
    const w = await mountAs(meAdmin)
    await w.find('#flt-txt').setValue('beta')
    expect(w.findAll('#loadRows tr')).toHaveLength(1)
    await w.find('#flt-txt').setValue('')
    await w.find('#flt-status').setValue('ENTREGUE')
    expect(w.findAll('#loadRows tr').map((r) => r.text().slice(0, 40)).join()).toContain('CRG-000003')
    expect(w.findAll('#loadRows tr')).toHaveLength(1)
    await w.find('#flt-status').setValue('')
    await w.find('#flt-transp').setValue('ROTA SUL')
    expect(w.findAll('#loadRows tr')).toHaveLength(1)
    await w.find('#flt-txt').setValue('zzz')
    await w.find('#flt-transp').setValue('')
    expect(w.find('.empty').text()).toContain('Nenhuma carga')
  })
  it('ações por status: editar/trânsito/cancelar, entregar, devolver', async () => {
    const w = await mountAs(meAdmin)
    expect(acts(rowOf(w, 'CRG-000001'))).toEqual(['Editar', 'transit-load', 'cancel-load'])
    expect(acts(rowOf(w, 'CRG-000002'))).toEqual(['Editar', 'deliver-load', 'cancel-load'])
    expect(acts(rowOf(w, 'CRG-000003'))).toEqual(['Editar', 'return-load'])
    expect(acts(rowOf(w, 'CRG-000004'))).toEqual([])
  })
})

async function confirmar() {
  expect(modal).toMatchObject({ open: true, kind: 'confirm' })
  modal.payload.onYes()
  await flushPromises()
}

describe('LoadsView — transições', () => {
  it('em trânsito e entrega só acontecem depois da confirmação; cancelar o diálogo não chama a API', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/status', ({ params, body }) => ok(load({ id: params.id, codigo: 'CRG-000001', status: body.status })))
    await rowOf(w, 'CRG-000001').find('[data-act="transit-load"]').trigger('click')
    expect(modal).toMatchObject({ open: true, kind: 'confirm' })
    expect(modal.payload.message).toContain('CRG-000001')
    expect(api.called('POST', '/programacoes/l-1/status')).toHaveLength(0)
    closeModal()
    await flushPromises()
    expect(api.called('POST', '/programacoes/l-1/status')).toHaveLength(0)
    await rowOf(w, 'CRG-000002').find('[data-act="deliver-load"]').trigger('click')
    expect(modal).toMatchObject({ open: true, kind: 'confirm' })
    expect(modal.payload.message).toContain('CRG-000002')
    expect(api.called('POST', '/programacoes/l-2/entregar')).toHaveLength(0)
  })
  it('em trânsito: POST status com a data e atualiza a linha', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/status', ({ params, body }) => ok(load({ id: params.id, codigo: 'CRG-000001', status: body.status })))
    await rowOf(w, 'CRG-000001').find('[data-act="transit-load"]').trigger('click')
    await confirmar()
    const call = api.called('POST', '/programacoes/l-1/status')[0]
    expect(call.body.status).toBe('EM_TRANSITO')
    expect(call.body.data).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(rowOf(w, 'CRG-000001').text()).toContain('EM TRÂNSITO')
    expect(toasts.at(-1).type).toBe('info')
  })
  it('entregar no prazo: toast NO PRAZO', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/entregar', ({ params, body }) => ok(load({ id: params.id, codigo: 'CRG-000002', status: 'ENTREGUE', data_entrega_realizada: '2026-10-08', entregue_em: '2026-10-08T14:05:00.000-03:00', entregue_no_prazo: true })))
    await rowOf(w, 'CRG-000002').find('[data-act="deliver-load"]').trigger('click')
    await confirmar()
    expect(api.called('POST', '/programacoes/l-2/entregar')[0].body).toEqual({})
    expect(toasts.at(-1).type).toBe('ok')
    expect(toasts.at(-1).parts.some((p) => p.bold && p.text.includes('NO PRAZO'))).toBe(true)
    expect(rowOf(w, 'CRG-000002').find('.prz-ok').exists()).toBe(true)
  })
  it('entregar atrasado: toast de aviso', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/entregar', ({ params }) => ok(load({ id: params.id, codigo: 'CRG-000002', status: 'ENTREGUE', data_entrega_realizada: '2026-10-20', entregue_no_prazo: false })))
    await rowOf(w, 'CRG-000002').find('[data-act="deliver-load"]').trigger('click')
    await confirmar()
    expect(toasts.at(-1).type).toBe('warn')
    expect(toasts.at(-1).parts.some((p) => p.bold && p.text.includes('ATRASADO'))).toBe(true)
  })
  it('transição inválida (422) vira toast de erro e mantém o status', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/status', err(422, 'Transição de status inválida.'))
    await rowOf(w, 'CRG-000001').find('[data-act="transit-load"]').trigger('click')
    await confirmar()
    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
    expect(rowOf(w, 'CRG-000001').text()).toContain('AGUARDANDO CARREGAMENTO')
  })
  it('cancelar pede o motivo e envia', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/cancelar', ({ params }) => ok(load({ id: params.id, codigo: 'CRG-000001', status: 'CANCELADO' })))
    await rowOf(w, 'CRG-000001').find('[data-act="cancel-load"]').trigger('click')
    await w.find('[data-act="confirm-motivo"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/programacoes/l-1/cancelar')).toHaveLength(0)
    await w.find('#mt-motivo').setValue('pedido duplicado')
    await w.find('[data-act="confirm-motivo"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/programacoes/l-1/cancelar')[0].body).toEqual({ motivo: 'pedido duplicado' })
    expect(rowOf(w, 'CRG-000001').text()).toContain('CANCELADO')
    expect(w.find('#mt-motivo').exists()).toBe(false)
  })
  it('devolver confirma e usa /status DEVOLVIDO', async () => {
    const w = await mountAs(meAdmin)
    api.on('POST /programacoes/:id/status', ({ params, body }) => ok(load({ id: params.id, codigo: 'CRG-000003', status: body.status })))
    await rowOf(w, 'CRG-000003').find('[data-act="return-load"]').trigger('click')
    expect(modal).toMatchObject({ open: true, kind: 'confirm' })
    modal.payload.onYes()
    await flushPromises()
    expect(api.called('POST', '/programacoes/l-3/status')[0].body.status).toBe('DEVOLVIDO')
  })
})

describe('LoadsView — perfis', () => {
  it('transportadora: faixa, sem nova programação/edição, marca em trânsito e entregue', async () => {
    const w = await mountAs(meCarrier, [loads()[0], loads()[1], loads()[2]])
    expect(w.find('.note-strip').text()).toContain('ARCO')
    expect(w.find('[data-act="new-load"]').exists()).toBe(false)
    expect(w.find('#flt-transp').exists()).toBe(false)
    expect(acts(rowOf(w, 'CRG-000001'))).toEqual(['transit-load'])
    expect(acts(rowOf(w, 'CRG-000002'))).toEqual(['deliver-load'])
    expect(rowOf(w, 'CRG-000003').find('.prz-ok').attributes('aria-label')).toBe('Entregue')
    expect(api.called('GET', '/vendedores')).toHaveLength(0)
  })
  it('vendedor: somente leitura (olho + toast)', async () => {
    const w = await mountAs(meSeller)
    expect(w.find('.note-strip').text()).toContain('somente leitura')
    expect(w.find('[data-act="new-load"]').exists()).toBe(false)
    const eye = rowOf(w, 'CRG-000001').find('.ibtn.ey')
    expect(eye.exists()).toBe(true)
    await eye.trigger('click')
    expect(toasts.at(-1).parts.map((p) => p.text).join('')).toContain('somente leitura')
  })
})

describe('LoadsView — formulário (admin)', () => {
  const fillCommon = async (w) => {
    await digitaParte(w, '#lp-parte', 'super')
    await flushPromises()
    await w.find('[data-parte]').trigger('mousedown')
    await w.find('#lp-prod-0').setValue('p-soda')
    await w.find('#lp-qtd-0').setValue('20000')
  }

  it('nova programação: itens, representante, parte, transportadora, valor total calculado, payload do contrato', async () => {
    const w = await mountAs(meAdmin, [])
    api.on('GET /partes', ok([parte()]))
    await w.find('[data-act="new-load"]').trigger('click')
    expect(w.findAll('#lp-rep option').map((o) => o.text())).toEqual(['—', 'MARCOS', 'LUCIA'])
    await fillCommon(w)
    await w.find('#lp-rep').setValue('u-marcos')
    await w.find('#lp-transp').setValue('t-arco')
    await w.find('#lp-veiculo').setValue('CARRETA')
    await w.find('#lp-vton').setValue('175')
    expect(w.find('#lp-vtotal').element.value.replace(/\s/g, ' ')).toBe('R$ 3.500,00')
    await w.find('#lp-prazo').setValue('3')
    await w.find('#lp-pedido').setValue('PV-2000')
    await w.find('#lp-cepO').setValue('Paulínia - SP')
    await w.find('#lp-cepD').setValue('Campinas - SP')
    api.on('POST /programacoes', ok(load({ id: 'l-new', codigo: 'CRG-000009' }), {}, 201))
    await w.find('[data-act="save-load"]').trigger('click')
    await flushPromises()
    const body = api.called('POST', '/programacoes')[0].body
    expect(body).toMatchObject({
      operacao: 'CARREGAMENTO', pedido_venda: 'PV-2000', representante_id: 'u-marcos', parte: { tipo: 'CLIENTE', id: 'c-1' },
      transportadora_id: 't-arco', tipo_veiculo_codigo: 'CARRETA', valor_tonelada: '175.00', prazo_dias: 3, modalidade_frete: 'CIF', deposito: 'VIRTUAL',
      origem: { cidade: 'PAULÍNIA', uf: 'SP', ibge: null },
    })
    expect(body.itens).toEqual([{ produto_id: 'p-soda', embalagem_codigo: 'BIG_BAG', quantidade_kg: '20000.000', volumes: null }])
    expect(w.find('#lp-data').exists()).toBe(false)
    expect(db.loads[0].id).toBe('l-new')
    expect(toasts.at(-1).parts.find((p) => p.bold).text).toBe('CRG-000009')
  })
  it('meta.alertas (quantidade acima da cotada) aparece em toast de aviso', async () => {
    const w = await mountAs(meAdmin, [])
    api.on('GET /partes', ok([parte()]))
    await w.find('[data-act="new-load"]').trigger('click')
    await fillCommon(w)
    await w.find('#lp-pedido').setValue('PV-1')
    api.on('POST /programacoes', ok(load({ id: 'l-new' }), { alertas: ['Quantidade da carga excede a cotada em 2.000 kg.'] }, 201))
    await w.find('[data-act="save-load"]').trigger('click')
    await flushPromises()
    const msgs = toasts.map((t) => ({ type: t.type, text: t.parts.map((p) => p.text).join('') }))
    expect(msgs).toContainEqual({ type: 'warn', text: 'Quantidade da carga excede a cotada em 2.000 kg.' })
  })
  it('sem cliente/itens: erro e formulário aberto', async () => {
    const w = await mountAs(meAdmin, [])
    await w.find('[data-act="new-load"]').trigger('click')
    await w.find('[data-act="save-load"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(w.find('#lp-data').exists()).toBe(true)
  })
  it('editar carrega os dados e envia PATCH com versao; 409 mostra o erro', async () => {
    const w = await mountAs(meAdmin, [load({ versao: 4 })])
    await rowOf(w, 'CRG-000001').find('.ibtn.ed').trigger('click')
    expect(w.find('.modal-h h3').text()).toContain('CRG-000001')
    expect(w.find('#lp-pedido').element.value).toBe('PV-1001')
    expect(w.find('#lp-vton').element.value).toBe('175,00')
    expect(w.find('#lp-qtd-0').element.value).toBe('20.000')
    expect(w.find('#lp-parte').element.value).toBe('ALFAQUIM INDÚSTRIA QUÍMICA')
    expect(w.find('.chosen-tag').exists()).toBe(true)
    api.on('PATCH /programacoes/:id', err(409, 'Registro alterado por outro usuário.'))
    await w.find('#lp-pedido').setValue('PV-9')
    await w.find('[data-act="save-load"]').trigger('click')
    await flushPromises()
    const body = api.called('PATCH', '/programacoes/l-1')[0].body
    expect(body).toMatchObject({ versao: 4, pedido_venda: 'PV-9' })
    expect(toasts.at(-1)).toMatchObject({ type: 'err' })
    expect(toasts.at(-1).parts[0].text).toBe('Registro alterado por outro usuário.')
    expect(w.find('#lp-pedido').exists()).toBe(true)
  })
})

describe('C8: busca só com espaços equivale a busca vazia', () => {
  it('/loads lista tudo e não marca filtro ativo', async () => {
    const w = await mountAs(meAdmin)
    await w.find('#flt-txt').setValue('   ')
    expect(w.findAll('#loadRows tr[data-load-row], #loadRows tr').filter((r) => r.text().includes('CRG-')).length).toBe(4)
    expect(w.find('.card-h .cnt').text()).toBe('4 registros')
  })
})
