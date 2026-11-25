<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { auth } from '../stores/session'
import { db, embalagemLabel, findLoadByCode, loadDashboard, loadDomains, loadQuotes } from '../stores/db'
import { openModal, pushToast } from '../stores/ui'
import { STATUS_COLORS, statusLabel } from '../factories/statusFactory'
import { money, dec, fmtN, fmtD, fmtRegistros, fmtKg } from '../utils/format'
import { usePolling } from '../composables/usePolling'
import KpiCard from '../components/KpiCard.vue'
import CardPanel from '../components/CardPanel.vue'
import QuoteCard from '../components/QuoteCard.vue'
import StatusPill from '../components/StatusPill.vue'
import EmptyState from '../components/EmptyState.vue'
import ListFilters from '../components/ListFilters.vue'
import PieChart from '../components/PieChart.vue'
import SplitBar from '../components/SplitBar.vue'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session
const isCarrier = session.isCarrier

const STATUS_PIZZA = ['ENTREGUE', 'AGUARDANDO_CARREGAMENTO', 'EM_COTACAO', 'EM_TRANSITO', 'DEVOLVIDO']
const STATUS_FILTRO_TRANSP = ['AGUARDANDO_CARREGAMENTO', 'EM_TRANSITO', 'ENTREGUE', 'DEVOLVIDO']
const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro']
const PASSO = 25

const f = reactive({ ano: '', mes: '', produto_id: '', embalagem: '', frete: '', transportadora_id: '', origem: '', destino: '', status: '', busca: '' })
const limit = ref(PASSO)

const query = () => {
  const q = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
  return { ...q, por_pagina: limit.value }
}
let seq = 0
const reload = async (silent = false) => {
  const mine = ++seq
  const r = await loadDashboard(query(), { silent })
  return mine === seq ? r : null
}

onMounted(() => {
  loadDomains()
  loadQuotes()
  reload()
})
usePolling(() => Promise.all([reload(true), loadQuotes(undefined, { silent: true })]))

watch(() => [f.ano, f.mes, f.produto_id, f.embalagem, f.frete, f.transportadora_id, f.origem, f.destino, f.status, limit.value], () => reload())
let timer
watch(() => f.busca, () => { clearTimeout(timer); timer = setTimeout(reload, 300) })

const s = computed(() => db.dashboard)
const k = computed(() => s.value?.kpis || {})
const op = computed(() => s.value?.opcoes || {})
const tabela = computed(() => s.value?.cargas || { total: 0, itens: [] })

const nFiltros = computed(() => Object.entries(f).filter(([key, v]) => v !== '' && key !== 'busca').length)
const filtrando = computed(() => nFiltros.value > 0 || f.busca !== '')
function limpar() {
  Object.keys(f).forEach((key) => { f[key] = '' })
  limit.value = PASSO
  pushToast('info', 'Filtros **limpos**.')
}
const alterna = (key, v) => { f[key] = f[key] === v ? '' : v; limit.value = PASSO }

const opts = (arr, label = (x) => x) => (arr || []).map((x) => ({ value: String(x.id ?? x), label: label(x.nome ?? x) }))
const selects = computed(() => {
  const ano = { key: 'ano', label: 'Todos os anos', options: opts(op.value.anos) }
  const mes = { key: 'mes', label: 'Todos os meses', options: MESES.map((m, i) => ({ value: String(i + 1), label: m })) }
  const frete = { key: 'frete', label: 'CIF e FOB', options: opts(['CIF', 'FOB']) }
  const prod = { key: 'produto_id', label: 'Todos os produtos', options: opts(op.value.produtos) }
  const emb = { key: 'embalagem', label: 'Todas as embalagens', options: opts(op.value.embalagens, embalagemLabel) }
  const orig = { key: 'origem', label: 'Todas as origens', options: opts(op.value.origens) }
  const dest = { key: 'destino', label: 'Todos os destinos', options: opts(op.value.destinos) }
  if (isCarrier) return [ano, mes, frete, prod, emb, orig, dest, { key: 'status', label: 'Todos os status', options: STATUS_FILTRO_TRANSP.map((x) => ({ value: x, label: statusLabel(x) })) }]
  return [ano, mes, prod, emb, frete, { key: 'transportadora_id', label: 'Todas as transportadoras', options: opts(op.value.transportadoras) }, orig, dest]
})
watch(() => Object.values(f).join('|'), () => { limit.value = PASSO })

const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0)
const ton = (kg) => `${fmtN(Math.round(dec(kg) / 1000))} t`
const cifPct = computed(() => pct(k.value.cif, k.value.cif + k.value.fob))
const pizzaFrete = computed(() => [{ val: k.value.cif || 0, color: '#00693C' }, { val: k.value.fob || 0, color: '#9DB4AA' }])

