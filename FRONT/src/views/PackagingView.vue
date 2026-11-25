<script setup>
import { onMounted, reactive, ref } from 'vue'
import { auth } from '../stores/session'
import { db, loadPackagings, savePackaging, setPackagingActive } from '../stores/db'
import { pushToast, confirmAction } from '../stores/ui'
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
const form = reactive({ codigo: '', descricao: '', permite_paletizar: false, permite_remontar: false, granel: false, fracionada: false })
const { bad, tentar, limpar } = useRequired(form, ['codigo', 'descricao'])

onMounted(() => loadPackagings())

const { f: flt, visible, contagem } = useListFilter(() => db.packagings, {
  texto: (e) => [e.codigo, e.descricao].join(' '),
  filtros: {
    situacao: (e, v) => (e.ativo ? 'ATIVO' : 'INATIVO') === v,
    tipo: (e, v) => (v === 'GRANEL' ? !!e.granel : v === 'FRACIONADA' ? !!e.fracionada : !e.granel && !e.fracionada),
    revisao: (e) => !!e.revisao_pendente,
  },
})
const selects = [
  { key: 'situacao', label: 'Todas as situações', options: [{ value: 'ATIVO', label: 'Ativas' }, { value: 'INATIVO', label: 'Inativas' }] },
  { key: 'tipo', label: 'Todos os tipos', options: [{ value: 'GRANEL', label: 'Granel' }, { value: 'FRACIONADA', label: 'Fracionada' }, { value: 'COMUM', label: 'Demais' }] },
  { key: 'revisao', label: 'Todas', options: [{ value: 'sim', label: 'Revisar' }] },
]

function reset() {
  limpar()
  open.value = false
  editId.value = null
  Object.assign(form, { codigo: '', descricao: '', permite_paletizar: false, permite_remontar: false, granel: false, fracionada: false })
}
function startNew() {
  reset()
  open.value = true
}
function startEdit(e) {
  editId.value = e.id
  Object.assign(form, { codigo: e.codigo, descricao: e.descricao, permite_paletizar: e.permite_paletizar, permite_remontar: e.permite_remontar, granel: e.granel, fracionada: e.fracionada })
  open.value = true
}
async function save() {
  tentar()
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await savePackaging(form, editId.value)
  saving.value = false
  if (!r.ok) return pushToast('err', r.error)
  pushToast('ok', editing ? `Embalagem **${r.data.descricao}** atualizada.` : `Embalagem **${r.data.descricao}** incluída com sucesso.`)
  reset()
}
function inactivate(e) {
  confirmAction(`Inativar a embalagem **${e.descricao}**? Ela deixa de ser oferecida em novos cadastros.`, async () => {
    const r = await setPackagingActive(e.id, false)
    r.ok ? pushToast('warn', `Embalagem **${e.descricao}** inativada.`) : pushToast('err', r.error)
  }, { title: 'Confirmar inativação', label: 'Inativar', icon: 'pause' })
}
async function activate(e) {
  const r = await setPackagingActive(e.id, true)
  r.ok ? pushToast('ok', `Embalagem **${e.descricao}** ativada.`) : pushToast('err', r.error)
}
</script>

<template>
  <CardPanel title="Embalagens cadastradas" :count="contagem(db.packagings.length, 'itens')" flush>
    <template v-if="session.can('EMBALAGEM', 'inserir')" #actions><button class="btn btn-primary btn-sm" data-act="new-packaging" @click="startNew"><NavIcon name="plus" :size="14" /> Incluir embalagem</button></template>
    <ListFilters :f="flt" :selects="selects" label="Buscar embalagens" placeholder="Buscar por código ou descrição…" />
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de embalagens">
      <table class="tbl" style="min-width:640px">
        <thead><tr><th class="act">Ações</th><th>Código</th><th>Descrição</th><th>Permissões</th><th>Situação</th></tr></thead>
        <tbody>
          <tr v-for="e in visible" :key="e.id" :class="{ off: !e.ativo }">
            <td class="act"><div class="wrap" v-if="session.can('EMBALAGEM', 'alterar')">
              <button class="ibtn ed" title="Editar" aria-label="Editar" @click="startEdit(e)"><NavIcon name="edit" :size="16" /></button>
              <button v-if="e.ativo && session.can('EMBALAGEM', 'cancelar')" class="ibtn dl" title="Inativar" aria-label="Inativar" @click="inactivate(e)"><NavIcon name="pause" :size="16" /></button>
              <button v-else-if="session.can('EMBALAGEM', 'cancelar')" class="ibtn ok" title="Ativar" aria-label="Ativar" @click="activate(e)"><NavIcon name="play" :size="16" /></button>
            </div></td>
            <td><span class="mut">{{ e.codigo }}</span></td>
            <td><b class="strong">{{ e.descricao }}</b> <span v-if="e.revisao_pendente" class="pill sm st-cot" title="Veio do ERP com opções sugeridas: confira e salve para confirmar">REVISAR</span></td>
            <td>
              <span v-if="e.granel" class="pill st-tra" style="margin:2px 3px 2px 0">Granel</span>
              <span v-if="e.fracionada" class="pill st-tra" style="margin:2px 3px 2px 0">Fracionada</span>
              <span v-if="e.permite_paletizar" class="pill st-tra" style="margin:2px 3px 2px 0">Paletizável</span>
              <span v-if="e.permite_remontar" class="pill st-tra" style="margin:2px 3px 2px 0">Remontável</span>
              <span v-if="!e.granel && !e.fracionada && !e.permite_paletizar && !e.permite_remontar" class="mut">—</span>
            </td>
            <td><span class="pill sm" :class="e.ativo ? 'st-ent' : 'st-dev'">{{ e.ativo ? 'ATIVO' : 'INATIVO' }}</span></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="5"><EmptyState list-key="packagings" icon="packagings" :message="db.loading.packagings ? 'Carregando…' : db.packagings.length ? 'Nenhuma embalagem para esse filtro.' : 'Nenhuma embalagem cadastrada.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="editId ? 'Editar embalagem' : 'Incluir embalagem'" sub="acesso do administrador" @close="reset">
    <div class="fgrid">
      <div class="field c6"><label for="pk-code">Código <i>*</i></label>
        <input id="pk-code" :class="{ invalid: bad('codigo') }" :aria-invalid="bad('codigo') || undefined" v-model="form.codigo" class="inp" placeholder="ex.: SACARIA" :disabled="!!editId" style="text-transform:uppercase" /></div>
      <div class="field c6"><label for="pk-desc">Descrição <i>*</i></label>
        <input id="pk-desc" :class="{ invalid: bad('descricao') }" :aria-invalid="bad('descricao') || undefined" v-model="form.descricao" class="inp" placeholder="ex.: Sacaria" /></div>
      <div class="field c12"><label>Tipo</label>
        <div class="item-flags">
          <label><input v-model="form.granel" type="checkbox" data-f="granel" @change="form.granel && (form.fracionada = false)" /> Granel (sem volumes)</label>
          <label><input v-model="form.fracionada" type="checkbox" data-f="fracionada" :disabled="form.granel" /> Fracionada (volumes em paletes)</label>
        </div></div>
      <div class="field c12"><label>Opções na cotação</label>
        <div class="item-flags">
          <label><input v-model="form.permite_paletizar" type="checkbox" :disabled="form.granel" /> Paletizado</label>
          <label><input v-model="form.permite_remontar" type="checkbox" :disabled="form.granel" /> Remontado</label>
        </div></div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">Cancelar</button>
      <button class="btn btn-primary" data-act="save-packaging" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir embalagem</template></button>
    </template>
  </FormModal>
</template>
