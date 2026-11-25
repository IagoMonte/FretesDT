<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { auth, login } from '../stores/session'
import BrandMark from '../components/BrandMark.vue'
import { safeNext } from '../utils/next'

const router = useRouter()
const user = ref('')
const pass = ref('')
const error = ref('')
const pending = ref(false)

async function submit() {
  if (auth.loading) return
  error.value = ''
  pending.value = false
  const r = await login(user.value, pass.value)
  if (r.ok) {
    const next = safeNext(router.currentRoute.value.query.next)
    return next ? router.replace(next) : router.push({ name: auth.session.home || 'login' })
  }
  pending.value = r.code === 'CADASTRO_PENDENTE'
  error.value = r.error || 'Usuário ou senha inválidos.'
}
</script>

<template>
  <div class="login-screen">
    <div class="sw s1"></div><div class="sw s2"></div>
    <div class="sw s3h"></div><div class="sw s3"></div>
    <div class="sw s4h"></div><div class="sw s4"></div>
    <div class="sw ring1"></div><div class="sw ring2"></div>

    <div class="lg-left">
      <div class="lockup">
        <BrandMark variant="login" />
        <div class="word">FRETES</div>
        <div class="word2">DT</div>
        <div class="divline"></div>
        <div class="tagline">Gestão <span>de Fretes</span></div>
        <div class="lg-road">
          <svg class="lg-truck" width="112" height="44" viewBox="0 0 120 52" aria-hidden="true">
            <rect x="2" y="8" width="76" height="26" rx="3" fill="#D6001C" />
            <rect x="6" y="12" width="68" height="4" fill="#fff" opacity=".25" />
            <path d="M80 14h20l12 12v8H80V14z" fill="#00522B" />
            <path d="M84 18h14l8 8H84v-8z" fill="#BFE6D2" />
            <circle cx="22" cy="40" r="8" fill="#23302A" /><circle cx="22" cy="40" r="3.4" fill="#fff" />
            <circle cx="60" cy="40" r="8" fill="#23302A" /><circle cx="60" cy="40" r="3.4" fill="#fff" />
            <circle cx="98" cy="40" r="8" fill="#23302A" /><circle cx="98" cy="40" r="3.4" fill="#fff" />
          </svg>
        </div>
      </div>
      <div class="lg-foot">
        <span><i class="dotp"></i> Sistema operacional</span>
        <span>Transportadoras homologadas</span>
        <span>Cargas monitoradas 24h</span>
      </div>
    </div>

    <form class="lg-right" @submit.prevent="submit">
      <h2>Acesso ao <span>painel</span></h2>
      <p class="sub">FretesDT · Gestão de Fretes. Informe usuário e senha.</p>
      <div v-if="error" class="lg-err" :class="{ info: pending }" :role="pending ? 'status' : 'alert'" :data-code="pending ? 'CADASTRO_PENDENTE' : undefined">{{ error }}</div>
      <div class="field"><label for="lgUser">Usuário</label><input id="lgUser" v-model="user" maxlength="200" class="inp" placeholder="seu usuário" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" /></div>
      <div class="field"><label for="lgPass">Senha</label><input id="lgPass" v-model="pass" class="inp" type="password" placeholder="••••••••" autocomplete="current-password" @keydown.enter.prevent="submit" /></div>
      <button type="button" class="btn btn-primary btn-block" :disabled="auth.loading" :aria-busy="auth.loading" @click="submit">{{ auth.loading ? 'Entrando…' : 'Entrar na operação' }}</button>
      <p class="lg-alt">Ainda não tem acesso? <router-link :to="{ name: 'signup' }" class="lg-link" data-act="signup">Criar conta</router-link></p>
    </form>
  </div>
</template>
