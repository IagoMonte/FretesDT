import { EVENT_LABELS, eventLabel } from '../../../src/utils/notifications'

describe('rótulos dos eventos de notificação', () => {
  it('PROGRAMACAO_CRIADA aparece como "Programação Criada", não como o código', () => {
    expect(eventLabel('PROGRAMACAO_CRIADA')).toBe('Programação Criada')
  })
  it('todo evento do back tem rótulo legível (sem underline nem caixa alta de código)', () => {
    const eventos = [
      'NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO_RESPONDIDA', 'NEGOCIACAO_DECLINADA', 'NEGOCIACAO_FECHADA', 'COTACAO_CANCELADA',
      'NEGOCIACAO_CANCELADA', 'PROGRAMACAO_STATUS', 'PROGRAMACAO_CANCELADA', 'PROGRAMACAO_CRIADA', 'PROGRAMACAO_QTD_EXCEDIDA',
      'PROGRAMACAO_DIVERGE_FECHAMENTO',
    ]
    for (const e of eventos) {
      expect(EVENT_LABELS[e], e).toBeTruthy()
      expect(eventLabel(e), e).not.toMatch(/_/)
    }
  })
  it('evento desconhecido continua aparecendo pelo código', () => {
    expect(eventLabel('NOVO_EVENTO')).toBe('NOVO_EVENTO')
  })
})
