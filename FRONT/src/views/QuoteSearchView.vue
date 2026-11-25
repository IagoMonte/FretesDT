<script setup>
import { computed, onMounted, reactive } from 'vue'
import { auth } from '../stores/session'
import { db, loadDomains, loadQuotes, veiculoLabel } from '../stores/db'
import { openModal } from '../stores/ui'
import { STATUS_COTACAO, STATUS_COTACAO_TRANSPORTADORA, statusLabel } from '../factories/statusFactory'
import { money, fmtN, fmtD, fmtKg, dec, norm, cityLabel, fmtRegistros } from '../utils/format'
import { itemsSummary } from '../utils/items'
import CardPanel from '../components/CardPanel.vue'
import StatusPill from '../components/StatusPill.vue'
import EmptyState from '../components/EmptyState.vue'
import NavIcon from '../components/NavIcon.vue'
import { usePolling } from '../composables/usePolling'
import { useIsMobile } from '../composables/useMedia'

const session = auth.session
const isMobile = useIsMobile()
const filters = reactive({ text: '', status: '', produto: '', origem: '', destino: '' })

usePolling(() => loadQuotes(undefined, { silent: true }), { interval: 15000 })
onMounted(() => {
  loadDomains()
  loadQuotes()
})

const respondidas = (q) => (q.negociacao?.respostas ?? []).filter((r) => r.status === 'RESPONDIDA').length
const consultadas = (q) => (q.negociacao?.respostas ?? []).filter((r) => r.origem !== 'REFERENCIADA').length
const carro = (q) => (q.tipo_veiculo_codigo ? veiculoLabel(q.tipo_veiculo_codigo) : q.fracionada ? 'A definir' : '')
const vencedora = (q) => q.negociacao?.fechamento?.transportadora?.nome_fantasia

const statusOpcoes = session.isCarrier ? STATUS_COTACAO_TRANSPORTADORA : STATUS_COTACAO

const visible = computed(() => {
  const t = norm(filters.text).trim()
  const fp = norm(filters.produto).trim()
  const fo = norm(filters.origem).trim()
  const fd = norm(filters.destino).trim()
  return db.quotes.slice().sort((a, b) => String(b.codigo).localeCompare(String(a.codigo))).filter((q) => {
    const hay = norm([
      q.codigo, q.parte?.razao_social, q.vendedor?.nome, q.observacao, cityLabel(q.origem), cityLabel(q.destino), statusLabel(q.status, { carrier: session.isCarrier }),
      vencedora(q), ...q.itens.map((i) => i.produto?.nome),
    ].join(' '))
    return (!t || hay.includes(t)) && (!filters.status || q.status === filters.status)
      && (!fp || norm(q.itens.map((i) => i.produto?.nome).join(' ')).includes(fp))
      && (!fo || norm(cityLabel(q.origem)).includes(fo))
      && (!fd || norm(cityLabel(q.destino)).includes(fd))
  })
})
const filtering = computed(() => !!(filters.text.trim() || filters.status || filters.produto.trim() || filters.origem.trim() || filters.destino.trim()))
const vazio = computed(() => (db.loading.quotes ? 'Carregando…' : filtering.value ? 'Nenhuma cotação com esse filtro.' : 'Nenhuma cotação.'))
const view = (q) => openModal('quote', { id: q.id, readOnly: true })
</script>

