import { onBeforeUnmount, ref, watch } from 'vue'
import { findPreviousQuote } from '../stores/db'
import { parseCity } from '../utils/format'

/**
 * Aviso de cotação repetida no formulário de nova cotação.
 * Quando TODOS os itens são GRANEL_LIQUIDO com produto escolhido e veículo + origem + destino estão preenchidos, consulta
 * GET /cotacoes/anterior (com debounce) e expõe a cotação anterior em `previous`.
 * `loading` fica true do gatilho (debounce) até a resposta chegar.
 * Respostas fora de ordem são descartadas (token de requisição).
 */
export function usePreviousQuote(form, { delay = 500 } = {}) {
  const previous = ref(null)
  const loading = ref(false)
  let timer = null
  let seq = 0

  const eligible = () =>
    form.itens.length > 0 && form.itens.every((i) => i.embalagem === 'GRANEL_LIQUIDO' && !!i.produto_id) &&
    !!form.veiculo && !!parseCity(form.origem)?.uf && !!parseCity(form.destino)?.uf

  watch(
    () => [form.itens.map((i) => `${i.embalagem}:${i.produto_id}`).join('|'), form.veiculo, form.origem, form.destino],
    () => {
      clearTimeout(timer)
      const mine = ++seq
      if (!eligible()) { previous.value = null; loading.value = false; return }
      loading.value = true
      timer = setTimeout(async () => {
        const r = await findPreviousQuote({ veiculo: form.veiculo, origem: form.origem, destino: form.destino, produtoIds: form.itens.map((i) => i.produto_id) })
        if (mine !== seq) return
        loading.value = false
        previous.value = r
        if (r) form.usarAnterior = true
      }, delay)
    },
  )
  onBeforeUnmount(() => { clearTimeout(timer); seq++ })
  return { previous, loading, reset: () => { clearTimeout(timer); seq++; previous.value = null; loading.value = false } }
}
