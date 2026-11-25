import { mount, flushPromises } from '@vue/test-utils'
import PackagingView from '../../../src/views/PackagingView.vue'
import ProductsView from '../../../src/views/ProductsView.vue'
import ItemsEditor from '../../../src/components/ItemsEditor.vue'
import QuoteModal from '../../../src/components/QuoteModal.vue'
import { createAppRouter } from '../../../src/router/index'
import { db, resetDb } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { toasts, closeModal, openModal, modal } from '../../../src/stores/ui'
import { blankItem, setFracionadaCodes } from '../../../src/utils/items'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meSeller, meCarrier, domains, product, quote } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const ALLP = { ver: true, inserir: true, alterar: true, cancelar: true, escopo: 'TODOS' }
const adminPack = { ...meAdmin, permissoes: { ...meAdmin.permissoes, EMBALAGEM: ALLP } }
const sellerPack = { ...meSeller, permissoes: { ...meSeller.permissoes, EMBALAGEM: { ver: true, inserir: false, alterar: false, cancelar: false, escopo: 'TODOS' } } }
const stubs = { global: { stubs: { teleport: true } } }
const pack = (o = {}) => ({ codigo: 'SACARIA', descricao: 'Sacaria', permite_paletizar: true, permite_remontar: false, ativo: true, versao: 0, ...o })
const lastToast = () => toasts.at(-1).parts.map((p) => p.text).join('')

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
  loginAs(createSession(adminPack))
})

const mountPk = async () => {
  const w = mount(PackagingView, stubs)
  await flushPromises()
  return w
}

