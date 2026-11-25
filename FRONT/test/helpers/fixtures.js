const P = (ver, inserir = false, alterar = false, cancelar = false, escopo = 'TODOS') => ({ ver, inserir, alterar, cancelar, escopo })
const ALL = P(true, true, true, true)
const NONE = P(false)

export const meAdmin = {
  id: 'u-admin', nome: 'Administrador', login: 'admin', email: null, tipo_usuario: 'INTERNO', transportadora: null,
  grupos: ['ADMINISTRADOR'],
  permissoes: { PAINEL: ALL, COTACAO: ALL, NEGOCIACAO: ALL, PROGRAMACAO: ALL, PRODUTO: ALL, PARTE: ALL, TRANSPORTADORA: ALL, USUARIO: ALL },
  acoes: ['NEGOCIACAO.ENCAMINHAR', 'NEGOCIACAO.FECHAR', 'NEGOCIACAO.RESPONDER', 'PROGRAMACAO.CONFIRMAR_ENTREGA', 'USUARIO.APROVAR_CADASTRO'],
}

export const meSeller = {
  id: 'u-marcos', nome: 'MARCOS', login: 'marcos', email: null, tipo_usuario: 'INTERNO', transportadora: null,
  grupos: ['VENDEDOR'],
  permissoes: {
    PAINEL: NONE, COTACAO: P(true, true, true, true, 'PROPRIOS'), NEGOCIACAO: P(true, false, false, false, 'PROPRIOS'),
    PROGRAMACAO: P(true, false, false, false, 'PROPRIOS'), PRODUTO: NONE, PARTE: P(true, true), TRANSPORTADORA: NONE, USUARIO: NONE,
  },
  acoes: [],
}

export const meCarrier = {
  id: 'u-arco', nome: 'Operador ARCO', login: 'arco', email: null, tipo_usuario: 'TRANSPORTADORA',
  transportadora: { id: 't-arco', nome_fantasia: 'ARCO' },
  grupos: ['TRANSPORTADORA'],
  permissoes: {
    PAINEL: P(true, false, false, false, 'TRANSPORTADORA'), COTACAO: P(true, false, false, false, 'TRANSPORTADORA'), NEGOCIACAO: P(true, false, false, false, 'TRANSPORTADORA'),
    PROGRAMACAO: P(true, false, true, false, 'TRANSPORTADORA'), PRODUTO: NONE, PARTE: NONE, TRANSPORTADORA: NONE, USUARIO: NONE,
  },
  acoes: ['NEGOCIACAO.RESPONDER', 'PROGRAMACAO.CONFIRMAR_ENTREGA'],
}

export const domains = {
  embalagens: [
    { codigo: 'BIG_BAG', descricao: 'Big bag', permite_paletizar: true, permite_remontar: false, granel: false },
    { codigo: 'GRANEL_LIQUIDO', descricao: 'Granel líquido', permite_paletizar: false, permite_remontar: false, granel: true },
    { codigo: 'BOMBONA', descricao: 'Bombona', permite_paletizar: true, permite_remontar: true, granel: false },
  ],
  tipos_veiculo: [
    { codigo: 'TRUCK', descricao: 'Truck' },
    { codigo: 'CARRETA', descricao: 'Carreta' },
    { codigo: 'TOCO', descricao: 'Toco' },
    { codigo: 'PICAPE', descricao: 'Picape' },
    { codigo: 'VUC_VAN', descricao: 'Vuc/Van' },
    { codigo: 'TOCO_34', descricao: 'Toco 3/4' },
    { codigo: 'CARRETA_LS', descricao: 'Carreta LS' },
    { codigo: 'RODOTREM', descricao: 'Rodotrem' },
    { codigo: 'PRANCHA', descricao: 'Prancha' },
  ],
}

