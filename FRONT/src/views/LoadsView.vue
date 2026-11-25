<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { auth } from '../stores/session'
import {
  db, findLoadByCode, loadDomains, loadLoads, loadProducts, loadCarriers, loadSellers,
  saveLoad, setLoadStatus, deliverLoad, cancelLoad, embalagemLabel,
} from '../stores/db'
import { pushToast, confirmAction, openModal } from '../stores/ui'
import { STATUS_PROGRAMACAO, STATUS_FINAIS_PROG, statusLabel } from '../factories/statusFactory'
import { money, fmtN, fmtD, fmtDH, fmtKg, dec, parseNum, formatMoney, today, norm, cityLabel, freightValue, fmtRegistros } from '../utils/format'
import { blankItem, itemFromApi, itemsSummary } from '../utils/items'
import MoneyInput from '../components/MoneyInput.vue'
import CardPanel from '../components/CardPanel.vue'
import CityInput from '../components/CityInput.vue'
import StatusPill from '../components/StatusPill.vue'
import DeadlineTag from '../components/DeadlineTag.vue'
import EmptyState from '../components/EmptyState.vue'
import FormModal from '../components/FormModal.vue'
import MotivoModal from '../components/MotivoModal.vue'
import PartePicker from '../components/PartePicker.vue'
import ItemsEditor from '../components/ItemsEditor.vue'
import LoadActions from '../components/LoadActions.vue'
import { usePolling } from '../composables/usePolling'
import { useAbrirPorCodigo } from '../composables/useAbrirPorCodigo'
import { useIsMobile } from '../composables/useMedia'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session
const isMobile = useIsMobile()
const editId = ref(null)
const open = ref(false)
const saving = ref(false)
const cancelling = ref(null)
const filters = reactive({ text: '', status: '', carrier: '' })

const isCarrier = session.isCarrier
const canInsert = !isCarrier && session.can('PROGRAMACAO', 'inserir')
const canEdit = !isCarrier && session.can('PROGRAMACAO', 'alterar')
const canCancel = !isCarrier && session.can('PROGRAMACAO', 'cancelar')
const canDeliver = session.canAction('PROGRAMACAO.CONFIRMAR_ENTREGA')
const readOnlyProfile = !isCarrier && !canEdit && !canInsert
const canMove = isCarrier || session.can('PROGRAMACAO', 'alterar')

const blank = () => ({
  dataPedido: today(), operacao: 'CARREGAMENTO', pedidoVenda: '', nfeNumero: '', representanteId: '', parte: null,
  deposito: 'VIRTUAL', modalidade: 'CIF', transportadoraId: '', veiculo: '', origem: '', destino: '',
  valorTon: '', prazo: '', prazoColeta: '', coleta: '', entrega: '', observacao: '', itens: [blankItem()],
})
const form = reactive(blank())
const partyTipo = computed(() => (form.operacao === 'RECEBIMENTO' ? 'FORNECEDOR' : 'CLIENTE'))
const carrierOptions = computed(() => db.carriers.filter((c) => c.ativo || c.id === form.transportadoraId))
const kgTotal = computed(() => form.itens.reduce((s, i) => s + parseNum(i.qtd), 0))
const apiBase = ref(null)
const total = computed(() => {
  const ton = parseNum(form.valorTon)
  const kg = kgTotal.value
  const b = apiBase.value
  if (b && b.total != null && b.total !== '' && b.valorTon === form.valorTon && b.kg === kg) return money(dec(b.total))
  return ton && kg ? money(freightValue(ton, kg)) : ''
})

usePolling(() => loadLoads(undefined, { silent: true }), { interval: 15000, paused: () => open.value })

useAbrirPorCodigo({ rotulo: 'A carga', buscar: findLoadByCode, abrir: (l) => openModal('load', { id: l.id }), cancelado: (l) => l.status === 'CANCELADO' })

