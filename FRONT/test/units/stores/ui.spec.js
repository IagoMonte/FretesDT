import { toasts, pushToast, modal, openModal, closeModal, confirmDelete } from '../../../src/stores/ui'

beforeEach(() => {
  vi.useFakeTimers()
  toasts.splice(0)
  closeModal()
})
afterEach(() => vi.useRealTimers())

describe('toasts', () => {
  it('empilha e auto-dismiss em 4,2s com saída animada de 0,3s', () => {
    pushToast('ok', 'Cotação **COT-1** ok')
    expect(toasts).toHaveLength(1)
    expect(toasts[0].out).toBeFalsy()
    vi.advanceTimersByTime(4200)
    expect(toasts[0].out).toBe(true)
    vi.advanceTimersByTime(320)
    expect(toasts).toHaveLength(0)
  })
  it('mais recente entra por último', () => {
    pushToast('ok', 'a')
    pushToast('err', 'b')
    expect(toasts.map((t) => t.type)).toEqual(['ok', 'err'])
  })
})

describe('modal', () => {
  it('abre com tipo e payload, fecha', () => {
    openModal('quote', { id: 'x' })
    expect(modal).toMatchObject({ open: true, kind: 'quote', payload: { id: 'x' } })
    closeModal()
    expect(modal.open).toBe(false)
  })
  it('confirmDelete abre confirmação e executa callback ao confirmar', () => {
    const yes = vi.fn()
    confirmDelete('Excluir **X**?', yes)
    expect(modal).toMatchObject({ open: true, kind: 'confirm' })
    modal.payload.onYes()
    expect(yes).toHaveBeenCalled()
    expect(modal.open).toBe(false)
  })
})

