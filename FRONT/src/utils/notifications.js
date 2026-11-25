
export const EVENT_LABELS = {
  NEGOCIACAO_ENCAMINHADA: 'Cotação encaminhada à transportadora',
  NEGOCIACAO_RESPONDIDA: 'Transportadora respondeu',
  NEGOCIACAO_DECLINADA: 'Transportadora declinou',
  NEGOCIACAO_FECHADA: 'Frete fechado',
  COTACAO_CANCELADA: 'Cotação cancelada',
  NEGOCIACAO_CANCELADA: 'Negociação cancelada',
  PROGRAMACAO_STATUS: 'Status da carga alterado',
  PROGRAMACAO_CRIADA: 'Programação Criada',
  PROGRAMACAO_CANCELADA: 'Carga cancelada',
  PROGRAMACAO_QTD_EXCEDIDA: 'Quantidade programada acima da cotada',
  PROGRAMACAO_DIVERGE_FECHAMENTO: 'Carga diverge do fechamento',
  TESTE: 'Teste de notificação',
}

export const GROUPS = ['VENDEDOR', 'TRANSPORTADORA', 'GRUPO_TRANSPORTADORA', 'ADMINISTRADOR', 'GESTOR']
export const GROUP_LABELS = { VENDEDOR: 'Vendedor', TRANSPORTADORA: 'Transportadora', GRUPO_TRANSPORTADORA: 'Grupo da transportadora', ADMINISTRADOR: 'Administrador', GESTOR: 'Gestor' }
export const WHATSAPP_ONLY = ['GRUPO_TRANSPORTADORA']
export const groupsOf = (canal) => GROUPS.filter((g) => canal === 'WHATSAPP' || !WHATSAPP_ONLY.includes(g))
export const CHANNELS = ['WHATSAPP', 'EMAIL']
export const CHANNEL_LABELS = { WHATSAPP: 'WhatsApp', EMAIL: 'E-mail' }
export const STATUS_LABELS = { PENDENTE: 'Pendente', ENVIADO: 'Enviado', FALHA: 'Falha' }

export const eventLabel = (code) => EVENT_LABELS[code] || code

export function e164(telefone) {
  let d = String(telefone ?? '').replace(/\D+/g, '').replace(/^0+/, '')
  if (!d) return null
  if (d.startsWith('55') && [12, 13].includes(d.length)) d = d.slice(2)
  return /^[1-9][1-9](\d{8}|9\d{8})$/.test(d) ? '55' + d : null
}

export const isEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v ?? '').trim())