onMounted(() => {
  loadDomains()
  loadLoads()
  if (canInsert || canEdit) {
    loadProducts({ ativo: 1 })
    loadCarriers()
    loadSellers()
  }
})

const cityText = (c) => (c && c.cidade ? cityLabel(c) : '')
function reset() {
  open.value = false
  editId.value = null
  apiBase.value = null
  Object.assign(form, blank())
}
function startNew() {
  reset()
  open.value = true
}
function startEdit(l) {
  editId.value = l.id
  Object.assign(form, {
    dataPedido: l.data_pedido ?? '', operacao: l.operacao, pedidoVenda: l.pedido_venda ?? '', nfeNumero: l.nfe_numero ?? '',
    representanteId: l.representante?.id ?? '', parte: l.parte, deposito: l.deposito || 'VIRTUAL', modalidade: l.modalidade_frete || 'CIF',
    transportadoraId: l.transportadora?.id ?? '', veiculo: l.tipo_veiculo_codigo ?? '', origem: cityText(l.origem), destino: cityText(l.destino),
    valorTon: l.valor_tonelada ? formatMoney(dec(l.valor_tonelada)) : '', prazo: l.prazo_dias ? String(l.prazo_dias) : '', prazoColeta: l.prazo_coleta_dias != null ? String(l.prazo_coleta_dias) : '',
    coleta: l.data_coleta_prevista ?? '', entrega: l.data_entrega_prevista ?? '', observacao: l.observacao ?? '',
    itens: l.itens.length ? l.itens.map(itemFromApi) : [blankItem()],
  })
  apiBase.value = { valorTon: form.valorTon, kg: kgTotal.value, total: l.valor_total }
  open.value = true
}
async function save() {
  if (saving.value) return
  const editing = !!editId.value
  saving.value = true
  const r = await saveLoad({ ...form }, editId.value)
  saving.value = false
  if (!r.ok) return pushToast('err', r.error)
  pushToast('ok', editing ? 'Programação atualizada.' : `Carga **${r.data.codigo}** incluída na programação.`)
  reset()
}

function toTransit(l) {
  confirmAction(`Colocar a carga **${l.codigo}** em trânsito?`, async () => {
    const r = await setLoadStatus(l.id, 'EM_TRANSITO', today())
    r.ok ? pushToast('info', `Carga **${l.codigo}** em trânsito.`) : pushToast('err', r.error)
  }, { title: 'Confirmar saída da carga', label: 'Colocar em trânsito', icon: 'truck', tone: 'primary' })
}
function deliver(l) {
  confirmAction(`Confirmar a entrega da carga **${l.codigo}**? Fica registrado o dia e a hora de agora.`, async () => {
    const r = await deliverLoad(l.id)
    if (!r.ok) return pushToast('err', r.error)
    const onTime = (db.loads.find((x) => x.id === l.id) ?? r.data)?.entregue_no_prazo !== false
    pushToast(onTime ? 'ok' : 'warn',
      `Entrega registrada para **${l.codigo}**! ${onTime ? '**NO PRAZO**' : '**ATRASADO** — data diferente da prevista.'}`)
  }, { title: 'Confirmar entrega', label: 'Confirmar entrega', icon: 'check', tone: 'primary' })
}
function giveBack(l) {
  confirmAction(`Marcar a carga **${l.codigo}** como devolvida?`, async () => {
    const r = await setLoadStatus(l.id, 'DEVOLVIDO', today())
    r.ok ? pushToast('warn', `Carga **${l.codigo}** devolvida.`) : pushToast('err', r.error)
  }, { title: 'Confirmar devolução', label: 'Devolver', icon: 'undo' })
}
async function doCancel(motivo) {
  const l = cancelling.value
  const r = await cancelLoad(l.id, motivo)
  if (!r.ok) return pushToast('err', r.error)
  pushToast('warn', `Carga **${l.codigo}** cancelada.`)
  cancelling.value = null
}
const readOnly = () => pushToast('info', 'Perfil vendedor: modo somente leitura.')

