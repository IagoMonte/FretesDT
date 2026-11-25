<script setup>
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import { pushModal, removeModal, isTopModal } from '../composables/useModalStack'
import { searchParties, createParty } from '../stores/db'
import { pushToast } from '../stores/ui'
import { maskCNPJ } from '../utils/format'
import CityInput from './CityInput.vue'
import NavIcon from './NavIcon.vue'

const props = defineProps({ modelValue: { type: Object, default: null }, tipo: { type: String, default: 'CLIENTE' }, id: String, invalid: Boolean })
const emit = defineEmits(['update:modelValue'])

const term = ref(props.modelValue ? props.modelValue.razao_social : '')
const results = ref([])
const open = ref(false)
const creating = ref(false)
const saving = ref(false)
const loading = ref(false)
const sel = ref(-1)
const form = reactive({ razao_social: '', nome_fantasia: '', cnpj: '', cidade: '' })
let seq = 0
let timer = null
const DEBOUNCE_MS = 300

const listId = `${props.id || 'parte'}-list`
const noun = () => (props.tipo === 'FORNECEDOR' ? 'fornecedor' : 'cliente')
const close = () => { open.value = false; sel.value = -1 }
const onBlur = () => setTimeout(close, 160)

watch(() => props.tipo, () => { term.value = ''; results.value = []; creating.value = false; open.value = false })

async function onInput(e) {
  term.value = e.target.value
  if (props.modelValue) emit('update:modelValue', null)
  const q = term.value.trim()
  const n = ++seq
  sel.value = -1
  clearTimeout(timer)
  if (q.length < 2) { results.value = []; loading.value = false; open.value = false; return }
  loading.value = true
  open.value = true
  timer = setTimeout(async () => {
    const r = await searchParties(q, props.tipo)
    if (n !== seq) return
    loading.value = false
    results.value = r.ok ? r.data : []
  }, DEBOUNCE_MS)
}
const camada = 'parte-' + Math.random().toString(36).slice(2, 8)
const onEscPainel = (e) => { if (e.key === 'Escape' && isTopModal(camada)) { e.stopImmediatePropagation(); creating.value = false } }
watch(creating, (aberto) => {
  if (aberto) { pushModal(camada); document.addEventListener('keydown', onEscPainel) }
  else { removeModal(camada); document.removeEventListener('keydown', onEscPainel) }
})
onBeforeUnmount(() => { clearTimeout(timer); removeModal(camada); document.removeEventListener('keydown', onEscPainel) })

function onKey(e) {
  if (!open.value) return
  const ultimo = results.value.length
  if (e.key === 'ArrowDown') { e.preventDefault(); sel.value = Math.min(sel.value + 1, ultimo) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); sel.value = Math.max(sel.value - 1, 0) }
  else if (e.key === 'Enter' && sel.value >= 0) { e.preventDefault(); sel.value === ultimo ? startCreate() : pick(results.value[sel.value]) }
  else if (e.key === 'Escape') { e.stopPropagation(); close() }
}

function pick(p) {
  term.value = p.razao_social
  emit('update:modelValue', p)
  close()
}

function startCreate() {
  Object.assign(form, { razao_social: term.value.trim(), nome_fantasia: '', cnpj: '', cidade: '' })
  creating.value = true
  close()
}

async function create() {
  if (saving.value) return
  saving.value = true
  const r = await createParty(props.tipo, form)
  saving.value = false
  if (r.status === 409 && r.existente) {
    pushToast('info', `${r.error} Usando o cadastro existente.`)
    creating.value = false
    return pick(r.existente)
  }
  if (!r.ok) return pushToast('err', r.error)
  pushToast('ok', `Cadastro de ${noun()} **${r.data.razao_social}** criado (provisório, sem código do ERP).`)
  creating.value = false
  pick(r.data)
}
</script>

<template>
  <div>
    <div class="autowrap">
      <input :id="id" class="inp" :class="{ invalid }" :aria-invalid="invalid || undefined" role="combobox" aria-autocomplete="list" :aria-expanded="open" :aria-controls="listId" :aria-activedescendant="open && sel >= 0 ? `${listId}-${sel}` : undefined" :value="term" :placeholder="`Buscar ${noun()} por nome ou CNPJ…`" autocomplete="off" @input="onInput" @keydown="onKey" @blur="onBlur" />
      <div v-if="open" :id="listId" class="citybox" role="listbox">
        <div v-if="loading" class="mut" role="status" aria-live="polite" style="padding:8px 12px">Buscando…</div>
        <div v-else-if="!results.length" class="mut" role="status" style="padding:8px 12px">Nenhum {{ noun() }} encontrado</div>
        <div v-for="(p, i) in results" :id="`${listId}-${i}`" :key="p.id" role="option" data-parte :aria-selected="i === sel" :class="{ sel: i === sel }" @mousedown="pick(p)">
          {{ p.razao_social }}<b>{{ maskCNPJ(p.cnpj) }}<template v-if="p.cidade"> · {{ p.cidade }}{{ p.uf ? '-' + p.uf : '' }}</template></b>
        </div>
        <div :id="`${listId}-${results.length}`" role="option" data-act="new-parte" :aria-selected="sel === results.length" :class="{ sel: sel === results.length }" @mousedown="startCreate">+ Cadastrar novo {{ noun() }}</div>
      </div>
    </div>
    <div v-if="modelValue" class="chosen-tag mut"><NavIcon name="check" :size="14" /> {{ modelValue.tipo === 'FORNECEDOR' ? 'Fornecedor' : 'Cliente' }} · CNPJ <span class="mono">{{ maskCNPJ(modelValue.cnpj) }}</span></div>

    <div v-if="creating" class="partebox">
      <div class="fgrid">
        <div class="field c8"><label for="pp-razao">Razão social <i>*</i></label><input id="pp-razao" v-model="form.razao_social" class="inp" /></div>
        <div class="field c4"><label for="pp-cnpj">CNPJ <i>*</i></label>
          <input id="pp-cnpj" :value="form.cnpj" class="inp" placeholder="00.000.000/0000-00" maxlength="18" @input="form.cnpj = maskCNPJ($event.target.value)" /></div>
        <div class="field c6"><label for="pp-fantasia">Nome fantasia</label><input id="pp-fantasia" v-model="form.nome_fantasia" class="inp" /></div>
        <div class="field c6"><label for="pp-cidade">Cidade</label><CityInput id="pp-cidade" v-model="form.cidade" placeholder="Digite a cidade…" /></div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
        <button class="btn btn-line btn-sm" type="button" @click="creating = false">Cancelar</button>
        <button class="btn btn-green btn-sm" type="button" data-act="save-parte" :disabled="saving" @click="create">Cadastrar {{ noun() }}</button>
      </div>
    </div>
  </div>
</template>
