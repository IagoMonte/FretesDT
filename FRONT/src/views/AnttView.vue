<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { auth } from '../stores/session'
import { db, findFreteMinimo, loadAnttCoefs, loadDomains, saveAnttCoef, setAnttCoefActive, tipoCargaLabel } from '../stores/db'
import { pushToast, confirmAction } from '../stores/ui'
import { dec, fmtD, fmtN, formatMoney, money, parseNum } from '../utils/format'
import { EIXOS } from '../utils/antt'
import CardPanel from '../components/CardPanel.vue'
import ListFilters from '../components/ListFilters.vue'
import { useListFilter } from '../composables/useListFilter'
import CityInput from '../components/CityInput.vue'
import FormModal from '../components/FormModal.vue'
import EmptyState from '../components/EmptyState.vue'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session
const editId = ref(null)
const open = ref(false)
const saving = ref(false)
const blank = () => ({ tipo_carga_codigo: '', eixos: '', ccd: '', cc: '', vigencia_inicio: '', vigencia_fim: '' })
const form = reactive(blank())
const { f: flt, visible, contagem } = useListFilter(() => db.anttCoefs, {
  texto: (c) => [tipoCargaLabel(c.tipo_carga_codigo), c.tipo_carga_codigo, c.eixos].join(' '),
  filtros: {
    situacao: (c, v) => (c.ativo ? 'ATIVO' : 'INATIVO') === v,
    tipo: (c, v) => c.tipo_carga_codigo === v,
    eixos: (c, v) => String(c.eixos) === v,
  },
})
const selects = computed(() => [
  { key: 'situacao', label: 'Todas as situações', options: [{ value: 'ATIVO', label: 'Ativos' }, { value: 'INATIVO', label: 'Inativos' }] },
  { key: 'tipo', label: 'Todos os tipos de carga', options: (db.domains.tipos_carga_antt ?? []).map((t) => ({ value: t.codigo, label: t.descricao })) },
  { key: 'eixos', label: 'Todos os eixos', options: EIXOS.map((n) => ({ value: String(n), label: n + ' eixos' })) },
])
const fmt4 = (v) => dec(v).toLocaleString('pt-BR', { minimumFractionDigits: 4, maximumFractionDigits: 4 })

onMounted(() => {
  loadDomains()
  loadAnttCoefs()
})

function reset() {
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
  Object.assign(form, {
    tipo_carga_codigo: c.tipo_carga_codigo, eixos: String(c.eixos), ccd: fmt4(c.ccd), cc: formatMoney(dec(c.cc)),
    vigencia_inicio: c.vigencia_inicio || '', vigencia_fim: c.vigencia_fim || '',
  })
  open.value = true
}
async function save() {
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await saveAnttCoef(form, editId.value)
  saving.value = false
  if (!r.ok) return pushToast('err', r.error)
  pushToast('ok', editing ? 'Coeficiente atualizado.' : 'Coeficiente incluído.')
  reset()
}
function inactivate(c) {
  confirmAction(`Inativar o coeficiente de **${tipoCargaLabel(c.tipo_carga_codigo)}** com **${c.eixos} eixos**? Ele deixa de valer para novas respostas.`, async () => {
    const r = await setAnttCoefActive(c.id, false)
    r.ok ? pushToast('warn', 'Coeficiente inativado.') : pushToast('err', r.error)
  }, { title: 'Confirmar inativação', label: 'Inativar', icon: 'pause' })
}
async function activate(c) {
  const r = await setAnttCoefActive(c.id, true)
  r.ok ? pushToast('ok', 'Coeficiente ativado.') : pushToast('err', r.error)
}

const q = reactive({ origem: '', destino: '', eixos: '', tipo: '', peso: '', res: undefined })
const porTon = computed(() => {
  const peso = parseNum(q.peso)
  return q.res && peso > 0 ? dec(q.res.frete_minimo) / peso : null
})
const consultando = ref(false)
async function consultar() {
  if (consultando.value) return
  if (!q.origem || !q.destino) return pushToast('err', 'Informe a **origem** e o **destino**.')
  if (!q.eixos) return pushToast('err', 'Escolha os **eixos**.')
  if (!q.tipo) return pushToast('err', 'Escolha o **tipo de carga**.')
  q.res = undefined
  consultando.value = true
  q.res = await findFreteMinimo(q.origem, q.destino, { eixos: q.eixos, tipoCarga: q.tipo })
  consultando.value = false
}
</script>

