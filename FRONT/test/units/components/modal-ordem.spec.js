import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount } from '@vue/test-utils'
import ModalHost from '../../../src/components/ModalHost.vue'
import FormModal from '../../../src/components/FormModal.vue'
import { modal, openModal, closeModal, confirmAction } from '../../../src/stores/ui'

const css = readFileSync(resolve(process.cwd(), 'src/styles/main.css'), 'utf8')
const z = (seletor) => {
  const re = new RegExp('(?:^|[\\s}])' + seletor.replace(/[.]/g, '\\.') + '\\s*\\{([^}]*)\\}', 'm')
  const m = css.match(re)
  const v = m?.[1].match(/z-index:\s*(-?\d+)/)
  return v ? Number(v[1]) : null
}

beforeEach(() => closeModal())

describe('escala de camadas (z-index)', () => {
  it('menu e topbar < detalhe < formulário < confirmação < toasts', () => {
    const camadas = {
      topbar: z('.topbar'), sidebar: z('.side'), detalhe: z('.modal-ov'), formulario: z('.modal-ov-form'),
      confirmacao: z('.modal-ov-top'), toasts: z('.toasts'),
    }
    for (const [nome, v] of Object.entries(camadas)) expect(v, nome).not.toBeNull()
    expect(Math.max(camadas.topbar, camadas.sidebar)).toBeLessThan(camadas.detalhe)
    expect(camadas.detalhe).toBeLessThan(camadas.formulario)
    expect(camadas.formulario).toBeLessThan(camadas.confirmacao)
    expect(camadas.confirmacao).toBeLessThan(camadas.toasts)
  })
  it('menu mobile e seu fundo ficam abaixo dos modais', () => {
    expect(z('.nav-backdrop') ?? css.match(/\.nav-backdrop\{[^}]*z-index:(\d+)/)?.[1]).toBeTruthy()
    expect(Number(css.match(/\.nav-backdrop\{[^}]*z-index:(\d+)/)[1])).toBeLessThan(z('.modal-ov'))
  })
})

describe('classe de camada de cada modal', () => {
  it('formulário (FormModal) usa a camada própria acima do detalhe', () => {
    const w = mount(FormModal, { props: { title: 'Novo' }, attachTo: document.body })
    const ov = document.body.querySelector('.modal-ov')
    expect(ov.classList.contains('modal-ov-form')).toBe(true)
    w.unmount()
  })
  it('detalhe (cotação/carga) fica na camada base; só a confirmação sobe', () => {
    openModal('confirm', { message: 'x', title: 'y', label: 'z', icon: 'trash', tone: 'danger', onYes: () => {} })
    const confirmacao = mount(ModalHost)
    expect(confirmacao.find('.modal-ov').classes()).toContain('modal-ov-top')
    confirmacao.unmount()
    closeModal()

    openModal('load', { id: 'l-1' })
    const detalhe = mount(ModalHost)
    expect(detalhe.find('.modal-ov').classes()).not.toContain('modal-ov-top')
    detalhe.unmount()
  })
})

describe('Esc: o modal visível por cima é quem fecha', () => {
  const esc = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
  const montados = []
  const abrir = (C, opts) => { const w = mount(C, { attachTo: document.body, ...opts }); montados.push(w); return w }
  afterEach(() => { while (montados.length) montados.pop().unmount() })

  it('detalhe aberto, formulário aberto por ele, depois a confirmação: Esc fecha só a confirmação', async () => {
    openModal('confirm', { message: 'detalhe', title: 'a', label: 'b', icon: 'trash', tone: 'danger', onYes: () => {} })
    abrir(ModalHost)
    const form = abrir(FormModal, { props: { title: 'Fechar' } })
    await form.vm.$nextTick()
    confirmAction('Fechar mesmo assim?', () => {}, { tone: 'primary', label: 'Fechar' })
    await form.vm.$nextTick()

    esc()
    expect(modal.open).toBe(false)
    expect(form.emitted('close')).toBeUndefined()

    esc()
    expect(form.emitted('close')).toHaveLength(1)
  })
})
