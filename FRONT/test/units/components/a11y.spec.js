import { mount } from '@vue/test-utils'
import FormModal from '../../../src/components/FormModal.vue'
import CityInput from '../../../src/components/CityInput.vue'
import NavIcon from '../../../src/components/NavIcon.vue'
import KpiCard from '../../../src/components/KpiCard.vue'
import ToastHost from '../../../src/components/ToastHost.vue'
import { toasts, pushToast } from '../../../src/stores/ui'

afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); toasts.splice(0) })

describe('FormModal — diálogo acessível', () => {
  const slots = { default: '<input id="a" class="inp" /><input id="b" class="inp" />', footer: '<button id="ok">OK</button>' }

  it('nomeia o diálogo pelo título e leva o foco para o primeiro campo', async () => {
    const w = mount(FormModal, { props: { title: 'Novo produto' }, slots, attachTo: document.body })
    await w.vm.$nextTick(); await w.vm.$nextTick()
    const dlg = w.find('[role=dialog]')
    expect(dlg.attributes('aria-modal')).toBe('true')
    expect(document.getElementById(dlg.attributes('aria-labelledby')).textContent).toBe('Novo produto')
    expect(document.activeElement.id).toBe('a')
    w.unmount()
  })

  it('Tab no último controle volta ao primeiro (Fechar) e Shift+Tab nele vai ao último', async () => {
    const w = mount(FormModal, { props: { title: 'x' }, slots, attachTo: document.body })
    await w.vm.$nextTick(); await w.vm.$nextTick()
    const dlg = w.find('[role=dialog]')
    document.getElementById('ok').focus()
    await dlg.trigger('keydown', { key: 'Tab' })
    expect(document.activeElement.className).toContain('x')
    await dlg.trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement.id).toBe('ok')
    w.unmount()
  })

  it('o botão de fechar tem nome acessível e ícone decorativo', () => {
    const w = mount(FormModal, { props: { title: 'x' }, slots })
    const x = w.find('.modal-h .x')
    expect(x.attributes('aria-label')).toBe('Fechar')
    expect(x.find('svg').attributes('aria-hidden')).toBe('true')
  })
})

describe('CityInput — combobox', () => {
  it('expõe papéis e estado da lista de sugestões', async () => {
    const w = mount(CityInput, { props: { id: 'c1', modelValue: '' } })
    const inp = w.find('input')
    expect(inp.attributes('role')).toBe('combobox')
    expect(inp.attributes('aria-expanded')).toBe('false')
    await inp.setValue('dumont')
    expect(inp.attributes('aria-expanded')).toBe('true')
    expect(w.find('[role=listbox]').attributes('id')).toBe(inp.attributes('aria-controls'))
    expect(w.findAll('[role=option]').length).toBeGreaterThan(0)
    await inp.trigger('keydown', { key: 'ArrowDown' })
    expect(inp.attributes('aria-activedescendant')).toBe(w.find('[aria-selected=true]').attributes('id'))
  })
})

describe('ícones', () => {
  it('são decorativos para leitores de tela e herdam a cor do texto', () => {
    const svg = mount(NavIcon, { props: { name: 'check', size: 14 } }).find('svg')
    expect(svg.attributes('aria-hidden')).toBe('true')
    expect(svg.attributes('stroke')).toBe('currentColor')
    expect(svg.attributes('width')).toBe('14')
  })
})

describe('notificações', () => {
  it('erro é anunciado como alerta e os demais como status', () => {
    pushToast('err', 'falhou')
    pushToast('ok', 'feito')
    const w = mount(ToastHost)
    const roles = w.findAll('.toast').map((t) => t.attributes('role'))
    expect(roles).toEqual(['alert', 'status'])
  })
})

describe('KpiCard — movimento reduzido', () => {
  it('mostra o valor final sem contar quando o usuário pede menos movimento', async () => {
    vi.stubGlobal('matchMedia', (q) => ({ matches: q.includes('reduce'), addEventListener() {}, removeEventListener() {} }))
    const w = mount(KpiCard, { props: { label: 'Cargas', value: 40, sub: 's' } })
    await w.vm.$nextTick()
    expect(w.find('.kv').text()).toBe('40')
  })
})