<template>
  <CardPanel title="Consulta de frete mínimo" count="origem + destino + eixos + tipo de carga">
    <div class="fgrid">
      <div class="field c6"><label for="an-o">Origem</label><CityInput id="an-o" v-model="q.origem" placeholder="Digite a cidade…" /></div>
      <div class="field c6"><label for="an-d">Destino</label><CityInput id="an-d" v-model="q.destino" placeholder="Digite a cidade…" /></div>
      <div class="field c3"><label for="an-q-eixos">Eixos</label>
        <select id="an-q-eixos" v-model="q.eixos" class="inp"><option value="">— selecionar —</option><option v-for="n in EIXOS" :key="n" :value="String(n)">{{ n }} eixos</option></select></div>
      <div class="field c3"><label for="an-q-peso">Peso (ton)</label>
        <input id="an-q-peso" v-model="q.peso" class="inp" type="text" inputmode="decimal" autocomplete="off" placeholder="ex.: 32,5" /></div>
      <div class="field c6"><label for="an-q-tipo">Tipo de carga</label>
        <select id="an-q-tipo" v-model="q.tipo" class="inp"><option value="">— selecionar —</option><option v-for="t in db.domains.tipos_carga_antt" :key="t.codigo" :value="t.codigo">{{ t.descricao }}</option></select></div>
      <div class="field c12"><button class="btn btn-primary" data-act="consulta-frete" :disabled="consultando" :aria-busy="consultando" @click="consultar">Consultar</button></div>
      <div class="field c12 mut" style="font-size:12px">O CCD e o CC vêm da tabela de coeficientes vigente para o tipo de carga e os eixos escolhidos.</div>
      <div v-if="q.res" class="field c12" data-frete-minimo>
        <b class="mono">Frete mínimo {{ money(dec(q.res.frete_minimo)) }}</b>
        <span v-if="porTon !== null" class="mono" data-frete-minimo-ton> ({{ money(porTon) }} por ton)</span>
        <span class="mut"> · {{ fmtN(dec(q.res.km)) }} km · {{ q.res.eixos }} eixos</span>
      </div>
      <div v-else-if="q.res === null" class="field c12 mut" data-frete-minimo-vazio>Sem resultado: o par de cidades não está na tabela de distâncias ou não há coeficiente vigente para esse tipo de carga e eixos.</div>
    </div>
  </CardPanel>

  <CardPanel title="Coeficientes do piso mínimo" :count="contagem(db.anttCoefs.length, 'itens')" flush>
    <template v-if="session.can('ANTT', 'inserir')" #actions><button class="btn btn-primary btn-sm" data-act="new-antt" @click="startNew"><NavIcon name="plus" :size="14" /> Incluir coeficiente</button></template>
    <ListFilters :f="flt" :selects="selects" label="Buscar coeficientes" placeholder="Buscar por tipo de carga ou eixos…" />
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de coeficientes ANTT">
      <table class="tbl" style="min-width:720px">
        <thead><tr><th class="act">Ações</th><th>Tipo de carga</th><th>Eixos</th><th>CCD (R$/km)</th><th>CC (R$)</th><th>Vigência</th><th>Situação</th></tr></thead>
        <tbody>
          <tr v-for="c in visible" :key="c.id" :class="{ off: !c.ativo }" data-coef>
            <td class="act"><div class="wrap" v-if="session.can('ANTT', 'alterar')">
              <button class="ibtn ed" title="Editar" aria-label="Editar" @click="startEdit(c)"><NavIcon name="edit" :size="16" /></button>
              <button v-if="c.ativo && session.can('ANTT', 'cancelar')" class="ibtn dl" title="Inativar" aria-label="Inativar" @click="inactivate(c)"><NavIcon name="pause" :size="16" /></button>
              <button v-else-if="session.can('ANTT', 'cancelar')" class="ibtn ok" title="Ativar" aria-label="Ativar" @click="activate(c)"><NavIcon name="play" :size="16" /></button>
            </div></td>
            <td><b class="strong">{{ tipoCargaLabel(c.tipo_carga_codigo) }}</b></td>
            <td class="num">{{ c.eixos }}</td>
            <td class="num mono">{{ fmt4(c.ccd) }}</td>
            <td class="num mono">{{ money(dec(c.cc)) }}</td>
            <td>{{ fmtD(c.vigencia_inicio) }} → {{ c.vigencia_fim ? fmtD(c.vigencia_fim) : 'em aberto' }}</td>
            <td><span class="pill sm" :class="c.ativo ? 'st-ent' : 'st-dev'">{{ c.ativo ? 'ATIVO' : 'INATIVO' }}</span></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="7"><EmptyState icon="antt" :message="db.loading.anttCoefs ? 'Carregando…' : db.anttCoefs.length ? 'Nenhum coeficiente para esse filtro.' : 'Nenhum coeficiente cadastrado: sem eles o piso ANTT não é calculado.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="editId ? 'Editar coeficiente' : 'Incluir coeficiente'" sub="tabela do piso mínimo ANTT (administração)" @close="reset">
    <div class="fgrid">
      <div class="field c6"><label for="an-tipo">Tipo de carga <i>*</i></label>
        <select id="an-tipo" v-model="form.tipo_carga_codigo" class="inp" :disabled="!!editId"><option value="">— selecionar —</option><option v-for="t in db.domains.tipos_carga_antt" :key="t.codigo" :value="t.codigo">{{ t.descricao }}</option></select></div>
      <div class="field c6"><label for="an-eixos">Eixos <i>*</i></label>
        <select id="an-eixos" v-model="form.eixos" class="inp" :disabled="!!editId"><option value="">— selecionar —</option><option v-for="n in EIXOS" :key="n" :value="String(n)">{{ n }} eixos</option></select></div>
      <div class="field c6"><label for="an-ccd">CCD — R$ por km <i>*</i></label>
        <input id="an-ccd" v-model="form.ccd" class="inp" type="text" inputmode="decimal" autocomplete="off" placeholder="ex.: 3,9826" /></div>
      <div class="field c6"><label for="an-cc">CC — R$ de carga e descarga</label>
        <input id="an-cc" v-model="form.cc" class="inp" type="text" inputmode="decimal" autocomplete="off" placeholder="ex.: 451,84" /></div>
      <div class="field c6"><label for="an-vi">Início da vigência <i>*</i></label>
        <input id="an-vi" v-model="form.vigencia_inicio" class="inp" type="date" /></div>
      <div class="field c6"><label for="an-vf">Fim da vigência</label>
        <input id="an-vf" v-model="form.vigencia_fim" class="inp" type="date" /></div>
      <div class="field c12 mut" style="font-size:12px">Piso = distância (km) × CCD + CC. Ao reajustar a tabela, cadastre um novo coeficiente com a nova vigência: as respostas já registradas mantêm o piso da época.</div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">Cancelar</button>
      <button class="btn btn-primary" data-act="save-antt" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir coeficiente</template></button>
    </template>
  </FormModal>
</template>
