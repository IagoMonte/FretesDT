<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { db, findDistance } from '../stores/db'
import { createQuote, editQuote } from '../services/quoteFlow'
import { fmtN, parseNum, cityLabel } from '../utils/format'
import { FIELD_LABELS, focusId, validateQuote } from '../utils/quoteForm'
import { pushToast } from '../stores/ui'
import MoneyInput from './MoneyInput.vue'
import { blankItem, itemFromApi, temFracionada } from '../utils/items'
import { usePreviousQuote } from '../composables/usePreviousQuote'
import CityInput from './CityInput.vue'
import FormModal from './FormModal.vue'
import PartePicker from './PartePicker.vue'
import ItemsEditor from './ItemsEditor.vue'
import CotacaoAnterior from './CotacaoAnterior.vue'
import NavIcon from './NavIcon.vue'

const props = defineProps({ quote: { type: Object, default: null } })
const emit = defineEmits(['close'])
const editing = computed(() => !!props.quote)

const cargaGeral = () => (db.domains.tipos_carga_antt ?? []).find((t) => t.codigo === 'CARGA_GERAL')?.codigo ?? ''
const blank = () => ({
  operacao: 'CARREGAMENTO', parte: null, veiculo: db.domains.tipos_veiculo[0]?.codigo ?? '',
  valorNF: '', observacao: '', origem: '', destino: '', itens: [blankItem()], usarAnterior: true,
  tipoCarga: cargaGeral(), km: '',
})
const fromQuote = (q) => ({
  operacao: q.operacao, parte: q.parte, veiculo: q.tipo_veiculo_codigo ?? '',
  valorNF: fmtN(parseNum(q.valor_nf)), observacao: q.observacao ?? '',
  origem: cityLabel(q.origem), destino: cityLabel(q.destino),
  itens: q.itens.map(itemFromApi), usarAnterior: false,
  tipoCarga: q.tipo_carga_antt_codigo ?? '', km: q.distancia_km ? fmtN(parseNum(q.distancia_km)) : '',
})
const form = reactive(props.quote ? fromQuote(props.quote) : blank())
const saving = ref(false)
const { previous, loading: checkingPrev } = usePreviousQuote(form)
const kgTotal = computed(() => form.itens.reduce((s, i) => s + parseNum(i.qtd), 0))
const attempted = ref(false)
const errors = computed(() => (attempted.value ? validateQuote(form) : {}))
const fracionada = computed(() => temFracionada(form.itens))
const partyTipo = computed(() => (form.operacao === 'RECEBIMENTO' ? 'FORNECEDOR' : 'CLIENTE'))

watch(() => db.domains.tipos_veiculo, (l) => { if (!form.veiculo && l[0] && !fracionada.value) form.veiculo = l[0].codigo })

let kmTimer = null
let kmSeq = 0
watch(() => [form.origem, form.destino], () => {
  clearTimeout(kmTimer)
  const mine = ++kmSeq
  kmTimer = setTimeout(async () => {
    const km = await findDistance(form.origem, form.destino)
    if (mine !== kmSeq) return
    form.km = km ? fmtN(parseNum(km)) : ''
  }, 400)
})
watch(() => db.domains.tipos_carga_antt, () => { if (!form.tipoCarga) form.tipoCarga = cargaGeral() })

const lado = computed(() => (form.operacao === 'RECEBIMENTO' ? 'origem' : 'destino'))
const cidadeDaParte = computed(() => (form.parte?.cidade ? form.parte.cidade + (form.parte.uf ? ' - ' + form.parte.uf : '') : ''))
const travado = (campo) => !!cidadeDaParte.value && lado.value === campo
watch([() => form.parte, () => form.operacao], () => {
  if (cidadeDaParte.value) form[lado.value] = cidadeDaParte.value
})

async function save() {
  if (saving.value) return
  attempted.value = true
  const bad = validateQuote(form)
  const fields = Object.keys(bad)
  if (fields.length) {
    pushToast('err', `Revise os campos: **${fields.map((f) => FIELD_LABELS[f]).join(', ')}**.`)
    await nextTick()
    document.getElementById(focusId(fields[0], form))?.focus()
    return
  }
  saving.value = true
  const r = editing.value ? await editQuote(props.quote.id, form) : await createQuote(form)
  saving.value = false
  if (r.ok) emit('close')
}
</script>

