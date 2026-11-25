<script setup>
import { computed, ref } from 'vue'
import { db } from '../stores/db'
import { respondQuote } from '../services/quoteFlow'
import { EIXOS, pisoValido } from '../utils/antt'
import { cityLabel, dec, fmtN, formatMoney, freightValue, money, sumKg, today } from '../utils/format'
import FormModal from './FormModal.vue'
import MoneyInput from './MoneyInput.vue'

const props = defineProps({ quote: { type: Object, required: true }, resposta: { type: Object, required: true } })
const emit = defineEmits(['close'])

const r0 = props.resposta
const fracionada = !!props.quote.fracionada
const ton = ref(!fracionada && r0.valor_tonelada ? formatMoney(dec(r0.valor_tonelada)) : '')
const frete = ref(fracionada && r0.valor_frete ? formatMoney(dec(r0.valor_frete)) : '')
const veiculo = ref(r0.tipo_veiculo_codigo || '')
const prazo = ref(r0.prazo_dias ? String(r0.prazo_dias) : '')
const prazoColeta = ref(r0.prazo_coleta_dias != null ? String(r0.prazo_coleta_dias) : '')
const validade = ref(r0.validade ? String(r0.validade).slice(0, 10) : '')
const obs = ref(r0.observacao || '')
const eixos = ref(r0.eixos ? String(r0.eixos) : '')
const dedicado = ref(fracionada && !!r0.dedicado)
const comPiso = computed(() => pisoValido(props.quote, { dedicado: dedicado.value }))
const tipoCarga = ref(r0.tipo_carga_antt_codigo || props.quote.tipo_carga_antt_codigo || '')
const busy = ref(false)

const kg = computed(() => sumKg(props.quote.itens))
const estimate = computed(() => money(freightValue(ton.value, kg.value)))
const minDate = today()

async function submit() {
  if (busy.value) return
  busy.value = true
  const r = await respondQuote(props.quote.id, props.resposta.id, { valorTon: ton.value, valorFrete: frete.value, veiculo: veiculo.value, prazo: prazo.value, prazoColeta: prazoColeta.value, validade: validade.value, obs: obs.value, eixos: comPiso.value ? eixos.value : '', tipoCarga: comPiso.value ? tipoCarga.value : '', dedicado: fracionada && dedicado.value })
  busy.value = false
  if (r.ok) emit('close')
}
</script>

<template>
  <FormModal :title="'Responder ' + quote.codigo + (resposta.transportadora?.nome_fantasia ? ' · ' + resposta.transportadora.nome_fantasia : '')" :sub="cityLabel(quote.origem) + ' → ' + cityLabel(quote.destino)" @close="emit('close')">
    <div class="fgrid">
      <div v-if="fracionada" class="field c6"><label :for="'rt-' + resposta.id">Valor do frete (R$)</label>
        <MoneyInput :id="'rt-' + resposta.id" v-model="frete" placeholder="ex.: 2.500,00" /></div>
      <div v-else class="field c6"><label :for="'rt-' + resposta.id">R$ por tonelada</label>
        <MoneyInput :id="'rt-' + resposta.id" v-model="ton" placeholder="ex.: 250,00" /></div>
      <div v-if="fracionada" class="field c6"><label :for="'rc-' + resposta.id">Tipo de carro que atende <i>*</i></label>
        <select :id="'rc-' + resposta.id" v-model="veiculo" class="inp"><option value="">— escolher —</option><option v-for="v in db.domains.tipos_veiculo" :key="v.codigo" :value="v.codigo">{{ v.descricao }}</option></select></div>
      <div v-if="fracionada" class="field c12"><label class="use-prev" data-dedicado><input :id="'rd-' + resposta.id" v-model="dedicado" type="checkbox" /> Carga dedicada (vale o frete mínimo ANTT)</label></div>
      <div class="field c6"><label :for="'rpc-' + resposta.id">Prazo de coleta (dias)<template v-if="fracionada"> <i>*</i></template></label>
        <input :id="'rpc-' + resposta.id" v-model="prazoColeta" class="inp" type="number" min="0" placeholder="ex.: 1" /></div>
      <div class="field c6"><label :for="'rp-' + resposta.id">Prazo de entrega (dias)</label>
        <input :id="'rp-' + resposta.id" v-model="prazo" class="inp" type="number" min="1" placeholder="ex.: 3" /></div>
      <div v-if="comPiso" class="field c6"><label :for="'rg-' + resposta.id">Tipo de carga</label>
        <select :id="'rg-' + resposta.id" v-model="tipoCarga" class="inp"><option value="">— não informado —</option><option v-for="t in db.domains.tipos_carga_antt" :key="t.codigo" :value="t.codigo">{{ t.descricao }}</option></select></div>
      <div v-if="comPiso" class="field c6"><label :for="'re-' + resposta.id">Eixos do veículo</label>
        <select :id="'re-' + resposta.id" v-model="eixos" class="inp"><option value="">— não informado —</option><option v-for="n in EIXOS" :key="n" :value="String(n)">{{ n }} eixos</option></select></div>
      <div class="field c6"><label :for="'rv-' + resposta.id">Validade da proposta</label>
        <input :id="'rv-' + resposta.id" v-model="validade" class="inp" type="date" :min="minDate" /></div>
      <div class="field c6"><label :for="'ro-' + resposta.id">Observação</label>
        <input :id="'ro-' + resposta.id" v-model="obs" class="inp" maxlength="2000" placeholder="opcional" /></div>
      <div v-if="!fracionada" class="field c12 mut">Total estimado ({{ fmtN(kg) }} kg × R$/t ÷ 1.000): <b :id="'rf-' + resposta.id" class="mono">{{ estimate }}</b></div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="emit('close')">Cancelar</button>
      <button class="btn btn-green" data-act="respond-quote" :disabled="busy" :aria-busy="busy" @click="submit">Registrar resposta</button>
    </template>
  </FormModal>
</template>