export const product = (o = {}) => ({
  id: 'p-soda', nome: 'SODA CÁUSTICA', descricao: null, onu: null, risco: null, densidade: '1.520', embalagens: ['BIG_BAG', 'GRANEL_LIQUIDO'],
  codigo_erp: null, origem_cadastro: 'MANUAL', ativo: true, versao: 0, ...o,
})

export const carrier = (o = {}) => ({
  id: 't-arco', razao_social: 'ARCO TRANSPORTES LTDA', nome_fantasia: 'ARCO', cnpj: '11222333000181',
  email: 'arco@arco.com', telefone: '11999990000', ativo: true, versao: 0, ...o,
})

export const parte = (o = {}) => ({
  tipo: 'CLIENTE', id: 'c-1', razao_social: 'ALFAQUIM INDÚSTRIA QUÍMICA', nome_fantasia: 'ALFAQUIM', cnpj: '11222333000181',
  cidade: 'CAMPINAS', uf: 'SP', codigo_erp: null, ativo: true, ...o,
})

export const resposta = (o = {}) => ({
  id: 'r-1', transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, status: 'AGUARDANDO', encaminhado_em: '2026-10-05T14:03:00.000-03:00',
  valor_tonelada: null, valor_frete: null, prazo_dias: null, validade: null, observacao: null, respondido_em: null, ...o,
})

export const quote = (o = {}) => ({
  id: 'q-1', codigo: 'COT-000001', data_solicitacao: '2026-10-05', status: 'ABERTA', operacao: 'CARREGAMENTO',
  vendedor: { id: 'u-marcos', nome: 'MARCOS' }, parte: parte(), tipo_veiculo_codigo: 'CARRETA',
  origem: { ibge: null, cidade: 'PAULÍNIA', uf: 'SP' }, destino: { ibge: null, cidade: 'CAMPINAS', uf: 'SP' },
  valor_nf: '15000.00', observacao: null,
  itens: [
    { id: 'i-1', sequencia: 1, produto: { id: 'p-soda', nome: 'SODA CÁUSTICA' }, embalagem_codigo: 'BIG_BAG', quantidade_kg: '12000.000', volumes: 10 },
    { id: 'i-2', sequencia: 2, produto: { id: 'p-hcl', nome: 'HCL' }, embalagem_codigo: 'GRANEL_LIQUIDO', quantidade_kg: '8000.000', volumes: null },
  ],
  quantidade_total_kg: '20000.000',
  negociacao: { id: 'n-1', status: 'ABERTA', respostas: [], fechamento: null },
  programacoes: [], versao: 0, ...o,
})

export const load = (o = {}) => ({
  id: 'l-1', codigo: 'CRG-000001', status: 'AGUARDANDO_CARREGAMENTO', operacao: 'CARREGAMENTO', cotacao: null,
  data_pedido: '2026-10-05', pedido_venda: 'PV-1001', nfe_numero: null, nfe_serie: null, nfe_chave: null,
  representante: { id: 'u-marcos', nome: 'MARCOS' }, parte: parte(), deposito: 'VIRTUAL', modalidade_frete: 'CIF',
  transportadora: { id: 't-arco', nome_fantasia: 'ARCO' }, tipo_veiculo_codigo: 'CARRETA',
  origem: { ibge: null, cidade: 'PAULÍNIA', uf: 'SP' }, destino: { ibge: null, cidade: 'CAMPINAS', uf: 'SP' },
  valor_tonelada: '175.00', valor_total: '3500.00', prazo_dias: 3,
  data_coleta_prevista: '2026-10-06', data_coleta_realizada: null, data_entrega_prevista: '2026-10-08', data_entrega_realizada: null,
  entregue_no_prazo: null, observacao: null,
  itens: [{ id: 'li-1', sequencia: 1, cotacao_item_id: null, produto: { id: 'p-soda', nome: 'SODA CÁUSTICA' }, embalagem_codigo: 'BIG_BAG', quantidade_kg: '20000.000', volumes: null }],
  quantidade_total_kg: '20000.000', versao: 0, ...o,
})
