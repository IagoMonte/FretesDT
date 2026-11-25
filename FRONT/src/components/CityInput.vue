<script setup>
import { ref, watch, nextTick } from 'vue'
import { buscaCidades } from '../utils/cidades'

const props = defineProps({ modelValue: { type: String, default: '' }, placeholder: String, id: String, invalid: Boolean, readonly: Boolean })
const emit = defineEmits(['update:modelValue'])

const listId = `${props.id || 'city'}-list`
const items = ref([])
const open = ref(false)
const sel = ref(-1)

watch(open, async (v) => {
  if (!v) return
  await nextTick()
  document.getElementById(listId)?.scrollIntoView?.({ block: 'nearest' })
})

const close = () => { open.value = false; sel.value = -1 }
const pick = (city) => { emit('update:modelValue', city); close() }
const split = (c) => c.split(' - ')

function onInput(e) {
  if (props.readonly) return
  const v = e.target.value
  emit('update:modelValue', v)
  items.value = buscaCidades(v)
  if (!items.value.length) return close()
  open.value = items.value.length > 0
  sel.value = -1
}

const onBlur = () => setTimeout(close, 140)

function onKey(e) {
  if (!open.value) return
  if (e.key === 'ArrowDown') { e.preventDefault(); sel.value = Math.min(sel.value + 1, items.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); sel.value = Math.max(sel.value - 1, 0) }
  else if (e.key === 'Enter' && sel.value >= 0) { e.preventDefault(); pick(items.value[sel.value]) }
  else if (e.key === 'Escape') { e.stopPropagation(); close() }
}
</script>

<template>
  <div class="autowrap">
    <input :id="id" class="inp" :class="{ invalid }" :aria-invalid="invalid || undefined" role="combobox" aria-autocomplete="list" :aria-expanded="open" :aria-controls="listId" :aria-activedescendant="open && sel >= 0 ? `${listId}-${sel}` : undefined" :value="modelValue" :placeholder="placeholder" :readonly="readonly" autocomplete="off"
      @input="onInput" @keydown="onKey" @blur="onBlur" />
    <div v-if="open" :id="listId" class="citybox" role="listbox">
      <div v-for="(c, i) in items" :id="`${listId}-${i}`" :key="c" role="option" :aria-selected="i === sel" :class="{ sel: i === sel }" @mousedown="pick(c)">
        {{ split(c)[0].toUpperCase() }}<b>{{ split(c)[1] }}</b>
      </div>
    </div>
  </div>
</template>
