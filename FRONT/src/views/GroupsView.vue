<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { db, loadGroupsAdmin, loadGroupCatalog, saveGroup, setGroupActive } from '../stores/db'
import { pushToast, confirmAction } from '../stores/ui'
import CardPanel from '../components/CardPanel.vue'
import FormModal from '../components/FormModal.vue'
import EmptyState from '../components/EmptyState.vue'
import NavIcon from '../components/NavIcon.vue'

const OPS = ['ver', 'inserir', 'alterar', 'cancelar']
const OP_LABEL = { ver: 'Ver', inserir: 'Inserir', alterar: 'Alterar', cancelar: 'Cancelar' }
const MODULOS = { OPERACAO: 'Operação', CADASTRO: 'Cadastros', ACESSO: 'Acesso', SISTEMA: 'Sistema' }

const open = ref(false)
const saving = ref(false)
const editId = ref(null)
const somenteLeitura = ref(false)
const form = reactive({ codigo: '', nome: '', descricao: '', tipo_usuario: 'INTERNO', perms: {}, acoes: [] })

const catalogo = computed(() => db.groupCatalog ?? { recursos: [], recursos_transportadora: [], acoes_transportadora: [], escopos: { INTERNO: ['PROPRIOS', 'TODOS'], TRANSPORTADORA: ['TRANSPORTADORA'] } })
const isTransp = computed(() => form.tipo_usuario === 'TRANSPORTADORA')
const recursos = computed(() => catalogo.value.recursos.filter((r) => !isTransp.value || catalogo.value.recursos_transportadora.includes(r.codigo)))
const acoesDe = (r) => r.acoes.filter((a) => !isTransp.value || catalogo.value.acoes_transportadora.includes(`${r.codigo}.${a.codigo}`))
const porModulo = computed(() => {
  const mods = {}
  for (const r of recursos.value) (mods[r.modulo] ??= []).push(r)
  return Object.entries(mods).map(([modulo, itens]) => ({ modulo, nome: MODULOS[modulo] ?? modulo, itens }))
})
const escopos = computed(() => catalogo.value.escopos[form.tipo_usuario] ?? [])

const blankPerm = () => ({ ver: false, inserir: false, alterar: false, cancelar: false, escopo: isTransp.value ? 'TRANSPORTADORA' : 'PROPRIOS' })
const perm = (cod) => (form.perms[cod] ??= blankPerm())
const acaoKey = (r, a) => `${r.codigo}.${a.codigo}`
const temAcao = (r, a) => form.acoes.includes(acaoKey(r, a))

onMounted(() => { loadGroupCatalog(); loadGroupsAdmin() })

function reset() {
  open.value = false
  editId.value = null
  somenteLeitura.value = false
  Object.assign(form, { codigo: '', nome: '', descricao: '', tipo_usuario: 'INTERNO', perms: {}, acoes: [] })
}
function startNew() {
  reset()
  open.value = true
}
function startEdit(g, leitura = false) {
  reset()
  editId.value = g.id
  somenteLeitura.value = leitura
  const perms = {}
  for (const [cod, p] of Object.entries(g.permissoes ?? {})) perms[cod] = { ...p }
  Object.assign(form, { codigo: g.codigo, nome: g.nome, descricao: g.descricao ?? '', tipo_usuario: g.tipo_usuario, perms, acoes: [...(g.acoes ?? [])] })
  open.value = true
}

function toggle(r, op, checked) {
  const p = perm(r.codigo)
  p[op] = checked
  if (op !== 'ver' && checked) p.ver = true
  if (op === 'ver' && !checked) {
    for (const o of OPS) p[o] = false
    form.acoes = form.acoes.filter((a) => !a.startsWith(`${r.codigo}.`))
  }
}
function toggleAcao(r, a, checked) {
  const k = acaoKey(r, a)
  form.acoes = checked ? [...new Set([...form.acoes, k])] : form.acoes.filter((x) => x !== k)
}
function onTipo() {
  form.perms = {}
  form.acoes = []
}

