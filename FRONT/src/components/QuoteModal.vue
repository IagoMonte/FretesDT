<script setup>
import { computed, onMounted, ref } from 'vue'
import { useDialogFocus } from '../composables/useDialogFocus'
import { db, loadQuote, embalagemLabel, veiculoLabel, tipoCargaLabel } from '../stores/db'
import { auth } from '../stores/session'
import { modal, closeModal, confirmAction, openModal } from '../stores/ui'
import { declineQuote, cancelQuote } from '../services/quoteFlow'
import { money, fmtN, fmtD, fmtKg, dec, cityLabel, maskCNPJ } from '../utils/format'
import StatusPill from './StatusPill.vue'
import RespondModal from './RespondModal.vue'
import CloseModal from './CloseModal.vue'
import MotivoModal from './MotivoModal.vue'
import ForwardModal from './ForwardModal.vue'
import QuoteFormModal from './QuoteFormModal.vue'
import { usePolling } from '../composables/usePolling'
import { isGranel } from '../utils/items'
import ValidadeTag from './ValidadeTag.vue'
import NavIcon from './NavIcon.vue'
import AnttInfo from './AnttInfo.vue'

const session = auth.session
const dialog = ref(null)
const { onKeydown } = useDialogFocus(dialog)
const q = computed(() => db.quotes.find((x) => x.id === modal.payload.id))
const temPaletes = computed(() => (q.value?.itens ?? []).some((i) => i.paletes))
const respostas = computed(() => q.value?.negociacao?.respostas ?? [])
const fechamento = computed(() => q.value?.negociacao?.fechamento)
const negOpen = computed(() => ['EM_NEGOCIACAO', 'RESPONDIDA'].includes(q.value?.status) && q.value?.negociacao?.status === 'ABERTA')
const readOnly = !!modal.payload.readOnly
const canReply = computed(() => !readOnly && negOpen.value && session.canAction('NEGOCIACAO.RESPONDER'))
const canForward = computed(() => !readOnly && ['ABERTA', 'EM_NEGOCIACAO'].includes(q.value?.status) && session.canAction('NEGOCIACAO.ENCAMINHAR'))
const canEdit = computed(() => !readOnly && q.value?.status === 'ABERTA' && session.can('COTACAO', 'alterar') && !session.isCarrier)
const canClose = computed(() => !readOnly && negOpen.value && session.canAction('NEGOCIACAO.FECHAR'))
const canCancel = computed(() => !readOnly && ['ABERTA', 'EM_NEGOCIACAO', 'FECHADA'].includes(q.value?.status) && session.can('COTACAO', 'cancelar') && !session.isCarrier)

const bestId = computed(() => {
  const done = respostas.value.filter((r) => r.status === 'RESPONDIDA')
  if (done.length < 2) return null
  return done.reduce((a, b) => (dec(b.valor_frete) < dec(a.valor_frete) ? b : a)).id
})

const ordenadas = computed(() => {
  const done = respostas.value.filter((r) => r.status === 'RESPONDIDA').sort((a, b) => dec(a.valor_frete) - dec(b.valor_frete))
  return [...done, ...respostas.value.filter((r) => r.status !== 'RESPONDIDA')]
})
const dias = (n) => (n === null || n === undefined || n === '' ? '—' : `${n} ${Number(n) === 1 ? 'dia' : 'dias'}`)

const forwarding = ref(false)
const editing = ref(false)
const answering = ref(null)
const closing = ref(false)
const cancelling = ref(false)
const busy = ref(false)

usePolling(() => loadQuote(modal.payload.id), { interval: 15000 })

onMounted(() => {
  loadQuote(modal.payload.id)
})

function decline(r) {
  if (busy.value) return
  const { id, codigo } = q.value
  confirmAction(`Declinar a cotação **${codigo}**? A sua proposta será encerrada e não poderá ser retomada.`, async () => {
    await declineQuote(id, r.id)
    openModal('quote', { id })
  }, { title: 'Confirmar declínio', label: 'Declinar', icon: 'ban' })
}
async function doCancel(motivo) {
  busy.value = true
  const r = await cancelQuote(q.value.id, motivo)
  busy.value = false
  if (r.ok) cancelling.value = false
}
</script>

