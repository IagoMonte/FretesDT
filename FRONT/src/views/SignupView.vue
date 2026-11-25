<script setup>
import { computed, nextTick, reactive, ref } from 'vue'
import { register } from '../services/auth'
import { maskCNPJ, isValidCNPJ } from '../utils/format'
import BrandMark from '../components/BrandMark.vue'
import NavIcon from '../components/NavIcon.vue'

const form = reactive({
  tipo: 'VENDEDOR', nome: '', login: '', email: '', senha: '', confirmacao: '',
  razao_social: '', nome_fantasia: '', cnpj: '',
})
const attempted = ref(false)
const busy = ref(false)
const done = ref(false)
const formError = ref('')
const serverErrors = reactive({})

const isCarrier = computed(() => form.tipo === 'TRANSPORTADORA')
const normLogin = () => form.login.trim().toLowerCase()

const ORDER = ['nome', 'login', 'email', 'senha', 'confirmacao', 'razao_social', 'cnpj']
const ID = { nome: 'sg-nome', login: 'sg-login', email: 'sg-email', senha: 'sg-senha', confirmacao: 'sg-conf', razao_social: 'sg-razao', cnpj: 'sg-cnpj' }

function validate() {
  const e = {}
  if (!form.nome.trim()) e.nome = 'Informe seu nome.'
  const l = normLogin()
  if (!l) e.login = 'Informe um login.'
  else if (l.length < 3) e.login = 'O login deve ter pelo menos 3 caracteres.'
  else if (!/^[a-z0-9._@+-]+$/.test(l)) e.login = 'Use apenas letras, números, ponto, hífen, sublinhado ou @.'
  if (!form.email.trim()) e.email = 'Informe seu e-mail.'
  else if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) e.email = 'E-mail inválido.'
  if (!form.senha) e.senha = 'Crie uma senha.'
  else if (form.senha.length < 8) e.senha = 'A senha deve ter pelo menos 8 caracteres.'
  if (!form.confirmacao) e.confirmacao = 'Repita a senha.'
  else if (form.confirmacao !== form.senha) e.confirmacao = 'A confirmação não confere com a senha.'
  if (isCarrier.value) {
    if (!form.razao_social.trim()) e.razao_social = 'Informe a razão social.'
    if (!form.cnpj.trim()) e.cnpj = 'Informe o CNPJ.'
    else if (!isValidCNPJ(form.cnpj)) e.cnpj = 'CNPJ inválido. Confira os dígitos.'
  }
  return e
}

const local = computed(() => (attempted.value ? validate() : {}))
const err = (f) => local.value[f] || serverErrors[f] || ''
const bind = (f) => ({ id: ID[f], 'aria-invalid': err(f) ? 'true' : undefined, 'aria-describedby': err(f) ? `${ID[f]}-err` : undefined })

function touch(f) { delete serverErrors[f]; formError.value = '' }

async function submit() {
  if (busy.value) return
  attempted.value = true
  formError.value = ''
  const e = validate()
  const first = ORDER.find((f) => e[f])
  if (first) return nextTick(() => document.getElementById(ID[first])?.focus())

  busy.value = true
  const body = {
    tipo: form.tipo, nome: form.nome.trim(), login: normLogin(), email: form.email.trim().toLowerCase(),
    senha: form.senha, senha_confirmation: form.confirmacao,
  }
  if (isCarrier.value) Object.assign(body, { razao_social: form.razao_social.trim(), nome_fantasia: form.nome_fantasia.trim() || null, cnpj: form.cnpj.replace(/\D/g, '') })
  const r = await register(body)
  busy.value = false
  if (r.ok) { done.value = true; return }

  Object.keys(serverErrors).forEach((k) => delete serverErrors[k])
  const map = { senha: 'senha', senha_confirmation: 'confirmacao' }
  let shown = false
  for (const [k, msgs] of Object.entries(r.errors || {})) {
    const f = map[k] || k
    if (ID[f] || ['nome_fantasia', 'tipo'].includes(f)) { serverErrors[f] = msgs[0]; shown = true }
  }
  if (!shown) formError.value = r.error
  const firstServer = ORDER.find((f) => serverErrors[f])
  nextTick(() => (firstServer ? document.getElementById(ID[firstServer])?.focus() : null))
}
</script>

