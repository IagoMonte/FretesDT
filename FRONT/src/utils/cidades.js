import { CIDADES } from '../data/cidades'
import { norm } from './format'

let INDEX = null
const index = () =>
  (INDEX ??= CIDADES.map((c) => {
    const i = c.lastIndexOf(' - ')
    return { c, nome: norm(c.slice(0, i)), uf: c.slice(i + 3).toLowerCase() }
  }))

export function buscaCidades(texto, limite = 12) {
  let q = norm(String(texto ?? '').trim()).replace(/\s*-\s*/g, ' ')
  if (q.length < 2) return []
  let uf = ''
  const m = q.match(/^(.+)\s([a-z]{2})$/)
  if (m && index().some((e) => e.uf === m[2])) [, q, uf] = m
  const a = [], b = [], c = []
  for (const e of index()) {
    if (uf && e.uf !== uf) continue
    const p = e.nome.indexOf(q)
    if (p < 0) continue
    if (p === 0) a.push(e.c)
    else if (e.nome[p - 1] === ' ') b.push(e.c)
    else c.push(e.c)
    if (a.length >= limite) break
  }
  return a.concat(b, c).slice(0, limite)
}
