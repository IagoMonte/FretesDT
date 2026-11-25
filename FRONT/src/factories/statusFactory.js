const STATUS = {
  ABERTA: ['ABERTA', 'st-cot'],
  EM_NEGOCIACAO: ['EM NEGOCIAÇÃO', 'st-agu'],
  FECHADA: ['FECHADA', 'st-apr'],
  PROGRAMADA: ['PROGRAMADA', 'st-ent'],
  ENCERRADA: ['ENCERRADA', 'st-dev'],
  CANCELADA: ['CANCELADA', 'st-can'],
  AGUARDANDO: ['AGUARDANDO', 'st-agu'],
  RESPONDIDA: ['RESPONDIDA', 'st-res'],
  DECLINADA: ['DECLINADA', 'st-dev'],
  EM_COTACAO: ['EM COTAÇÃO', 'st-cot'],
  AGUARDANDO_CARREGAMENTO: ['AGUARDANDO CARREGAMENTO', 'st-agu'],
  EM_TRANSITO: ['EM TRÂNSITO', 'st-tra'],
  ENTREGUE: ['ENTREGUE', 'st-ent'],
  CANCELADO: ['CANCELADO', 'st-can'],
  DEVOLVIDO: ['DEVOLVIDO', 'st-dev'],
}

const STATUS_TRANSPORTADORA = {
  EM_NEGOCIACAO: ['EM NEGOCIAÇÃO', 'st-agu'],
  RESPONDIDA: ['RESPONDIDO', 'st-res'],
  PROGRAMADA: ['PROGRAMADO', 'st-ent'],
  ENCERRADA: ['ENCERRADO', 'st-dev'],
}
const tabela = (code, carrier) => (carrier && STATUS_TRANSPORTADORA[code]) || STATUS[code]

export const statusLabel = (code, { carrier = false } = {}) => { const t = tabela(code, carrier); return t ? t[0] : String(code ?? '') }

export const STATUS_COTACAO = ['ABERTA', 'EM_NEGOCIACAO', 'FECHADA', 'PROGRAMADA', 'ENCERRADA', 'CANCELADA']
export const STATUS_COTACAO_TRANSPORTADORA = ['EM_NEGOCIACAO', 'RESPONDIDA', 'PROGRAMADA', 'ENCERRADA']
export const STATUS_PROGRAMACAO = ['EM_COTACAO', 'AGUARDANDO_CARREGAMENTO', 'EM_TRANSITO', 'ENTREGUE', 'CANCELADO', 'DEVOLVIDO']
export const STATUS_FINAIS_PROG = ['ENTREGUE', 'CANCELADO', 'DEVOLVIDO']

export const STATUS_COLORS = {
  EM_COTACAO: '#D6001C',
  AGUARDANDO_CARREGAMENTO: '#6F8C7E',
  EM_TRANSITO: '#00A050',
  ENTREGUE: '#00693C',
  CANCELADO: '#7A1119',
  DEVOLVIDO: '#5B6660',
}

export function createStatus(code, { carrier = false } = {}) {
  const [label, cls] = tabela(code, carrier) || [String(code ?? ''), 'st-agu']
  return { label, cls, pulse: code === 'EM_TRANSITO' }
}

export function createDeadline({ dataEntregaRealizada, entregueNoPrazo, status }) {
  if (status === 'CANCELADO') return { cls: 'prz-no', label: 'cancelada' }
  if (!dataEntregaRealizada) return { cls: 'prz-no', label: 'em aberto' }
  if (entregueNoPrazo === null || entregueNoPrazo === undefined) return { cls: 'prz-no', label: 'sem previsão' }
  return entregueNoPrazo === false
    ? { cls: 'prz-bad', label: 'ATRASADO' }
    : { cls: 'prz-ok', label: 'NO PRAZO' }
}
