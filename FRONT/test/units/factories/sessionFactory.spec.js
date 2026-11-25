import { createSession, ROUTE_RESOURCE } from '../../../src/factories/sessionFactory'
import { meAdmin, meSeller, meCarrier } from '../../helpers/fixtures'

describe('createSession (menu e home pelas permissões do /me)', () => {
  it('ADMINISTRADOR: menu completo (inclui usuários) e home no painel', () => {
    const s = createSession(meAdmin)
    expect(s.home).toBe('dash')
    expect(s.menu.map((m) => m.key)).toEqual(['dash', 'quotes', 'products', 'carriers', 'users', 'registrations', 'loads', 'quote-search'])
    expect(s.roleLabel).toBe('ADMINISTRADOR')
    expect(s.avatar).toBe('A')
    expect(s.name).toBe('Administrador')
  })
  it('VENDEDOR: só o que tem "ver"; home em cotações; chip com nome', () => {
    const s = createSession(meSeller)
    expect(s.home).toBe('quotes')
    expect(s.menu.map((m) => m.key)).toEqual(['quotes', 'loads', 'quote-search'])
    expect(s.roleLabel).toBe('VENDEDOR · MARCOS')
  })
  it('TRANSPORTADORA: Painel Operacional primeiro e como home, depois cargas, "Minhas Cotações", chip com a transportadora', () => {
    const s = createSession(meCarrier)
    expect(s.home).toBe('dash')
    expect(s.menu.map((m) => m.label)).toEqual(['Painel Operacional', 'Programação de Cargas', 'Minhas Cotações', 'Consultar Cotação'])
    expect(s.roleLabel).toBe('TRANSPORTADORA · ARCO')
    expect(s.transportadora).toEqual({ id: 't-arco', nome_fantasia: 'ARCO' })
  })
  it('flags derivadas de grupos/tipo', () => {
    expect(createSession(meAdmin)).toMatchObject({ isAdmin: true, isSeller: false, isCarrier: false })
    expect(createSession(meSeller)).toMatchObject({ isAdmin: false, isSeller: true, isCarrier: false })
    expect(createSession(meCarrier)).toMatchObject({ isAdmin: false, isSeller: false, isCarrier: true })
  })
  it('can() e canAction() leem permissoes/acoes', () => {
    const s = createSession(meSeller)
    expect(s.can('COTACAO', 'inserir')).toBe(true)
    expect(s.can('PROGRAMACAO', 'alterar')).toBe(false)
    expect(s.can('PRODUTO')).toBe(false)
    expect(s.can('INEXISTENTE')).toBe(false)
    expect(s.canAction('NEGOCIACAO.FECHAR')).toBe(false)
    expect(createSession(meAdmin).canAction('NEGOCIACAO.FECHAR')).toBe(true)
  })
  it('sem nenhuma permissão: menu vazio e home nula', () => {
    const s = createSession({ id: 'x', nome: 'Z', login: 'z', tipo_usuario: 'INTERNO', grupos: [], permissoes: {}, acoes: [] })
    expect(s.menu).toEqual([])
    expect(s.home).toBeNull()
  })
  it('mapa de recursos por rota', () => {
    expect(ROUTE_RESOURCE).toMatchObject({ dash: 'PAINEL', quotes: 'COTACAO', products: 'PRODUTO', carriers: 'TRANSPORTADORA', users: 'USUARIO', loads: 'PROGRAMACAO' })
  })
})
