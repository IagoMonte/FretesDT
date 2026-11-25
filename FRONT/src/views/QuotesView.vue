<script setup>
import { computed, onMounted, ref } from 'vue'
import { auth } from '../stores/session'
import { db, findQuoteByCode, loadDomains, loadProducts, loadQuotes } from '../stores/db'
import { STATUS_COTACAO, STATUS_COTACAO_TRANSPORTADORA, statusLabel } from '../factories/statusFactory'
import { cityLabel, norm } from '../utils/format'
import { openModal } from '../stores/ui'
import { usePolling } from '../composables/usePolling'
import { useAbrirPorCodigo } from '../composables/useAbrirPorCodigo'
import { useListFilter } from '../composables/useListFilter'
import ListFilters from '../components/ListFilters.vue'
import CardPanel from '../components/CardPanel.vue'
import QuoteCard from '../components/QuoteCard.vue'
import QuoteFormModal from '../components/QuoteFormModal.vue'
import EmptyState from '../components/EmptyState.vue'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session

const { f: flt, visible, filtrando, contagem } = useListFilter(() => db.quotes, {
  texto: (q) => [q.codigo, q.parte?.razao_social, q.parte?.nome_fantasia, cityLabel(q.destino), q.vendedor?.nome].join(' '),
  filtros: {
    status: (q, v) => q.status === v,
    cliente: (q, v) => q.parte?.id === v,
    destino: (q, v) => cityLabel(q.destino) === v,
  },
})
const unicos = (arr) => [...new Map(arr.filter((o) => o.value).map((o) => [o.value, o])).values()].sort((a, b) => norm(a.label).localeCompare(norm(b.label)))
const selects = computed(() => [
  { key: 'status', label: 'Todos os status', options: (session.isCarrier ? STATUS_COTACAO_TRANSPORTADORA : STATUS_COTACAO).map((st) => ({ value: st, label: statusLabel(st, { carrier: session.isCarrier }) })) },
  { key: 'cliente', label: 'Todos os clientes', options: unicos(db.quotes.map((q) => ({ value: q.parte?.id, label: q.parte?.nome_fantasia || q.parte?.razao_social }))) },
  { key: 'destino', label: 'Todos os destinos', options: unicos(db.quotes.map((q) => ({ value: cityLabel(q.destino), label: cityLabel(q.destino) }))) },
])

const groups = computed(() => {
  const sorted = visible.value.slice().sort((a, b) => String(b.codigo).localeCompare(String(a.codigo)))
  return (session.isCarrier ? STATUS_COTACAO_TRANSPORTADORA : STATUS_COTACAO)
    .map((st) => ({ status: st, items: sorted.filter((q) => q.status === st) }))
    .filter((g) => g.items.length)
})

const open = ref(false)

usePolling(() => loadQuotes(undefined, { silent: true }), { interval: 15000 })

useAbrirPorCodigo({ rotulo: 'A cotação', buscar: findQuoteByCode, abrir: (q) => openModal('quote', { id: q.id }), cancelado: (q) => q.status === 'CANCELADA' })

onMounted(() => {
  loadDomains()
  loadQuotes()
  if (session.can('COTACAO', 'inserir')) loadProducts({ ativo: 1 })
})

</script>

<template>
  <div v-if="session.isCarrier" class="note-strip"><NavIcon name="info" :size="16" /> Cotações encaminhadas para <b>&nbsp;{{ session.transportadora?.nome_fantasia || session.name }}</b>. Registre sua resposta com o valor do frete ou declare que não atende.</div>

  <CardPanel :title="session.isCarrier ? 'Cotações recebidas' : 'Cotações geradas'" :count="db.loading.quotes && !db.quotes.length ? 'Carregando…' : db.loadError.quotes && !db.quotes.length ? 'Erro ao carregar' : contagem(db.quotes.length, db.quotes.length === 1 ? 'registro' : 'registros')" flush>
    <template v-if="session.can('COTACAO', 'inserir')" #actions><button class="btn btn-primary btn-sm" data-act="new-quote" @click="open = true"><NavIcon name="plus" :size="14" /> Nova cotação</button></template>
    <ListFilters :f="flt" :selects="selects" label="Buscar cotações" placeholder="Buscar por código, cliente, destino…" />
    <div class="card-b">
    <template v-if="groups.length">
      <section v-for="g in groups" :key="g.status" :data-group="g.status">
        <h3 class="grp-h">{{ statusLabel(g.status, { carrier: session.isCarrier }) }} <span class="cnt">{{ g.items.length }}</span></h3>
        <div class="qgrid">
          <QuoteCard v-for="(q, i) in g.items" :key="q.id" :quote="q" :index="i" />
        </div>
      </section>
    </template>
    <EmptyState list-key="quotes" v-else icon="inbox" :message="db.loading.quotes ? 'Carregando…' : filtrando ? 'Nenhuma cotação com esse filtro.' : 'Nenhuma cotação por aqui ainda.'" />
    </div>
  </CardPanel>

  <QuoteFormModal v-if="open" @close="open = false" />
</template>
