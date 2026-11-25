import { parseBold } from '../../../src/utils/markup'

describe('parseBold', () => {
  it('separa trechos em negrito marcados com **', () => {
    expect(parseBold('Cotação **COT-1** ok')).toEqual([
      { text: 'Cotação ', bold: false },
      { text: 'COT-1', bold: true },
      { text: ' ok', bold: false },
    ])
  })
  it('texto sem marcação vira um único trecho', () => {
    expect(parseBold('oi')).toEqual([{ text: 'oi', bold: false }])
  })
  it('nunca interpreta HTML', () => {
    expect(parseBold('<b>x</b>')).toEqual([{ text: '<b>x</b>', bold: false }])
  })
})
