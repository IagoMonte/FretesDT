<script setup>
import { computed, onMounted, ref } from 'vue'
import { db, loadCarriers } from '../stores/db'
import { forwardQuote } from '../services/quoteFlow'
import { norm } from '../utils/format'
import { isTouchOrNarrow } from '../composables/useMedia'
import FormModal from './FormModal.vue'
import NavIcon from './NavIcon.vue'

const props = defineProps({ quote: { type: Object, required: true } })
const emit = defineEmits(['close'])

const search = ref('')
const picked = ref([])
const busy = ref(false)

const already = computed(() => new Set((props.quote.negociacao?.respostas ?? []).map((r) => r.transportadora.id)))
const carriers = computed(() =>
  db.carriers
    .filter((c) => (c.ativo || already.value.has(c.id)) && norm(c.nome_fantasia).includes(norm(search.value)))
    .sort((a, b) => a.nome_fantasia.localeCompare(b.nome_fantasia)),
)

onMounted(() => {
  if (!db.carriers.length) loadCarriers({ ativo: 1 })
  if (!isTouchOrNarrow()) document.getElementById('fwSearch')?.focus()
})

function toggle(id, checked) {
  picked.value = checked ? [...picked.value, id] : picked.value.filter((x) => x !== id)
}

async function send() {
  if (busy.value || !picked.value.length) return
  busy.value = true
  const names = db.carriers.filter((c) => picked.value.includes(c.id)).map((c) => c.nome_fantasia)
  const r = await forwardQuote(props.quote.id, picked.value, names)
  busy.value = false
  if (r.ok) emit('close')
}
</script>

<template>
  <FormModal title="Encaminhar transportadoras" :sub="'COTAÇÃO ' + quote.codigo + ' · SELEÇÃO MÚLTIPLA'" @close="emit('close')">
    <div class="field"><input id="fwSearch" v-model="search" class="inp" placeholder="Buscar transportadora…" autocomplete="off" aria-label="Buscar transportadora" /></div>
    <div class="fw-list">
      <label v-for="c in carriers" :key="c.id" :class="{ dis: already.has(c.id) }" data-carrier>
        <input type="checkbox" :checked="already.has(c.id) || picked.includes(c.id)" :disabled="already.has(c.id)" @change="toggle(c.id, $event.target.checked)" />
        {{ c.nome_fantasia }}<small v-if="already.has(c.id)">JÁ CONSULTADA</small>
      </label>
      <div v-if="!carriers.length" class="mut fw-empty">{{ db.loading.carriers ? 'Carregando…' : 'Nenhuma transportadora encontrada.' }}</div>
    </div>
    <template #footer>
      <button class="btn btn-line" @click="emit('close')">Cancelar</button>
      <button class="btn btn-primary" data-act="forward-selected" :disabled="busy || !picked.length" :aria-busy="busy" @click="send">Encaminhar ({{ picked.length }}) <NavIcon name="arrow" :size="14" /></button>
    </template>
  </FormModal>
</template>
