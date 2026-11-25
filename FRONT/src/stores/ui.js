import { reactive } from 'vue'
import { parseBold } from '../utils/markup'

const ICONS = { ok: 'ok', err: 'err', info: 'info', warn: 'warn' }
let counter = 0

export const toasts = reactive([])
export const modal = reactive({ open: false, kind: null, payload: null })
export const nav = reactive({ open: false })
export const toggleNav = () => { nav.open = !nav.open }
export const closeNav = () => { nav.open = false }

export function pushToast(type, message, icon) {
  const parts0 = parseBold(message)
  const texto = (ps) => ps.map((p) => p.text).join('')
  const igual = toasts.find((x) => !x.out && x.type === type && texto(x.parts) === texto(parts0))
  if (igual) return igual
  const toast = {
    id: ++counter,
    type,
    icon: icon || ICONS[type] || 'info',
    parts: parseBold(message),
  }
  const t = reactive(toast)
  toasts.push(t)
  setTimeout(() => {
    t.out = true
    setTimeout(() => {
      const i = toasts.indexOf(t)
      if (i >= 0) toasts.splice(i, 1)
    }, 300)
  }, 4200)
  return t
}

export function openModal(kind, payload = null) {
  Object.assign(modal, { open: true, kind, payload })
}

export function closeModal() {
  Object.assign(modal, { open: false, kind: null, payload: null })
}

export function confirmAction(message, onYes, { title = 'Confirmar exclusão', label = 'Excluir', icon = 'trash', tone = 'danger' } = {}) {
  openModal('confirm', {
    message, title, label, icon, tone,
    onYes: () => {
      closeModal()
      onYes()
    },
  })
}

export const confirmDelete = (message, onYes) => confirmAction(message, onYes)