<template>
  <div class="login-screen is-signup">
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
      </div>
    </div>

    <section v-if="done" class="lg-right" aria-labelledby="sg-ok-title" data-signup-done>
      <h2 id="sg-ok-title">Cadastro <span>enviado</span></h2>
      <div class="lg-ok" role="status"><NavIcon name="ok" :size="20" /><p>Cadastro enviado. Aguarde a aprovação do administrador.</p></div>
      <p class="sub">Você poderá entrar assim que o cadastro for aprovado. Se for recusado, o motivo aparece ao tentar entrar.</p>
      <router-link :to="{ name: 'login' }" class="btn btn-primary btn-block" data-act="back-login">Voltar ao login</router-link>
    </section>

    <form v-else class="lg-right" novalidate aria-labelledby="sg-title" @submit.prevent="submit">
      <h2 id="sg-title">Criar <span>conta</span></h2>
      <p class="sub">O acesso é liberado depois da aprovação do administrador.</p>
      <div v-if="formError" class="lg-err" role="alert">{{ formError }}</div>

      <fieldset class="field sg-type">
        <legend class="lbl">Tipo de acesso <i>*</i></legend>
        <div class="chipz">
          <label :class="{ on: form.tipo === 'VENDEDOR' }"><input v-model="form.tipo" type="radio" name="sg-tipo" value="VENDEDOR" />Vendedor</label>
          <label :class="{ on: form.tipo === 'TRANSPORTADORA' }"><input v-model="form.tipo" type="radio" name="sg-tipo" value="TRANSPORTADORA" />Transportadora</label>
        </div>
      </fieldset>

      <div class="fgrid sg-grid">
        <div class="field c12"><label for="sg-nome">Nome <i>*</i></label>
          <input v-bind="bind('nome')" v-model="form.nome" maxlength="150" class="inp" :class="{ invalid: !!err('nome') }" autocomplete="name" placeholder="seu nome completo" @input="touch('nome')" />
          <div v-if="err('nome')" id="sg-nome-err" class="ferr" role="alert">{{ err('nome') }}</div></div>
        <div class="field c6"><label for="sg-login">Login <i>*</i></label>
          <input v-bind="bind('login')" v-model="form.login" maxlength="200" class="inp" :class="{ invalid: !!err('login') }" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" placeholder="ex.: maria.souza" @input="touch('login')" />
          <div v-if="err('login')" id="sg-login-err" class="ferr" role="alert">{{ err('login') }}</div></div>
        <div class="field c6"><label for="sg-email">E-mail <i>*</i></label>
          <input v-bind="bind('email')" v-model="form.email" maxlength="200" class="inp" :class="{ invalid: !!err('email') }" type="email" autocomplete="email" autocapitalize="none" placeholder="voce@empresa.com.br" @input="touch('email')" />
          <div v-if="err('email')" id="sg-email-err" class="ferr" role="alert">{{ err('email') }}</div></div>
        <div class="field c6"><label for="sg-senha">Senha <i>*</i></label>
          <input v-bind="bind('senha')" v-model="form.senha" class="inp" :class="{ invalid: !!err('senha') }" type="password" autocomplete="new-password" placeholder="mínimo 8 caracteres" @input="touch('senha')" />
          <div v-if="err('senha')" id="sg-senha-err" class="ferr" role="alert">{{ err('senha') }}</div></div>
        <div class="field c6"><label for="sg-conf">Confirmar senha <i>*</i></label>
          <input v-bind="bind('confirmacao')" v-model="form.confirmacao" class="inp" :class="{ invalid: !!err('confirmacao') }" type="password" autocomplete="new-password" placeholder="repita a senha" @input="touch('confirmacao')" />
          <div v-if="err('confirmacao')" id="sg-conf-err" class="ferr" role="alert">{{ err('confirmacao') }}</div></div>

        <template v-if="isCarrier">
          <div class="field c12"><label for="sg-razao">Razão social <i>*</i></label>
            <input v-bind="bind('razao_social')" v-model="form.razao_social" class="inp" :class="{ invalid: !!err('razao_social') }" autocomplete="organization" placeholder="razão social da transportadora" @input="touch('razao_social')" />
            <div v-if="err('razao_social')" id="sg-razao-err" class="ferr" role="alert">{{ err('razao_social') }}</div></div>
          <div class="field c6"><label for="sg-fantasia">Nome fantasia</label>
            <input id="sg-fantasia" v-model="form.nome_fantasia" class="inp" placeholder="como é conhecida" /></div>
          <div class="field c6"><label for="sg-cnpj">CNPJ <i>*</i></label>
            <input v-bind="bind('cnpj')" :value="form.cnpj" class="inp" :class="{ invalid: !!err('cnpj') }" inputmode="numeric" placeholder="00.000.000/0000-00" maxlength="18" @input="form.cnpj = maskCNPJ($event.target.value); touch('cnpj')" />
            <div v-if="err('cnpj')" id="sg-cnpj-err" class="ferr" role="alert">{{ err('cnpj') }}</div></div>
        </template>
      </div>

      <button type="submit" class="btn btn-primary btn-block sg-submit" :disabled="busy" :aria-busy="busy">{{ busy ? 'Enviando…' : 'Enviar cadastro' }}</button>
      <p class="lg-alt">Já tem acesso? <router-link :to="{ name: 'login' }" class="lg-link" data-act="to-login">Entrar</router-link></p>
    </form>
  </div>
</template>