const statusLinhas = computed(() => STATUS_PIZZA.map((st) => ({ st, n: s.value?.por_status?.[st] || 0 })))
const totalStatus = computed(() => statusLinhas.value.reduce((a, l) => a + l.n, 0))
const pizzaStatus = computed(() => statusLinhas.value.map((l) => ({ val: l.n, color: STATUS_COLORS[l.st] })))

const rankT = computed(() => (s.value?.ranking_transportadoras || []).slice(0, 5))
const maxT = computed(() => Math.max(...rankT.value.map((r) => dec(r.total)), 1))
const rankD = computed(() => s.value?.ranking_destinos || [])
const maxD = computed(() => Math.max(...rankD.value.map((r) => dec(r.total) || r.cargas), 1))

const cotacoes = computed(() => db.quotes
  .filter((q) => (isCarrier
    ? q.status === 'EM_NEGOCIACAO' && q.negociacao?.status === 'ABERTA' && (q.negociacao.respostas || []).some((r) => r.status === 'AGUARDANDO' && r.origem !== 'REFERENCIADA')
    : q.status === 'ABERTA'))
  .slice().sort((a, b) => String(a.codigo).localeCompare(String(b.codigo))))

const embs = (l) => String(l.embalagem || '').split(', ').filter(Boolean).map(embalagemLabel).join(', ')
const deposito = (d) => (d === 'FISICO' ? 'FÍSICO' : d)
async function ver(l) {
  const c = await findLoadByCode(l.codigo)
  if (!c) return pushToast('warn', `A carga **${l.codigo}** não foi encontrada ou você não tem acesso a ela.`)
  openModal('load', { id: c.id })
}
</script>

