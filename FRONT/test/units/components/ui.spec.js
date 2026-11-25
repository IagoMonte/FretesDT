import { mount } from '@vue/test-utils'
import BrandMark from '../../../src/components/BrandMark.vue'
import StatusPill from '../../../src/components/StatusPill.vue'
import DeadlineTag from '../../../src/components/DeadlineTag.vue'
import CardPanel from '../../../src/components/CardPanel.vue'
import KpiCard from '../../../src/components/KpiCard.vue'
import BarRow from '../../../src/components/BarRow.vue'
import EmptyState from '../../../src/components/EmptyState.vue'
import ChipGroup from '../../../src/components/ChipGroup.vue'
import CityInput from '../../../src/components/CityInput.vue'

afterEach(() => vi.useRealTimers())

describe('BrandMark', () => {
  it('desenha 3 formas na viewBox 72x72 com gradientes', () => {
    const w = mount(BrandMark, { props: { variant: 'side' } })
    expect(w.find('svg').attributes('viewBox')).toBe('0 0 72 72')
    expect(w.findAll('path')).toHaveLength(2)
    expect(w.findAll('rect')).toHaveLength(1)
    expect(w.findAll('linearGradient')).toHaveLength(2)
  })
  it('sidebar usa vermelho #F0142C→#D6001C; login #E40016→#B00014', () => {
    expect(mount(BrandMark, { props: { variant: 'side' } }).html()).toContain('#F0142C')
    expect(mount(BrandMark, { props: { variant: 'login' } }).html()).toContain('#B00014')
  })
})

describe('StatusPill', () => {
  it('aplica classe do mapa e texto', () => {
    const w = mount(StatusPill, { props: { status: 'ENTREGUE' } })
    expect(w.classes()).toContain('st-ent')
    expect(w.text()).toBe('ENTREGUE')
  })
  it('mostra o rótulo do código estável', () => {
    expect(mount(StatusPill, { props: { status: 'EM_NEGOCIACAO' } }).text()).toBe('EM NEGOCIAÇÃO')
    expect(mount(StatusPill, { props: { status: 'AGUARDANDO_CARREGAMENTO' } }).text()).toBe('AGUARDANDO CARREGAMENTO')
  })
  it('dot pulsante só em EM_TRANSITO', () => {
    const t = mount(StatusPill, { props: { status: 'EM_TRANSITO', dot: true } })
    expect(t.find('.d').classes()).toContain('pulse')
    const e = mount(StatusPill, { props: { status: 'ENTREGUE', dot: true } })
    expect(e.find('.d').classes()).not.toContain('pulse')
  })
  it('versão compacta', () => {
    expect(mount(StatusPill, { props: { status: 'ENTREGUE', compact: true } }).classes()).toContain('compact')
  })
})

describe('DeadlineTag', () => {
  it('no prazo / atrasado / em aberto', () => {
    expect(mount(DeadlineTag, { props: { dataEntregaRealizada: '2026-10-08', entregueNoPrazo: true } }).classes()).toContain('prz-ok')
    expect(mount(DeadlineTag, { props: { dataEntregaRealizada: '2026-10-09', entregueNoPrazo: false } }).classes()).toContain('prz-bad')
    const w = mount(DeadlineTag, { props: { dataEntregaRealizada: null, entregueNoPrazo: null } })
    expect(w.classes()).toContain('prz-no')
    expect(w.text()).toBe('em aberto')
  })
})

describe('CardPanel', () => {
  it('header com tag bicolor, título e contador', () => {
    const w = mount(CardPanel, { props: { title: 'Cargas', count: '3 registros' }, slots: { default: '<p>x</p>' } })
    expect(w.find('.card-h .tag').exists()).toBe(true)
    expect(w.find('.card-h h2').text()).toBe('Cargas')
    expect(w.find('.cnt').text()).toBe('3 registros')
    expect(w.find('.card-b').exists()).toBe(true)
  })
  it('sem contador não renderiza .cnt; flush remove o padding do corpo', () => {
    const w = mount(CardPanel, { props: { title: 'X', flush: true }, slots: { default: '<p>x</p>' } })
    expect(w.find('.cnt').exists()).toBe(false)
    expect(w.find('.card-b').exists()).toBe(false)
  })
})

describe('KpiCard', () => {
  it('anima a contagem de 0 até o valor', async () => {
    vi.useFakeTimers()
    const w = mount(KpiCard, { props: { label: 'Cargas', value: 10, sub: 's', color: '#00693C' } })
    expect(w.find('.kv').text()).toBe('0')
    vi.advanceTimersByTime(28 * 40)
    await w.vm.$nextTick()
    expect(w.find('.kv').text()).toBe('10')
  })
  it('sufixo e cor da barra', async () => {
    vi.useFakeTimers()
    const w = mount(KpiCard, { props: { label: 'x', value: 50, suffix: '%', color: '#D6001C' } })
    vi.advanceTimersByTime(28 * 60)
    await w.vm.$nextTick()
    expect(w.find('.kv').text()).toBe('50%')
    expect(w.attributes('style')).toContain('--kc')
  })
  it('texto fixo (moeda) não anima e usa fonte menor', () => {
    const w = mount(KpiCard, { props: { label: 'Total', text: 'R$ 1,00' } })
    expect(w.find('.kv').text()).toBe('R$ 1,00')
    expect(w.find('.kv').classes()).toContain('money')
  })
})

