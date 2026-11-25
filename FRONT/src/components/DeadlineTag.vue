<script setup>
import { computed } from 'vue'
import { createDeadline } from '../factories/statusFactory'
import NavIcon from './NavIcon.vue'

const props = defineProps({ dataEntregaRealizada: String, entregueNoPrazo: { type: Boolean, default: null }, status: String })
const d = computed(() => createDeadline(props))
</script>

<template>
  <span :class="[d.cls, 'prz']"><NavIcon v-if="d.cls === 'prz-ok'" name="check" :size="12" /><NavIcon v-else-if="d.cls === 'prz-bad'" name="warn" :size="12" />{{ d.label }}</span>
</template>
