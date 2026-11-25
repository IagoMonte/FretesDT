import { describe, it, expect } from 'vitest'
import { CIDADES } from '../../../src/data/cidades'
import { buscaCidades } from '../../../src/utils/cidades'

describe('buscaCidades', () => {
  it('traz todos os municípios do Brasil', () => {
    expect(CIDADES.length).toBeGreaterThanOrEqual(5570)
    expect(CIDADES).toContain('Pequeri - MG')
  })
  it('exige 2 caracteres e ignora acentos', () => {
    expect(buscaCidades('s')).toEqual([])
    expect(buscaCidades('sao jose dos campos')[0]).toBe('São José dos Campos - SP')
  })
  it('prefixo vem antes de "contém" e respeita o limite', () => {
    const r = buscaCidades('campinas')
    expect(r[0]).toBe('Campinas - SP')
    expect(buscaCidades('sa').length).toBe(12)
  })
  it('filtra por UF quando digitada ("cidade sp" ou "cidade - sp")', () => {
    expect(buscaCidades('campinas sp')).toEqual(['Campinas - SP'])
    expect(buscaCidades('santa - mg').every((c) => c.endsWith('- MG'))).toBe(true)
  })
  it('é rápido (< 5 ms por busca após o índice montado)', () => {
    buscaCidades('xx')
    const t = performance.now()
    for (let i = 0; i < 20; i++) buscaCidades('zzz')
    expect((performance.now() - t) / 20).toBeLessThan(5)
  })
})