async function save() {
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await saveGroup(form, editId.value)
  saving.value = false
  if (!r.ok) return pushToast('err', r.errors?.codigo?.[0] || r.errors?.permissoes?.[0] || r.errors?.acoes?.[0] || r.error)
  pushToast('ok', editing ? `Grupo **${r.data.nome}** atualizado. Quem está nele renova a sessão para receber as novas permissões.` : `Grupo **${r.data.nome}** criado — já pode ser escolhido em **Usuários e Acessos**.`)
  reset()
}
function toggleActive(g) {
  if (!g.ativo) {
    return setGroupActive(g.id, true).then((r) => (r.ok ? pushToast('ok', `Grupo **${g.nome}** ativado.`) : pushToast('err', r.error)))
  }
  confirmAction(`Inativar o grupo **${g.nome}**? Ele deixa de aparecer ao cadastrar usuários.`, async () => {
    const r = await setGroupActive(g.id, false)
    r.ok ? pushToast('warn', `Grupo **${g.nome}** inativado.`) : pushToast('err', r.error)
  }, { title: 'Confirmar inativação', label: 'Inativar', icon: 'pause' })
}
const resumo = (g) => {
  const n = Object.values(g.permissoes ?? {}).filter((p) => p.ver).length
  return `${n} ${n === 1 ? 'recurso' : 'recursos'}`
}
</script>

