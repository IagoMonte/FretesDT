import { safeNext } from '../../../src/utils/next'

describe('safeNext — destino pós-login só pode ser caminho interno', () => {
  it('aceita caminho interno com query', () => {
    expect(safeNext('/quotes?abrir=COT-000001')).toBe('/quotes?abrir=COT-000001')
    expect(safeNext('/loads')).toBe('/loads')
  })
  it.each([
    ['//evil.com'], ['/\\evil.com'], ['https://evil.com'], ['http://evil.com/x'], ['javascript:alert(1)'],
    ['quotes'], [''], [null], [undefined], [['/quotes']], ['/login'], ['/login?next=/quotes'], ['/cadastro'], ['/\nhttps://evil.com'],
  ])('rejeita %j', (v) => {
    expect(safeNext(v)).toBeNull()
  })
})
