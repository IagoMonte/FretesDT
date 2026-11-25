<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { auth } from '../stores/session'
import { db, loadDomains, loadProducts, saveProduct, setProductActive, embalagemLabel } from '../stores/db'
import { pushToast, confirmAction } from '../stores/ui'
import { fmtDens, fmtN, dec } from '../utils/format'
import CardPanel from '../components/CardPanel.vue'
import ListFilters from '../components/ListFilters.vue'
import { useListFilter } from '../composables/useListFilter'
import ChipGroup from '../components/ChipGroup.vue'
import FormModal from '../components/FormModal.vue'
import EmptyState from '../components/EmptyState.vue'
import { isGranel, isFracionada } from '../utils/items'
import { useRequired } from '../composables/useRequired'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session
const editId = ref(null)
const open = ref(false)
const saving = ref(false)
const form = reactive({ nome: '', descricao: '', onu: '', risco: '', densidade: '', embalagens: [], fatores: {}, fatoresPalete: {} })
const { bad, tentar, limpar } = useRequired(form, ['nome'])
const packOptions = computed(() => db.domains.embalagens.map((e) => ({ value: e.codigo, label: e.descricao })))

onMounted(() => { loadDomains(); loadProducts() })

const { f: flt, visible, contagem } = useListFilter(() => db.products, {
  texto: (p) => [p.nome, p.descricao, p.onu, p.risco].join(' '),
  filtros: {
    situacao: (p, v) => (p.ativo ? 'ATIVO' : 'INATIVO') === v,
    origem: (p, v) => (p.origem_cadastro || 'MANUAL') === v,
    embalagem: (p, v) => (p.embalagens ?? []).includes(v),
  },
})
const selects = computed(() => [
  { key: 'situacao', label: 'Todas as situações', options: [{ value: 'ATIVO', label: 'Ativos' }, { value: 'INATIVO', label: 'Inativos' }] },
  { key: 'origem', label: 'Todas as origens', options: [{ value: 'ERP', label: 'Do ERP' }, { value: 'MANUAL', label: 'Cadastro manual' }] },
  { key: 'embalagem', label: 'Todas as embalagens', options: packOptions.value },
])

function reset() {
  limpar()
  open.value = false
  editId.value = null
  Object.assign(form, { nome: '', descricao: '', onu: '', risco: '', densidade: '', embalagens: [], fatores: {}, fatoresPalete: {} })
}
function startNew() {
  reset()
  open.value = true
}
function startEdit(p) {
  editId.value = p.id
  Object.assign(form, { nome: p.nome, descricao: p.descricao ?? '', onu: p.onu ?? '', risco: p.risco ?? '', densidade: p.densidade ? fmtDens(p.densidade) : '', embalagens: [...p.embalagens], fatores: Object.fromEntries(Object.entries(p.fatores ?? {}).map(([c, v]) => [c, fmtN(dec(v))])), fatoresPalete: Object.fromEntries(Object.entries(p.fatores_palete ?? {}).map(([c, v]) => [c, fmtN(dec(v))])) })
  open.value = true
}
async function save() {
  tentar()
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await saveProduct(form, editId.value)
  saving.value = false
  if (!r.ok) return pushToast('err', r.error)
  pushToast('ok', editing ? `Produto **${r.data.nome}** atualizado.` : `Produto **${r.data.nome}** incluído com sucesso.`)
  reset()
}
function inactivate(p) {
  confirmAction(`Inativar o produto **${p.nome}**? Ele deixa de ser oferecido em novas cotações.`, async () => {
    const r = await setProductActive(p.id, false)
    r.ok ? pushToast('warn', `Produto **${p.nome}** inativado.`) : pushToast('err', r.error)
  }, { title: 'Confirmar inativação', label: 'Inativar', icon: 'pause' })
}
async function activate(p) {
  const r = await setProductActive(p.id, true)
  r.ok ? pushToast('ok', `Produto **${p.nome}** ativado.`) : pushToast('err', r.error)
}
</script>