describe('PackagingView', () => {
  it('lista com permissões e situação', async () => {
    api.on('GET /embalagens', ok([pack(), pack({ codigo: 'TUBO', descricao: 'Tubo', permite_paletizar: false, ativo: false, versao: 3 })]))
    const w = await mountPk()
    const rows = w.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Paletizável')
    expect(rows[0].text()).not.toContain('Remontável')
    expect(rows[0].text()).toContain('ATIVO')
    expect(rows[1].classes()).toContain('off')
    expect(rows[1].text()).toContain('INATIVO')
    expect(w.find('.card-h .cnt').text()).toBe('2 itens')
  })
  it('T21: marcar Granel desmarca Fracionada (não fica marcada e desabilitada)', async () => {
    api.on('GET /embalagens', ok([]))
    const w = await mountPk()
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('[data-f="fracionada"]').setValue(true)
    expect(w.find('[data-f="fracionada"]').element.checked).toBe(true)
    await w.find('[data-f="granel"]').setValue(true)
    expect(w.find('[data-f="fracionada"]').element.checked).toBe(false)
    expect(w.find('[data-f="fracionada"]').element.disabled).toBe(true)
  })
  it('incluir: código em maiúsculas, flags no POST e domínios recarregados', async () => {
    api.on('GET /embalagens', ok([]))
    api.on('POST /embalagens', ({ body }) => ok(pack(body), {}, 201))
    const w = await mountPk()
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('#pk-code').setValue('caixa_2')
    await w.find('#pk-desc').setValue('  Caixa  ')
    await w.findAll('.item-flags input')[2].setValue(true)
    const before = api.called('GET', '/dominios').length
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/embalagens')[0].body).toEqual({ codigo: 'CAIXA_2', descricao: 'Caixa', permite_paletizar: true, permite_remontar: false, granel: false, fracionada: false })
    expect(api.called('GET', '/dominios').length).toBeGreaterThan(before)
    expect(w.findAll('tbody tr')).toHaveLength(1)
    expect(lastToast()).toBe('Embalagem Caixa incluída com sucesso.')
    expect(w.find('#pk-code').exists()).toBe(false)
  })

  it('granel desabilita paletizado/remontado e é enviado no POST', async () => {
    api.on('GET /embalagens', ok([]))
    api.on('POST /embalagens', ({ body }) => ok(pack(body), {}, 201))
    const w = await mountPk()
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('#pk-code').setValue('graneleiro')
    await w.find('#pk-desc').setValue('Graneleiro')
    await w.findAll('.item-flags input')[1].setValue(true)
    await w.find('[data-f="granel"]').setValue(true)
    expect(w.findAll('.item-flags input').slice(1).every((i) => i.element.disabled)).toBe(true)
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/embalagens')[0].body).toEqual({ codigo: 'GRANELEIRO', descricao: 'Graneleiro', permite_paletizar: false, permite_remontar: false, granel: true, fracionada: false })
  })
  it('validação local: código inválido ou descrição vazia não chamam a API', async () => {
    api.on('GET /embalagens', ok([]))
    const w = await mountPk()
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    await w.find('#pk-code').setValue('com espaco')
    await w.find('#pk-desc').setValue('X')
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    await w.find('#pk-code').setValue('OK')
    await w.find('#pk-desc').setValue('   ')
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/embalagens')).toHaveLength(0)
    expect(w.find('#pk-code').exists()).toBe(true)
  })
  it('fracionada é enviada no POST e some com granel', async () => {
    api.on('GET /embalagens', ok([]))
    api.on('POST /embalagens', ({ body }) => ok(pack(body), {}, 201))
    const w = await mountPk()
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('#pk-code').setValue('FRAC')
    await w.find('#pk-desc').setValue('Fracionada')
    await w.find('[data-f="fracionada"]').setValue(true)
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/embalagens')[0].body.fracionada).toBe(true)
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('[data-f="granel"]').setValue(true)
    expect(w.find('[data-f="fracionada"]').element.disabled).toBe(true)
  })
  it('erro da API (422) vira toast e mantém o formulário', async () => {
    api.on('GET /embalagens', ok([]))
    api.on('POST /embalagens', err(422, 'O código já existe.'))
    const w = await mountPk()
    await w.find('[data-act="new-packaging"]').trigger('click')
    await w.find('#pk-code').setValue('SACARIA')
    await w.find('#pk-desc').setValue('Sacaria')
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(toasts.at(-1).type).toBe('err')
    expect(w.find('#pk-code').exists()).toBe(true)
  })
  it('editar: código travado, PATCH por código com versao', async () => {
    api.on('GET /embalagens', ok([pack({ versao: 4 })]))
    api.on('PATCH /embalagens/:codigo', ({ body }) => ok(pack({ ...body, versao: 5 })))
    const w = await mountPk()
    await w.find('.ibtn.ed').trigger('click')
    expect(w.find('#pk-code').element.value).toBe('SACARIA')
    expect(w.find('#pk-code').element.disabled).toBe(true)
    expect(w.find('#pk-desc').element.value).toBe('Sacaria')
    expect(w.findAll('.item-flags input').map((i) => i.element.checked)).toEqual([false, false, true, false])
    await w.find('#pk-desc').setValue('Sacaria 25kg')
    await w.findAll('.item-flags input')[3].setValue(true)
    await w.find('[data-act="save-packaging"]').trigger('click')
    await flushPromises()
    expect(api.called('PATCH', '/embalagens/SACARIA')[0].body).toEqual({ descricao: 'Sacaria 25kg', permite_paletizar: true, permite_remontar: true, granel: false, fracionada: false, versao: 4 })
    expect(db.packagings[0].versao).toBe(5)
    expect(lastToast()).toBe('Embalagem Sacaria 25kg atualizada.')
  })
  it('inativar pede confirmação e ativar volta; usa o código na URL', async () => {
    api.on('GET /embalagens', ok([pack()]))
    api.on('POST /embalagens/:codigo/inativar', ok(pack({ ativo: false })))
    api.on('POST /embalagens/:codigo/ativar', ok(pack({ ativo: true })))
    const w = await mountPk()
    await w.find('.ibtn.dl').trigger('click')
    expect(modal).toMatchObject({ open: true, kind: 'confirm' })
    expect(modal.payload).toMatchObject({ title: 'Confirmar inativação', label: 'Inativar' })
    modal.payload.onYes()
    await flushPromises()
    expect(api.called('POST', '/embalagens/SACARIA/inativar')).toHaveLength(1)
    expect(w.find('tbody tr').classes()).toContain('off')
    await w.find('.ibtn.ok').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/embalagens/SACARIA/ativar')).toHaveLength(1)
    expect(w.find('tbody tr').classes()).not.toContain('off')
  })
  it('somente leitura: sem incluir nem ações', async () => {
    loginAs(createSession(sellerPack))
    api.on('GET /embalagens', ok([pack()]))
    const w = await mountPk()
    expect(w.find('[data-act="new-packaging"]').exists()).toBe(false)
    expect(w.find('.ibtn').exists()).toBe(false)
  })
})

