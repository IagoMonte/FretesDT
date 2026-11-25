import { readFileSync } from 'node:fs'
import { mount, flushPromises } from '@vue/test-utils'
import MoneyInput from '../../../src/components/MoneyInput.vue'
import ItemsEditor from '../../../src/components/ItemsEditor.vue'
import ModalHost from '../../../src/components/ModalHost.vue'
import PartePicker from '../../../src/components/PartePicker.vue'
import LoadModal from '../../../src/components/LoadModal.vue'
import LoadsView from '../../../src/views/LoadsView.vue'
import LoginView from '../../../src/views/LoginView.vue'
import SignupView from '../../../src/views/SignupView.vue'
import { numeroAmbiguo } from '../../../src/utils/format'
import { validateQuote } from '../../../src/utils/quoteForm'
import { blankItem } from '../../../src/utils/items'
import { toasts, pushToast, openModal, closeModal } from '../../../src/stores/ui'
import { db, resetDb, createParty } from '../../../src/stores/db'
import { loginAs, logout } from '../../../src/stores/session'
import { createSession } from '../../../src/factories/sessionFactory'
import { api, ok, err } from '../../helpers/fakeApi'
import { meAdmin, meCarrier, domains, product, carrier, parte, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const css = readFileSync('src/styles/main.css', 'utf8')
const textos = () => toasts.map((t) => t.parts.map((p) => p.text).join(''))

beforeEach(async () => {
  api.reset()
  api.on('POST /auth/logout', ok(null, {}, 204))
  api.on('GET /dominios', ok(domains))
  resetDb()
  await logout()
  toasts.splice(0)
  closeModal()
})

describe('P1.5: uma regra só para número (BR), sem converter em silêncio', () => {
  it('numeroAmbiguo: mistura de separadores, vírgula repetida e grupos de milhar quebrados', () => {
    for (const ruim of ['1,234.56', '1.234.56', '1,2,3', '1.2345', '12.3.4', '1,5.5']) expect(numeroAmbiguo(ruim), ruim).toBe(true)
    for (const bom of ['', '250', '12,5', '12.5', '1.234', '1.234,56', '12.500', '1.234.567,89', 'R$ 1.234,56', '1.', '1,', ' 10 ']) expect(numeroAmbiguo(bom), bom).toBe(false)
  })

  async function digitar(w, seletor, texto) {
    const el = w.find(seletor)
    el.element.value = texto
    await el.trigger('input')
    return el
  }

  it('R$ recusa "1,234.56": mantém o valor anterior e avisa no toast', async () => {
    const w = mount(MoneyInput, { props: { modelValue: '' } })
    await digitar(w, 'input', '25')
    const el = await digitar(w, 'input', '1,234.56')
    expect(el.element.value).toBe('25')
    expect(textos().join(' ')).toMatch(/ambíguo/i)
  })

  it('QTD recusa "1,234.56" e "1.2345" sem emitir valor alterado', async () => {
    db.products = [product({ id: 'p-a', nome: 'A', embalagens: ['BOMBONA'] })]
    db.domains = domains
    const w = mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), produto_id: 'p-a', embalagem: 'BOMBONA' }] } })
    for (const ruim of ['1,234.56', '1.2345']) {
      await digitar(w, '#it-qtd-0', ruim)
      expect(w.emitted('update:modelValue')?.at(-1)?.[0][0].qtd, ruim).toBeUndefined()
    }
    expect(textos().join(' ')).toMatch(/ambíguo/i)
  })
})

