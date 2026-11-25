<script setup>
import { formatMoney, hasInvalidNumChars, maskMoney, numeroAmbiguo } from '../utils/format'
import { avisoNumero, avisoNumeroAmbiguo } from '../utils/numberInput'

defineProps({ modelValue: { type: String, default: '' }, placeholder: { type: String, default: '0,00' } })
const emit = defineEmits(['update:modelValue'])

let ultimo = ''
function onInput(e) {
  if (hasInvalidNumChars(e.target.value)) {
    e.target.value = ultimo
    return avisoNumero()
  }
  if (numeroAmbiguo(e.target.value)) {
    e.target.value = ultimo
    return avisoNumeroAmbiguo()
  }
  const v = maskMoney(e.target.value)
  ultimo = v
  if (v !== e.target.value) e.target.value = v
  emit('update:modelValue', v)
}
function onBlur(e) {
  const v = formatMoney(e.target.value)
  ultimo = v
  e.target.value = v
  emit('update:modelValue', v)
}
</script>

<template>
  <input class="inp" type="text" inputmode="decimal" autocomplete="off" :value="modelValue" :placeholder="placeholder" @input="onInput" @blur="onBlur" />
</template>
