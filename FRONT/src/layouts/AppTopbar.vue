<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { auth } from '../stores/session'
import { nav, toggleNav } from '../stores/ui'
import NavIcon from '../components/NavIcon.vue'

const route = useRoute()
const title = computed(() => (route.name === 'quotes' && auth.session?.role === 'TRANSPORTADORA' ? ['Minhas', 'Cotações'] : route.meta.title || ['', '']))
const crumb = computed(() => 'FretesDT // ' + title.value.join(' ').toUpperCase())

const now = () => new Date().toLocaleTimeString('pt-BR')
const clock = ref(now())
let timer
onMounted(() => { timer = setInterval(() => (clock.value = now()), 1000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <header v-if="auth.session" class="topbar">
    <button class="nav-toggle" type="button" :aria-label="nav.open ? 'Fechar menu' : 'Abrir menu'" aria-controls="sideNav" :aria-expanded="nav.open" data-act="toggle-nav" @click="toggleNav"><NavIcon name="menu" :size="22" /></button>
    <div>
      <div class="crumb">{{ crumb }}</div>
      <h1>{{ title[0] }} <b>{{ title[1] }}</b></h1>
    </div>
    <div class="top-right">
      <span class="rolechip" :class="'role-' + auth.session.role" :title="auth.session.roleLabel"><span class="rc-role">{{ auth.session.role }}</span><span v-if="auth.session.roleOrg" class="rc-name"> · {{ auth.session.roleOrg }}</span></span>
      <span class="clock">{{ clock }}</span>
    </div>
  </header>
</template>
