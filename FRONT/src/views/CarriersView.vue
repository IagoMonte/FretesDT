<script setup>
import { onMounted, reactive, ref } from 'vue'
import { auth } from '../stores/session'
import { db, loadCarriers, saveCarrier, setCarrierActive } from '../stores/db'
import { pushToast, confirmAction } from '../stores/ui'
import { fmtPhone, maskCNPJ, maskPhone } from '../utils/format'
import CardPanel from '../components/CardPanel.vue'
import ListFilters from '../components/ListFilters.vue'
import { useListFilter } from '../composables/useListFilter'
import FormModal from '../components/FormModal.vue'
import EmptyState from '../components/EmptyState.vue'
import { useRequired } from '../composables/useRequired'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session
const editId = ref(null)
const open = ref(false)
const saving = ref(false)
const blank = () => ({ razao_social: '', nome_fantasia: '', cnpj: '', email: '', telefone: '', whatsapp_grupo_id: '' })
const form = reactive(blank())
const { bad, tentar, limpar } = useRequired(form, ['nome_fantasia', 'razao_social'])

onMounted(() => loadCarriers())

const { f: flt, visible, contagem } = useListFilter(() => db.carriers, {
  texto: (c) => [c.nome_fantasia, c.razao_social, c.cnpj, c.email, c.telefone, c.whatsapp_grupo_id, c.cidade].join(' '),
  filtros: { situacao: (c, v) => (c.ativo ? 'ATIVO' : 'INATIVO') === v, revisao: (c) => !!c.revisao_pendente },
})
const selects = [
  { key: 'situacao', label: 'Todas as situações', options: [{ value: 'ATIVO', label: 'Ativas' }, { value: 'INATIVO', label: 'Inativas' }] },
  { key: 'revisao', label: 'Todas', options: [{ value: 'sim', label: 'Revisar CNPJ' }] },
]

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
function startEdit(c) {
  editId.value = c.id
  Object.assign(form, { razao_social: c.razao_social, nome_fantasia: c.nome_fantasia, cnpj: maskCNPJ(c.cnpj), email: c.email ?? '', telefone: fmtPhone(c.telefone), whatsapp_grupo_id: c.whatsapp_grupo_id ?? '' })
  open.value = true
}
async function save() {
  tentar()
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await saveCarrier(form, editId.value)
  saving.value = false
  if (!r.ok) return pushToast('err', r.error)
  pushToast('ok', editing ? `Transportadora **${r.data.nome_fantasia}** atualizada.` : `Transportadora **${r.data.nome_fantasia}** incluída. Crie os usuários dela em **Usuários e Acessos**.`)
  reset()
}
function inactivate(c) {
  confirmAction(`Inativar a transportadora **${c.nome_fantasia}**? Ela deixa de receber novas cotações.`, async () => {
    const r = await setCarrierActive(c.id, false)
    r.ok ? pushToast('warn', `Transportadora **${c.nome_fantasia}** inativada.`) : pushToast('err', r.error)
  }, { title: 'Confirmar inativação', label: 'Inativar', icon: 'pause' })
}
async function activate(c) {
  const r = await setCarrierActive(c.id, true)
  r.ok ? pushToast('ok', `Transportadora **${c.nome_fantasia}** ativada.`) : pushToast('err', r.error)
}
</script>

