<script setup>
import { computed } from 'vue'
import { fmtD, isPast } from '../utils/format'

const props = defineProps({ value: { type: String, default: null }, label: { type: Boolean, default: true } })
const expired = computed(() => isPast(props.value))
</script>

<template>
  <span v-if="value" class="validade" :class="{ venc: expired }" data-validade>
    <template v-if="label">Validade: </template>{{ fmtD(value) }}<span v-if="expired" class="pill compact st-can venc-tag" data-vencida>vencida</span>
  </span>
</template>
