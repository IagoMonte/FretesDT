<script setup>
import { computed, ref } from 'vue'
import { auth } from '../stores/session'
import { embalagemLabel, veiculoLabel } from '../stores/db'
import { openModal, confirmAction } from '../stores/ui'
import { cancelQuote, declineQuote, encerrarQuote } from '../services/quoteFlow'
import { money, fmtD, fmtKg, dec, cityLabel, prazosTxt } from '../utils/format'
import { itemsSummary } from '../utils/items'
import { anttDelta } from '../utils/antt'
import { statusLabel } from '../factories/statusFactory'
import StatusPill from './StatusPill.vue'
import RespondModal from './RespondModal.vue'
import CloseModal from './CloseModal.vue'
import MotivoModal from './MotivoModal.vue'
import ForwardModal from './ForwardModal.vue'
import QuoteFormModal from './QuoteFormModal.vue'
import ValidadeTag from './ValidadeTag.vue'
import NavIcon from './NavIcon.vue'

const props = defineProps({ quote: Object, index: { type: Number, default: 0 } })

const session = auth.session
const q = computed(() => props.quote)
const respostas = computed(() => q.value.negociacao?.respostas ?? [])
const negOpen = computed(() => ['EM_NEGOCIACAO', 'RESPONDIDA'].includes(q.value.status) && q.value.negociacao?.status === 'ABERTA')
const fechamento = computed(() => q.value.negociacao?.fechamento)
const mine = computed(() => (session.isCarrier ? respostas.value.find((r) => r.origem !== 'REFERENCIADA') ?? null : null))
const refResp = computed(() => (session.isCarrier ? respostas.value[0] ?? null : null))
const canReply = computed(() => !!mine.value && negOpen.value && mine.value.status !== 'DECLINADA' && session.canAction('NEGOCIACAO.RESPONDER'))
const canForward = computed(() => ['ABERTA', 'EM_NEGOCIACAO'].includes(q.value.status) && session.canAction('NEGOCIACAO.ENCAMINHAR'))
const canEdit = computed(() => q.value.status === 'ABERTA' && session.can('COTACAO', 'alterar') && !session.isCarrier)
const canClose = computed(() => negOpen.value && session.canAction('NEGOCIACAO.FECHAR'))
const canCancel = computed(() => ['ABERTA', 'EM_NEGOCIACAO', 'FECHADA'].includes(q.value.status) && session.can('COTACAO', 'cancelar') && !session.isCarrier)
const canEnd = computed(() => ['ABERTA', 'EM_NEGOCIACAO'].includes(q.value.status) && session.canAction('NEGOCIACAO.FECHAR') && !session.isCarrier)
const hideCarrier = session.isCarrier || session.isSeller
const naoEnviada = (r) => session.isCarrier && q.value.status === 'ENCERRADA' && r.status === 'AGUARDANDO'
const who = session.isCarrier ? 'Você' : 'Transportadora'
const sinal = { abaixo: '−', acima: '+', igual: '' }
const anttCard = (antt) => {
  const d = anttDelta(antt)
  return `frete mínimo ${money(dec(antt.piso))} · ${sinal[d.sinal]}${money(d.abs)} (${d.pct.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%)`
}
const emb = computed(() => {
  const i = q.value.itens?.[0]
  return i ? embalagemLabel(i.embalagem_codigo) : '—'
})

const forwarding = ref(false)
const editing = ref(false)
const answering = ref(false)
const closing = ref(false)
const cancelling = ref(false)
const ending = ref(false)
const busy = ref(false)

function decline() {
  if (busy.value || !mine.value) return
  confirmAction(`Declinar a cotação **${q.value.codigo}**? A sua proposta será encerrada e não poderá ser retomada.`, async () => {
    busy.value = true
    await declineQuote(q.value.id, mine.value.id)
    busy.value = false
  }, { title: 'Confirmar declínio', label: 'Declinar', icon: 'ban' })
}
async function doCancel(motivo) {
  busy.value = true
  const r = await cancelQuote(q.value.id, motivo)
  busy.value = false
  if (r.ok) cancelling.value = false
}
async function doEnd(motivo) {
  busy.value = true
  const r = await encerrarQuote(q.value.id, motivo)
  busy.value = false
  if (r.ok) ending.value = false
}
</script>

