<script setup>
import { onMounted, onBeforeUnmount, watch } from 'vue'
import { modal, closeModal } from '../stores/ui'
import { lockScroll, unlockScroll } from '../composables/useScrollLock'
import { pushModal, removeModal, isTopModal } from '../composables/useModalStack'
import ConfirmModal from './ConfirmModal.vue'
import QuoteModal from './QuoteModal.vue'
import LoadModal from './LoadModal.vue'

const STACK_ID = 'host-' + Math.random().toString(36).slice(2, 8)
const onKey = (e) => { if (e.key === 'Escape' && modal.open && isTopModal(STACK_ID)) { e.stopImmediatePropagation(); closeModal() } }
onMounted(() => { document.addEventListener('keydown', onKey); if (modal.open) { lockScroll(); pushModal(STACK_ID) } })
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  removeModal(STACK_ID)
  if (modal.open) unlockScroll()
})

watch(() => modal.payload, () => {
  if (modal.open && modal.kind === 'confirm') { removeModal(STACK_ID); pushModal(STACK_ID) }
}, { flush: 'sync' })

let opener = null
watch(() => modal.open, (open, was) => {
  if (open && !was) { lockScroll(); pushModal(STACK_ID) }
  else if (!open && was) { unlockScroll(); removeModal(STACK_ID) }
  if (open) opener = document.activeElement
  else {
    if (opener?.isConnected && opener !== document.body) opener.focus?.()
    opener = null
  }
}, { flush: 'sync' })
</script>

<template>
  <div v-if="modal.open" class="modal-ov" :class="{ 'modal-ov-top': modal.kind === 'confirm' }">
    <ConfirmModal v-if="modal.kind === 'confirm'" />
    <QuoteModal v-else-if="modal.kind === 'quote'" />
    <LoadModal v-else-if="modal.kind === 'load'" />
  </div>
</template>
