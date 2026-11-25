import { flushPromises } from '@vue/test-utils'

export async function digitaParte(w, seletor, texto) {
  await w.find(seletor).setValue(texto)
  await new Promise((r) => setTimeout(r, 330))
  await flushPromises()
}