describe('iOS: o #app não pode ser um contexto de empilhamento de altura 0', () => {
  it('#app não tem position nem z-index', () => {
    expect(css).not.toMatch(/#app\{[^}]*(position|z-index)/)
  })
  it('as listras do body::before ficam atrás do conteúdo por z-index negativo', () => {
    expect(css).toMatch(/body::before\{[^}]*z-index:-1/)
  })
})

describe('P0.1: a confirmação abre acima do modal de fechamento', () => {
  it('o overlay do modal global tem z-index maior que o dos formulários (.modal-ov)', () => {
    const z = (re) => Number(css.match(re)?.[1])
    const base = z(/\.modal-ov\{[^}]*z-index:(\d+)/)
    const topo = z(/\.modal-ov-top\{[^}]*z-index:(\d+)/)
    expect(base).toBeGreaterThan(0)
    expect(topo).toBeGreaterThan(base)
  })
  it('ModalHost marca o overlay como topo', async () => {
    openModal('confirm', { message: 'x', title: 'Confirmar valor de fechamento', label: 'Fechar mesmo assim', icon: 'warn', tone: 'primary', onYes: () => {} })
    const w = mount(ModalHost, { global: { stubs: { teleport: true } } })
    expect(w.find('.modal-ov').classes()).toContain('modal-ov-top')
    w.unmount()
  })
})

describe('P1.4: cliente já existente por CNPJ', () => {
  const existente = parte({ id: 'c-9', razao_social: 'BETA', cnpj: '11444777000161' })

  it('createParty devolve o cadastro existente quando a API responde 409', async () => {
    api.on('POST /clientes', { ok: false, status: 409, error: 'Já existe um cliente ativo com este CNPJ: BETA.', errors: {}, existente })
    const r = await createParty('CLIENTE', { razao_social: 'Outra', cnpj: '11.444.777/0001-61' })
    expect(r.ok).toBe(false)
    expect(r.existente.id).toBe('c-9')
  })

  it('PartePicker oferece usar o existente, sem UUID na mensagem', async () => {
    api.on('GET /partes', ok([]))
    api.on('POST /clientes', { ok: false, status: 409, error: 'Já existe um cliente ativo com este CNPJ: BETA.', errors: {}, existente })
    const w = mount(PartePicker, { props: { modelValue: null, tipo: 'CLIENTE', id: 'pp' } })
    await w.find('#pp').setValue('Outra Razao')
    await new Promise((r) => setTimeout(r, 400))
    await flushPromises()
    await w.find('[data-act="new-parte"]').trigger('mousedown')
    await w.find('#pp-cnpj').setValue('11444777000161')
    await w.find('[data-act="save-parte"]').trigger('click')
    await flushPromises()
    expect(w.emitted('update:modelValue')?.at(-1)?.[0].id).toBe('c-9')
    const t = textos().join(' ')
    expect(t).toMatch(/já existe/i)
    expect(t).not.toMatch(/c-9|[0-9a-f]{8}-[0-9a-f]{4}/)
    expect(t).not.toMatch(/criado/i)
  })
})

describe('P1.12: busca de cliente com debounce', () => {
  it('digitar vários caracteres seguidos dispara uma só busca', async () => {
    vi.useFakeTimers()
    api.on('GET /partes', ok([]))
    const w = mount(PartePicker, { props: { modelValue: null, tipo: 'CLIENTE', id: 'pp' } })
    for (const t of ['un', 'uni', 'unil', 'unile']) await w.find('#pp').setValue(t)
    expect(api.called('GET', '/partes')).toHaveLength(0)
    await vi.advanceTimersByTimeAsync(350)
    expect(api.called('GET', '/partes')).toHaveLength(1)
    expect(api.called('GET', '/partes')[0].query.termo).toBe('unile')
    vi.useRealTimers()
  })
})

describe('P1.13: cidade sem escolher da lista', () => {
  const base = { parte: { id: 'c' }, operacao: 'CARREGAMENTO', itens: [{ ...blankItem(), produto_id: 'p', embalagem: 'BOMBONA', qtd: '1.000' }], veiculo: 'CARRETA', valorNF: '1.000,00' }
  it('texto sem " - UF" marca o campo com "Escolha a cidade na lista."', () => {
    const e = validateQuote({ ...base, origem: 'Paulinia', destino: 'Campinas - SP' })
    expect(e.origem).toBe('Escolha a cidade na lista.')
    expect(e.destino).toBeUndefined()
    expect(validateQuote({ ...base, origem: 'Paulínia - SP', destino: 'Campinas - SP' })).toEqual({})
  })
})

describe('P1.6 e P1.8: lista de cargas', () => {
  async function montar(me, lista) {
    api.on('GET /programacoes', ok(lista))
    api.on('GET /produtos', ok([product()]))
    api.on('GET /transportadoras', ok([carrier()]))
    api.on('GET /vendedores', ok([]))
    loginAs(createSession(me))
    const w = mount(LoadsView, { global: { stubs: { teleport: true } } })
    await flushPromises()
    return w
  }

  it('com filtro ativo e nenhuma carga, diz "Nenhuma carga com esse filtro"', async () => {
    const w = await montar(meAdmin, [load({ id: 'l-1', codigo: 'CRG-000001' })])
    await w.find('#flt-txt').setValue('zzzz')
    expect(w.text()).toContain('Nenhuma carga com esse filtro')
    expect(w.text()).not.toContain('Nenhuma carga programada')
  })

  it('carga fechada por valor diferente mostra "proposto" e "fechado" na lista', async () => {
    const w = await montar(meAdmin, [load({ id: 'l-1', codigo: 'CRG-000001', valor_tonelada: '120.00', valor_proposto: '240.00' })])
    const row = w.findAll('#loadRows tr').find((r) => r.text().includes('CRG-000001'))
    expect(row.text()).toMatch(/proposto/i)
    expect(row.text()).toMatch(/240,00/)
    expect(row.text()).toMatch(/120,00/)
  })

  it('o modal da carga mostra proposto e fechado para o gestor e para a transportadora', async () => {
    for (const me of [meAdmin, meCarrier]) {
      loginAs(createSession(me))
      const l = load({ id: 'l-1', codigo: 'CRG-000001', valor_tonelada: '120.00', valor_proposto: '240.00' })
      db.loads = [l]
      openModal('load', { id: 'l-1' })
      const w = mount(LoadModal, { global: { stubs: { teleport: true } } })
      expect(w.text()).toMatch(/proposto/i)
      expect(w.text()).toMatch(/fechado/i)
      expect(w.text()).toMatch(/240,00/)
      w.unmount()
      closeModal()
    }
  })
})

describe('P2: toasts e campos', () => {
  it('toast idêntico ainda na tela não se repete', () => {
    pushToast('err', 'Informe o motivo do cancelamento.')
    pushToast('err', 'Informe o motivo do cancelamento.')
    pushToast('err', 'Informe o motivo do cancelamento.')
    expect(toasts).toHaveLength(1)
    pushToast('err', 'Outro erro.')
    expect(toasts).toHaveLength(2)
  })

  it('login e cadastro limitam o tamanho do que o back aceita', () => {
    const l = mount(LoginView, { global: { stubs: { teleport: true } } })
    expect(l.find('#lgUser').attributes('maxlength')).toBe('200')
    const s = mount(SignupView, { global: { stubs: { teleport: true } } })
    const max = (n) => s.find(`[name="${n}"], #sg-${n}`).attributes('maxlength')
    expect(max('nome')).toBe('150')
    expect(max('login')).toBe('200')
    expect(max('email')).toBe('200')
  })
})

describe('P1.9 e P1.10: mobile', () => {
  const mobile = css.slice(css.indexOf('@media'))
  it('o toast desce para baixo da topbar no mobile (não cobre o botão do menu)', () => {
    expect(css).toMatch(/@media[^{]*max-width[^{]*\{[^]*\.toasts\{[^}]*top:\s*(6\d|7\d|8\d)px/)
  })
  it('título e selo podem quebrar linha; título do /antt não vira coluna de letras', () => {
    expect(css).toMatch(/\.modal-h h3\{[^}]*(overflow-wrap|word-break|min-width)/)
    expect(css).toMatch(/\.card-h h2\{[^}]*min-width:\s*[1-9]/)
    expect(css).toMatch(/\.modal-h h3\{[^}]*overflow-wrap:break-word/)
    expect(mobile.length).toBeGreaterThan(0)
  })
})
