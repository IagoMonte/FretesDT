import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { lockScroll, unlockScroll, scrollLockCount, useScrollLock } from '../../../src/composables/useScrollLock'
import FormModal from '../../../src/components/FormModal.vue'

const html = () => document.documentElement
const Probe = defineComponent({ setup() { useScrollLock(); return () => h('div') } })

afterEach(() => { while (scrollLockCount() > 0) unlockScroll() })

describe('useScrollLock', () => {
  it('abrir aplica a trava e fechar remove', () => {
    lockScroll()
    expect(html().classList.contains('scroll-locked')).toBe(true)
    unlockScroll()
    expect(html().classList.contains('scroll-locked')).toBe(false)
  })

  it('modais empilhados mantêm a trava até fechar o último', () => {
    lockScroll(); lockScroll()
    unlockScroll()
    expect(html().classList.contains('scroll-locked')).toBe(true)
    unlockScroll()
    expect(html().classList.contains('scroll-locked')).toBe(false)
  })

  it('unlock sem lock não quebra o contador', () => {
    unlockScroll()
    expect(scrollLockCount()).toBe(0)
  })

  it('o composable trava ao montar e libera ao desmontar', () => {
    const a = mount(Probe)
    const b = mount(Probe)
    expect(html().classList.contains('scroll-locked')).toBe(true)
    a.unmount()
    expect(html().classList.contains('scroll-locked')).toBe(true)
    b.unmount()
    expect(html().classList.contains('scroll-locked')).toBe(false)
  })

  it('FormModal trava a página enquanto estiver montado', () => {
    const w = mount(FormModal, { props: { title: 'x' }, attachTo: document.body })
    expect(html().classList.contains('scroll-locked')).toBe(true)
    w.unmount()
    expect(html().classList.contains('scroll-locked')).toBe(false)
  })
})
