import { onBeforeUnmount, onMounted } from 'vue'

let count = 0
let savedY = 0
let fixed = false

const isIOS = () =>
  typeof navigator !== 'undefined' &&
  (/iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1))

export function lockScroll() {
  if (typeof document === 'undefined') return
  if (count++ > 0) return
  const html = document.documentElement
  savedY = window.scrollY || html.scrollTop || 0
  html.classList.add('scroll-locked')
  fixed = isIOS()
  if (fixed) {
    html.classList.add('scroll-fixed')
    document.body.style.top = `-${savedY}px`
  }
}

export function unlockScroll() {
  if (typeof document === 'undefined' || count === 0) return
  if (--count > 0) return
  const html = document.documentElement
  html.classList.remove('scroll-locked', 'scroll-fixed')
  if (fixed) {
    document.body.style.top = ''
    fixed = false
    const prev = html.style.scrollBehavior
    html.style.scrollBehavior = 'auto'
    window.scrollTo(0, savedY)
    html.style.scrollBehavior = prev
  }
}

export const scrollLockCount = () => count

export function useScrollLock() {
  let held = false
  onMounted(() => { lockScroll(); held = true })
  onBeforeUnmount(() => { if (held) { held = false; unlockScroll() } })
}
