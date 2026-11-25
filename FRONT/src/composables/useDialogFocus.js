import { watch } from 'vue'
import { isTouchOrNarrow } from './useMedia'

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'

export function useDialogFocus(rootRef) {
  const items = () => [...(rootRef.value?.querySelectorAll(FOCUSABLE) ?? [])]

  function onKeydown(e) {
    if (e.key !== 'Tab') return
    const list = items()
    if (!list.length) return e.preventDefault()
    const first = list[0]
    const last = list[list.length - 1]
    if (e.shiftKey && (document.activeElement === first || document.activeElement === rootRef.value)) { e.preventDefault(); last.focus() }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
  }

  watch(rootRef, (root) => {
    if (!root || root.contains(document.activeElement)) return
    const target = isTouchOrNarrow() ? null : root.querySelector('.modal-b input:not([type=hidden]),.modal-b select,.modal-b textarea')
    ;(target ?? root).focus({ preventScroll: true })
  }, { immediate: true, flush: 'post' })

  return { onKeydown }
}