describe('menu e rota de embalagens por permissão', () => {
  it('menu: admin com EMBALAGEM vê "Cadastro Embalagens" depois de produtos; sem permissão não vê', () => {
    expect(createSession(adminPack).menu.map((m) => m.key)).toEqual(['dash', 'quotes', 'products', 'packagings', 'carriers', 'users', 'registrations', 'loads', 'quote-search'])
    expect(createSession(adminPack).menu.find((m) => m.key === 'packagings').label).toBe('Cadastro Embalagens')
    expect(createSession(meAdmin).menu.map((m) => m.key)).not.toContain('packagings')
    expect(createSession(meCarrier).menu.map((m) => m.key)).not.toContain('packagings')
  })
  it('rota: com EMBALAGEM.ver entra; sem, cai na home do perfil', async () => {
    const router = createAppRouter(true)
    await router.push('/login')
    loginAs(createSession(adminPack))
    await router.push('/packagings')
    expect(router.currentRoute.value.name).toBe('packagings')
    loginAs(createSession(meSeller))
    await router.push('/quotes')
    await router.push('/packagings')
    expect(router.currentRoute.value.name).toBe('quotes')
  })
})

describe('ItemsEditor: paletizado/remontado', () => {
  const mountEd = (item, props = {}) => mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), produto_id: 'p-a', embalagem: 'BOMBONA', ...item }], ...props } })
  const flagLabels = (w) => w.findAll('.item-flags label').map((l) => l.text())
  beforeEach(() => {
    db.products = [product({ id: 'p-a', nome: 'A', embalagens: ['BIG_BAG', 'BOMBONA', 'GRANEL_LIQUIDO'] })]
    db.domains = domains
  })

  it('checkboxes aparecem só conforme o que a embalagem permite', () => {
    expect(flagLabels(mountEd({ embalagem: 'BOMBONA' }))).toEqual(['Paletizado', 'Remontado'])
    expect(flagLabels(mountEd({ embalagem: 'BIG_BAG' }))).toEqual(['Paletizado'])
    expect(mountEd({ embalagem: 'GRANEL_LIQUIDO' }).find('.item-flags').exists()).toBe(false)
  })
  it('marcar emite paletizado/remontado', async () => {
    const w = mountEd({ embalagem: 'BOMBONA' })
    await w.findAll('.item-flags input')[0].setValue(true)
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ paletizado: true, remontado: false })
    await w.findAll('.item-flags input')[1].setValue(true)
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ remontado: true })
  })
  it('trocar a embalagem zera o que a nova não permite e mantém o permitido', async () => {
    const w = mountEd({ embalagem: 'BOMBONA', paletizado: true, remontado: true })
    await w.find('#it-emb-0').setValue('BIG_BAG')
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ embalagem: 'BIG_BAG', paletizado: true, remontado: false })
    const g = mountEd({ embalagem: 'BOMBONA', paletizado: true, remontado: true })
    await g.find('#it-emb-0').setValue('GRANEL_LIQUIDO')
    expect(g.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ embalagem: 'GRANEL_LIQUIDO', paletizado: false, remontado: false })
  })
  it('C4: trocar para granel com mais de 1 item pede confirmação; cancelar mantém os itens', async () => {
    closeModal()
    const dois = [{ ...blankItem(), produto_id: 'p-a', embalagem: 'BOMBONA', qtd: '1.000' }, { ...blankItem(), produto_id: 'p-a', embalagem: 'BIG_BAG', qtd: '500' }]
    const w = mount(ItemsEditor, { props: { modelValue: dois } })
    await w.find('#it-emb-1').setValue('GRANEL_LIQUIDO')
    expect(w.emitted('update:modelValue')).toBeUndefined()
    expect(modal.open && modal.kind).toBe('confirm')
    expect(modal.payload.message).toMatch(/outros itens/)
    expect(w.find('#it-emb-1').element.value).toBe('BIG_BAG')
    closeModal()
    expect(w.emitted('update:modelValue')).toBeUndefined()

    await w.find('#it-emb-1').setValue('GRANEL_LIQUIDO')
    modal.payload.onYes()
    const lista = w.emitted('update:modelValue').at(-1)[0]
    expect(lista).toHaveLength(1)
    expect(lista[0]).toMatchObject({ embalagem: 'GRANEL_LIQUIDO', qtd: '500' })
  })
  it('C4: com um item só, trocar para granel não pergunta nada', async () => {
    closeModal()
    const w = mountEd({ embalagem: 'BOMBONA' })
    await w.find('#it-emb-0').setValue('GRANEL_LIQUIDO')
    expect(modal.open).toBe(false)
    expect(w.emitted('update:modelValue').at(-1)[0][0]).toMatchObject({ embalagem: 'GRANEL_LIQUIDO' })
  })
  it('T19: com granel o botão "Adicionar item" fica desabilitado e explica o porquê no tooltip', () => {
    const w = mountEd({ embalagem: 'GRANEL_LIQUIDO' })
    const botao = w.find('[data-act="add-item"]')
    expect(botao.attributes('disabled')).toBeDefined()
    expect(botao.attributes('title')).toContain('Granel')
    expect(w.find('[data-granel-hint]').exists()).toBe(true)
  })
  it('showFlags=false (programação) nunca mostra os checkboxes', () => {
    const w = mountEd({ embalagem: 'BOMBONA', paletizado: true }, { showFlags: false })
    expect(w.find('.item-flags').exists()).toBe(false)
  })
})

