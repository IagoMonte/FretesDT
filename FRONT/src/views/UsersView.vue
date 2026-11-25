<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { db, loadUsers, loadGroups, loadCarriers, saveUser, setUserBlocked } from '../stores/db'
import { pushToast, confirmAction } from '../stores/ui'
import CardPanel from '../components/CardPanel.vue'
import ListFilters from '../components/ListFilters.vue'
import { useListFilter } from '../composables/useListFilter'
import ChipGroup from '../components/ChipGroup.vue'
import FormModal from '../components/FormModal.vue'
import EmptyState from '../components/EmptyState.vue'
import { useRequired } from '../composables/useRequired'
import NavIcon from '../components/NavIcon.vue'
import { fmtPhone, maskPhone } from '../utils/format'

const editId = ref(null)
const open = ref(false)
const saving = ref(false)
const blank = () => ({ nome: '', login: '', email: '', telefone: '', senha: '', grupos: [], transportadora_id: '' })
const form = reactive(blank())
const { bad, tentar, limpar } = useRequired(form, ['nome', 'login'])

const groupOptions = computed(() => db.groups.map((g) => ({ value: g.codigo, label: g.nome })))
const needsCarrier = computed(() => form.grupos.some((g) => db.groups.find((x) => x.codigo === g)?.tipo_usuario === 'TRANSPORTADORA'))
const activeCarriers = computed(() => db.carriers.filter((c) => c.ativo))
const groupName = (code) => db.groups.find((g) => g.codigo === code)?.nome || code
const { f: flt, visible, contagem } = useListFilter(() => db.users, {
  texto: (u) => [u.nome, u.login, u.email, u.telefone, ...(u.grupos ?? []).map(groupName), carrierName(u.transportadora_id)].join(' '),
  filtros: {
    situacao: (u, v) => (u.situacao === 'ATIVO' ? 'ATIVO' : 'INATIVO') === v,
    grupo: (u, v) => (u.grupos ?? []).includes(v),
  },
})
const selects = computed(() => [
  { key: 'situacao', label: 'Todas as situações', options: [{ value: 'ATIVO', label: 'Ativos' }, { value: 'INATIVO', label: 'Inativos' }] },
  { key: 'grupo', label: 'Todos os grupos', options: groupOptions.value },
])
const carrierName = (id) => db.carriers.find((c) => c.id === id)?.nome_fantasia || (db.loading.carriers ? null : '—')

onMounted(() => { loadUsers(); loadGroups(); loadCarriers() })

function reset() {
  limpar()
  open.value = false
  editId.value = null
  Object.assign(form, blank())
}
function startNew() {
  reset()
  open.value = true
}
function startEdit(u) {
  editId.value = u.id
  Object.assign(form, { nome: u.nome, login: u.login, email: u.email ?? '', telefone: fmtPhone(u.telefone), senha: '', grupos: [...u.grupos], transportadora_id: u.transportadora_id ?? '' })
  open.value = true
}
async function save() {
  tentar()
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await saveUser(form, editId.value)
  saving.value = false
  if (!r.ok) {
    const loginError = r.errors?.login?.[0]
    return pushToast('err', loginError || r.error)
  }
  pushToast('ok', editing ? `Usuário **${r.data.nome}** atualizado.` : `Usuário **${r.data.nome}** criado — já pode entrar com o login **${r.data.login}**.`)
  reset()
}
function block(u) {
  confirmAction(`Bloquear o usuário **${u.nome}**? Ele perde o acesso ao painel.`, async () => {
    const r = await setUserBlocked(u.id, true)
    r.ok ? pushToast('warn', `Usuário **${u.nome}** bloqueado.`) : pushToast('err', r.error)
  }, { title: 'Confirmar bloqueio', label: 'Bloquear', icon: 'lock' })
}
async function unblock(u) {
  const r = await setUserBlocked(u.id, false)
  r.ok ? pushToast('ok', `Usuário **${u.nome}** reativado.`) : pushToast('err', r.error)
}
</script>

