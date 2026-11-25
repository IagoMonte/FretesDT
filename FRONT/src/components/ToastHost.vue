<script setup>
import { toasts } from '../stores/ui'
import NavIcon from './NavIcon.vue'
</script>

<template>
  <div class="toasts" role="region" aria-label="Notificações">
    <div v-for="t in toasts" :key="t.id" class="toast" :class="[t.type, { out: t.out }]" :role="t.type === 'err' ? 'alert' : 'status'">
      <span class="ti"><NavIcon :name="t.icon" :size="20" /></span>
      <span><template v-for="(p, i) in t.parts" :key="i"><b v-if="p.bold">{{ p.text }}</b><template v-else>{{ p.text }}</template></template></span>
    </div>
  </div>
</template>