const final = (l) => STATUS_FINAIS_PROG.includes(l.status)
const hasActions = (l) =>
  (canEdit && l.status !== 'CANCELADO') || (canCancel && !final(l)) ||
  (canMove && l.status === 'AGUARDANDO_CARREGAMENTO') || (canDeliver && l.status === 'EM_TRANSITO') || l.status === 'ENTREGUE'

const actionProps = (l) => ({ canEdit, canCancel, canMove, canDeliver, readOnlyProfile, hasActions: hasActions(l), final: final(l) })
const carrierFilterNames = computed(() => [...new Set(db.loads.map((l) => l.transportadora?.nome_fantasia).filter(Boolean))])
const visible = computed(() => {
  const t = norm(filters.text).trim()
  return db.loads.slice().sort((a, b) => String(b.codigo).localeCompare(String(a.codigo))).filter((l) => {
    const hay = norm([
      l.codigo, l.parte?.razao_social, l.pedido_venda, l.nfe_numero, l.representante?.nome, l.transportadora?.nome_fantasia,
      cityLabel(l.origem), cityLabel(l.destino), statusLabel(l.status), ...l.itens.map((i) => i.produto?.nome),
    ].join(' '))
    return (!t || hay.includes(t)) && (!filters.status || l.status === filters.status) &&
      (!filters.carrier || l.transportadora?.nome_fantasia === filters.carrier)
  })
})
const filtering = computed(() => !!(filters.text.trim() || filters.status || filters.carrier))
const itemsTitle = (l) => l.itens.map((i) => `${i.produto?.nome}${i.produto?.onu ? ` (ONU ${i.produto.onu}${i.produto.risco ? `, risco ${i.produto.risco}` : ''})` : ''} · ${embalagemLabel(i.embalagem_codigo)} · ${fmtKg(i.quantidade_kg)}`).join('\n')
</script>

