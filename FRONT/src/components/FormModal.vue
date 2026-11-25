<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import { useDialogFocus } from '../composables/useDialogFocus'
import { useScrollLock } from '../composables/useScrollLock'
import { pushModal, removeModal, isTopModal } from '../composables/useModalStack'
import NavIcon from './NavIcon.vue'

const props = defineProps({ title: String, sub: String, size: { type: String, default: '' } })
const emit = defineEmits(['close'])
const dialog = ref(null)
const { onKeydown } = useDialogFocus(dialog)
useScrollLock()
const titleId = 'dlg-' + Math.random().toString(36).slice(2, 8)

const opener = typeof document !== 'undefined' ? document.activeElement : null
const onKey = (e) => { if (e.key === 'Escape' && isTopModal(titleId)) { e.stopImmediatePropagation(); emit('close') } }
pushModal(titleId)
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  removeModal(titleId)
  if (opener?.isConnected && opener !== document.body) opener.focus?.()
})
</script>

<template>
  <Teleport to="body">
    <div class="modal-ov modal-ov-form">
      <div ref="dialog" class="modal form" :class="props.size" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1" @keydown="onKeydown">
        <div class="modal-h">
          <h3 :id="titleId">{{ title }}</h3>
          <span v-if="sub" class="modal-sub">{{ sub }}</span>
          <button class="x" type="button" aria-label="Fechar" @click="emit('close')"><NavIcon name="x" :size="22" /></button>
        </div>
        <div class="modal-b"><slot /></div>
        <div class="modal-f"><slot name="footer" /></div>
      </div>
    </div>
  </Teleport>
</template>