<template>
  <template v-if="s">
    <CardPanel v-if="!isCarrier" title="Filtros" :count="nFiltros ? `${nFiltros} ${nFiltros > 1 ? 'filtros ativos' : 'filtro ativo'}` : 'todos'" flush>
      <template #actions><button class="btn btn-line btn-sm" type="button" data-act="limpar" @click="limpar">Limpar filtros</button></template>
      <ListFilters :f="f" :selects="selects" :search="false" />
    </CardPanel>

    <div v-if="!isCarrier" class="kpis">
      <KpiCard :key="'f' + k.total_frete" label="Total frete" :text="money(dec(k.total_frete))" :sub="dec(k.ticket_medio) ? `ticket médio ${money(dec(k.ticket_medio))}` : 'sem frete CIF no filtro'" color="var(--red)" />
      <KpiCard :key="'c' + k.cargas" label="Carregamentos" :value="k.cargas" :sub="`${k.aguardando_carregamento} aguardando carregamento`" color="var(--green)" />
      <KpiCard :key="'p' + k.pct_no_prazo" label="No prazo" :value="k.pct_no_prazo" suffix="%" :sub="`${k.no_prazo} no prazo · ${k.atrasadas} atrasadas`" color="var(--green-m)" />
      <KpiCard :key="'a' + k.atrasadas" label="Atrasados" :value="k.atrasadas" sub="cargas fora do prazo" color="var(--red)" />
      <KpiCard :key="'v' + k.volume_kg" label="Volume" :text="ton(k.volume_kg)" :sub="fmtKg(k.volume_kg)" color="var(--green-d)" />
    </div>
    <div v-else class="kpis six">
      <KpiCard :key="'f' + k.total_frete" label="Frete total" :text="money(dec(k.total_frete))" :sub="dec(k.ticket_medio) ? `ticket médio ${money(dec(k.ticket_medio))}` : '—'" color="var(--red)" />
      <KpiCard :key="'c' + k.cargas" label="Minhas cargas" :value="k.cargas" :sub="`${ton(k.volume_kg)} transportadas`" color="var(--green)" />
      <KpiCard :key="'g' + k.aguardando_carregamento" label="Agrd. carregamento" :value="k.aguardando_carregamento" sub="próximas coletas" color="var(--green-l)" />
      <KpiCard :key="'t' + k.em_transito" label="Em trânsito" :value="k.em_transito" sub="na estrada agora" color="var(--green-m)" />
      <KpiCard :key="'e' + k.entregues" label="Entregues" :value="k.entregues" :sub="`${k.devolvidas} devolvida(s)`" color="var(--green-d)" />
      <div class="kpi" style="--kc:var(--green)">
        <div class="kl">Frete CIF / FOB</div>
        <div class="pk">
          <PieChart class="sm" :parts="pizzaFrete" label="CIF e FOB" />
          <div class="leg"><div><i style="background:#00693C"></i>CIF<b>{{ k.cif }}</b></div><div><i style="background:#9DB4AA"></i>FOB<b>{{ k.fob }}</b></div></div>
        </div>
      </div>
    </div>

    <div v-if="!isCarrier" class="three">
      <CardPanel title="Carregamentos por status" :count="fmtRegistros(totalStatus)">
        <div class="donut-w">
          <PieChart :parts="pizzaStatus" label="Carregamentos por status" />
          <div class="leg click" style="flex:1;min-width:190px">
            <div v-for="l in statusLinhas" :key="l.st" :data-leg="l.st" :class="{ sel: f.status === l.st }" role="button" tabindex="0" title="Clique para filtrar" @click="alterna('status', l.st)" @keydown.enter="alterna('status', l.st)">
              <i :style="{ background: STATUS_COLORS[l.st] }"></i><span>{{ statusLabel(l.st) }}</span><b>{{ fmtN(l.n) }}</b>
            </div>
          </div>
        </div>
      </CardPanel>
      <CardPanel title="Pontualidade">
        <div class="mini" data-pontualidade>
          <div class="big">{{ k.pct_no_prazo }}%</div><div class="sub">no prazo</div>
          <SplitBar style="margin:14px 0 8px" :parts="[{ val: k.no_prazo, color: '#00693C' }, { val: k.atrasadas, color: '#D6001C' }]" />
          <div class="mono">{{ k.no_prazo }} no prazo · {{ k.atrasadas }} atrasadas</div>
          <hr />
          <SplitBar :parts="[{ val: k.cif, color: '#00693C' }, { val: k.fob, color: '#9DB4AA' }]" />
          <div class="mono split-t"><span>CIF {{ cifPct }}% · {{ fmtN(k.cif) }}</span><span>{{ fmtN(k.fob) }} · {{ k.cargas ? 100 - cifPct : 0 }}% FOB</span></div>
        </div>
      </CardPanel>
      <CardPanel title="Ranking transportadoras" :count="rankT.length >= 5 ? 'top 5' : `${rankT.length} transp.`">
        <div class="rk-list">
          <div v-for="(r, i) in rankT" :key="r.id" data-rank-transp class="rk-item clk" :class="{ sel: f.transportadora_id === r.id }" role="button" tabindex="0" title="Clique para filtrar" @click="alterna('transportadora_id', r.id)" @keydown.enter="alterna('transportadora_id', r.id)">
            <div class="rk-top"><span class="rk-pos">{{ i + 1 }}</span><b class="rk-n" :title="r.nome">{{ r.nome }}</b><span class="rk-val">{{ money(dec(r.total)) }}</span></div>
            <div class="rk-bar"><div class="bar-mini"><i :style="{ width: (dec(r.total) / maxT * 100).toFixed(0) + '%' }"></i></div><span class="rk-sub">{{ fmtN(r.pedidos) }} {{ r.pedidos === 1 ? 'pedido' : 'pedidos' }}</span></div>
          </div>
          <div v-if="!rankT.length" class="empty"><NavIcon name="inbox" :size="30" />Nenhum frete CIF no filtro.</div>
        </div>
      </CardPanel>
    </div>

    <CardPanel :title="isCarrier ? 'Cotações aguardando resposta' : 'Aguardando encaminhamento'" :count="`${cotacoes.length} ${isCarrier ? 'aguardando resposta' : 'a encaminhar'}`">
      <div class="qrow" data-cotacoes>
        <QuoteCard v-for="(q, i) in cotacoes" :key="q.id" :quote="q" :index="i" />
        <EmptyState v-if="!cotacoes.length" list-key="quotes" icon="quotes" :message="db.loading.quotes ? 'Carregando…' : isCarrier ? 'Nenhuma cotação por aqui ainda.' : 'Nenhuma cotação aguardando encaminhamento.'" />
      </div>
    </CardPanel>

    <CardPanel v-if="isCarrier" title="Ranking de cidades" :count="`${rankD.length} ${rankD.length === 1 ? 'destino' : 'destinos'}${rankD.length >= 8 ? ' · top 8' : ''}`">
      <template v-if="rankD.length">
        <div v-for="(r, i) in rankD" :key="r.destino" class="bar-row rk clk" data-rank-dest :class="{ sel: f.destino === r.destino }" role="button" tabindex="0" title="Clique para filtrar" @click="alterna('destino', r.destino)" @keydown.enter="alterna('destino', r.destino)">
          <span class="bl">{{ i + 1 }}. {{ r.destino }}</span>
          <span class="tr"><span class="fl" :style="{ width: ((dec(r.total) || r.cargas) / maxD * 100) + '%', background: '#00693C' }"></span></span>
          <span class="bn">{{ r.cargas }} {{ r.cargas > 1 ? 'cargas' : 'carga' }} · {{ dec(r.total) ? money(dec(r.total)) : '—' }}</span>
        </div>
      </template>
      <div v-else class="empty"><NavIcon name="inbox" :size="30" />Nenhum destino no filtro.</div>
    </CardPanel>

    <CardPanel :title="isCarrier ? 'Minhas cargas' : 'Cargas programadas'" :count="fmtRegistros(tabela.total)" flush>
      <template #actions>
        <span v-if="f.status" class="chip" data-chip-status>{{ statusLabel(f.status) }}<button type="button" aria-label="Remover filtro de status" @click="f.status = ''">×</button></span>
        <button v-if="isCarrier" class="btn btn-line btn-sm" type="button" data-act="limpar" @click="limpar">Limpar filtros</button>
      </template>
      <ListFilters v-if="isCarrier" :f="f" :selects="selects" :search="false" />
      <div v-else class="filt"><input v-model="f.busca" class="inp" type="search" aria-label="Buscar cargas" placeholder="Buscar por pedido, cliente, vendedor…" /></div>
      <div class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de cargas">
        <table class="tbl">
          <thead>
            <tr>
              <th class="act">Ver</th><th>Data carreg.</th><th>Depósito</th><th>Pedido</th><th v-if="!isCarrier">Vendedor</th><th>Produto</th><th class="r">Qtd (kg)</th><th>Embalagem</th><th>Cliente</th><th>Origem</th><th>Destino</th>
              <th v-if="!isCarrier">Frete</th><th class="r">Valor (T)</th><th class="r">Total</th><th v-if="!isCarrier">Transportadora</th><th>Status</th><th>Prazo</th>
            </tr>
          </thead>
          <tbody id="dashRows">
            <tr v-for="l in tabela.itens" :key="l.id">
              <td class="act"><button class="ibtn" type="button" data-act="ver-carga" :aria-label="`Visualizar carga ${l.codigo}`" title="Visualizar" @click="ver(l)"><NavIcon name="eye" :size="16" /></button></td>
              <td class="num">{{ fmtD(l.data) }}</td><td>{{ deposito(l.deposito) }}</td>
              <td class="num"><b>{{ l.codigo }}</b><template v-if="l.pedido"><br /><span class="mut">{{ l.pedido }}</span></template></td>
              <td v-if="!isCarrier">{{ l.vendedor }}</td><td>{{ l.produto }}</td><td class="r num">{{ fmtN(dec(l.kg)) }}</td><td>{{ embs(l) }}</td>
              <td>{{ l.cliente }}</td><td>{{ l.origem }}</td><td>{{ l.destino }}</td>
              <td v-if="!isCarrier"><span :class="l.frete.toLowerCase()">{{ l.frete }}</span></td>
              <td class="r num"><template v-if="dec(l.valor_tonelada)">{{ money(dec(l.valor_tonelada)) }}</template><span v-else class="mut">—</span></td>
              <td class="r num"><template v-if="dec(l.valor_total)">{{ money(dec(l.valor_total)) }}</template><span v-else class="mut">—</span></td>
              <td v-if="!isCarrier"><template v-if="l.transportadora">{{ l.transportadora }}</template><span v-else class="mut">—</span></td>
              <td><StatusPill :status="l.status" dot /></td>
              <td><span v-if="l.prazo === 'ok'" class="prz prz-ok">NO PRAZO</span><span v-else-if="l.prazo === 'bad'" class="prz prz-bad">ATRASADO</span><span v-else class="prz prz-no">—</span></td>
            </tr>
            <tr v-if="!tabela.itens.length"><td :colspan="isCarrier ? 13 : 17"><div class="empty"><NavIcon name="inbox" :size="30" />{{ filtrando ? 'Nenhuma carga com esse filtro.' : 'Nenhuma carga por aqui.' }}</div></td></tr>
          </tbody>
          <tfoot v-if="tabela.itens.length">
            <tr id="dashFoot">
              <td class="act"></td><td :colspan="isCarrier ? 3 : 4">TOTAL · {{ fmtN(tabela.total) }} {{ tabela.total === 1 ? 'carga' : 'cargas' }}</td><td v-if="!isCarrier"></td><td></td>
              <td class="r num">{{ fmtN(dec(tabela.total_kg)) }}</td><td :colspan="isCarrier ? 4 : 5"></td><td class="r num">{{ money(dec(tabela.total_frete)) }}</td><td :colspan="isCarrier ? 2 : 3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="tfoot-bar">
        <template v-if="tabela.total > tabela.itens.length"><span class="mono">mostrando {{ tabela.itens.length }} de {{ tabela.total }}</span><button class="btn btn-line btn-sm" type="button" data-act="ver-mais" @click="limit += PASSO">Ver mais ▾</button></template>
        <span v-else-if="tabela.total" class="mono">{{ tabela.total }} de {{ tabela.total }}</span>
      </div>
    </CardPanel>
  </template>
  <div v-else class="empty"><div class="big"><NavIcon name="chart" :size="36" /></div>{{ db.loading.dashboard ? 'Carregando painel…' : 'Painel indisponível.' }}</div>
</template>
