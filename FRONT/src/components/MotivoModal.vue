<script setup>
import { ref } from 'vue'
import FormModal from './FormModal.vue'

defineProps({ title: String, sub: String, label: { type: String, default: 'Confirmar cancelamento' }, opcional: Boolean, busy: Boolean, error: { type: String, default: '' } })
const emit = defineEmits(['close', 'confirm'])
const motivo = ref('')
</script>

<template>
  <FormModal :title="title" :sub="sub" @close="emit('close')">
    <div class="field"><label for="mt-motivo">Motivo <i v-if="!opcional">*</i><template v-else>(opcional)</template></label>
      <textarea id="mt-motivo" v-model="motivo" class="inp" :class="{ invalid: error }" rows="3" placeholder="descreva o motivo" :aria-invalid="error ? 'true' : undefined" :aria-describedby="error ? 'mt-motivo-err' : undefined"></textarea>
      <div v-if="error" id="mt-motivo-err" class="ferr" role="alert">{{ error }}</div></div>
    <template #footer>
      <button class="btn btn-line" @click="emit('close')">Voltar</button>
      <button :class="opcional ? 'btn-primary' : 'btn-danger'" class="btn" data-act="confirm-motivo" :disabled="busy" :aria-busy="busy" @click="emit('confirm', motivo)">{{ label }}</button>
    </template>
  </FormModal>
</template>
