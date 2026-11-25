<script setup>
import { computed } from 'vue'
import { auth } from '../stores/session'
import { createStatus } from '../factories/statusFactory'

const props = defineProps({
  status: { type: String, required: true },
  dot: Boolean,
  compact: Boolean,
  cotacao: Boolean,
})
const s = computed(() => createStatus(props.status, { carrier: props.cotacao && !!auth.session?.isCarrier }))
</script>

<template>
  <span class="pill" :class="[s.cls, { compact }]"><i v-if="dot" class="d" :class="{ pulse: s.pulse }"></i>{{ s.label }}</span>
</template>