<template>
  <CardPanel title="Transportadoras homologadas" :count="contagem(db.carriers.length, 'cadastradas')" flush>
    <template v-if="session.can('TRANSPORTADORA', 'inserir')" #actions><button class="btn btn-primary btn-sm" data-act="new-carrier" @click="startNew"><NavIcon name="plus" :size="14" /> Incluir transportadora</button></template>
    <ListFilters :f="flt" :selects="selects" label="Buscar transportadoras" placeholder="Buscar por nome, CNPJ, e-mail…" />
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de transportadoras">
      <table class="tbl" style="min-width:760px">
        <thead><tr><th class="act">Ações</th><th>Transportadora</th><th>Razão social</th><th>CNPJ</th><th>E-mail</th><th>Telefone</th><th>Situação</th></tr></thead>
        <tbody>
          <tr v-for="c in visible" :key="c.id" :class="{ off: !c.ativo }">
            <td class="act"><div v-if="session.can('TRANSPORTADORA', 'alterar')" class="wrap">
              <button class="ibtn ed" title="Editar" aria-label="Editar" @click="startEdit(c)"><NavIcon name="edit" :size="16" /></button>
              <button v-if="c.ativo && session.can('TRANSPORTADORA', 'cancelar')" class="ibtn dl" title="Inativar" aria-label="Inativar" @click="inactivate(c)"><NavIcon name="pause" :size="16" /></button>
              <button v-else-if="session.can('TRANSPORTADORA', 'cancelar')" class="ibtn ok" title="Ativar" aria-label="Ativar" @click="activate(c)"><NavIcon name="play" :size="16" /></button>
            </div></td>
            <td><b class="strong"><NavIcon name="truck" :size="16" /> {{ c.nome_fantasia }}</b></td>
            <td>{{ c.razao_social }}</td>
            <td class="num"><template v-if="c.cnpj">{{ maskCNPJ(c.cnpj) }}</template><span v-else-if="c.revisao_pendente" class="pill sm st-cot" title="Veio do ERP sem CNPJ: edite e preencha para encerrar a revisão">REVISAR CNPJ</span><span v-else class="mut">—</span></td>
            <td><template v-if="c.email">{{ c.email }}</template><span v-else class="mut">—</span></td>
            <td class="num"><template v-if="c.telefone">{{ fmtPhone(c.telefone) }}</template><span v-else class="mut">—</span></td>
            <td><span class="pill sm" :class="c.ativo ? 'st-ent' : 'st-dev'">{{ c.ativo ? 'ATIVA' : 'INATIVA' }}</span></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="7"><EmptyState list-key="carriers" icon="truck" :message="db.loading.carriers ? 'Carregando…' : db.carriers.length ? 'Nenhuma transportadora para esse filtro.' : 'Nenhuma transportadora cadastrada.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="editId ? 'Editar transportadora' : 'Incluir transportadora'" sub="acesso do administrador" @close="reset">
    <div class="fgrid">
      <div class="field c6"><label for="cr-fantasia">Nome fantasia <i>*</i></label>
        <input id="cr-fantasia" :class="{ invalid: bad('nome_fantasia') }" :aria-invalid="bad('nome_fantasia') || undefined" v-model="form.nome_fantasia" class="inp" placeholder="ex.: ARCO" style="text-transform:uppercase" /></div>
      <div class="field c6"><label for="cr-razao">Razão social <i>*</i></label>
        <input id="cr-razao" :class="{ invalid: bad('razao_social') }" :aria-invalid="bad('razao_social') || undefined" v-model="form.razao_social" class="inp" placeholder="razão social completa" /></div>
      <div class="field c6"><label for="cr-cnpj">CNPJ</label>
        <input id="cr-cnpj" :value="form.cnpj" class="inp" placeholder="00.000.000/0000-00" maxlength="18" @input="form.cnpj = maskCNPJ($event.target.value)" /></div>
      <div class="field c6"><label for="cr-tel">Telefone</label>
        <input id="cr-tel" :value="form.telefone" class="inp" placeholder="(00) 00000-0000" inputmode="tel" maxlength="15" @input="form.telefone = maskPhone($event.target.value)" /></div>
      <div class="field c12"><label for="cr-email">E-mail</label>
        <input id="cr-email" v-model="form.email" class="inp" type="email" placeholder="contato@transportadora.com.br" /></div>
      <div class="field c12"><label for="cr-grupo">ID do grupo de WhatsApp</label>
        <input id="cr-grupo" v-model="form.whatsapp_grupo_id" class="inp" placeholder="120363123456789012@g.us" autocomplete="off" spellcheck="false" />
        <small class="mut">Ao encaminhar, o aviso vai também para este grupo (validado na Evolution ao salvar), além do telefone e dos e-mails.</small></div>
      <div class="field c12 mut" style="font-size:12px">O acesso ao painel é criado em <b>Usuários e Acessos</b>, vinculando o usuário a esta transportadora.</div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">Cancelar</button>
      <button class="btn btn-primary" data-act="save-carrier" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir transportadora</template></button>
    </template>
  </FormModal>
</template>
