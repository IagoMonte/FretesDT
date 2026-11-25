import { mount } from '@vue/test-utils'
import MoneyInput from '../../../src/components/MoneyInput.vue'
import ItemsEditor from '../../../src/components/ItemsEditor.vue'
import { maskKg, hasInvalidNumChars } from '../../../src/utils/format'
import { toasts } from '../../../src/stores/ui'
import { db, resetDb } from '../../../src/stores/db'
import { blankItem } from '../../../src/utils/items'
import { product, domains } from '../../helpers/fixtures'

const toastText = () => toasts.map((t) => t.parts.map((p) => p.text).join('')).join(' | ')

beforeEach(() => {
  toasts.splice(0)
  resetDb()
})

describe('T10: utilitários', () => {
  it('hasInvalidNumChars separa sinal/letras de dígitos, ponto e vírgula', () => {
    for (const ruim of ['-50', '1e9', 'abc', '12x', '+5']) expect(hasInvalidNumChars(ruim), ruim).toBe(true)
    for (const bom of ['', '250', '1.234,56', '12,5', 'R$ 1.234,56', ' 10 ']) expect(hasInvalidNumChars(bom), bom).toBe(false)
  })
  it('maskKg: milhar automático, vírgula decimal (até 3 casas) e ponto decimal vira vírgula', () => {
    expect(maskKg('27000')).toBe('27.000')
    expect(maskKg('12,5')).toBe('12,5')
    expect(maskKg('12.5')).toBe('12,5')
    expect(maskKg('1.250')).toBe('1.250')
    expect(maskKg('12,3456')).toBe('12,345')
    expect(maskKg('0,0004')).toBe('0,000')
    expect(maskKg(',5')).toBe('0,5')
    expect(maskKg('')).toBe('')
  })
})

describe('T10: MoneyInput', () => {
  async function type(w, text) {
    const el = w.find('input')
    el.element.value = text
    await el.trigger('input')
    return el
  }
  it('sinal e letras são recusados com toast e o campo mantém o valor anterior', async () => {
    const w = mount(MoneyInput, { props: { modelValue: '' } })
    let el = await type(w, '-50')
    expect(el.element.value).toBe('')
    expect(toastText()).toContain('apenas números')
    toasts.splice(0)
    el = await type(w, '1e9')
    expect(el.element.value).toBe('')
    expect(toastText()).toContain('apenas números')
    await type(w, '25')
    el = await type(w, '25-')
    expect(el.element.value).toBe('25')
  })
  it('colar "R$ 1.234,56" é aceito e formatado ao sair', async () => {
    const w = mount(MoneyInput, { props: { modelValue: '' } })
    const el = await type(w, 'R$ 1.234,56')
    await el.trigger('blur')
    expect(el.element.value).toBe('1.234,56')
    expect(toasts).toHaveLength(0)
  })
})

describe('T10: QTD do ItemsEditor', () => {
  const mountEd = () => mount(ItemsEditor, { props: { modelValue: [{ ...blankItem(), produto_id: 'p-a', embalagem: 'BOMBONA' }] } })
  beforeEach(() => {
    db.products = [product({ id: 'p-a', nome: 'A', embalagens: ['BOMBONA', 'GRANEL_LIQUIDO'] })]
    db.domains = domains
  })
  const digitar = async (w, text) => {
    const el = w.find('#it-qtd-0')
    el.element.value = text
    await el.trigger('input')
    return w.emitted('update:modelValue')?.at(-1)?.[0][0].qtd
  }
  it('12,5 kg continua 12,5 (não vira 125)', async () => {
    expect(await digitar(mountEd(), '12,5')).toBe('12,5')
  })
  it('12.5 vira 12,5 e 27000 vira 27.000', async () => {
    expect(await digitar(mountEd(), '12.5')).toBe('12,5')
    expect(await digitar(mountEd(), '27000')).toBe('27.000')
  })
  it('sinal e letras são recusados com toast, sem emitir valor alterado', async () => {
    const w = mountEd()
    expect(await digitar(w, '-50')).toBeUndefined()
    expect(toastText()).toContain('apenas números')
    expect(await digitar(w, '1e9')).toBeUndefined()
  })
})