describe('ProductsView: kg por volume', () => {
  const mountPr = async () => {
    const w = mount(ProductsView, stubs)
    await flushPromises()
    return w
  }
  it('campo de fator só para embalagens marcadas e não granel', async () => {
    api.on('GET /produtos', ok([]))
    const w = await mountPr()
    await w.find('[data-act="new-product"]').trigger('click')
    expect(w.find('#pr-fat-BOMBONA').exists()).toBe(false)
    const chip = (i) => w.findAll('.chipz input')[i]
    await chip(1).setValue(true)
    expect(w.find('[id^="pr-fat-"]').exists()).toBe(false)
    await chip(2).setValue(true)
    expect(w.find('#pr-fat-BOMBONA').exists()).toBe(true)
    expect(w.find('#pr-fat-GRANEL_LIQUIDO').exists()).toBe(false)
  })
  it('POST envia fatores (vírgula vira ponto, vazio fica de fora)', async () => {
    api.on('GET /produtos', ok([]))
    api.on('POST /produtos', ({ body }) => ok(product({ id: 'p-new', ...body }), {}, 201))
    const w = await mountPr()
    await w.find('[data-act="new-product"]').trigger('click')
    await w.find('#pr-name').setValue('hcl')
    await w.findAll('.chipz input')[0].setValue(true)
    await w.findAll('.chipz input')[2].setValue(true)
    await w.find('#pr-fat-BOMBONA').setValue('25,5')
    await w.find('[data-act="save-product"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/produtos')[0].body).toMatchObject({ embalagens: ['BIG_BAG', 'BOMBONA'], fatores: { BOMBONA: '25.500' } })
  })
  it('POST envia descrição, ONU e risco (risco em maiúsculas; vazios viram null)', async () => {
    api.on('GET /produtos', ok([]))
    api.on('POST /produtos', ({ body }) => ok(product({ id: 'p-new', ...body }), {}, 201))
    const w = await mountPr()
    await w.find('[data-act="new-product"]').trigger('click')
    await w.find('#pr-name').setValue('hcl')
    await w.find('#pr-desc').setValue('Líquido ácido')
    await w.find('#pr-onu').setValue('1789')
    await w.find('#pr-risco').setValue('r80')
    await w.findAll('.chipz input')[0].setValue(true)
    await w.find('[data-act="save-product"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/produtos')[0].body).toMatchObject({ descricao: 'Líquido ácido', onu: '1789', risco: 'R80' })
  })
  it('ONU fora de 4 dígitos não envia', async () => {
    api.on('GET /produtos', ok([]))
    api.on('POST /produtos', ({ body }) => ok(product({ id: 'p-new', ...body }), {}, 201))
    const w = await mountPr()
    await w.find('[data-act="new-product"]').trigger('click')
    await w.find('#pr-name').setValue('hcl')
    await w.find('#pr-onu').setValue('12')
    await w.findAll('.chipz input')[0].setValue(true)
    await w.find('[data-act="save-product"]').trigger('click')
    await flushPromises()
    expect(api.called('POST', '/produtos')).toHaveLength(0)
  })
  it('editar carrega os fatores existentes e envia no PATCH', async () => {
    api.on('GET /produtos', ok([product({ embalagens: ['BIG_BAG', 'BOMBONA'], fatores: { BOMBONA: '50.000' }, versao: 2 })]))
    api.on('PATCH /produtos/:id', ({ body }) => ok(product({ ...body, versao: 3 })))
    const w = await mountPr()
    await w.find('.ibtn.ed').trigger('click')
    expect(w.find('#pr-fat-BOMBONA').element.value).toBe('50')
    expect(w.find('#pr-fat-BIG_BAG').element.value).toBe('')
    await w.find('#pr-fat-BOMBONA').setValue('60')
    await w.find('[data-act="save-product"]').trigger('click')
    await flushPromises()
    expect(api.called('PATCH', '/produtos/p-soda')[0].body).toMatchObject({ fatores: { BOMBONA: '60.000' }, versao: 2 })
  })
})

