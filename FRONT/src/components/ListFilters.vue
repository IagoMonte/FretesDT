<script setup>
defineProps({ f: { type: Object, required: true }, selects: { type: Array, default: () => [] }, placeholder: { type: String, default: 'Buscar…' }, search: { type: Boolean, default: true }, label: { type: String, default: 'Buscar' } })
</script>

<template>
  <div class="filt" data-filters>
    <input v-if="search" v-model="f.text" class="inp" type="search" :aria-label="label" :placeholder="placeholder" data-flt="text" />
    <select v-for="s in selects" :key="s.key" v-model="f[s.key]" class="inp" :aria-label="s.label" :data-flt="s.key">
      <option value="">{{ s.label }}</option>
      <option v-for="o in s.options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
  </div>
</template>