<template>
  <div v-if="session.isCarrier" class="note-strip"><NavIcon name="truck" :size="16" /> Exibindo somente as cotações encaminhadas para <b>&nbsp;{{ session.transportadora?.nome_fantasia || session.name }}</b>.</div>

  <CardPanel title="Consultar cotação" :count="db.loading.quotes && !db.quotes.length ? 'Carregando…' : db.loadError.quotes && !db.quotes.length ? 'Erro ao carregar' : filtering ? `${visible.length} de ${db.quotes.length} registros` : fmtRegistros(db.quotes.length)" flush>
    <div class="filt">
      <input id="flt-txt" v-model="filters.text" class="inp" type="search" aria-label="Buscar cotações" placeholder="Buscar por código, cliente, produto, cidade…" />
      <input id="flt-produto" v-model="filters.produto" class="inp" type="search" aria-label="Filtrar por produto" placeholder="Produto" />
      <input id="flt-origem" v-model="filters.origem" class="inp" type="search" aria-label="Filtrar por origem" placeholder="Origem (cidade/UF)" />
      <input id="flt-destino" v-model="filters.destino" class="inp" type="search" aria-label="Filtrar por destino" placeholder="Destino (cidade/UF)" />
      <select id="flt-status" v-model="filters.status" class="inp" aria-label="Filtrar por status"><option value="">Todos os status</option><option v-for="s in statusOpcoes" :key="s" :value="s">{{ statusLabel(s, { carrier: session.isCarrier }) }}</option></select>
    </div>
    <div v-if="isMobile" class="load-cards" data-quote-cards>
      <article v-for="q in visible" :key="q.id" class="load-card" data-quote-card>
        <div class="lc-h">
          <b class="mono">{{ q.codigo }}</b><StatusPill :status="q.status" compact cotacao />
          <button class="btn btn-line btn-sm" data-act="view-quote" :aria-label="'Ver cotação ' + q.codigo" title="Ver cotação" @click="view(q)"><NavIcon name="eye" :size="14" /></button>
        </div>
        <div><b>{{ q.parte?.razao_social }}</b> <span class="mut">· {{ itemsSummary(q.itens) }} · {{ fmtN(dec(q.quantidade_total_kg)) }} kg</span></div>
        <div class="lc-route">{{ cityLabel(q.origem) }} → {{ cityLabel(q.destino) }}</div>
        <div class="lc-meta">
          <div><span>Data</span>{{ fmtD(q.data_solicitacao) }}</div>
          <div><span>Vendedor</span>{{ q.vendedor?.nome }}</div>
          <div><span>Valor NF</span>{{ money(dec(q.valor_nf)) }}</div>
          <div><span>Tipo de carro</span>{{ carro(q) || '—' }}</div>
        </div>
      </article>
      <EmptyState list-key="quotes" v-if="!visible.length" icon="quotes" :message="vazio" />
    </div>
    <div v-else class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de cotações">
      <table id="quoteTable" class="tbl">
        <thead>
          <tr>
            <th class="act">Ações</th>
            <th>Código</th><th>Data</th><th>Status</th><th>Operação</th><th>Cliente / Fornecedor</th><th>Vendedor</th>
            <th>Produtos</th><th>Qtd (kg)</th><th>Tipo de carro</th><th>Origem</th><th>Destino</th><th>Distância (km)</th>
            <th>Valor NF</th><th>Respostas</th><th>Fechada com</th><th>Observação</th>
          </tr>
        </thead>
        <tbody id="quoteRows">
          <tr v-for="q in visible" :key="q.id" data-quote-row>
            <td class="act"><div class="wrap">
              <button class="btn btn-line btn-sm" data-act="view-quote" :aria-label="'Ver cotação ' + q.codigo" title="Ver cotação" @click="view(q)"><NavIcon name="eye" :size="14" /></button>
            </div></td>
            <td class="mono"><b>{{ q.codigo }}</b></td>
            <td class="num">{{ fmtD(q.data_solicitacao) }}</td>
            <td><StatusPill :status="q.status" dot cotacao /></td>
            <td><span class="pill sm" :class="q.operacao === 'CARREGAMENTO' ? 'st-tra' : 'st-dev'">{{ q.operacao }}</span></td>
            <td><b>{{ q.parte?.razao_social }}</b></td>
            <td>{{ q.vendedor?.nome }}</td>
            <td><b style="font-family:var(--disp);letter-spacing:.5px">{{ itemsSummary(q.itens) }}</b></td>
            <td class="num">{{ fmtKg(q.quantidade_total_kg) }}</td>
            <td><template v-if="carro(q)">{{ carro(q) }}</template><span v-else class="mut">—</span></td>
            <td><template v-if="q.origem?.cidade">{{ cityLabel(q.origem) }}</template><span v-else class="mut">—</span></td>
            <td><template v-if="q.destino?.cidade">{{ cityLabel(q.destino) }}</template><span v-else class="mut">—</span></td>
            <td class="num"><template v-if="q.distancia_km">{{ fmtN(dec(q.distancia_km)) }}</template><span v-else class="mut">—</span></td>
            <td class="num">{{ money(dec(q.valor_nf)) }}</td>
            <td class="num">{{ respondidas(q) }} / {{ consultadas(q) }}</td>
            <td><template v-if="vencedora(q)"><NavIcon name="truck" :size="14" /> {{ vencedora(q) }}</template><span v-else class="mut">—</span></td>
            <td><template v-if="q.observacao">{{ q.observacao }}</template><span v-else class="mut">—</span></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="17"><EmptyState list-key="quotes" icon="quotes" :message="vazio" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>
</template>
