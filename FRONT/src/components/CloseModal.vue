<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { db, loadCarriers } from '../stores/db'
import { closeNegotiation } from '../services/quoteFlow'
import { pushToast, confirmAction } from '../stores/ui'
import { cityLabel, dec, fmtN, formatMoney, freightValue, money, parseNum, sumKg } from '../utils/format'
import { isPast } from '../utils/format'
import { EIXOS, pisoValido } from '../utils/antt'
import FormModal from './FormModal.vue'
import MoneyInput from './MoneyInput.vue'
import NavIcon from './NavIcon.vue'

const props = defineProps({ quote: { type: Object, required: true } })
const emit = defineEmits(['close'])

const respostas = computed(() => props.quote.negociacao?.respostas ?? [])
const answered = (id) => respostas.value.find((r) => r.transportadora.id === id && r.status === 'RESPONDIDA')

const options = computed(() => {
  const byId = new Map()
  for (const r of respostas.value) byId.set(r.transportadora.id, { id: r.transportadora.id, nome: r.transportadora.nome_fantasia })
  for (const c of db.carriers.filter((x) => x.ativo)) if (!byId.has(c.id)) byId.set(c.id, { id: c.id, nome: c.nome_fantasia })
  const rank = (o) => { const r = answered(o.id); return r ? dec(r.valor_frete) : Infinity }
  return [...byId.values()].sort((a, b) => rank(a) - rank(b) || a.nome.localeCompare(b.nome))
})
const optionLabel = (o) => {
  const r = answered(o.id)
  return r ? `${o.nome} — respondeu ${money(dec(r.valor_frete))}${isPast(r.validade) ? ' (proposta vencida)' : ''}` : o.nome
}

const fracionada = !!props.quote.fracionada
const f = ref({ transportadoraId: '', valorTon: '', valorFrete: '', veiculo: '', prazo: '', prazoColeta: '', eixos: '', tipoCarga: '', obs: '', porFora: false, pedidoVenda: '', deposito: 'VIRTUAL', modalidade: 'CIF', coleta: '', entrega: '' })
const busy = ref(false)
const comPiso = computed(() => pisoValido(props.quote, answered(f.value.transportadoraId)))

function prefill(id) {
  const r = answered(id)
  f.value.valorTon = r && !fracionada ? formatMoney(dec(r.valor_tonelada)) : ''
  f.value.valorFrete = r && fracionada ? formatMoney(dec(r.valor_frete)) : ''
  f.value.veiculo = r?.tipo_veiculo_codigo || ''
  f.value.prazo = r?.prazo_dias ? String(r.prazo_dias) : ''
  f.value.prazoColeta = r?.prazo_coleta_dias != null ? String(r.prazo_coleta_dias) : ''
  f.value.eixos = r?.eixos ? String(r.eixos) : ''
  f.value.tipoCarga = r?.tipo_carga_antt_codigo || props.quote.tipo_carga_antt_codigo || ''
  f.value.obs = r?.observacao || ''
}
watch(() => f.value.transportadoraId, prefill)

onMounted(() => {
  if (!db.carriers.length) loadCarriers({ ativo: 1 })
  f.value.transportadoraId = options.value.find((o) => answered(o.id))?.id ?? ''
})

const kg = computed(() => sumKg(props.quote.itens))
const estimate = computed(() => money(freightValue(f.value.valorTon, kg.value)))

const semResposta = computed(() => !!f.value.transportadoraId && !answered(f.value.transportadoraId))
watch(() => f.value.transportadoraId, () => { f.value.porFora = false })

const aviso = computed(() => {
  const r = answered(f.value.transportadoraId)
  if (!r) return ''
  const digitado = parseNum(fracionada ? f.value.valorFrete : f.value.valorTon)
  const proposto = dec(fracionada ? r.valor_frete : r.valor_tonelada)
  const un = fracionada ? '' : '/t'
  if (digitado > 0 && proposto > 0 && digitado < proposto / 2) {
    return `O valor informado (**${money(digitado)}${un}**) é menos da metade da proposta da transportadora (**${money(proposto)}${un}**). Fechar mesmo assim?`
  }
  const piso = dec(r.antt?.piso)
  const total = fracionada ? digitado : freightValue(f.value.valorTon, kg.value)
  if (piso > 0 && total > 0 && total < piso) {
    return `O frete de **${money(total)}** fica abaixo do piso mínimo da ANTT (**${money(piso)}**). Fechar mesmo assim?`
  }
  return ''
})

function submit() {
  if (busy.value) return
  if (semResposta.value && !f.value.porFora) {
    pushToast('err', 'Esta transportadora não respondeu a cotação. Marque **Negociado por fora** para fechar mesmo assim.')
    return
  }
  if (aviso.value) {
    confirmAction(aviso.value, enviar, { title: 'Confirmar valor de fechamento', label: 'Fechar mesmo assim', icon: 'warn', tone: 'primary' })
    return
  }
  enviar()
}

