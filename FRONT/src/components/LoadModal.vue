<script setup>
import { computed, ref } from 'vue'
import { useDialogFocus } from '../composables/useDialogFocus'
import { db, embalagemLabel, veiculoLabel } from '../stores/db'
import { modal, closeModal } from '../stores/ui'
import { money, fmtN, fmtD, fmtDH, fmtKg, dec, cityLabel } from '../utils/format'
import StatusPill from './StatusPill.vue'
import DeadlineTag from './DeadlineTag.vue'
import NavIcon from './NavIcon.vue'

const dialog = ref(null)
const { onKeydown } = useDialogFocus(dialog)
const l = computed(() => db.loads.find((x) => x.id === modal.payload.id))
</script>

<template>
  <div v-if="l" ref="dialog" class="modal wide" role="dialog" aria-modal="true" aria-labelledby="load-title" tabindex="-1" @keydown="onKeydown">
    <div class="modal-h">
      <h3 id="load-title">Carga {{ l.codigo }}</h3>
      <StatusPill :status="l.status" style="margin-left:4px" />
      <DeadlineTag :data-entrega-realizada="l.data_entrega_realizada" :entregue-no-prazo="l.entregue_no_prazo" :status="l.status" />
      <button class="x" type="button" aria-label="Fechar" @click="closeModal"><NavIcon name="x" :size="22" /></button>
    </div>
    <div class="modal-b">
      <div class="route-banner"><span class="c">{{ cityLabel(l.origem) }}</span><span class="a" aria-hidden="true">━━━ ▸</span><span class="c">{{ cityLabel(l.destino) }}</span></div>
      <div class="dgrid">
        <div class="m"><span>{{ l.operacao === 'RECEBIMENTO' ? 'Fornecedor' : 'Cliente' }}</span><b>{{ l.parte?.razao_social }}</b></div>
        <div class="m"><span>Operação</span><b>{{ l.operacao }}</b></div>
        <div class="m"><span>Pedido</span><b>{{ l.pedido_venda || '—' }}</b></div>
        <div class="m"><span>NFe</span><b>{{ l.nfe_numero || '—' }}</b></div>
        <div class="m"><span>Transportadora</span><b>{{ l.transportadora?.nome_fantasia || 'a definir' }}</b></div>
        <div class="m"><span>Tipo de carro</span><b>{{ l.tipo_veiculo_codigo ? veiculoLabel(l.tipo_veiculo_codigo) : '—' }}</b></div>
        <div class="m"><span>Frete</span><b>{{ l.modalidade_frete }}</b></div>
        <div class="m"><span>Depósito</span><b>{{ l.deposito }}</b></div>
        <div class="m"><span>Valor por tonelada</span><b>{{ l.valor_tonelada ? money(dec(l.valor_tonelada)) : '—' }}</b></div>
        <div v-if="l.valor_proposto" class="m"><span>Valor proposto</span><b>{{ money(dec(l.valor_proposto)) }}</b><small class="mut">fechado com valor diferente da proposta ({{ l.valor_tonelada ? money(dec(l.valor_tonelada)) : '—' }}/t)</small></div>
        <div class="m"><span>Valor total</span><b>{{ l.valor_total != null && l.valor_total !== '' ? money(dec(l.valor_total)) : '—' }}</b></div>
        <div class="m"><span>Coleta prevista</span><b>{{ fmtD(l.data_coleta_prevista) }}</b></div>
        <div class="m"><span>Entrega prevista</span><b>{{ fmtD(l.data_entrega_prevista) }}</b></div>
        <div v-if="l.data_entrega_realizada" class="m"><span>Entregue em</span><b>{{ l.entregue_em ? fmtDH(l.entregue_em) : fmtD(l.data_entrega_realizada) }}</b></div>
        <div class="m"><span>Representante</span><b>{{ l.representante?.nome || '—' }}</b></div>
        <div v-if="l.observacao" class="m" style="grid-column:1/-1"><span>Observação</span><b>{{ l.observacao }}</b></div>
      </div>
      <div class="tbl-wrap" role="region" tabindex="0" aria-label="Itens da carga" style="margin-top:14px"><table class="tbl">
        <thead><tr><th>#</th><th>Produto</th><th>Embalagem</th><th>QTD (KG)</th></tr></thead>
        <tbody>
          <tr v-for="i in l.itens" :key="i.id" data-item>
            <td class="num">{{ i.sequencia }}</td><td><b>{{ i.produto?.nome }}</b></td><td>{{ embalagemLabel(i.embalagem_codigo) }}</td><td class="num">{{ fmtKg(i.quantidade_kg) }}</td>
          </tr>
        </tbody>
      </table></div>
      <p class="mut" style="margin-top:10px">Total: {{ fmtN(dec(l.quantidade_total_kg)) }} kg</p>
    </div>
    <div class="modal-f"><button class="btn btn-line" @click="closeModal">Fechar janela</button></div>
  </div>
</template>
