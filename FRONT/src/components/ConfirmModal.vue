<script setup>
import { ref } from 'vue'
import { modal, closeModal } from '../stores/ui'
import { useDialogFocus } from '../composables/useDialogFocus'
import RichText from './RichText.vue'
import NavIcon from './NavIcon.vue'
const dialog = ref(null)
const { onKeydown } = useDialogFocus(dialog)
</script>

<template>
  <div ref="dialog" class="modal" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-msg" tabindex="-1" @keydown="onKeydown">
    <div class="modal-h"><h3 id="confirm-title">{{ modal.payload.title || 'Confirmar exclusão' }}</h3><button class="x" type="button" aria-label="Fechar" @click="closeModal"><NavIcon name="x" :size="22" /></button></div>
    <div class="modal-b confirm-body">
      <div class="big"><NavIcon :name="modal.payload.icon || 'trash'" :size="36" /></div>
      <p id="confirm-msg"><RichText :text="modal.payload.message" /></p>
    </div>
    <div class="modal-f">
      <button class="btn btn-line" @click="closeModal">Cancelar</button>
      <button class="btn" :class="modal.payload.tone === 'primary' ? 'btn-primary' : 'btn-danger'" @click="modal.payload.onYes()">{{ modal.payload.label || 'Excluir' }}</button>
    </div>
  </div>
</template>