<template>
  <div v-if="q" ref="dialog" class="modal wide" role="dialog" aria-modal="true" aria-labelledby="quote-title" tabindex="-1" @keydown="onKeydown">
    <div class="modal-h">
      <h3 id="quote-title">Cotação <span class="nobr">{{ q.codigo }}</span></h3>
      <StatusPill :status="q.status" cotacao style="margin-left:4px" />
      <button class="x" type="button" aria-label="Fechar" @click="closeModal"><NavIcon name="x" :size="22" /></button>
    </div>
    <div class="modal-b">
      <div class="route-banner"><span class="c">{{ cityLabel(q.origem) }}</span><span class="a" aria-hidden="true">━━━ ▸</span><span class="c">{{ cityLabel(q.destino) }}</span></div>
      <div class="dgrid">
        <div class="m"><span>{{ q.operacao === 'RECEBIMENTO' ? 'Fornecedor' : 'Cliente' }}</span><b>{{ q.parte?.razao_social }}</b></div>
        <div class="m"><span>CNPJ</span><b class="mono">{{ maskCNPJ(q.parte?.cnpj) }}</b></div>
        <div class="m"><span>Operação</span><b>{{ q.operacao }}</b></div>
        <div class="m"><span>Tipo de carro</span><b>{{ q.tipo_veiculo_codigo ? veiculoLabel(q.tipo_veiculo_codigo) : (q.fracionada ? 'A definir pela transportadora' : '—') }}</b></div>
        <div v-if="q.tipo_carga_antt_codigo && (session.can('ANTT') || session.isCarrier)" class="m"><span>Carga ANTT · distância</span><b>{{ tipoCargaLabel(q.tipo_carga_antt_codigo) }}<template v-if="q.distancia_km"> · {{ fmtN(dec(q.distancia_km)) }} km</template></b></div>
        <div class="m"><span>QTD total</span><b>{{ fmtKg(q.quantidade_total_kg) }}</b></div>
        <div class="m"><span>Valor aprox. NF</span><b>{{ money(dec(q.valor_nf)) }}</b></div>
        <div class="m"><span>Data da solicitação</span><b>{{ fmtD(q.data_solicitacao) }}</b></div>
        <div class="m"><span>Vendedor</span><b>{{ q.vendedor?.nome }}</b></div>
        <div v-if="q.observacao" class="m" style="grid-column:1/-1"><span>Observação</span><b>{{ q.observacao }}</b></div>
      </div>

      <div class="tbl-wrap" role="region" tabindex="0" aria-label="Itens da cotação" style="margin-top:18px"><table class="tbl tbl-itens">
        <thead><tr><th class="n">#</th><th>Produto</th><th>ONU</th><th>Risco</th><th>Embalagem</th><th class="r">QTD (KG)</th><th class="r">Volumes</th><th v-if="temPaletes" class="r">Paletes</th><template v-if="q.itens.length >= 1"><th>Paletizado</th><th>Remontado</th></template></tr></thead>
        <tbody>
          <tr v-for="i in q.itens" :key="i.id" data-item>
            <td class="num">{{ i.sequencia }}</td><td><b>{{ i.produto?.nome }}</b></td><td data-onu>{{ i.produto?.onu || '—' }}</td><td data-risco>{{ i.produto?.risco || '—' }}</td><td>{{ embalagemLabel(i.embalagem_codigo) }}</td>
            <td class="num">{{ fmtKg(i.quantidade_kg) }}</td><td class="num">{{ i.volumes && !isGranel(i.embalagem_codigo) ? fmtN(i.volumes) : '—' }}</td><td v-if="temPaletes" class="num">{{ i.paletes ? fmtN(i.paletes) : '—' }}</td>
            <template v-if="q.itens.length >= 1"><td><span class="yn" :class="{ on: i.paletizado }">{{ i.paletizado ? 'Sim' : 'Não' }}</span></td><td><span class="yn" :class="{ on: i.remontado }">{{ i.remontado ? 'Sim' : 'Não' }}</span></td></template>
          </tr>
        </tbody>
      </table></div>

      <div v-if="q.cotacao_origem?.status === 'CANCELADA' && !session.isCarrier" class="prev-warn" data-origem-cancelada style="margin-top:16px;background:var(--red-t);border-color:var(--red-b);color:var(--red-d)"><NavIcon name="warn" :size="14" /> A cotação de origem <b>{{ q.cotacao_origem.codigo }}</b> foi cancelada; as respostas dela não valem mais.</div>
      <div v-else-if="q.cotacao_origem && !session.isCarrier" class="prev-warn" data-origem style="margin-top:16px"><NavIcon name="refresh" :size="14" /> Vinculada à <b>{{ q.cotacao_origem.codigo }}</b> ({{ fmtD(q.cotacao_origem.data_solicitacao) }}): as respostas dela valem aqui e acompanham as mudanças da original.</div>

      <div v-if="fechamento" class="resp-card">
        <div class="rc-h"><NavIcon name="ok" :size="16" /> Fechamento — {{ fechamento.transportadora?.nome_fantasia }}</div>
        <div class="vals">
          <div class="v"><b>{{ money(dec(fechamento.valor_frete)) }}</b><span>Valor do frete</span></div>
          <div v-if="!q.fracionada" class="v"><b>{{ money(dec(fechamento.valor_tonelada)) }}</b><span>por tonelada</span></div>
          <div v-if="fechamento.tipo_veiculo_codigo" class="v" data-veiculo><b>{{ veiculoLabel(fechamento.tipo_veiculo_codigo) }}</b><span>Tipo de carro</span></div>
          <div class="v"><b>{{ dias(fechamento.prazo_coleta_dias) }}</b><span>Prazo de coleta</span></div>
          <div class="v"><b>{{ dias(fechamento.prazo_dias) }}</b><span>Prazo de entrega</span></div>
          <div class="v"><b>{{ fmtD(fechamento.fechado_em) }}</b><span>Fechado em</span></div>
        </div>
        <AnttInfo v-if="fechamento.piso_valido !== false" :antt="fechamento.antt" :eixos="fechamento.eixos" />
        <p v-if="fechamento.observacao" class="mut" style="margin-top:10px">Obs.: {{ fechamento.observacao }}</p>
      </div>

      <ul v-if="respostas.length" class="resp-list" aria-label="Respostas das transportadoras">
        <li v-for="r in ordenadas" :key="r.id" class="resp-row" :class="{ best: r.id === bestId, dim: r.status !== 'RESPONDIDA' }" data-resp>
          <div class="resp-main">
            <div class="resp-head">
            <div class="resp-id">
              <NavIcon name="truck" :size="16" /> <b class="resp-nome">{{ r.transportadora.nome_fantasia }}</b>
              <span v-if="r.origem === 'REFERENCIADA' && !session.isCarrier" class="pill compact st-agu" data-ref>da {{ r.cotacao_codigo || q.cotacao_origem?.codigo }}</span>
              <span v-if="r.id === bestId" class="badge">MENOR VALOR</span>
            </div>
            <div v-if="r.status === 'RESPONDIDA'" class="resp-price"><div class="rv rv-frete"><b>{{ money(dec(r.valor_frete)) }}</b><span>Valor do frete</span></div></div>
            </div>
            <div v-if="r.status === 'RESPONDIDA'" class="rvals">
              <div v-if="!q.fracionada" class="rv"><b>{{ money(dec(r.valor_tonelada)) }}</b><span>por tonelada</span></div>
              <div v-if="r.tipo_veiculo_codigo" class="rv" data-veiculo><b>{{ veiculoLabel(r.tipo_veiculo_codigo) }}</b><span>Tipo de carro</span></div>
              <div class="rv" data-prazo-coleta><b>{{ dias(r.prazo_coleta_dias) }}</b><span>Prazo de coleta</span></div>
              <div class="rv" data-prazo-entrega><b>{{ dias(r.prazo_dias) }}</b><span>Prazo de entrega</span></div>
              <div v-if="r.tipo_carga_antt_codigo" class="rv" data-tipo-carga><b>{{ tipoCargaLabel(r.tipo_carga_antt_codigo) }}</b><span>Tipo de carga</span></div>
              <div v-if="r.dedicado" class="rv" data-dedicado><b>Sim</b><span>Carga dedicada</span></div>
              <div v-if="r.eixos" class="rv" data-eixos><b>{{ r.eixos }}</b><span>Eixos</span></div>
            </div>
            <p v-else class="resp-status"><StatusPill :status="r.status" compact /> <template v-if="r.status === 'AGUARDANDO'">encaminhada em {{ fmtD(r.encaminhado_em) }}</template></p>
          </div>
          <div v-if="(r.status === 'RESPONDIDA' && r.piso_valido !== false && r.antt) || r.validade || r.observacao" class="resp-sub">
            <AnttInfo v-if="r.status === 'RESPONDIDA' && r.piso_valido !== false" :antt="r.antt" />
            <p v-if="r.validade" class="mut"><ValidadeTag :value="r.validade" /></p>
            <p v-if="r.observacao" class="mut">Obs.: {{ r.observacao }}</p>
          </div>
            <div v-if="canReply && r.status !== 'DECLINADA' && r.origem !== 'REFERENCIADA'" class="resp-acts">
              <button class="btn btn-green btn-sm" type="button" data-act="answer-quote" :aria-label="'Responder – ' + r.transportadora.nome_fantasia" @click="answering = r"><NavIcon name="edit" :size="14" /> Responder</button>
              <button class="btn btn-line btn-sm" type="button" data-act="decline-quote" :aria-label="'Declinar – ' + r.transportadora.nome_fantasia" :disabled="busy" :aria-busy="busy" @click="decline(r)"><NavIcon name="ban" :size="14" /> Declinar</button>
            </div>
        </li>
      </ul>
      <div v-else-if="!fechamento" class="await"><NavIcon name="clock" :size="16" /> Nenhuma transportadora consultada ainda.<template v-if="canForward"> Use <b>Encaminhar</b> para escolher as transportadoras.</template></div>

    </div>
    <div class="modal-f">
      <button class="btn btn-line" @click="closeModal">Fechar janela</button>
      <button v-if="canEdit" class="btn btn-line" data-act="edit-quote" @click="editing = true"><NavIcon name="edit" :size="14" /> Editar</button>
      <button v-if="canForward" class="btn btn-primary" data-act="forward-quote" @click="forwarding = true">Encaminhar <NavIcon name="arrow" :size="14" /></button>
      <button v-if="canCancel" class="btn btn-line" data-act="cancel-quote" @click="cancelling = true"><NavIcon name="x" :size="14" /> Cancelar cotação</button>
      <button v-if="canClose" class="btn btn-green" data-act="close-quote" @click="closing = true"><NavIcon name="check" :size="14" /> Fechar negociação</button>
    </div>

 <QuoteFormModal v-if="editing" :quote="q" @close="editing = false" />
 <ForwardModal v-if="forwarding" :quote="q" @close="forwarding = false" />
    <RespondModal v-if="answering" :quote="q" :resposta="answering" @close="answering = null" />
    <CloseModal v-if="closing" :quote="q" @close="closing = false" />
    <MotivoModal v-if="cancelling" :title="'Cancelar ' + q.codigo" sub="a cotação não poderá mais ser negociada" :busy="busy" @close="cancelling = false" @confirm="doCancel" />
  </div>
</template>