<template>
  <div class="qcard" :style="{ animationDelay: index * 0.05 + 's' }">
    <div class="qhead">
      <span class="qc">{{ q.codigo }}</span>
      <span class="qd">{{ fmtD(q.data_solicitacao) }} · {{ q.vendedor?.nome }}</span>
      <StatusPill :status="q.status" cotacao />
    </div>
    <div class="qbody">
      <div class="route"><span class="pt o"><i></i>{{ cityLabel(q.origem) }}</span><span class="ln"></span><span class="pt d"><i></i>{{ cityLabel(q.destino) }}</span></div>
      <div class="qmeta">
        <div class="m"><span>{{ q.operacao === 'RECEBIMENTO' ? 'Fornecedor' : 'Cliente' }}</span><b>{{ q.parte?.razao_social }}</b></div>
        <div class="m"><span>Produto / Emb.</span><b>{{ itemsSummary(q.itens) }} · {{ emb }}</b></div>
        <div class="m"><span>QTD (KG)</span><b>{{ fmtKg(q.quantidade_total_kg) }}</b></div>
        <div class="m"><span>Itens</span><b>{{ q.itens?.length ?? 0 }}</b></div>
        <div class="m"><span>Veículo</span><b>{{ q.tipo_veiculo_codigo ? veiculoLabel(q.tipo_veiculo_codigo) : (q.fracionada ? 'A definir' : '—') }}</b></div>
        <div class="m"><span>Valor NF</span><b>{{ money(dec(q.valor_nf)) }}</b></div>
      </div>
      <div v-if="q.cotacao_origem?.status === 'CANCELADA' && !session.isCarrier" class="resp-line wait" data-origem-cancelada><NavIcon name="warn" :size="14" /> Origem <b>{{ q.cotacao_origem.codigo }}</b> cancelada: as respostas dela não valem mais</div>
      <div v-else-if="q.cotacao_origem && !session.isCarrier" class="resp-line wait" data-origem><NavIcon name="refresh" :size="14" /> Vinculada à <b>{{ q.cotacao_origem.codigo }}</b>&nbsp;({{ fmtD(q.cotacao_origem.data_solicitacao) }})</div>
      <div v-if="q.status === 'ENCERRADA' && !session.isCarrier" class="resp-line wait" data-encerrada><NavIcon name="ban" :size="14" /> Encerrada sem fechamento: as respostas abaixo continuam válidas</div>
      <div v-if="fechamento" class="resp-line"><NavIcon name="ok" :size="14" /> Fechada<template v-if="!hideCarrier"> com <b>{{ fechamento.transportadora?.nome_fantasia }}</b></template>&nbsp;· {{ money(dec(fechamento.valor_frete)) }} · {{ prazosTxt(fechamento) }}</div>
      <template v-else>
        <div v-for="r in respostas" :key="r.id" class="resp-line" :class="{ wait: r.status !== 'RESPONDIDA' }" data-resp>
          <StatusPill :status="r.status" compact />&nbsp;
          <template v-if="r.status === 'RESPONDIDA'"><NavIcon name="truck" :size="14" /> <b>{{ hideCarrier ? who : r.transportadora.nome_fantasia }}</b><span v-if="r.origem === 'REFERENCIADA' && !session.isCarrier" class="pill compact st-agu" data-ref>&nbsp;da {{ r.cotacao_codigo }}</span>&nbsp;respondeu: <b>{{ money(dec(r.valor_frete)) }}</b>&nbsp;· {{ prazosTxt(r) }}<template v-if="r.validade">&nbsp;· <ValidadeTag :value="r.validade" :label="false" /></template><span v-if="r.antt" class="antt-card" :class="r.antt.abaixo ? 'abaixo' : 'dentro'" data-antt-card>&nbsp;· {{ anttCard(r.antt) }}</span></template>
          <template v-else-if="r.status === 'DECLINADA'"><NavIcon name="ban" :size="14" /> <b>{{ hideCarrier ? who : r.transportadora.nome_fantasia }}</b>&nbsp;declinou</template>
          <template v-else-if="naoEnviada(r)"><NavIcon name="warn" :size="14" /> <b data-resp-nao-enviada>Resposta não enviada</b></template>
          <template v-else><NavIcon name="clock" :size="14" /> <b>{{ hideCarrier ? who : r.transportadora.nome_fantasia }}</b>&nbsp;— aguardando resposta</template>
        </div>
      </template>
    </div>
    <div class="qfoot">
      <span class="vf">
        <template v-if="session.isCarrier">Sua resposta: <b>{{ refResp ? statusLabel(refResp.status) : '—' }}</b></template>
        <template v-else-if="respostas.length">Consultadas: <b>{{ respostas.length }}</b></template>
        <template v-else>Transportadoras a definir</template>
      </span>
      <template v-if="canReply">
        <button class="btn btn-green btn-sm" data-act="answer-quote" @click="answering = true"><NavIcon name="edit" :size="14" /> Responder</button>
        <button class="btn btn-line btn-sm" data-act="decline-quote" :disabled="busy" :aria-busy="busy" @click="decline"><NavIcon name="ban" :size="14" /> Declinar</button>
      </template>
      <button v-if="canForward" class="btn btn-primary btn-sm" data-act="def-quote" @click="forwarding = true">Encaminhar <NavIcon name="arrow" :size="14" /></button>
      <button v-if="canEdit" class="btn btn-line btn-sm" data-act="edit-quote" @click="editing = true"><NavIcon name="edit" :size="14" /> Editar</button>
      <button class="btn btn-line btn-sm" data-act="view-quote" @click="openModal('quote', { id: q.id })"><NavIcon name="eye" :size="14" /> Visualizar</button>
      <button v-if="canClose" class="btn btn-green btn-sm" data-act="close-quote" @click="closing = true"><NavIcon name="check" :size="14" /> Fechar</button>
      <button v-if="canEnd" class="btn btn-line btn-sm" data-act="end-quote" @click="ending = true"><NavIcon name="ban" :size="14" /> Encerrar</button>
      <button v-if="canCancel" class="btn btn-line btn-sm" data-act="cancel-quote" @click="cancelling = true"><NavIcon name="x" :size="14" /> Cancelar</button>
    </div>

    <QuoteFormModal v-if="editing" :quote="q" @close="editing = false" />
    <ForwardModal v-if="forwarding" :quote="q" @close="forwarding = false" />
    <RespondModal v-if="answering && mine" :quote="q" :resposta="mine" @close="answering = false" />
    <CloseModal v-if="closing" :quote="q" @close="closing = false" />
    <MotivoModal v-if="ending" :title="'Encerrar ' + q.codigo" sub="as respostas recebidas continuam válidas, mas a cotação não poderá mais ser alterada nem encaminhada" label="Encerrar cotação" opcional :busy="busy" @close="ending = false" @confirm="doEnd" />
    <MotivoModal v-if="cancelling" :title="'Cancelar ' + q.codigo" sub="a cotação não poderá mais ser negociada" :busy="busy" @close="cancelling = false" @confirm="doCancel" />
  </div>
</template>
