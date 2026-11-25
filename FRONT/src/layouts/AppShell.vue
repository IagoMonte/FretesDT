<script setup>
import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'
import { watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { nav, closeNav } from '../stores/ui'
import { lockScroll, unlockScroll } from '../composables/useScrollLock'

const route = useRoute()
const skip = () => document.getElementById('conteudo')?.focus()
watch(() => route.name, closeNav)
watch(() => nav.open, (open, was) => { if (open && !was) lockScroll(); else if (!open && was) unlockScroll() }, { flush: 'sync' })
const onKey = (e) => { if (e.key === 'Escape' && nav.open) closeNav() }
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  if (nav.open) unlockScroll()
})
</script>

<template>
  <div class="app-shell">
    <a class="skip-link" href="#conteudo" @click.prevent="skip">Pular para o conteúdo</a>
    <AppSidebar />
    <div v-if="nav.open" class="nav-backdrop" data-nav-backdrop @click="closeNav"></div>
    <div class="main">
      <AppTopbar />
      <div class="road"></div>
      <main id="conteudo" class="view" tabindex="-1"><router-view /></main>
    </div>
  </div>
</template>
