import { computed, reactive } from 'vue'
import { norm } from '../utils/format'

/**
 * Busca + filtros das listagens de cadastro, 100% no cliente (a lista inteira já está em memória).
 * `texto(item)` devolve o que a busca varre; `filtros` = { chave: (item, valor) => boolean } (valor vazio = sem filtro).
 */
export function useListFilter(origem, { texto, filtros = {} }) {
  const f = reactive({ text: '', ...Object.fromEntries(Object.keys(filtros).map((k) => [k, ''])) })
  const visible = computed(() => {
    const t = norm(f.text).trim()
    return origem().filter((it) => (!t || norm(texto(it)).includes(t)) && Object.entries(filtros).every(([k, ok]) => !f[k] || ok(it, f[k])))
  })
  const filtrando = computed(() => Object.values(f).some(Boolean))
  const contagem = (total, unidade) => (filtrando.value ? `${visible.value.length} de ${total} ${unidade}` : `${total} ${unidade}`)
  return { f, visible, filtrando, contagem }
}
