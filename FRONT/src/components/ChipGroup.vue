<script setup>
import { computed } from 'vue'

const props = defineProps({ modelValue: { type: Array, default: () => [] }, options: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue'])

const opts = computed(() => props.options.map((o) => (typeof o === 'object' ? o : { value: o, label: o })))

function toggle(value, checked) {
  const next = props.modelValue.filter((x) => x !== value)
  if (checked) next.push(value)
  emit('update:modelValue', opts.value.map((o) => o.value).filter((v) => next.includes(v)))
}
</script>

<template>
  <div class="chipz">
    <label v-for="o in opts" :key="o.value" :class="{ on: modelValue.includes(o.value) }">
      <input type="checkbox" :value="o.value" :checked="modelValue.includes(o.value)" @change="toggle(o.value, $event.target.checked)" />{{ o.label }}
    </label>
  </div>
</template>