<template>
  <FormModal :title="editing ? 'Editar cotação ' + quote.codigo : 'Nova cotação de frete'" :sub="editing ? 'antes de encaminhar · não notifica' : 'uso do vendedor'" size="wide" @close="emit('close')">
    <div class="fgrid">
      <div class="field c4"><label for="qc-operacao">Operação <i>*</i></label>
        <select id="qc-operacao" v-model="form.operacao" class="inp" @change="form.parte = null"><option value="CARREGAMENTO">CARREGAMENTO (cliente)</option><option value="RECEBIMENTO">RECEBIMENTO (fornecedor)</option></select></div>
      <div class="field c8"><label for="qc-parte">{{ partyTipo === 'CLIENTE' ? 'Cliente' : 'Fornecedor' }} <i>*</i></label>
        <PartePicker id="qc-parte" v-model="form.parte" :tipo="partyTipo" :invalid="!!errors.parte" />
        <div v-if="errors.parte" class="ferr" role="alert" data-err="parte">{{ errors.parte }}</div></div>
      <div class="field c12"><label>Itens da cotação <i>*</i></label><ItemsEditor v-model="form.itens" id-prefix="qc" :show-errors="attempted" />
        <div v-if="errors.itens" class="ferr" role="alert" data-err="itens">{{ errors.itens }}</div></div>
      <div v-if="fracionada" class="field c4" data-fracionada><label>Tipo de carro</label>
        <div class="inp mut" style="display:flex;align-items:center">A definir pela transportadora</div></div>
      <div v-else class="field c4"><label for="qc-veiculo">Tipo de carro <i>*</i></label>
        <select id="qc-veiculo" v-model="form.veiculo" class="inp" :class="{ invalid: errors.veiculo }"><option v-for="v in db.domains.tipos_veiculo" :key="v.codigo" :value="v.codigo">{{ v.descricao }}</option></select>
        <div v-if="errors.veiculo" class="ferr" role="alert" data-err="veiculo">{{ errors.veiculo }}</div></div>
      <div class="field c4"><label for="qc-valor">Valor aprox. da NF (R$) <i>*</i></label>
        <MoneyInput id="qc-valor" v-model="form.valorNF" :class="{ invalid: errors.valorNF }" :aria-invalid="!!errors.valorNF || undefined" />
        <div v-if="errors.valorNF" class="ferr" role="alert" data-err="valorNF">{{ errors.valorNF }}</div></div>
      <div class="field c4"><label for="qc-obs">Observação</label><input id="qc-obs" v-model="form.observacao" class="inp" placeholder="opcional" maxlength="2000" /></div>
      <div class="field c6"><label for="qc-origem">Cidade de origem <i>*</i></label><CityInput id="qc-origem" v-model="form.origem" placeholder="Digite a cidade…" :invalid="!!errors.origem" :readonly="travado('origem')" />
        <div v-if="errors.origem" class="ferr" role="alert" data-err="origem">{{ errors.origem }}</div></div>
      <div class="field c6"><label for="qc-destino">Cidade de destino <i>*</i></label><CityInput id="qc-destino" v-model="form.destino" placeholder="Digite a cidade…" :invalid="!!errors.destino" :readonly="travado('destino')" />
        <div v-if="errors.destino" class="ferr" role="alert" data-err="destino">{{ errors.destino }}</div></div>
      <div class="field c12"><label for="qc-km">Distância (km)</label>
        <input id="qc-km" v-model="form.km" class="inp" type="text" readonly tabindex="-1" placeholder="calculada pela rota (origem e destino)" /></div>
      <template v-if="!editing">
        <div v-if="checkingPrev && !previous" class="field c12 prev-loading" data-prev-loading role="status"><span class="spin" aria-hidden="true"></span>Verificando se esta rota já foi cotada…</div>
        <div v-else-if="previous" class="field c12">
          <div class="prev-warn"><NavIcon name="warn" :size="14" /> Esta rota já foi cotada recentemente: as respostas abaixo podem ser reaproveitadas sem esperar as transportadoras.</div>
          <CotacaoAnterior :anterior="previous" :kg="kgTotal" />
          <label class="use-prev" data-use-prev><input v-model="form.usarAnterior" type="checkbox" /> Usar as respostas da {{ previous.codigo }}</label>
        </div>
      </template>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="emit('close')">Cancelar</button>
      <button class="btn btn-primary" data-act="save-quote" :disabled="saving" :aria-busy="saving" @click="save"><NavIcon :name="editing ? 'check' : 'plus'" :size="14" /> {{ editing ? 'Salvar alterações' : 'Gerar cotação' }}</button>
    </template>
  </FormModal>
</template>
