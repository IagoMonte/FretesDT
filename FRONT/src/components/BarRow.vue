<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({ label: String, count: Number, max: Number, color: String })

const armed = ref(false)
let timer
onMounted(() => { timer = setTimeout(() => (armed.value = true), 80) })
onBeforeUnmount(() => clearTimeout(timer))
const width = computed(() => (armed.value ? (props.count / (props.max || 1)) * 100 : 0))
</script>

<template>
  <div class="bar-row">
    <span class="bl">{{ label }}</span>
    <span class="tr"><span class="fl" :style="{ width: width + '%', background: color }"></span></span>
    <span class="bn">{{ count }}</span>
  </div>
</template>
