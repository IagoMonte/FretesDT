import { readFileSync } from 'node:fs'

const css = readFileSync('src/styles/main.css', 'utf8')
const mobile = css.slice(css.indexOf('@media (max-width:768px)'))

describe('C10: tabelas de cadastro no celular', () => {
  it('o .tbl-wrap mostra uma dica de rolagem lateral só em telas estreitas', () => {
    expect(css).not.toMatch(/\n\.tbl-wrap::before/)
    expect(mobile).toMatch(/\.tbl-wrap::before\{[^}]*content:\s*'[^']*lado[^']*'/)
    expect(mobile).toMatch(/\.tbl-wrap::before\{[^}]*position:\s*sticky[^}]*left:\s*0/)
  })
  it('dentro de modal a dica não aparece (a tabela já rola em max-content)', () => {
    expect(mobile).toMatch(/\.modal-b \.tbl-wrap::before\{[^}]*display:\s*none/)
  })
})
