<script setup>
import { computed, ref } from 'vue'
import { db, embalagemLabel } from '../stores/db'
import { confirmAction } from '../stores/ui'
import { maskKg, maskQty, parseNum, fmtN, dec, hasInvalidNumChars, numeroAmbiguo } from '../utils/format'
import { avisoNumero, avisoNumeroAmbiguo } from '../utils/numberInput'
import { blankItem, isGranel, isFracionada, kgToVolumes, temGranel, volumesToKg } from '../utils/items'
import NavIcon from './NavIcon.vue'

const props = defineProps({ modelValue: { type: Array, default: () => [] }, idPrefix: { type: String, default: 'it' }, showErrors: Boolean, showFlags: { type: Boolean, default: true } })
const emit = defineEmits(['update:modelValue'])

const productsFor = (item) => {
  const cur = db.products.find((p) => p.id === item.produto_id)
  const active = db.products.filter((p) => p.ativo)
  return cur && !cur.ativo ? [...active, cur] : active
}
const packsOf = (item) => db.products.find((p) => p.id === item.produto_id)?.embalagens ?? []

const allows = (item) => db.domains.embalagens.find((e) => e.codigo === item.embalagem) ?? {}
const flags = (emb, it) => {
  const e = db.domains.embalagens.find((x) => x.codigo === emb) ?? {}
  return { paletizado: !!(e.permite_paletizar && it.paletizado), remontado: !!(e.permite_remontar && it.remontado) }
}

const factorOf = (item) => (isGranel(item.embalagem) ? 0 : dec(db.products.find((p) => p.id === item.produto_id)?.fatores?.[item.embalagem]))
const volText = (n) => (n ? fmtN(n) : '')
const onQty = (i, v, ev) => {
  if (ev?.inputType === 'insertText' && !v.includes(',') && /^\d+(\.\d+)+$/.test(v) && v.split('.').some((g) => g.length > 3)) v = v.replace(/\./g, '')
  if (hasInvalidNumChars(v)) {
    if (ev) ev.target.value = props.modelValue[i].qtd ?? ''
    return avisoNumero()
  }
  if (numeroAmbiguo(v)) {
    if (ev) ev.target.value = props.modelValue[i].qtd ?? ''
    return avisoNumeroAmbiguo()
  }
  const qtd = maskKg(v, !String(ev?.inputType ?? '').startsWith('delete'))
  patch(i, { qtd, ...(factorOf(props.modelValue[i]) ? { volumes: volText(kgToVolumes(qtd, factorOf(props.modelValue[i]))) } : {}) })
}
const onVolumes = (i, v, ev) => {
  if (hasInvalidNumChars(v)) {
    if (ev) ev.target.value = props.modelValue[i].volumes ?? ''
    return avisoNumero()
  }
  const volumes = maskQty(v)
  patch(i, { volumes, ...(factorOf(props.modelValue[i]) ? { qtd: volText(volumesToKg(volumes, factorOf(props.modelValue[i]))) } : {}) })
}
const palletKg = (item) => dec(db.products.find((p) => p.id === item.produto_id)?.fatores_palete?.[item.embalagem])
const paletes = (item) => kgToVolumes(item.qtd, palletKg(item))
const recalc = (item, produto_id, emb) => {
  const f = factorOf({ produto_id, embalagem: emb })
  return f ? { volumes: volText(kgToVolumes(item.qtd, f)) } : {}
}

const bad = (cond) => props.showErrors && cond
const patch = (i, change) => {
  const lista = props.modelValue.map((it, k) => (k === i ? { ...it, ...change } : it))
  emit('update:modelValue', isGranel(lista[i].embalagem) && lista.length > 1 ? [lista[i]] : lista)
}
const granel = computed(() => temGranel(props.modelValue))

