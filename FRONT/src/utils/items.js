import { shallowRef } from 'vue'
import { fmtN, dec, parseNum } from './format'

const DEFAULT_GRANEL = ['GRANEL_LIQUIDO', 'GRANEL_SOLIDO']
const granelCodes = shallowRef(new Set(DEFAULT_GRANEL))
export const setGranelCodes = (embalagens = []) => { granelCodes.value = new Set(embalagens.filter((e) => e.granel).map((e) => e.codigo)) }
export const isGranel = (codigo) => granelCodes.value.has(codigo)

const fracionadaCodes = shallowRef(new Set())
export const setFracionadaCodes = (embalagens = []) => { fracionadaCodes.value = new Set(embalagens.filter((e) => e.fracionada && !e.granel).map((e) => e.codigo)) }
export const isFracionada = (codigo) => fracionadaCodes.value.has(codigo)

export const temGranel = (itens = []) => itens.some((i) => isGranel(i.embalagem))
export const temFracionada = (itens = []) => itens.some((i) => isFracionada(i.embalagem))

export const blankItem = () => ({ produto_id: '', embalagem: '', qtd: '', volumes: '', paletizado: false, remontado: false })

export const itemFromApi = (i) => ({
  produto_id: i.produto?.id ?? '',
  embalagem: i.embalagem_codigo,
  qtd: fmtN(dec(i.quantidade_kg)),
  volumes: i.volumes && !isGranel(i.embalagem_codigo) ? fmtN(i.volumes) : '',
  paletizado: !!i.paletizado,
  remontado: !!i.remontado,
  cotacao_item_id: i.cotacao_item_id ?? null,
})

export function itemsSummary(itens = []) {
  if (!itens.length) return '—'
  const first = itens[0].produto?.nome ?? '—'
  return itens.length > 1 ? `${first} +${itens.length - 1}` : first
}

export function kgToVolumes(kg, fator) {
  const f = dec(fator)
  const q = parseNum(kg)
  return f > 0 && q > 0 ? Math.ceil(Math.round((q / f) * 1e6) / 1e6) : 0
}

export function volumesToKg(vol, fator) {
  const f = dec(fator)
  const v = parseNum(vol)
  return f > 0 && v > 0 ? Math.round(v * f * 1000) / 1000 : 0
}
