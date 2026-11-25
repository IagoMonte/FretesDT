import { formatMoney, maskPhone, fmtPhone, fmtDens, fmtTon, freightValue, money, fmtN, fmtD, fmtKg, dec, toDec, parseCity, cityLabel, parseNum, maskCNPJ, maskMoney, maskQty, norm, fmtRegistros, fmtDH } from '../../../src/utils/format'

describe('format pt-BR', () => {
  it('money formata R$ com 2 casas', () => {
    expect(money(1234.5).replace(/\s/g, ' ')).toBe('R$ 1.234,50')
    expect(money(null).replace(/\s/g, ' ')).toBe('R$ 0,00')
  })
  it('fmtN usa ponto de milhar', () => {
    expect(fmtN(27000)).toBe('27.000')
    expect(fmtN('')).toBe('0')
  })
  it('fmtD converte ISO para dd/mm/aaaa e vazio vira travessão', () => {
    expect(fmtD('2026-09-29')).toBe('29/09/2026')
    expect(fmtD('')).toBe('—')
    expect(fmtD(undefined)).toBe('—')
  })
  it('parseNum entende formato brasileiro', () => {
    expect(parseNum('27.000')).toBe(27000)
    expect(parseNum('1.234,56')).toBe(1234.56)
    expect(parseNum('')).toBe(0)
    expect(parseNum(250)).toBe(250)
  })
  it('maskCNPJ aplica máscara ao digitar', () => {
    expect(maskCNPJ('12345678000190')).toBe('12.345.678/0001-90')
    expect(maskCNPJ('1234')).toBe('12.34')
    expect(maskCNPJ('12345678000190999')).toBe('12.345.678/0001-90')
    expect(maskCNPJ('')).toBe('')
  })
  it('maskMoney filtra a digitação sem tratar como centavos', () => {
    expect(maskMoney('250')).toBe('250')
    expect(maskMoney('250,5')).toBe('250,5')
    expect(maskMoney('1.250,75')).toBe('1.250,75')
    expect(maskMoney('12,345')).toBe('12,34')
    expect(maskMoney('1,2,3')).toBe('1,23')
    expect(maskMoney('R$ abc')).toBe('')
    expect(maskMoney('')).toBe('')
  })
  it('formatMoney (blur): entrada natural pt-BR', () => {
    expect(formatMoney('250')).toBe('250,00')
    expect(formatMoney('250,5')).toBe('250,50')
    expect(formatMoney('1.250,75')).toBe('1.250,75')
    expect(formatMoney('1250,75')).toBe('1.250,75')
    expect(formatMoney('1.250')).toBe('1.250,00')
    expect(formatMoney('250.5')).toBe('250,50')
    expect(formatMoney(175)).toBe('175,00')
    expect(formatMoney('')).toBe('')
    expect(formatMoney('abc')).toBe('')
    expect(formatMoney(',')).toBe('')
  })
  it('parseNum e freightValue aceitam texto pt-BR e decimal da API', () => {
    expect(parseNum('1.250,75')).toBe(1250.75)
    expect(parseNum('180.00')).toBe(180)
    expect(parseNum('x')).toBe(0)
    expect(freightValue('180.00', 27000)).toBe(4860)
  })
  it('maskPhone/fmtPhone/fmtDens/fmtTon', () => {
    expect(maskPhone('11987654321')).toBe('(11) 98765-4321')
    expect(maskPhone('1134567890')).toBe('(11) 3456-7890')
    expect(maskPhone('119')).toBe('(11) 9')
    expect(fmtPhone('1134567890')).toBe('(11) 3456-7890')
    expect(fmtPhone('(11) 3456-7890')).toBe('(11) 3456-7890')
    expect(fmtPhone('+55 11 98765-4321')).toBe('+55 11 98765-4321')
    expect(fmtDens('1.690')).toBe('1,690')
    expect(fmtDens('1.19')).toBe('1,190')
    expect(fmtTon(27000)).toBe('27')
    expect(fmtTon(12500)).toBe('12,5')
  })
  it('maskQty agrupa milhares', () => {
    expect(maskQty('27000')).toBe('27.000')
    expect(maskQty('abc')).toBe('')
  })
  it('T20: fmtDH mostra dia e hora no fuso de São Paulo', () => {
    expect(fmtDH('2026-10-08T14:05:00.000-03:00')).toBe('08/10/2026 14:05')
    expect(fmtDH('2026-10-08T17:05:00.000Z')).toBe('08/10/2026 14:05')
    expect(fmtDH(null)).toBe('—')
  })
  it('T21: fmtRegistros usa singular para 1', () => {
    expect(fmtRegistros(0)).toBe('0 registros')
    expect(fmtRegistros(1)).toBe('1 registro')
    expect(fmtRegistros(4)).toBe('4 registros')
  })
  it('norm remove acentos e caixa', () => {
    expect(norm('São Paulo')).toBe('sao paulo')
  })

  it('fmtD aceita ISO-8601 completo', () => {
    expect(fmtD('2026-10-05T14:03:00.000-03:00')).toBe('05/10/2026')
  })
  it('decimais da API (string) viram número para exibir', () => {
    expect(dec('1234.50')).toBe(1234.5)
    expect(dec(null)).toBe(0)
    expect(dec('abc')).toBe(0)
    expect(money(dec('1234.50')).replace(/s/g, ' ')).toBe('R$ 1.234,50')
    expect(fmtKg('12000.000')).toBe('12.000 kg')
    expect(fmtKg(null)).toBe('—')
  })
  it('toDec converte texto pt-BR em string decimal da API', () => {
    expect(toDec('15.000,00')).toBe('15000.00')
    expect(toDec('27.000', 3)).toBe('27000.000')
    expect(toDec('')).toBeNull()
  })
  it('parseCity / cityLabel', () => {
    expect(parseCity('Paulínia - SP')).toEqual({ cidade: 'PAULÍNIA', uf: 'SP', ibge: null })
    expect(parseCity('Sao Paulo')).toEqual({ cidade: 'SAO PAULO', uf: null, ibge: null })
    expect(parseCity('  ')).toBeNull()
    expect(cityLabel({ cidade: 'PAULÍNIA', uf: 'SP' })).toBe('PAULÍNIA - SP')
    expect(cityLabel(null)).toBe('—')
  })
})