<template>
  <CardPanel title="Usuários e acessos" :count="contagem(db.users.length, 'usuários')" flush>
    <template #actions><button class="btn btn-primary btn-sm" data-act="new-user" @click="startNew"><NavIcon name="plus" :size="14" /> Incluir usuário</button></template>
    <ListFilters :f="flt" :selects="selects" label="Buscar usuários" placeholder="Buscar por nome, login, grupo…" />
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de usuários">
      <table class="tbl" style="min-width:760px">
        <thead><tr><th class="act">Ações</th><th>Nome</th><th>Login</th><th>Telefone</th><th>Grupos</th><th>Transportadora</th><th>Situação</th></tr></thead>
        <tbody>
          <tr v-for="u in visible" :key="u.id" :class="{ off: u.situacao !== 'ATIVO' }">
            <td class="act"><div class="wrap">
              <button class="ibtn ed" title="Editar" aria-label="Editar" @click="startEdit(u)"><NavIcon name="edit" :size="16" /></button>
              <button v-if="u.situacao === 'ATIVO'" class="ibtn dl" title="Bloquear" aria-label="Bloquear" @click="block(u)"><NavIcon name="lock" :size="16" /></button>
              <button v-else class="ibtn ok" title="Ativar" aria-label="Ativar" @click="unblock(u)"><NavIcon name="unlock" :size="16" /></button>
            </div></td>
            <td><b class="strong">{{ u.nome }}</b></td>
            <td class="num">{{ u.login }}</td>
            <td class="num"><template v-if="u.telefone">{{ fmtPhone(u.telefone) }}</template><span v-else class="mut">—</span></td>
            <td><span v-for="g in u.grupos" :key="g" class="pill st-tra" style="margin:2px 3px 2px 0">{{ groupName(g) }}</span></td>
            <td><template v-if="u.transportadora_id"><span v-if="carrierName(u.transportadora_id) === null" class="mut" data-carrier-loading>Carregando…</span><template v-else><NavIcon name="truck" :size="16" /> {{ carrierName(u.transportadora_id) }}</template></template><span v-else class="mut">—</span></td>
            <td><span class="pill sm" :class="u.situacao === 'ATIVO' ? 'st-ent' : 'st-dev'">{{ u.situacao }}</span></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="7"><EmptyState list-key="users" icon="user" :message="db.loading.users ? 'Carregando…' : db.users.length ? 'Nenhum usuário para esse filtro.' : 'Nenhum usuário cadastrado.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="editId ? 'Editar usuário' : 'Incluir usuário'" sub="acesso do administrador" @close="reset">
    <div class="fgrid">
      <div class="field c6"><label for="us-nome">Nome <i>*</i></label><input id="us-nome" :class="{ invalid: bad('nome') }" :aria-invalid="bad('nome') || undefined" v-model="form.nome" class="inp" placeholder="nome completo" /></div>
      <div class="field c6"><label for="us-login">Login <i>*</i></label><input id="us-login" :class="{ invalid: bad('login') }" :aria-invalid="bad('login') || undefined" v-model="form.login" class="inp" placeholder="login" maxlength="200" autocomplete="off" /></div>
      <div class="field c6"><label for="us-email">E-mail</label><input id="us-email" v-model="form.email" class="inp" type="email" placeholder="opcional" /></div>
      <div class="field c6"><label for="us-tel">Telefone (WhatsApp)</label>
        <input id="us-tel" :value="form.telefone" class="inp" placeholder="(00) 00000-0000" inputmode="tel" maxlength="15" @input="form.telefone = maskPhone($event.target.value)" /></div>
      <div class="field c6"><label for="us-senha">Senha <i v-if="!editId">*</i></label>
        <input id="us-senha" v-model="form.senha" class="inp" type="password" autocomplete="new-password" :placeholder="editId ? 'deixe em branco para manter' : 'senha de acesso'" /></div>
      <div class="field c12"><label>Grupos de acesso <i>*</i></label><ChipGroup v-model="form.grupos" :options="groupOptions" /></div>
      <div v-if="needsCarrier" class="field c12"><label for="us-transp">Transportadora <i>*</i></label>
        <select id="us-transp" v-model="form.transportadora_id" class="inp"><option value="">— selecionar —</option><option v-for="c in activeCarriers" :key="c.id" :value="c.id">{{ c.nome_fantasia }}</option></select></div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">Cancelar</button>
      <button class="btn btn-primary" data-act="save-user" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir usuário</template></button>
    </template>
  </FormModal>
</template>
