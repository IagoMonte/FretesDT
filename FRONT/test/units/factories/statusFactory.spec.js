import { createStatus, createDeadline, statusLabel, STATUS_COTACAO, STATUS_COTACAO_TRANSPORTADORA, STATUS_PROGRAMACAO } from '../../../src/factories/statusFactory'

describe('createStatus (códigos estáveis -> rótulo/cor no front)', () => {
  it.each([
    ['ABERTA', 'ABERTA', 'st-cot'],
    ['EM_NEGOCIACAO', 'EM NEGOCIAÇÃO', 'st-agu'],
    ['FECHADA', 'FECHADA', 'st-apr'],
    ['PROGRAMADA', 'PROGRAMADA', 'st-ent'],
    ['CANCELADA', 'CANCELADA', 'st-can'],
    ['AGUARDANDO', 'AGUARDANDO', 'st-agu'],
    ['RESPONDIDA', 'RESPONDIDA', 'st-res'],
    ['DECLINADA', 'DECLINADA', 'st-dev'],
    ['EM_COTACAO', 'EM COTAÇÃO', 'st-cot'],
    ['AGUARDANDO_CARREGAMENTO', 'AGUARDANDO CARREGAMENTO', 'st-agu'],
    ['EM_TRANSITO', 'EM TRÂNSITO', 'st-tra'],
    ['ENTREGUE', 'ENTREGUE', 'st-ent'],
    ['CANCELADO', 'CANCELADO', 'st-can'],
    ['DEVOLVIDO', 'DEVOLVIDO', 'st-dev'],
  ])('%s -> %s / %s', (code, label, cls) => {
    expect(createStatus(code)).toMatchObject({ label, cls })
  })
  it('status desconhecido cai no neutro e mantém o texto', () => {
    expect(createStatus('XYZ')).toMatchObject({ cls: 'st-agu', label: 'XYZ' })
    expect(statusLabel('XYZ')).toBe('XYZ')
  })
  it('só EM_TRANSITO pulsa', () => {
    expect(createStatus('EM_TRANSITO').pulse).toBe(true)
    expect(createStatus('ENTREGUE').pulse).toBe(false)
  })
  it('listas de ordenação cobrem todos os status de cotação e de programação', () => {
    expect(STATUS_COTACAO).toEqual(['ABERTA', 'EM_NEGOCIACAO', 'FECHADA', 'PROGRAMADA', 'ENCERRADA', 'CANCELADA'])
    expect(STATUS_PROGRAMACAO).toHaveLength(6)
  })
})

describe('status da cotação na visão da transportadora', () => {
  it.each([
    ['EM_NEGOCIACAO', 'EM NEGOCIAÇÃO', 'st-agu'],
    ['RESPONDIDA', 'RESPONDIDO', 'st-res'],
    ['PROGRAMADA', 'PROGRAMADO', 'st-ent'],
    ['ENCERRADA', 'ENCERRADO', 'st-dev'],
  ])('%s -> %s / %s', (code, label, cls) => {
    expect(createStatus(code, { carrier: true })).toMatchObject({ label, cls })
    expect(statusLabel(code, { carrier: true })).toBe(label)
  })
  it('sem a opção, os rótulos internos não mudam', () => {
    expect(statusLabel('RESPONDIDA')).toBe('RESPONDIDA')
    expect(statusLabel('PROGRAMADA')).toBe('PROGRAMADA')
  })
  it('lista de ordenação da transportadora tem só os 4 status', () => {
    expect(STATUS_COTACAO_TRANSPORTADORA).toEqual(['EM_NEGOCIACAO', 'RESPONDIDA', 'PROGRAMADA', 'ENCERRADA'])
  })
})

describe('T21: createDeadline sem previsão e cancelada', () => {
  it('entregue sem previsão não vira NO PRAZO', () => {
    expect(createDeadline({ dataEntregaRealizada: '2026-10-08', entregueNoPrazo: null })).toMatchObject({ cls: 'prz-no', label: 'sem previsão' })
  })
  it('carga cancelada não é "em aberto"', () => {
    expect(createDeadline({ dataEntregaRealizada: null, entregueNoPrazo: null, status: 'CANCELADO' })).toMatchObject({ cls: 'prz-no', label: 'cancelada' })
  })
  it('regras antigas seguem: no prazo, atrasado e em aberto', () => {
    expect(createDeadline({ dataEntregaRealizada: '2026-10-08', entregueNoPrazo: true }).label).toBe('NO PRAZO')
    expect(createDeadline({ dataEntregaRealizada: '2026-10-09', entregueNoPrazo: false }).label).toBe('ATRASADO')
    expect(createDeadline({ dataEntregaRealizada: null, entregueNoPrazo: null, status: 'EM_TRANSITO' }).label).toBe('em aberto')
  })
})

describe('createDeadline (entregue_no_prazo vem da API)', () => {
  it('sem data de entrega realizada: em aberto', () => {
    expect(createDeadline({ dataEntregaRealizada: null, entregueNoPrazo: null })).toMatchObject({ cls: 'prz-no', label: 'em aberto' })
  })
  it('no prazo', () => {
    expect(createDeadline({ dataEntregaRealizada: '2026-10-08', entregueNoPrazo: true })).toMatchObject({ cls: 'prz-ok', label: 'NO PRAZO' })
  })
  it('atrasado', () => {
    expect(createDeadline({ dataEntregaRealizada: '2026-10-09', entregueNoPrazo: false })).toMatchObject({ cls: 'prz-bad', label: 'ATRASADO' })
  })
})