<template>
  <CardPanel title="Grupos de acesso" :count="db.loading.groupsAdmin && !db.groupsAdmin.length ? 'Carregando…' : `${db.groupsAdmin.length} ${db.groupsAdmin.length === 1 ? 'grupo' : 'grupos'}`" flush>
    <template #actions><button class="btn btn-primary btn-sm" data-act="new-group" @click="startNew"><NavIcon name="plus" :size="14" /> Incluir grupo</button></template>
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de grupos de acesso">
      <table class="tbl" style="min-width:760px">
        <thead><tr><th class="act">Ações</th><th>Grupo</th><th>Código</th><th>Tipo</th><th>Acessos</th><th>Usuários</th><th>Situação</th></tr></thead>
        <tbody>
          <tr v-for="g in db.groupsAdmin" :key="g.id" :class="{ off: !g.ativo }">
            <td class="act"><div class="wrap">
              <template v-if="g.sistema">
                <button class="ibtn ed" data-act="view-group" title="Ver permissões" aria-label="Ver permissões" @click="startEdit(g, true)"><NavIcon name="eye" :size="16" /></button>
              </template>
              <template v-else>
                <button class="ibtn ed" data-act="edit-group" title="Editar" aria-label="Editar" @click="startEdit(g)"><NavIcon name="edit" :size="16" /></button>
                <button v-if="g.ativo" class="ibtn dl" data-act="toggle-group" title="Inativar" aria-label="Inativar" @click="toggleActive(g)"><NavIcon name="pause" :size="16" /></button>
                <button v-else class="ibtn ok" data-act="toggle-group" title="Ativar" aria-label="Ativar" @click="toggleActive(g)"><NavIcon name="unlock" :size="16" /></button>
              </template>
            </div></td>
            <td><b class="strong">{{ g.nome }}</b><span v-if="g.sistema" class="pill sm st-tra" style="margin-left:6px">SISTEMA</span><div v-if="g.descricao" class="mut" style="font-size:12px">{{ g.descricao }}</div></td>
            <td class="num">{{ g.codigo }}</td>
            <td>{{ g.tipo_usuario === 'TRANSPORTADORA' ? 'Transportadora' : 'Interno' }}</td>
            <td>{{ resumo(g) }}</td>
            <td class="num">{{ g.usuarios }}</td>
            <td><span class="pill sm" :class="g.ativo ? 'st-ent' : 'st-dev'">{{ g.ativo ? 'ATIVO' : 'INATIVO' }}</span></td>
          </tr>
          <tr v-if="!db.groupsAdmin.length"><td colspan="7"><EmptyState icon="user" :message="db.loading.groupsAdmin ? 'Carregando…' : 'Nenhum grupo cadastrado.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="somenteLeitura ? 'Permissões do grupo' : editId ? 'Editar grupo' : 'Incluir grupo'" :sub="somenteLeitura ? 'grupo do sistema — somente leitura' : 'acesso do administrador'" size="xl" @close="reset">
    <div class="fgrid">
      <div class="field c4"><label for="gr-codigo">Código <i v-if="!editId">*</i></label>
        <input id="gr-codigo" v-model="form.codigo" class="inp" placeholder="ex.: CONSULTA_COTACAO" maxlength="30" :disabled="!!editId" style="text-transform:uppercase" autocomplete="off" /></div>
      <div class="field c5"><label for="gr-nome">Nome <i v-if="!somenteLeitura">*</i></label>
        <input id="gr-nome" v-model="form.nome" class="inp" placeholder="ex.: Consulta de cotações" maxlength="100" :disabled="somenteLeitura" /></div>
      <div class="field c3"><label for="gr-tipo">Tipo de usuário</label>
        <select id="gr-tipo" v-model="form.tipo_usuario" class="inp" :disabled="!!editId" @change="onTipo"><option value="INTERNO">Interno</option><option value="TRANSPORTADORA">Transportadora</option></select></div>
      <div class="field c12"><label for="gr-desc">Descrição</label>
        <input id="gr-desc" v-model="form.descricao" class="inp" placeholder="opcional" maxlength="500" :disabled="somenteLeitura" /></div>
    </div>

    <p class="mut" style="font-size:12px;margin:14px 0 6px">Marque o que o grupo pode fazer em cada recurso. <b>Inserir</b>, <b>Alterar</b> e <b>Cancelar</b> exigem <b>Ver</b>. O escopo define se o usuário vê só os próprios registros ou todos.</p>
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Matriz de permissões" style="max-height:46vh">
      <table id="gr-matriz" class="tbl" style="min-width:640px">
        <thead><tr><th>Recurso</th><th v-for="op in OPS" :key="op" style="text-align:center">{{ OP_LABEL[op] }}</th><th>Escopo</th></tr></thead>
        <tbody>
          <template v-for="m in porModulo" :key="m.modulo">
            <tr class="grp-row"><td colspan="6"><b>{{ m.nome }}</b></td></tr>
            <template v-for="r in m.itens" :key="r.codigo">
              <tr>
                <td>{{ r.nome }}</td>
                <td v-for="op in OPS" :key="op" style="text-align:center">
                  <input :id="`gr-${r.codigo}-${op}`" type="checkbox" :aria-label="`${OP_LABEL[op]} — ${r.nome}`" :checked="!!form.perms[r.codigo]?.[op]" :disabled="somenteLeitura" @change="toggle(r, op, $event.target.checked)" />
                </td>
                <td>
                  <template v-if="r.usa_escopo">
                    <span v-if="isTransp" class="mut">Da transportadora</span>
                    <select v-else :id="`gr-${r.codigo}-escopo`" class="inp" :aria-label="`Escopo — ${r.nome}`" :value="form.perms[r.codigo]?.escopo ?? 'PROPRIOS'" :disabled="somenteLeitura || !form.perms[r.codigo]?.ver" @change="perm(r.codigo).escopo = $event.target.value">
                      <option v-for="e in escopos" :key="e" :value="e">{{ e === 'PROPRIOS' ? 'Só os próprios' : 'Todos' }}</option>
                    </select>
                  </template>
                  <span v-else class="mut">—</span>
                </td>
              </tr>
              <tr v-if="acoesDe(r).length" class="acoes-row">
                <td colspan="6" style="padding-left:28px">
                  <span class="mut" style="font-size:12px">Ações de {{ r.nome }}:</span>
                  <label v-for="a in acoesDe(r)" :key="a.codigo" style="margin-left:14px;font-size:13px">
                    <input :id="`gr-${r.codigo}-ac-${a.codigo}`" type="checkbox" :checked="temAcao(r, a)" :disabled="somenteLeitura || !form.perms[r.codigo]?.ver" @change="toggleAcao(r, a, $event.target.checked)" /> {{ a.nome }}
                  </label>
                </td>
              </tr>
            </template>
          </template>
        </tbody>
      </table>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">{{ somenteLeitura ? 'Fechar' : 'Cancelar' }}</button>
      <button v-if="!somenteLeitura" class="btn btn-primary" data-act="save-group" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir grupo</template></button>
    </template>
  </FormModal>
</template>