async function enviar() {
  if (busy.value) return
  busy.value = true
  const r = await closeNegotiation(props.quote.id, f.value)
  busy.value = false
  if (r.ok) emit('close')
}
</script>

<template>
  <FormModal :title="'Fechar negociação ' + quote.codigo" :sub="cityLabel(quote.origem) + ' → ' + cityLabel(quote.destino)" size="wide" @close="emit('close')">
    <div class="fgrid">
      <div class="field c12"><label for="fc-transp">Transportadora vencedora <i>*</i></label>
        <select id="fc-transp" v-model="f.transportadoraId" class="inp">
          <option value="">— selecionar —</option>
          <option v-for="o in options" :key="o.id" :value="o.id">{{ optionLabel(o) }}</option>
        </select></div>
      <div v-if="fracionada" class="field c3"><label for="fc-frete">Valor do frete (R$) <i>*</i></label>
        <MoneyInput id="fc-frete" v-model="f.valorFrete" placeholder="ex.: 2.400,00" /></div>
      <div v-else class="field c3"><label for="fc-ton">R$ por tonelada <i>*</i></label>
        <MoneyInput id="fc-ton" v-model="f.valorTon" placeholder="ex.: 175,00" /></div>
      <div class="field c3"><label for="fc-prazo-coleta">Prazo de coleta (dias)<template v-if="fracionada"> <i>*</i></template></label>
        <input id="fc-prazo-coleta" v-model="f.prazoColeta" class="inp" type="number" min="0" /></div>
      <div class="field c3"><label for="fc-prazo">Prazo de entrega (dias) <i>*</i></label>
        <input id="fc-prazo" v-model="f.prazo" class="inp" type="number" min="1" /></div>
      <div v-if="comPiso" class="field c3"><label for="fc-tipo">Tipo de carga</label>
        <select id="fc-tipo" v-model="f.tipoCarga" class="inp"><option value="">—</option><option v-for="t in db.domains.tipos_carga_antt" :key="t.codigo" :value="t.codigo">{{ t.descricao }}</option></select></div>
      <div v-if="comPiso" class="field c3"><label for="fc-eixos">Eixos</label>
        <select id="fc-eixos" v-model="f.eixos" class="inp"><option value="">—</option><option v-for="n in EIXOS" :key="n" :value="String(n)">{{ n }} eixos</option></select></div>
      <div v-if="fracionada" class="field c3"><label for="fc-veic">Tipo de carro <i>*</i></label>
        <select id="fc-veic" v-model="f.veiculo" class="inp"><option value="">— escolher —</option><option v-for="v in db.domains.tipos_veiculo" :key="v.codigo" :value="v.codigo">{{ v.descricao }}</option></select></div>
      <div v-else class="field c3"><label>Frete estimado ({{ fmtN(kg) }} kg)</label>
        <div id="fc-total" class="inp mono" style="background:#F4FAF6;font-weight:600">{{ estimate }}</div></div>
      <div v-if="semResposta" class="field c12">
        <label class="chk" for="fc-fora"><input id="fc-fora" v-model="f.porFora" type="checkbox" /> Negociado por fora — esta transportadora não respondeu a cotação</label></div>
      <div class="field c12"><label for="fc-obs">Observação do fechamento</label>
        <input id="fc-obs" v-model="f.obs" class="inp" placeholder="ex.: negociado por telefone" /></div>
      <div class="field c6"><label for="fc-pedido">Número do pedido <i>*</i></label>
        <input id="fc-pedido" v-model="f.pedidoVenda" class="inp" maxlength="30" placeholder="ex.: PV-1001" /></div>
      <div class="field c3"><label for="fc-dep">Depósito</label>
        <select id="fc-dep" v-model="f.deposito" class="inp"><option value="VIRTUAL">VIRTUAL</option><option value="FISICO">FÍSICO</option></select></div>
      <div class="field c3"><label for="fc-mod">Modalidade do frete</label>
        <select id="fc-mod" v-model="f.modalidade" class="inp"><option>CIF</option><option>FOB</option></select></div>
      <div class="field c3"><label for="fc-col">Coleta prevista</label><input id="fc-col" v-model="f.coleta" type="date" class="inp" /></div>
      <div class="field c3"><label for="fc-ent">Entrega prevista</label><input id="fc-ent" v-model="f.entrega" type="date" class="inp" /></div>
      <div class="field c12 mut" style="font-size:12px">Ao fechar, a cotação passa para <b>PROGRAMADA</b> e a carga entra na <b>Programação de Cargas</b> com estes dados.</div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="emit('close')">Cancelar</button>
      <button class="btn btn-green" data-act="close-negotiation" :disabled="busy" :aria-busy="busy" @click="submit"><NavIcon name="check" :size="14" /> Fechar e programar carga</button>
    </template>
  </FormModal>
</template>
