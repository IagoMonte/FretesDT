import { describe, it, expect } from 'vitest'
import { setFracionadaCodes, isFracionada, kgToVolumes } from '../../../src/utils/items'

describe('embalagem fracionada', () => {
  it('só vale para fracionada não granel', () => {
    setFracionadaCodes([{ codigo: 'FRAC', fracionada: true }, { codigo: 'G', fracionada: true, granel: true }, { codigo: 'SAC' }])
    expect(isFracionada('FRAC')).toBe(true)
    expect(isFracionada('G')).toBe(false)
    expect(isFracionada('SAC')).toBe(false)
  })
  it('paletes = kg / kg por palete, arredondando para cima', () => {
    expect(kgToVolumes('1.000', '1000.000')).toBe(1)
    expect(kgToVolumes('1.001', '1000.000')).toBe(2)
    expect(kgToVolumes('2.500', '1000.000')).toBe(3)
  })
})
