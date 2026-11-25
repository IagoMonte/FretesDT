<script setup>
import { computed } from 'vue'
import { dec, fmtN, money } from '../utils/format'
import { anttDelta } from '../utils/antt'

const props = defineProps({ antt: { type: Object, default: null }, eixos: { type: Number, default: null } })
const d = computed(() => anttDelta(props.antt))
const pct = computed(() => d.value.pct.toLocaleString('pt-BR', { maximumFractionDigits: 1 }))
const titulo = computed(() => ({ abaixo: 'Abaixo do frete mínimo ANTT', acima: 'Acima do frete mínimo ANTT', igual: 'No frete mínimo ANTT' })[d.value.sinal])
</script>

<template>
  <div v-if="antt" class="antt-info" :class="antt.abaixo ? 'abaixo' : 'dentro'" data-antt>
    <b>{{ titulo }}</b>
    <span>frete mínimo {{ money(dec(antt.piso)) }} · {{ fmtN(dec(antt.km)) }} km · {{ antt.eixos ?? eixos }} eixos</span>
    <span class="dif" data-antt-dif>{{ d.sinal === 'abaixo' ? '−' : '+' }}{{ money(d.abs) }} ({{ pct }}%)</span>
    <span v-if="d.sinal !== 'igual'" class="insight" data-antt-insight>Resposta {{ money(d.abs) }} ({{ pct }}%) {{ d.sinal }} do frete mínimo</span>
    <span v-else class="insight" data-antt-insight>Resposta igual ao frete mínimo</span>
  </div>
</template>

<style scoped>
.antt-info { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: baseline; margin-top: 10px; padding: 8px 10px; border-radius: 7px; font-size: 12.5px; border: 1px solid; }
.antt-info.dentro { background: var(--green-t); color: var(--green-d); border-color: var(--green-b); }
.antt-info.abaixo { background: var(--red-t); color: var(--red-d); border-color: var(--red-b); }
.dif { margin-left: auto; font-weight: 700; }
.insight { flex-basis: 100%; font-size: 12px; opacity: .9; }
</style>
