import { db, addQuote, updateQuote, forwardQuote as forward, answerQuote, declineQuote as decline, closeQuote, cancelQuote as cancel, encerrarQuote as encerrar } from '../stores/db'
import { pushToast, closeModal } from '../stores/ui'
import { dec, fmtN, money } from '../utils/format'


const fail = (r) => (pushToast('err', r.error), r)
const find = (id) => db.quotes.find((q) => q.id === id)
const codeOf = (id) => find(id)?.codigo || ''
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`

export async function createQuote(form) {
  const r = await addQuote(form)
  if (!r.ok) return fail(r)
  const q = r.data
  const vol = q.itens.length > 1 ? ` · ${plural(q.itens.length, 'item', 'itens')}` : ''
  if (q.cotacao_origem && q.status === 'EM_NEGOCIACAO') {
    pushToast('ok', `Cotação **${q.codigo}** criada com as respostas da **${q.cotacao_origem.codigo}** (${fmtN(dec(q.quantidade_total_kg))} kg${vol}).`)
  } else {
    pushToast('ok', `Cotação **${q.codigo}** gerada (${fmtN(dec(q.quantidade_total_kg))} kg${vol}) — aguardando ação **Encaminhar**.`)
  }
  return r
}

export async function editQuote(id, form) {
  const r = await updateQuote(id, form)
  if (!r.ok) return fail(r)
  pushToast('ok', `Cotação **${codeOf(id)}** atualizada.`)
  return r
}

export async function forwardQuote(id, carrierIds, names = []) {
  if (!carrierIds.length) {
    pushToast('err', 'Selecione ao menos uma transportadora.')
    return { ok: false, error: 'Selecione ao menos uma transportadora.' }
  }
  const r = await forward(id, carrierIds)
  if (!r.ok) return fail(r)
  const who = names.length === 1 ? `**${names[0]}**` : `**${plural(carrierIds.length, 'transportadora', 'transportadoras')}**`
  pushToast('info', `Cotação **${codeOf(id)}** encaminhada para ${who}.`)
  return r
}

export async function respondQuote(id, respId, data) {
  const r = await answerQuote(id, respId, data, !!find(id)?.fracionada)
  if (!r.ok) return fail(r)
  const resp = find(id)?.negociacao?.respostas.find((x) => x.id === respId)
  pushToast('ok', `Resposta registrada para **${codeOf(id)}**${resp?.valor_frete ? ': ' + money(dec(resp.valor_frete)) : ''}.`)
  return r
}

export async function declineQuote(id, respId, obs = '') {
  const r = await decline(id, respId, obs)
  if (!r.ok) return fail(r)
  pushToast('warn', `Cotação **${codeOf(id)}** declinada.`)
  return r
}

export async function closeNegotiation(id, form) {
  const r = await closeQuote(id, form, !!find(id)?.fracionada)
  if (!r.ok) return fail(r)
  closeModal()
  const q = find(id)
  const winner = q?.negociacao?.fechamento?.transportadora?.nome_fantasia
  const crg = r.meta?.programacao?.codigo
  pushToast('ok', `Negociação **${codeOf(id)}** fechada${winner ? ' com **' + winner + '**' : ''}! ${crg ? 'Carga **' + crg + '**' : 'Carga'} adicionada à **Programação de Cargas**.`)
  return r
}

export async function cancelQuote(id, motivo) {
  const r = await cancel(id, motivo)
  if (!r.ok) return fail(r)
  closeModal()
  pushToast('warn', `Cotação **${codeOf(id)}** cancelada.`)
  return r
}

export async function encerrarQuote(id, motivo = '') {
  const r = await encerrar(id, motivo)
  if (!r.ok) return fail(r)
  closeModal()
  pushToast('info', `Cotação **${codeOf(id)}** encerrada sem fechamento. As respostas continuam registradas.`)
  return r
}
