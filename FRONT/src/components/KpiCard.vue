<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  label: String,
  value: Number,
  suffix: { type: String, default: '' },
  text: String,
  sub: String,
  color: { type: String, default: 'var(--red)' },
})

const shown = ref(0)
let timer
onMounted(() => {
  if (props.text != null) return
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) { shown.value = props.value; return }
  const target = props.value
  const step = Math.max(1, Math.round(target / 34))
  timer = setInterval(() => {
    shown.value = Math.min(shown.value + step, target)
    if (shown.value >= target) clearInterval(timer)
  }, 28)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="kpi" :style="{ '--kc': color }">
    <div class="kl">{{ label }}</div>
    <div class="kv" :class="{ money: text != null }">{{ text != null ? text : shown + suffix }}</div>
    <div class="ks">{{ sub }}</div>
  </div>
</template>
