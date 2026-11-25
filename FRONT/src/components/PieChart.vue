<script setup>
import { computed } from 'vue'

const props = defineProps({ parts: { type: Array, required: true }, label: { type: String, default: '' } })

const C = 66
const ponto = (frac) => {
  const a = frac * 2 * Math.PI
  return `${(C + C * Math.cos(a)).toFixed(3)} ${(C + C * Math.sin(a)).toFixed(3)}`
}
const fatias = computed(() => {
  const tot = props.parts.reduce((a, p) => a + p.val, 0)
  const vivas = props.parts.filter((p) => p.val > 0)
  if (!tot) return { vazio: true, inteira: null, setores: [] }
  if (vivas.length === 1) return { vazio: false, inteira: vivas[0].color, setores: [] }
  let ini = 0
  const setores = vivas.map((p) => {
    const frac = p.val / tot
    const d = `M${C} ${C} L${ponto(ini)} A${C} ${C} 0 ${frac > 0.5 ? 1 : 0} 1 ${ponto(ini + frac)} Z`
    ini += frac
    return { color: p.color, d }
  })
  return { vazio: false, inteira: null, setores }
})
</script>

<template>
  <div class="pie" role="img" :aria-label="label">
    <svg width="100%" height="100%" viewBox="0 0 132 132">
      <circle v-if="fatias.vazio" cx="66" cy="66" r="66" fill="#EDF1EE" />
      <circle v-else-if="fatias.inteira" cx="66" cy="66" r="66" :fill="fatias.inteira" />
      <template v-else>
        <path v-for="(s, i) in fatias.setores" :key="i" :d="s.d" :fill="s.color" stroke="#fff" stroke-width="1.5" stroke-linejoin="round" />
      </template>
    </svg>
  </div>
</template>
