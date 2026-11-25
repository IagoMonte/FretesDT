<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({ parts: { type: Array, required: true }, height: { type: Number, default: 8 } })

const armed = ref(false)
let timer
onMounted(() => { timer = setTimeout(() => (armed.value = true), 80) })
onBeforeUnmount(() => clearTimeout(timer))
const tot = computed(() => props.parts.reduce((a, p) => a + p.val, 0))
const width = (p) => (armed.value && tot.value ? (p.val / tot.value) * 100 : 0)
</script>

<template>
  <div class="split" :style="{ height: height + 'px' }">
    <i v-for="(p, i) in parts" :key="i" :style="{ width: width(p) + '%', background: p.color }"></i>
  </div>
</template>
