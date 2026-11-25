import { parseCity, parseNum } from './format'
import { temFracionada, temGranel } from './items'

export function validateQuote(form) {
  const e = {}
  if (!form.parte) e.parte = 'Escolha o ' + (form.operacao === 'RECEBIMENTO' ? 'fornecedor' : 'cliente') + '.'
  const itens = form.itens ?? []
  if (!itens.length || itens.some((i) => !i.produto_id || !i.embalagem || !parseNum(i.qtd))) e.itens = 'Informe produto, embalagem e QTD (KG) em todos os itens.'
  if (temGranel(itens) && itens.length > 1) e.itens = 'Granel aceita um produto só por cotação.'
  if (!temFracionada(itens) && !form.veiculo) e.veiculo = 'Escolha o tipo de carro.'
  if (!parseNum(form.valorNF)) e.valorNF = 'Informe o valor da NF.'
  const cidade = (texto, vazio) => (!parseCity(texto) ? vazio : !parseCity(texto).uf ? 'Escolha a cidade na lista.' : null)
  const eo = cidade(form.origem, 'Informe a cidade de origem.')
  const ed = cidade(form.destino, 'Informe a cidade de destino.')
  if (eo) e.origem = eo
  if (ed) e.destino = ed
  return e
}

export const FIELD_LABELS = { parte: 'cliente/fornecedor', itens: 'itens', veiculo: 'tipo de carro', valorNF: 'valor da NF', origem: 'origem', destino: 'destino' }

export function focusId(field, form, prefix = 'qc') {
  if (field === 'itens') {
    const k = (form.itens ?? []).findIndex((i) => !i.produto_id || !i.embalagem || !parseNum(i.qtd))
    const i = (form.itens ?? [])[Math.max(k, 0)] ?? {}
    const part = !i.produto_id ? 'prod' : !i.embalagem ? 'emb' : 'qtd'
    return `${prefix}-${part}-${Math.max(k, 0)}`
  }
  return { parte: 'qc-parte', veiculo: 'qc-veiculo', valorNF: 'qc-valor', origem: 'qc-origem', destino: 'qc-destino' }[field]
}
