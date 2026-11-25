<script setup>
import { computed } from 'vue'
import { parseBold } from '../utils/markup'

const props = defineProps({ text: String })
const parts = computed(() => parseBold(props.text))
</script>

<template>
  <template v-for="(p, i) in parts" :key="i"><b v-if="p.bold">{{ p.text }}</b><template v-else>{{ p.text }}</template></template>
</template>
