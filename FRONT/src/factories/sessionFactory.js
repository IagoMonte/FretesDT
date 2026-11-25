const MENU = [
  { key: 'dash', res: 'PAINEL', label: 'Painel Operacional' },
  { key: 'quotes', res: 'COTACAO', label: 'Solicitar Cotação' },
  { key: 'products', res: 'PRODUTO', label: 'Cadastro Produtos' },
  { key: 'packagings', res: 'EMBALAGEM', label: 'Cadastro Embalagens' },
  { key: 'carriers', res: 'TRANSPORTADORA', label: 'Cadastro de Transportadoras' },
  { key: 'antt', res: 'ANTT', label: 'Piso Mínimo ANTT' },
  { key: 'users', res: 'USUARIO', label: 'Usuários e Acessos' },
  { key: 'groups', res: 'GRUPO', label: 'Grupos de Acesso' },
  { key: 'registrations', res: 'USUARIO', action: 'USUARIO.APROVAR_CADASTRO', label: 'Cadastros' },
  { key: 'loads', res: 'PROGRAMACAO', label: 'Programação de Cargas' },
  { key: 'quote-search', res: 'COTACAO', label: 'Consultar Cotação' },
  { key: 'notifications', res: 'NOTIFICACAO', label: 'Notificações' },
]
const HOME_ORDER = ['dash', 'quotes', 'loads']

export const ROUTE_RESOURCE = Object.fromEntries(MENU.map((m) => [m.key, m.res]))

const roleOf = (me) => {
  const g = me.grupos || []
  if (g.includes('ADMINISTRADOR') || g.includes('ADMIN')) return 'ADMINISTRADOR'
  if (me.tipo_usuario === 'TRANSPORTADORA' || g.includes('TRANSPORTADORA')) return 'TRANSPORTADORA'
  if (g.includes('VENDEDOR')) return 'VENDEDOR'
  return g[0] || me.tipo_usuario || 'USUARIO'
}

export function createSession(me) {
  const permissoes = me.permissoes || {}
  const acoes = me.acoes || []
  const role = roleOf(me)
  const isCarrier = role === 'TRANSPORTADORA'
  const can = (res, act = 'ver') => !!(permissoes[res] && permissoes[res][act])
  const transportadora = me.transportadora || null
  const name = String(me.nome ?? me.login ?? '')

  let menu = MENU.filter((m) => can(m.res) && (!m.action || acoes.includes(m.action))).map(({ key, label }) => ({ key, label }))
  if (isCarrier) {
    const first = ['dash', 'loads']
    menu = [...first.flatMap((k) => menu.filter((m) => m.key === k)), ...menu.filter((m) => !first.includes(m.key))]
    menu = menu.map((m) => (m.key === 'quotes' ? { ...m, label: 'Minhas Cotações' } : m))
  }
  const keys = menu.map((m) => m.key)
  const home = (isCarrier && !keys.includes('dash') && keys.includes('loads') && 'loads') || HOME_ORDER.find((k) => keys.includes(k)) || keys[0] || null
  const carrierName = transportadora ? transportadora.nome_fantasia : null

  return {
    id: me.id,
    login: me.login,
    role,
    name,
    ref: role === 'ADMINISTRADOR' ? 'ADMIN' : name,
    avatar: (name[0] || '?').toUpperCase(),
    roleOrg: role !== 'ADMINISTRADOR' ? carrierName || name : '',
    roleLabel: role + (role !== 'ADMINISTRADOR' ? ' · ' + (carrierName || name) : ''),
    tipo: me.tipo_usuario,
    grupos: me.grupos || [],
    transportadora,
    permissoes,
    acoes,
    isAdmin: role === 'ADMINISTRADOR',
    isSeller: role === 'VENDEDOR',
    isCarrier,
    can,
    canAction: (code) => acoes.includes(code),
    home,
    menu,
  }
}
