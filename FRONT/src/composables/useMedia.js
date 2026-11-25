import { onBeforeUnmount, onMounted, ref } from 'vue'

export function useMedia(query) {
  const matches = ref(false)
  let mql = null
  const sync = () => { matches.value = !!mql?.matches }
  onMounted(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return
    mql = window.matchMedia(query)
    sync()
    mql.addEventListener?.('change', sync)
  })
  onBeforeUnmount(() => mql?.removeEventListener?.('change', sync))
  return matches
}

export const useIsMobile = () => useMedia('(max-width: 768px)')

export const isTouchOrNarrow = () =>
  typeof window !== 'undefined' && !!window.matchMedia && window.matchMedia('(max-width: 768px), (pointer: coarse)').matches