describe('QuoteModal: colunas Paletizado/Remontado', () => {
  it('mostra Sim/Não por item', async () => {
    const q = quote()
    q.itens[0] = { ...q.itens[0], paletizado: true, remontado: false }
    q.itens[1] = { ...q.itens[1], paletizado: false, remontado: false }
    api.on('GET /cotacoes/:id', ok(q))
    db.quotes = [q]
    db.domains = domains
    openModal('quote', { id: 'q-1' })
    const m = mount(QuoteModal, stubs)
    await flushPromises()
    expect(m.findAll('thead th').map((t) => t.text())).toEqual(expect.arrayContaining(['Paletizado', 'Remontado']))
    const rows = m.findAll('[data-item]')
    const cells = (r) => r.findAll('td').map((c) => c.text())
    expect(cells(rows[0]).slice(-2)).toEqual(['Sim', 'Não'])
    expect(cells(rows[1]).slice(-2)).toEqual(['Não', 'Não'])
    m.unmount()
  })
})

describe('QuoteModal: item único', () => {
  it('mostra Paletizado/Remontado mesmo com um só produto', async () => {
    const q = quote()
    q.itens = [q.itens[0]]
    api.on('GET /cotacoes/:id', ok(q))
    db.quotes = [q]
    db.domains = domains
    openModal('quote', { id: 'q-1' })
    const m = mount(QuoteModal, stubs)
    await flushPromises()
    const heads = m.findAll('thead th').map((t) => t.text())
    expect(heads).toContain('Paletizado')
    expect(heads).toContain('Remontado')
    expect(m.find('[data-item]').findAll('td')).toHaveLength(heads.length)
    m.unmount()
  })
})

describe('ItemsEditor: embalagem fracionada', () => {
  it('kg gera volumes (bombonas) e paletes automaticamente', async () => {
    db.domains = { ...domains, embalagens: [...domains.embalagens, { codigo: 'FRAC', descricao: 'Fracionada', fracionada: true, permite_paletizar: false, permite_remontar: false }] }
    setFracionadaCodes(db.domains.embalagens)
    db.products = [{ id: 'p1', nome: 'HCL', ativo: true, embalagens: ['FRAC'], fatores: { FRAC: '60.000' }, fatores_palete: { FRAC: '600.000' } }]
    const w = mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), produto_id: 'p1', embalagem: 'FRAC' }] } })
    await w.find('[aria-label="QTD (KG)"]').setValue('1200')
    const item = w.emitted('update:modelValue').at(-1)[0][0]
    expect(item.volumes).toBe('20')
    await w.setProps({ modelValue: [item] })
    expect(w.find('[aria-label="Paletes"]').element.value).toBe('2')
    expect(w.find('[aria-label="Paletes"]').element.readOnly).toBe(true)
  })
  it('embalagem comum não mostra Paletes', () => {
    const w = mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), produto_id: 'p1', embalagem: 'SACARIA' }] } })
    expect(w.find('[aria-label="Paletes"]').exists()).toBe(false)
  })
})
