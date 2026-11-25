import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Recarrega dados periodicamente enquanto a aba está visível (Page Visibility API).
 * - nunca empilha: o próximo ciclo só é agendado depois que `fn` termina (uma requisição por vez);
 * - aba oculta: para; ao voltar a ficar visível, atualiza na hora se já passou o intervalo;
 * - para ao desmontar o componente (sair da página);
 * - `paused()` (opcional) pula o ciclo, ex.: enquanto um formulário de edição está aberto.
 * `fn` deve ser "silenciosa" (sem piscar loading nem toast de erro).
 */
export function usePolling(fn, { interval = 15000, paused = null } = {}) {
  let timer = null
  let running = false
  let active = false
  let last = 0

  const visible = () => typeof document === 'undefined' || document.visibilityState !== 'hidden'
  const clear = () => { if (timer) { clearTimeout(timer); timer = null } }
  const schedule = (ms = interval) => {
    clear()
    if (active && visible()) timer = setTimeout(tick, ms)
  }

  async function tick() {
    timer = null
    if (!active) return
    if (running || !visible() || (paused && paused())) return schedule()
    running = true
    try { await fn() } catch { }
    last = Date.now()
    running = false
    schedule()
  }

  function onVisibility() {
    if (!active) return
    if (!visible()) return clear()
    schedule(Date.now() - last >= interval ? 0 : interval - (Date.now() - last))
  }

  function start() {
    if (active) return
    active = true
    last = Date.now()
    document.addEventListener('visibilitychange', onVisibility)
    schedule()
  }
  function stop() {
    active = false
    clear()
    document.removeEventListener('visibilitychange', onVisibility)
  }

  onMounted(start)
  onBeforeUnmount(stop)
  return { start, stop }
}