<template>
  <div v-if="isCarrier" class="note-strip"><NavIcon name="truck" :size="16" /> Exibindo somente as cargas programadas para <b>&nbsp;{{ session.transportadora?.nome_fantasia || session.name }}</b>. Use os botões na coluna de ações para marcar <b>&nbsp;em trânsito&nbsp;</b> e <b>&nbsp;entregue</b>.</div>
  <div v-else-if="readOnlyProfile" class="note-strip"><NavIcon name="eye" :size="16" /> Você está visualizando as cargas programadas sob sua representação (modo somente leitura).</div>

  <CardPanel title="Cargas programadas" :count="db.loading.loads && !db.loads.length ? 'Carregando…' : db.loadError.loads && !db.loads.length ? 'Erro ao carregar' : filtering ? `${visible.length} de ${db.loads.length} registros` : fmtRegistros(db.loads.length)" flush>
    <template v-if="canInsert" #actions><button class="btn btn-primary btn-sm" data-act="new-load" @click="startNew"><NavIcon name="plus" :size="14" /> Nova programação</button></template>
    <div class="filt">
      <input id="flt-txt" v-model="filters.text" class="inp" type="search" aria-label="Buscar cargas" placeholder="Buscar por cliente, produto, pedido…" />
      <select id="flt-status" v-model="filters.status" class="inp" aria-label="Filtrar por status"><option value="">Todos os status</option><option v-for="s in STATUS_PROGRAMACAO" :key="s" :value="s">{{ statusLabel(s) }}</option></select>
      <select v-if="!isCarrier" id="flt-transp" v-model="filters.carrier" class="inp" aria-label="Filtrar por transportadora"><option value="">Todas as transportadoras</option><option v-for="t in carrierFilterNames" :key="t">{{ t }}</option></select>
    </div>
    <div v-if="isMobile" class="load-cards" data-load-cards>
      <article v-for="l in visible" :key="l.id" class="load-card" data-load-card>
        <div class="lc-h">
          <b class="mono">{{ l.codigo }}</b><StatusPill :status="l.status" compact />
          <span class="pill sm" :class="l.modalidade_frete === 'CIF' ? 'st-ent' : 'st-cot'">{{ l.modalidade_frete }}</span>
          <DeadlineTag :data-entrega-realizada="l.data_entrega_realizada" :entregue-no-prazo="l.entregue_no_prazo" :status="l.status" />
        </div>
        <div><b>{{ l.parte?.razao_social }}</b> <span class="mut">· {{ itemsSummary(l.itens) }} · {{ fmtN(dec(l.quantidade_total_kg)) }} kg</span></div>
        <div class="lc-route">{{ cityLabel(l.origem) }} → {{ cityLabel(l.destino) }}</div>
        <div class="lc-meta">
          <div><span>Transportadora</span><template v-if="l.transportadora"><NavIcon name="truck" :size="14" /> {{ l.transportadora.nome_fantasia }}</template><template v-else>a definir</template></div>
          <div><span>Valor total</span><b v-if="l.valor_total != null && l.valor_total !== ''">{{ money(dec(l.valor_total)) }}</b><template v-else>—</template></div>
          <div><span>Val./ton</span><template v-if="l.valor_tonelada">{{ money(dec(l.valor_tonelada)) }}</template><template v-else>—</template><small v-if="l.valor_proposto" class="mut" data-proposto> (proposto {{ money(dec(l.valor_proposto)) }})</small></div>
          <div><span>Pedido</span>{{ fmtD(l.data_pedido) }}</div>
          <div><span>Coleta prev.</span>{{ fmtD(l.data_coleta_prevista) }}</div>
          <div><span>Entrega prev.</span>{{ fmtD(l.data_entrega_prevista) }}</div>
          <div v-if="l.data_entrega_realizada"><span>Entregue</span>{{ l.entregue_em ? fmtDH(l.entregue_em) : fmtD(l.data_entrega_realizada) }}</div>
        </div>
        <div v-if="hasActions(l) || readOnlyProfile" class="lc-acts">
          <LoadActions :load="l" v-bind="actionProps(l)" @edit="startEdit" @transit="toTransit" @deliver="deliver" @giveback="giveBack" @cancel="cancelling = $event" @readonly="readOnly" />
        </div>
      </article>
      <EmptyState list-key="loads" v-if="!visible.length" icon="loads" :message="db.loading.loads ? 'Carregando…' : filtering ? 'Nenhuma carga com esse filtro.' : 'Nenhuma carga programada.'" />
    </div>
    <div v-else class="tbl-wrap" role="region" tabindex="0" aria-label="Tabela de cargas programadas">
      <table id="loadTable" class="tbl">
        <thead>
          <tr>
            <th class="act">Ações</th>
            <th>Data pedido</th><th>Operação</th><th>Carga / Pedido</th><th>Status</th><th>NFe</th><th>Repr.</th>
            <th>Cliente / Fornecedor</th><th>Produtos</th><th>Qtd (kg)</th><th>Depósito</th>
            <th>Transportadora</th><th>Frete</th><th>Origem</th><th>Destino</th><th>Val./ton</th><th>Valor total</th>
            <th>Coleta</th><th>Entrega</th><th>Entregue</th><th>Prazo</th>
          </tr>
        </thead>
        <tbody id="loadRows">
          <tr v-for="l in visible" :key="l.id">
            <td class="act"><div class="wrap">
              <LoadActions :load="l" v-bind="actionProps(l)" @edit="startEdit" @transit="toTransit" @deliver="deliver" @giveback="giveBack" @cancel="cancelling = $event" @readonly="readOnly" />
            </div></td>
            <td class="num">{{ fmtD(l.data_pedido) }}</td>
            <td><span class="pill sm" :class="l.operacao === 'CARREGAMENTO' ? 'st-tra' : 'st-dev'">{{ l.operacao }}</span></td>
            <td class="mono"><b>{{ l.codigo }}</b><template v-if="l.pedido_venda"><br /><span class="mut">{{ l.pedido_venda }}</span></template></td>
            <td><StatusPill :status="l.status" dot /></td>
            <td class="num"><template v-if="l.nfe_numero">{{ l.nfe_numero }}</template><span v-else class="mut">—</span></td>
            <td>{{ l.representante?.nome }}</td>
            <td><b>{{ l.parte?.razao_social }}</b></td>
            <td :title="itemsTitle(l)"><b style="font-family:var(--disp);letter-spacing:.5px">{{ itemsSummary(l.itens) }}</b></td>
            <td class="num">{{ fmtN(dec(l.quantidade_total_kg)) }}</td>
            <td>{{ l.deposito }}</td>
            <td><template v-if="l.transportadora"><NavIcon name="truck" :size="14" /> {{ l.transportadora.nome_fantasia }}</template><span v-else class="mut">a definir</span></td>
            <td><span class="pill sm" :class="l.modalidade_frete === 'CIF' ? 'st-ent' : 'st-cot'">{{ l.modalidade_frete }}</span></td>
            <td><template v-if="l.origem?.cidade">{{ cityLabel(l.origem) }}</template><span v-else class="mut">—</span></td>
            <td><template v-if="l.destino?.cidade">{{ cityLabel(l.destino) }}</template><span v-else class="mut">—</span></td>
            <td class="num"><template v-if="l.valor_tonelada">{{ money(dec(l.valor_tonelada)) }}</template><span v-else class="mut">—</span><small v-if="l.valor_proposto" class="mut" data-proposto style="display:block">proposto {{ money(dec(l.valor_proposto)) }}</small></td>
            <td class="num"><b v-if="l.valor_total != null && l.valor_total !== ''">{{ money(dec(l.valor_total)) }}</b><span v-else class="mut">—</span></td>
            <td class="num">{{ fmtD(l.data_coleta_prevista) }}</td>
            <td class="num">{{ fmtD(l.data_entrega_prevista) }}</td>
            <td class="num">{{ l.entregue_em ? fmtDH(l.entregue_em) : fmtD(l.data_entrega_realizada) }}</td>
            <td><DeadlineTag :data-entrega-realizada="l.data_entrega_realizada" :entregue-no-prazo="l.entregue_no_prazo" :status="l.status" /></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="21"><EmptyState list-key="loads" icon="loads" :message="db.loading.loads ? 'Carregando…' : filtering ? 'Nenhuma carga com esse filtro.' : 'Nenhuma carga programada.'" /></td></tr>
        </tbody>
      </table>
    </div>
  </CardPanel>

  <FormModal v-if="open" :title="editId ? 'Editar carga ' + (db.loads.find((x) => x.id === editId)?.codigo || '') : 'Nova programação de carga'" sub="acesso do administrador" size="xl" @close="reset">
    <div class="fgrid">
      <div class="field c2"><label for="lp-data">Data do pedido</label><input id="lp-data" v-model="form.dataPedido" type="date" class="inp" /></div>
      <div class="field c3"><label for="lp-operacao">Operação</label><select id="lp-operacao" v-model="form.operacao" class="inp" @change="form.parte = null"><option value="CARREGAMENTO">CARREGAMENTO</option><option value="RECEBIMENTO">RECEBIMENTO</option></select></div>
      <div class="field c3"><label for="lp-pedido">Pedido de venda<template v-if="!editId"> <i>*</i></template></label><input id="lp-pedido" v-model="form.pedidoVenda" class="inp" maxlength="30" placeholder="PV-0000" /></div>
      <div class="field c2"><label for="lp-nfe">NFe (nº)</label><input id="lp-nfe" v-model="form.nfeNumero" class="inp" placeholder="nº da nota" /></div>
      <div class="field c2"><label for="lp-rep">Representante</label><select id="lp-rep" v-model="form.representanteId" class="inp"><option value="">—</option><option v-for="r in db.sellers" :key="r.id" :value="r.id">{{ r.nome }}</option></select></div>
      <div class="field c6"><label for="lp-parte">{{ partyTipo === 'CLIENTE' ? 'Cliente' : 'Fornecedor' }} <i>*</i></label><PartePicker id="lp-parte" v-model="form.parte" :tipo="partyTipo" /></div>
      <div class="field c3"><label for="lp-transp">Transportadora</label>
        <select id="lp-transp" v-model="form.transportadoraId" class="inp"><option value="">— selecionar —</option><option v-for="c in carrierOptions" :key="c.id" :value="c.id">{{ c.nome_fantasia }}</option></select></div>
      <div class="field c3"><label for="lp-veiculo">Tipo de carro</label>
        <select id="lp-veiculo" v-model="form.veiculo" class="inp"><option value="">—</option><option v-for="v in db.domains.tipos_veiculo" :key="v.codigo" :value="v.codigo">{{ v.descricao }}</option></select></div>
      <div class="field c12"><label>Itens <i>*</i></label><ItemsEditor v-model="form.itens" id-prefix="lp" :show-flags="false" /></div>
      <div class="field c2"><label for="lp-deposito">Depósito</label><select id="lp-deposito" v-model="form.deposito" class="inp"><option value="VIRTUAL">VIRTUAL</option><option value="FISICO">FÍSICO</option></select></div>
      <div class="field c2"><label for="lp-frete">Frete</label><select id="lp-frete" v-model="form.modalidade" class="inp"><option>CIF</option><option>FOB</option></select></div>
      <div class="field c4"><label for="lp-cepO">Cidade de origem</label><CityInput id="lp-cepO" v-model="form.origem" placeholder="ex.: DUMONT - SP" /></div>
      <div class="field c4"><label for="lp-cepD">Cidade de destino</label><CityInput id="lp-cepD" v-model="form.destino" placeholder="ex.: DUMONT - SP" /></div>
      <div class="field c3"><label for="lp-vton">Valor p/ ton (R$)</label><MoneyInput id="lp-vton" v-model="form.valorTon" placeholder="ex.: 250,00" /></div>
      <div class="field c3"><label for="lp-vtotal">Valor total</label><input id="lp-vtotal" class="inp" readonly :value="total" style="background:#F4FAF6;font-family:var(--mono);font-weight:600" /></div>
      <div class="field c2"><label for="lp-prazo-coleta">Prazo de coleta (dias)</label><input id="lp-prazo-coleta" v-model="form.prazoColeta" type="number" min="0" class="inp" /></div>
      <div class="field c2"><label for="lp-prazo">Prazo de entrega (dias)</label><input id="lp-prazo" v-model="form.prazo" type="number" min="1" class="inp" /></div>
      <div class="field c2"><label for="lp-dcoleta">Coleta prevista</label><input id="lp-dcoleta" v-model="form.coleta" type="date" class="inp" /></div>
      <div class="field c2"><label for="lp-dentrega">Entrega prevista</label><input id="lp-dentrega" v-model="form.entrega" type="date" class="inp" /></div>
      <div class="field c12"><label for="lp-obs">Observação</label><input id="lp-obs" v-model="form.observacao" class="inp" placeholder="opcional" /></div>
      <div class="field c12 mut" style="font-size:12px"><b>VALOR TOTAL</b> = (Valor p/ ton ÷ 1.000) × Σ QTD (kg) dos itens — a API recalcula ao salvar. As datas realizadas e o <b>PRAZO</b> vêm das ações <b>em trânsito</b> e <b>entregue</b> na lista.</div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="reset">Cancelar</button>
      <button class="btn btn-primary" data-act="save-load" :disabled="saving" :aria-busy="saving" @click="save"><template v-if="editId">Salvar alterações</template><template v-else><NavIcon name="plus" :size="14" /> Incluir na programação</template></button>
    </template>
  </FormModal>

  <MotivoModal v-if="cancelling" :title="'Cancelar carga ' + cancelling.codigo" sub="a carga sai da programação ativa" @close="cancelling = null" @confirm="doCancel" />
</template>