describe('BarRow', () => {
  it('largura anima de 0 até a proporção do máximo', async () => {
    vi.useFakeTimers()
    const w = mount(BarRow, { props: { label: 'EM TRÂNSITO', count: 2, max: 4, color: '#00A050' } })
    expect(w.find('.fl').attributes('style')).toContain('width: 0%')
    vi.advanceTimersByTime(90)
    await w.vm.$nextTick()
    expect(w.find('.fl').attributes('style')).toContain('width: 50%')
    expect(w.find('.bn').text()).toBe('2')
    expect(w.find('.bl').text()).toBe('EM TRÂNSITO')
  })
})

describe('EmptyState', () => {
  it('ícone SVG + frase', () => {
    const w = mount(EmptyState, { props: { icon: 'inbox', message: 'Nenhuma cotação por aqui ainda.' } })
    expect(w.find('.big svg').attributes('aria-hidden')).toBe('true')
    expect(w.text()).toContain('Nenhuma cotação')
  })
})

describe('ChipGroup', () => {
  it('alterna seleção e emite o array', async () => {
    const w = mount(ChipGroup, { props: { modelValue: ['A'], options: ['A', 'B'] } })
    const labels = w.findAll('label')
    expect(labels[0].classes()).toContain('on')
    expect(labels[1].classes()).not.toContain('on')
    await labels[1].find('input').setValue(true)
    expect(w.emitted('update:modelValue')[0][0]).toEqual(['A', 'B'])
    await labels[0].find('input').setValue(false)
    expect(w.emitted('update:modelValue')[1][0]).toEqual([])
  })
})

describe('ChipGroup com { value, label }', () => {
  it('mostra o rótulo e emite o valor', async () => {
    const w = mount(ChipGroup, { props: { modelValue: [], options: [{ value: 'BIG_BAG', label: 'Big bag' }, { value: 'GRANEL', label: 'Granel' }] } })
    expect(w.findAll('label').map((l) => l.text())).toEqual(['Big bag', 'Granel'])
    await w.findAll('input')[1].setValue(true)
    expect(w.emitted('update:modelValue')[0][0]).toEqual(['GRANEL'])
  })
})

describe('ChipGroup com { value, label }', () => {
  it('mostra o rótulo e emite o valor', async () => {
    const w = mount(ChipGroup, { props: { modelValue: [], options: [{ value: 'BIG_BAG', label: 'Big bag' }, { value: 'GRANEL', label: 'Granel' }] } })
    expect(w.findAll('label').map((l) => l.text())).toEqual(['Big bag', 'Granel'])
    await w.findAll('input')[1].setValue(true)
    expect(w.emitted('update:modelValue')[0][0]).toEqual(['GRANEL'])
  })
})

describe('CityInput', () => {
  const type = async (w, v) => {
    const i = w.find('input')
    await i.setValue(v)
  }
  it('só sugere a partir de 2 caracteres', async () => {
    const w = mount(CityInput, { props: { modelValue: '' } })
    await type(w, 'c')
    expect(w.find('.citybox').exists()).toBe(false)
    await type(w, 'cam')
    expect(w.find('.citybox').exists()).toBe(true)
    expect(w.findAll('.citybox div').length).toBeGreaterThan(0)
    expect(w.findAll('.citybox div').length).toBeLessThanOrEqual(12)
  })
  it('ignora acentos e mostra UF em destaque', async () => {
    const w = mount(CityInput, { props: { modelValue: '' } })
    await type(w, 'sao jose dos')
    expect(w.find('.citybox').text()).toContain('SÃO JOSÉ DOS CAMPOS')
    expect(w.findAll('.citybox b').map((b) => b.text())).toContain('SP')
  })
  it('seleciona com o mouse', async () => {
    const w = mount(CityInput, { props: { modelValue: '' } })
    await type(w, 'campinas')
    await w.find('.citybox div').trigger('mousedown')
    expect(w.emitted('update:modelValue').at(-1)[0]).toBe('Campinas - SP')
    expect(w.find('.citybox').exists()).toBe(false)
  })
  it('navega por teclado: setas + Enter; Esc fecha', async () => {
    const w = mount(CityInput, { props: { modelValue: '' } })
    await type(w, 'campinas')
    await w.find('input').trigger('keydown', { key: 'ArrowDown' })
    expect(w.find('.citybox div').classes()).toContain('sel')
    await w.find('input').trigger('keydown', { key: 'Enter' })
    expect(w.emitted('update:modelValue').at(-1)[0]).toBe('Campinas - SP')
    await type(w, 'campinas')
    await w.find('input').trigger('keydown', { key: 'Escape' })
    expect(w.find('.citybox').exists()).toBe(false)
  })
})
