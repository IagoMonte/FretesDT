<script setup>
import { computed } from 'vue'
import { dec, fmtD, fmtTon, freightValue, money, prazosTxt } from '../utils/format'
import StatusPill from './StatusPill.vue'
import ValidadeTag from './ValidadeTag.vue'
import NavIcon from './NavIcon.vue'

const props = defineProps({ anterior: { type: Object, required: true }, title: { type: String, default: 'Cotação anterior' }, kg: { type: Number, default: 0 } })
const respostas = computed(() => props.anterior.respostas ?? [])
const fech = computed(() => props.anterior.fechamento)
const est = (valorTon) => money(freightValue(dec(valorTon), props.kg))
</script>

<template>
  <div class="prev-quote" data-prev-quote>
    <div class="pq-h">
      <b><NavIcon name="refresh" :size="14" /> {{ title }}: <span class="mono">{{ anterior.codigo }}</span></b>
      <span class="mut">{{ fmtD(anterior.data_solicitacao) }}</span>
      <StatusPill v-if="anterior.status" :status="anterior.status" compact />
    </div>
    <div v-for="r in respostas" :key="r.id" class="pq-r" data-prev-resp>
      <b>{{ r.transportadora?.nome_fantasia }}</b>
      <span class="pq-ton" data-pq-ton>{{ money(dec(r.valor_tonelada)) }}/t</span>
      <span v-if="kg > 0" class="pq-est" data-pq-est>frete estimado p/ {{ fmtTon(kg) }} t: <b>{{ est(r.valor_tonelada) }}</b></span>
      <span v-else class="mut">informe a QTD para estimar o frete</span>
      <span>{{ prazosTxt(r) }}</span>
      <ValidadeTag v-if="r.validade" :value="r.validade" />
    </div>
    <div v-if="!respostas.length" class="mut">Sem respostas registradas.</div>
    <div v-if="fech" class="pq-f"><NavIcon name="ok" :size="14" /> Fechada com <b>{{ fech.transportadora?.nome_fantasia }}</b> · <b>{{ money(dec(fech.valor_tonelada)) }}/t</b><template v-if="kg > 0"> · frete estimado p/ {{ fmtTon(kg) }} t: {{ est(fech.valor_tonelada) }}</template></div>
  </div>
</template>
