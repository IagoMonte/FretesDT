<script setup>
defineProps({ title: String, count: String, flush: Boolean })
</script>

<template>
  <section class="card">
    <header class="card-h">
      <div class="tag"></div>
      <h2>{{ title }}</h2>
      <span v-if="count" class="cnt">{{ count }}</span>
      <div v-if="$slots.actions" class="acts"><slot name="actions" /></div>
    </header>
    <slot v-if="flush" />
    <div v-else class="card-b"><slot /></div>
  </section>
</template>