<template>
  <CardPanel title="Produtos cadastrados" :count="contagem(db.products.length, 'itens')" flush>
    <template v-if="session.can('PRODUTO', 'inserir')" #actions><button class="btn btn-primary btn-sm" data-act="new-product" @click="startNew"><NavIcon name="plus" :size="14" /> Incluir produto</button></template>
    <ListFilters :f="flt" :selects="selects" label="Buscar produtos" placeholder="Buscar por nome, ONU, risco…" />
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de produtos">
      <table class="tbl" style="min-width:640px">
        <thead><tr><th class="act">Ações</th><th>Produto</th><th>ONU</th><th>Risco</th><th>Densidade</th><th>Embalagens</th><th>Situação</th></tr></thead>
        <tbody>
          <tr v-for="p in visible" :key="p.id" :class="{ off: !p.ativo }">
            <td class="act"><div class="wrap" v-if="session.can('PRODUTO', 'alterar')">
              <button class="ibtn ed" title="Editar" aria-label="Editar" @click="startEdit(p)"><NavIcon name="edit" :size="16" /></button>
              <button v-if="p.ativo && session.can('PRODUTO', 'cancelar')" class="ibtn dl" title="Inativar" aria-label="Inativar" @click="inactivate(p)"><NavIcon name="pause" :size="16" /></button>
              <button v-else-if="session.can('PRODUTO', 'cancelar')" class="ibtn ok" title="Ativar" aria-label="Ativar" @click="activate(p)"><NavIcon name="play" :size="16" /></button>
            </div></td>
            <td><b class="strong">{{ p.nome }}</b> <span v-if="p.codigos_erp > 1" class="pill sm st-agu" :title="`${p.codigos_erp} códigos do ERP (locais/estoques) agrupados neste produto`">{{ p.codigos_erp }} códigos ERP</span><div v-if="p.descricao" class="mut" style="font-size:12px">{{ p.descricao }}</div></td>
            <td>{{ p.onu || '—' }}</td>
            <td>{{ p.risco || '—' }}</td>
            <td class="num"><template v-if="p.densidade">{{ fmtDens(p.densidade) }} g/cm³</template><span v-else class="mut">—</span></td>
            <td><span v-for="x in p.embalagens" :key="x" class="pill st-tra" style="margin:2px 3px 2px 0">{{ embalagemLabel(x) }}</span></td>
            <td><span class="pill sm" :class="p.ativo ? 'st-ent' : 'st-dev'">{{ p.ativo ? 'ATIVO' : 'INATIVO' }}</span></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="7"><EmptyState list-key="products" icon="products" :message="db.loading.products ? 'Carregando…' : db.products.length ? 'Nenhum produto para esse filtro.' : 'Nenhum produto cadastrado.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="editId ? 'Editar produto' : 'Incluir produto'" sub="acesso do administrador" @close="reset">
    <div class="fgrid">
      <div class="field c6"><label for="pr-name">Nome do produto <i>*</i></label>
        <input id="pr-name" :class="{ invalid: bad('nome') }" :aria-invalid="bad('nome') || undefined" v-model="form.nome" class="inp" placeholder="ex.: HCL" style="text-transform:uppercase" /></div>
      <div class="field c12"><label for="pr-desc">Descrição (é o que a transportadora vê no lugar do nome)</label>
        <input id="pr-desc" v-model="form.descricao" class="inp" maxlength="255" placeholder="ex.: Líquido alcalino, sem o nome comercial" /></div>
      <div class="field c6"><label for="pr-onu">Nº ONU</label>
        <input id="pr-onu" v-model="form.onu" class="inp" maxlength="4" inputmode="numeric" placeholder="ex.: 1789" /></div>
      <div class="field c6"><label for="pr-risco">Risco</label>
        <input id="pr-risco" v-model="form.risco" class="inp" maxlength="10" placeholder="ex.: 80" style="text-transform:uppercase" /></div>
      <div class="field c6"><label for="pr-dens">Densidade (se houver)</label>
        <input id="pr-dens" v-model="form.densidade" class="inp" placeholder="ex.: 1,19" inputmode="decimal" /></div>
      <div class="field c12"><label>Embalagens permitidas <i>*</i></label>
        <ChipGroup v-model="form.embalagens" :options="packOptions" /></div>
      <template v-for="c in form.embalagens.filter((x) => !isGranel(x))" :key="c">
        <div class="field c6"><label :for="`pr-fat-${c}`">kg por volume — {{ embalagemLabel(c) }}</label>
          <input :id="`pr-fat-${c}`" class="inp" v-model="form.fatores[c]" inputmode="decimal" placeholder="ex.: 50" /></div>
        <div v-if="isFracionada(c)" class="field c6"><label :for="`pr-pal-${c}`">kg por palete — {{ embalagemLabel(c) }}</label>
          <input :id="`pr-pal-${c}`" class="inp" v-model="form.fatoresPalete[c]" inputmode="decimal" placeholder="ex.: 600" /></div>
      </template>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">Cancelar</button>
      <button class="btn btn-primary" data-act="save-product" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir produto</template></button>
    </template>
  </FormModal>
</template>
