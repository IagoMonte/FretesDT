<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth, logout } from '../stores/session'
import { db, loadPendingCount } from '../stores/db'
import { usePolling } from '../composables/usePolling'
import { useMenuOrder } from '../composables/useMenuOrder'
import { nav, closeNav } from '../stores/ui'
import BrandMark from '../components/BrandMark.vue'
import NavIcon from '../components/NavIcon.vue'

const route = useRoute()
const router = useRouter()

const badge = (key) => (key === 'registrations' && db.pendingCount > 0 ? db.pendingCount : 0)
const label = (m) => (badge(m.key) ? `${m.label}, ${badge(m.key)} ${badge(m.key) === 1 ? 'pendente' : 'pendentes'}` : m.label)
const refreshBadge = () => (auth.session?.canAction('USUARIO.APROVAR_CADASTRO') ? loadPendingCount() : null)
onMounted(refreshBadge)
usePolling(refreshBadge, { interval: 20000 })

const { ordered, move } = useMenuOrder(() => auth.session?.menu ?? [], auth.session?.login ?? 'anon')
const arrastando = ref(null)
const sobre = ref(null)
const fimDoArrasto = () => { arrastando.value = null; sobre.value = null }
function soltar(key) {
  if (arrastando.value) move(arrastando.value, key)
  fimDoArrasto()
}
async function teclar(e, key) {
  if (!e.altKey || !['ArrowUp', 'ArrowDown'].includes(e.key)) return
  e.preventDefault()
  const keys = ordered.value.map((m) => m.key)
  const alvo = keys[keys.indexOf(key) + (e.key === 'ArrowUp' ? -1 : 1)]
  if (alvo) move(key, alvo)
  await nextTick()
  e.target?.closest?.('nav')?.querySelector?.(`[data-menu="${key}"]`)?.focus?.()
}

function exit() {
  logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <aside v-if="auth.session" id="sideNav" class="side" :class="{ open: nav.open }">
    <div class="side-brand">
      <div class="row">
        <BrandMark variant="side" />
        <div><b>FRETES<em>DT</em></b><small>GESTÃO DE FRETES</small></div>
      </div>
    </div>
    <nav class="nav" aria-label="Módulos">
      <div class="nav-cap">Módulos</div>
      <button v-for="m in ordered" :key="m.key" type="button" class="nav-item" :class="{ on: route.name === m.key, drag: arrastando === m.key, over: sobre === m.key && arrastando !== m.key }" :data-menu="m.key"
        draggable="true" @dragstart="(e) => { arrastando = m.key; e.dataTransfer?.setData('text/plain', m.key) }" @dragover.prevent="sobre = m.key" @drop.prevent="soltar(m.key)" @dragend="fimDoArrasto" @keydown="teclar($event, m.key)"
        :title="m.label" :aria-label="label(m)" :aria-current="route.name === m.key ? 'page' : undefined" @click="router.push({ name: m.key }); closeNav()">
        <NavIcon :name="m.key" /><span>{{ m.label }}</span><b v-if="badge(m.key)" class="nav-badge" aria-hidden="true">{{ badge(m.key) }}</b>
      </button>
    </nav>
    <div class="side-foot">
      <div class="uchip">
        <div class="av">{{ auth.session.avatar }}</div>
        <div><div class="nm">{{ auth.session.name }}</div><div class="rl">{{ auth.session.role }}</div></div>
      </div>
      <button type="button" class="btn-out" title="Sair do painel" aria-label="Sair do painel" @click="exit"><NavIcon name="power" :size="16" /><span>Sair do painel</span></button>
    </div>
  </aside>
</template>
