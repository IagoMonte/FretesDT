import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import FormModal from '../../../src/components/FormModal.vue'
import PartePicker from '../../../src/components/PartePicker.vue'
import { db, resetDb } from '../../../src/stores/db'
import { api, ok } from '../../helpers/fakeApi'
import { domains } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

const esc = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))

describe('C5: Esc fecha uma camada por vez', () => {
  beforeEach(() => {
    api.reset()
    api.on('GET /dominios', ok(domains))
    api.on('GET /partes', ok([]))
    resetDb()
  })

  it('Esc fecha o painel de cadastro rápido e só o segundo Esc fecha o formulário', async () => {
    const fechou = vi.fn()
    const Pai = defineComponent({ render: () => h(FormModal, { title: 'Nova cotação', onClose: fechou }, { default: () => h(PartePicker, { modelValue: null, tipo: 'CLIENTE', id: 'pp' }) }) })
    const w = mount(Pai, { attachTo: document.body, global: { stubs: { teleport: true } } })

    await w.find('#pp').setValue('Outra Razao')
    await new Promise((r) => setTimeout(r, 400))
    await flushPromises()
    await w.find('[data-act="new-parte"]').trigger('mousedown')
    expect(w.find('.partebox').exists()).toBe(true)

    esc()
    await flushPromises()
    expect(w.find('.partebox').exists()).toBe(false)
    expect(fechou).not.toHaveBeenCalled()

    esc()
    await flushPromises()
    expect(fechou).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('Esc com o painel fechado continua fechando o formulário', async () => {
    const fechou = vi.fn()
    const Pai = defineComponent({ render: () => h(FormModal, { title: 'Nova cotação', onClose: fechou }, { default: () => h(PartePicker, { modelValue: null, tipo: 'CLIENTE', id: 'pp' }) }) })
    const w = mount(Pai, { attachTo: document.body, global: { stubs: { teleport: true } } })
    esc()
    await flushPromises()
    expect(fechou).toHaveBeenCalledTimes(1)
    w.unmount()
  })
})
