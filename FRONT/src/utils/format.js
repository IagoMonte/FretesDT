export const money = (v) =>
  'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const fmtN = (v) => Number(v || 0).toLocaleString('pt-BR')

export const fmtD = (d) => {
  if (!d) return '—'
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : d
}

export const fmtDH = (iso) => {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(iso)
  const p = Object.fromEntries(new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(d).map((x) => [x.type, x.value]))
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`
}

export const dec = (v) => {
  const n = Number(v)
  return Number.isFinite(n) ? n : 0
}

export const fmtKg = (v) => (v === null || v === undefined || v === '' ? '—' : fmtN(dec(v)) + ' kg')

export const toDec = (v, places = 2) => {
  const n = parseNum(v)
  return n ? n.toFixed(places) : null
}

export function parseCity(text) {
  const t = String(text ?? '').trim()
  if (!t) return null
  const i = t.lastIndexOf(' - ')
  const cidade = (i >= 0 ? t.slice(0, i) : t).trim().toUpperCase()
  const uf = i >= 0 ? t.slice(i + 3).trim().toUpperCase() : null
  return { cidade, uf: uf || null, ibge: null }
}

export const cityLabel = (c) => (c && c.cidade ? c.cidade + (c.uf ? ' - ' + c.uf : '') : '—')

export const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const isPast = (d) => {
  const m = String(d ?? '').match(/^\d{4}-\d{2}-\d{2}/)
  return !!m && m[0] < today()
}

export const freightValue = (valorTon, totalKg) => (parseNum(valorTon) * parseNum(totalKg)) / 1000

export const sumKg = (itens = []) => itens.reduce((s, i) => s + dec(i.quantidade_kg), 0)

export function parseNum(v) {
  if (typeof v === 'number') return Number.isFinite(v) ? v : 0
  const t = String(v ?? '').trim()
  if (/^\d+\.\d{1,2}$/.test(t)) return parseFloat(t)
  return parseFloat(t.replace(/\./g, '').replace(',', '.')) || 0
}

export function maskCNPJ(value) {
  return String(value ?? '')
    .replace(/\D/g, '')
    .slice(0, 14)
    .replace(/^(\d{2})(\d)/, '$1.$2')
    .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d)/, '.$1/$2')
    .replace(/(\d{4})(\d)/, '$1-$2')
}

export function isValidCNPJ(value) {
  const d = String(value ?? '').replace(/\D/g, '')
  if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) return false
  for (const n of [12, 13]) {
    const pesos = n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
    const soma = pesos.reduce((a, p, i) => a + Number(d[i]) * p, 0)
    const dv = soma % 11 < 2 ? 0 : 11 - (soma % 11)
    if (Number(d[n]) !== dv) return false
  }
  return true
}

export function maskMoney(value) {
  const v = String(value ?? '').replace(/[^\d.,]/g, '')
  const i = v.indexOf(',')
  if (i < 0) return v
  return v.slice(0, i) + ',' + v.slice(i + 1).replace(/\D/g, '').slice(0, 2)
}

export function formatMoney(value) {
  if (typeof value !== 'number' && !/\d/.test(String(value ?? ''))) return ''
  const n = parseNum(value)
  if (!Number.isFinite(n)) return ''
  return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export const hasInvalidNumChars = (value) => /[^\d.,\s]/.test(String(value ?? '').replace(/R\$/gi, ''))

export function maskKg(value, decimalDot = true) {
  let v = String(value ?? '').replace(/[^\d.,]/g, '')
  if (decimalDot && !v.includes(',') && /^\d+\.\d{1,2}$/.test(v)) v = v.replace('.', ',')
  const i = v.indexOf(',')
  const inteiro = (i < 0 ? v : v.slice(0, i)).replace(/\D/g, '')
  const decimais = i < 0 ? null : v.slice(i + 1).replace(/\D/g, '').slice(0, 3)
  const milhar = inteiro ? parseInt(inteiro, 10).toLocaleString('pt-BR') : decimais === null ? '' : '0'
  return decimais === null ? milhar : `${milhar},${decimais}`
}

export function numeroAmbiguo(value) {
  const t = String(value ?? '').replace(/R\$/gi, '').replace(/\s/g, '')
  if (!t) return false
  const virgulas = t.split(',').length - 1
  if (virgulas > 1) return true
  const [inteiro] = t.split(',')
  if (virgulas === 1 && t.lastIndexOf('.') > t.indexOf(',')) return true
  const grupos = inteiro.split('.')
  if (grupos.length === 1) return false
  if (grupos.length === 2 && virgulas === 0) return grupos[1].length > 3
  return !(/^\d{1,3}$/.test(grupos[0]) && grupos.slice(1).every((g, i, a) => /^\d{3}$/.test(g) || (g === '' && i === a.length - 1)))
}
export const fmtRegistros = (n) => `${n} ${Number(n) === 1 ? 'registro' : 'registros'}`

export function maskQty(value) {
  const v = String(value ?? '').replace(/\D/g, '')
  return v ? parseInt(v, 10).toLocaleString('pt-BR') : ''
}

export const norm = (s) =>
  String(s ?? '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')

export function maskPhone(value) {
  const d = String(value ?? '').replace(/\D/g, '').slice(0, 11)
  if (!d) return ''
  if (d.length <= 2) return '(' + d
  const ddd = '(' + d.slice(0, 2) + ') '
  const rest = d.slice(2)
  return rest.length <= (d.length === 11 ? 5 : 4) ? ddd + rest : ddd + rest.slice(0, d.length === 11 ? 5 : 4) + '-' + rest.slice(d.length === 11 ? 5 : 4)
}

export const fmtPhone = (v) => {
  const t = String(v ?? '')
  return /^[\d\s()-]+$/.test(t) && [10, 11].includes(t.replace(/\D/g, '').length) ? maskPhone(t) : t
}

export const fmtDens = (v) => dec(v).toLocaleString('pt-BR', { minimumFractionDigits: 3, maximumFractionDigits: 4 })

export const fmtTon = (kg) => (parseNum(kg) / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 3 })

const diasTxt = (n) => (n === null || n === undefined || n === '' ? '—' : `${n} ${Number(n) === 1 ? 'dia' : 'dias'}`)
export const prazosTxt = (r) => `coleta ${diasTxt(r?.prazo_coleta_dias)} · entrega ${diasTxt(r?.prazo_dias)}`
