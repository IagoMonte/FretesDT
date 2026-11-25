import { computed, ref } from 'vue'

const chave = (id) => `fretesdt.menu-ordem.${id}`

function ler(id) {
  try {
    const v = JSON.parse(localStorage.getItem(chave(id)))
    return Array.isArray(v) ? v.filter((k) => typeof k === 'string') : []
  } catch {
    return []
  }
}

export function useMenuOrder(getMenu, userId) {
  const salva = ref(ler(userId))
  const ordered = computed(() => {
    const menu = getMenu()
    const porChave = new Map(menu.map((m) => [m.key, m]))
    const fora = salva.value.filter((k) => porChave.has(k))
    return [...fora.map((k) => porChave.get(k)), ...menu.filter((m) => !fora.includes(m.key))]
  })

  function gravar(keys) {
    salva.value = keys
    try {
      localStorage.setItem(chave(userId), JSON.stringify(keys))
    } catch { }
  }

  function move(de, para) {
    const keys = ordered.value.map((m) => m.key)
    const i = keys.indexOf(de)
    const j = keys.indexOf(para)
    if (i < 0 || j < 0 || i === j) return
    keys.splice(j, 0, keys.splice(i, 1)[0])
    gravar(keys)
  }

  function reset() {
    salva.value = []
    try {
      localStorage.removeItem(chave(userId))
    } catch { }
  }

  return { ordered, move, reset }
}