function onProduct(i, id) {
  const packs = db.products.find((p) => p.id === id)?.embalagens ?? []
  const cur = props.modelValue[i].embalagem
  const padrao = props.modelValue.length > 1 ? (packs.find((e) => !isGranel(e)) ?? packs[0]) : packs[0]
  const emb = packs.includes(cur) ? cur : (padrao ?? '')
  patch(i, { produto_id: id, embalagem: emb, ...flags(emb, props.modelValue[i]), ...(isGranel(emb) ? { volumes: '' } : recalc(props.modelValue[i], id, emb)) })
}
const aplicaPack = (i, emb) => patch(i, { embalagem: emb, ...flags(emb, props.modelValue[i]), ...(isGranel(emb) ? { volumes: '' } : recalc(props.modelValue[i], props.modelValue[i].produto_id, emb)) })
const rev = ref(0)
function onPack(i, emb) {
  if (isGranel(emb) && props.modelValue.length > 1) {
    rev.value++
    return confirmAction('Granel é um produto só por cotação: os **outros itens serão removidos**. Deseja continuar?', () => aplicaPack(i, emb), { title: 'Trocar para granel', label: 'Trocar para granel', icon: 'warn', tone: 'primary' })
  }
  aplicaPack(i, emb)
}
const add = () => emit('update:modelValue', [...props.modelValue, blankItem()])
const remove = (i) => emit('update:modelValue', props.modelValue.filter((_, k) => k !== i))
</script>

<template>
  <div class="item-rows">
    <div v-for="(it, i) in modelValue" :key="i" class="item-row" :class="{ granel: isGranel(it.embalagem), frac: isFracionada(it.embalagem) }" :data-item="i">
      <select :id="`${idPrefix}-prod-${i}`" class="inp" :class="{ invalid: bad(!it.produto_id) }" aria-label="Produto" :value="it.produto_id" @change="onProduct(i, $event.target.value)">
        <option value="">— produto —</option>
        <option v-for="p in productsFor(it)" :key="p.id" :value="p.id">{{ p.nome }}</option>
      </select>
      <select :id="`${idPrefix}-emb-${i}`" :key="`emb-${rev}`" class="inp" :class="{ invalid: bad(!!it.produto_id && !it.embalagem) }" aria-label="Embalagem" :disabled="!it.produto_id" :value="it.embalagem" @change="onPack(i, $event.target.value)">
        <option v-if="!it.produto_id" value="">Escolha o produto</option>
        <option v-for="e in packsOf(it)" :key="e" :value="e">{{ embalagemLabel(e) }}</option>
      </select>
      <input :id="`${idPrefix}-qtd-${i}`" class="inp" :class="{ invalid: bad(!parseNum(it.qtd)) }" aria-label="QTD (KG)" placeholder="QTD (KG)" inputmode="decimal" :value="it.qtd" @input="onQty(i, $event.target.value, $event)" />
      <input v-if="!isGranel(it.embalagem)" :id="`${idPrefix}-vol-${i}`" class="inp" aria-label="Volumes" placeholder="Volumes" :title="factorOf(it) ? `1 volume = ${fmtN(factorOf(it))} kg` : undefined" inputmode="numeric" :value="it.volumes" @input="onVolumes(i, $event.target.value, $event)" />
      <input v-if="isFracionada(it.embalagem)" :id="`${idPrefix}-pal-${i}`" class="inp" aria-label="Paletes" placeholder="Paletes" :title="palletKg(it) ? `1 palete = ${fmtN(palletKg(it))} kg` : 'Cadastre o kg por palete no produto'" readonly :value="paletes(it) || ''" />
      <button class="ibtn dl" type="button" title="Remover item" aria-label="Remover item" :disabled="modelValue.length <= 1" @click="remove(i)"><NavIcon name="x" :size="16" /></button>
      <div v-if="showFlags && (allows(it).permite_paletizar || allows(it).permite_remontar)" class="item-flags">
        <label v-if="allows(it).permite_paletizar"><input type="checkbox" :checked="it.paletizado" @change="patch(i, { paletizado: $event.target.checked })" /> Paletizado</label>
        <label v-if="allows(it).permite_remontar"><input type="checkbox" :checked="it.remontado" @change="patch(i, { remontado: $event.target.checked })" /> Remontado</label>
      </div>
    </div>
    <div>
      <button class="btn btn-line btn-sm" type="button" data-act="add-item" :disabled="granel" :title="granel ? 'Granel é item único: uma cotação de granel leva um produto só.' : undefined" @click="add"><NavIcon name="plus" :size="14" /> Adicionar item</button>
      <span v-if="granel" class="mut" data-granel-hint style="margin-left:8px;font-size:12px">Granel: um produto só por cotação.</span>
    </div>
  </div>
</template>
