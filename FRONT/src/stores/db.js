import { reactive } from 'vue'
import { http } from '../services/http'
import { pushToast } from './ui'
import { parseCity, parseNum, toDec, dec, isPast, isValidCNPJ } from '../utils/format'
import { e164, isEmail } from '../utils/notifications'
import { isGranel, isFracionada, setGranelCodes, setFracionadaCodes, temFracionada } from '../utils/items'

const fresh = () => ({
  domains: { embalagens: [], tipos_veiculo: [], tipos_carga_antt: [] },
  products: [], packagings: [], anttCoefs: [], carriers: [], users: [], groups: [], groupsAdmin: [], groupCatalog: null, sellers: [],
  quotes: [], loads: [], dashboard: null,
  registrations: [], pendingCount: 0,
  notifPrefs: [], notifHistory: [],
  loading: {},
  loadError: {},
})

export const db = reactive(fresh())

let domainsPromise = null
export function resetDb() {
  Object.assign(db, fresh())
  domainsPromise = null
}

const fail = (error, errors = {}) => ({ ok: false, error, errors, status: 0 })

function upsert(list, item, atStart = false) {
  const i = list.findIndex((x) => x.id === item.id)
  if (i >= 0) list.splice(i, 1, item)
  else if (atStart) list.unshift(item)
  else list.push(item)
}

async function load(key, request, assign, { silent = false } = {}) {
  if (!silent) db.loading[key] = true
  const r = await request
  if (!silent) db.loading[key] = false
  if (r.ok) {
    delete db.loadError[key]
    assign(r.data, r.meta)
  } else if (!silent) {
    db.loadError[key] = r.error
    pushToast('err', r.error)
  }
  return r
}

function syncList(list, fresh) {
  const old = new Map(list.map((x) => [x.id, x]))
  return fresh.map((n) => {
    const o = old.get(n.id)
    return o && JSON.stringify(o) === JSON.stringify(n) ? o : n
  })
}

async function mutate(request, apply, reload, quiet) {
  const r = await request
  if (r.ok) {
    if (apply) await apply(r.data, r.meta)
    if (!quiet?.(r.data)) for (const a of r.meta?.alertas || []) pushToast('warn', a)
  } else if (r.status === 409 && reload) {
    reload()
  }
  return r
}

const withVersion = (list, id, body) => ({ ...body, versao: list.find((x) => x.id === id)?.versao })

export function loadDomains() {
  if (!domainsPromise) {
    domainsPromise = http.get('/dominios').then((r) => {
      if (r.ok) { db.domains = { tipos_carga_antt: [], ...r.data }; setGranelCodes(r.data.embalagens); setFracionadaCodes(r.data.embalagens) }
      else { domainsPromise = null; pushToast('err', r.error) }
      return r
    })
  }
  return domainsPromise
}

const label = (list, code) => (list ?? []).find((x) => x.codigo === code)?.descricao || code || '—'
export const embalagemLabel = (code) => label(db.domains.embalagens, code)
export const veiculoLabel = (code) => label(db.domains.tipos_veiculo, code)
export const tipoCargaLabel = (code) => label(db.domains.tipos_carga_antt, code)

export const loadProducts = (params) => load('products', http.get('/produtos', params), (d) => { db.products = d })

const factorsBody = (embalagens, fatores) =>
  Object.fromEntries(embalagens.filter((c) => !isGranel(c)).map((c) => [c, toDec(fatores?.[c], 3)]).filter(([, v]) => v))

const palletFactorsBody = (embalagens, fatores) =>
  Object.fromEntries(embalagens.filter((c) => isFracionada(c)).map((c) => [c, toDec(fatores?.[c], 3)]).filter(([, v]) => v))

export async function saveProduct({ nome, descricao = '', onu = '', risco = '', densidade = '', embalagens, fatores = {}, fatoresPalete = {} }, editId = null) {
  const n = String(nome ?? '').trim().toUpperCase()
  if (!n) return fail('Informe o nome do produto.')
  if (n.length < 3) return fail('O nome do produto deve ter pelo menos 3 letras.')
  if (!embalagens.length) return fail('Selecione ao menos uma embalagem.')
  const onuTxt = String(onu ?? '').trim()
  if (onuTxt && !/^\d{4}$/.test(onuTxt)) return fail('O número ONU deve ter 4 dígitos.')
  const naoNumerico = (mapa, cods) => cods.find((c) => String(mapa?.[c] ?? '').trim() && !/^\d[\d.,]*$/.test(String(mapa[c]).trim()))
  const textoVol = naoNumerico(fatores, embalagens.filter((c) => !isGranel(c)))
  if (textoVol) return fail(`O **kg por volume** de ${textoVol} deve ser um número.`)
  const textoPal = embalagens.some(isFracionada) && naoNumerico(fatoresPalete, embalagens.filter(isFracionada))
  if (textoPal) return fail(`O **kg por palete** de ${textoPal} deve ser um número.`)
  const body = { nome: n, descricao: String(descricao ?? '').trim() || null, onu: onuTxt || null, risco: String(risco ?? '').trim().toUpperCase() || null, densidade: String(densidade ?? '').trim().replace(',', '.') || null, embalagens: [...embalagens], fatores: factorsBody(embalagens, fatores), ...(embalagens.some(isFracionada) ? { fatores_palete: palletFactorsBody(embalagens, fatoresPalete) } : {}) }
  const req = editId ? http.patch(`/produtos/${editId}`, withVersion(db.products, editId, body)) : http.post('/produtos', body)
  return mutate(req, (p) => upsert(db.products, p), loadProducts)
}

export const setProductActive = (id, ativo) =>
  mutate(http.post(`/produtos/${id}/${ativo ? 'ativar' : 'inativar'}`), (p) => upsert(db.products, p), loadProducts)

const withId = (e) => ({ ...e, id: e.codigo })
export const loadPackagings = (params) => load('packagings', http.get('/embalagens', params), (d) => { db.packagings = d.map(withId) })

const packApply = async (e) => {
  upsert(db.packagings, withId(e))
  domainsPromise = null
  await loadDomains()
}

export async function savePackaging({ codigo, descricao, permite_paletizar, permite_remontar, granel, fracionada }, editId = null) {
  const d = String(descricao ?? '').trim()
  const c = String(codigo ?? '').trim().toUpperCase()
  if (!editId && !/^[A-Z0-9_]+$/.test(c)) return fail('Informe o código (letras, números e _).')
  if (!d) return fail('Informe a descrição da embalagem.')
  const body = { descricao: d, granel: !!granel, fracionada: !granel && !!fracionada, permite_paletizar: !granel && !!permite_paletizar, permite_remontar: !granel && !!permite_remontar }
  const req = editId
    ? http.patch(`/embalagens/${editId}`, withVersion(db.packagings, editId, body))
    : http.post('/embalagens', { codigo: c, ...body })
  return mutate(req, packApply, loadPackagings)
}

export const setPackagingActive = (id, ativo) =>
  mutate(http.post(`/embalagens/${id}/${ativo ? 'ativar' : 'inativar'}`), packApply, loadPackagings)

export const loadAnttCoefs = (params) => load('anttCoefs', http.get('/antt/coeficientes', params), (d) => { db.anttCoefs = d })

export async function saveAnttCoef({ tipo_carga_codigo, eixos, ccd, cc, vigencia_inicio, vigencia_fim }, editId = null) {
  if (!editId && (!tipo_carga_codigo || !eixos)) return fail('Escolha o **tipo de carga** e os **eixos**.')
  const nCcd = /^\d+\.\d+$/.test(String(ccd).trim()) ? parseFloat(ccd) : parseNum(ccd)
  const vCcd = nCcd ? nCcd.toFixed(4) : null
  if (!vCcd || !vigencia_inicio) return fail('Informe o **CCD** (R$/km) e o início da vigência.')
  const body = { ccd: vCcd, cc: toDec(cc) ?? '0.00', vigencia_inicio, vigencia_fim: vigencia_fim || null }
  const req = editId
    ? http.patch(`/antt/coeficientes/${editId}`, withVersion(db.anttCoefs, editId, body))
    : http.post('/antt/coeficientes', { tipo_carga_codigo, eixos: Number(eixos), ...body })
  return mutate(req, (c) => upsert(db.anttCoefs, c), loadAnttCoefs)
}

export const setAnttCoefActive = (id, ativo) =>
  mutate(http.post(`/antt/coeficientes/${id}/${ativo ? 'ativar' : 'inativar'}`), (c) => upsert(db.anttCoefs, c), loadAnttCoefs)

export async function findFreteMinimo(origem, destino, { eixos, tipoCarga } = {}) {
  const o = parseCity(origem)
  const d = parseCity(destino)
  if (!o?.cidade || !o.uf || !d?.cidade || !d.uf) return null
  const r = await http.get('/antt/frete-minimo', { origem_cidade: o.cidade, origem_uf: o.uf, destino_cidade: d.cidade, destino_uf: d.uf, eixos: Number(eixos), tipo_carga_codigo: tipoCarga })
  return r.ok ? r.data ?? null : null
}

export async function findDistance(origem, destino) {
  const o = parseCity(origem)
  const d = parseCity(destino)
  if (!o?.cidade || !o.uf || !d?.cidade || !d.uf) return null
  const r = await http.get('/distancias/consulta', { origem_cidade: o.cidade, origem_uf: o.uf, destino_cidade: d.cidade, destino_uf: d.uf })
  return r.ok ? r.data?.km ?? null : null
}

export const loadCarriers = (params) => load('carriers', http.get('/transportadoras', params), (d) => { db.carriers = d })

const WPP_GROUP = /^\d{10,25}@g\.us$/

export async function saveCarrier({ razao_social, nome_fantasia, cnpj = '', email = '', telefone = '', whatsapp_grupo_id = '' }, editId = null) {
  const razao = String(razao_social ?? '').trim()
  const fantasia = String(nome_fantasia ?? '').trim().toUpperCase()
  if (!razao || !fantasia) return fail('Preencha a **razão social** e o **nome fantasia**.')
  if (fantasia.length < 2 || razao.length < 2) return fail('O **nome fantasia** e a **razão social** devem ter pelo menos 2 letras.')
  const digits = String(cnpj).replace(/\D/g, '')
  if (digits && !isValidCNPJ(digits)) return fail('CNPJ inválido. Confira os dígitos.')
  if (String(telefone ?? '').trim() && !e164(telefone)) return fail('Informe um **telefone com DDD**, por exemplo (19) 99999-0001.')
  const grupo = String(whatsapp_grupo_id ?? '').trim()
  if (grupo && !WPP_GROUP.test(grupo)) return fail('Informe o **ID do grupo** de WhatsApp no formato 120363123456789012@g.us.')
  const body = {
    razao_social: razao, nome_fantasia: fantasia, cnpj: String(cnpj).replace(/\D/g, '') || null,
    email: String(email).trim() || null, telefone: String(telefone).trim() || null, whatsapp_grupo_id: grupo || null,
  }
  const req = editId ? http.patch(`/transportadoras/${editId}`, withVersion(db.carriers, editId, body)) : http.post('/transportadoras', body)
  return mutate(req, (c) => upsert(db.carriers, c), loadCarriers)
}

export const setCarrierActive = (id, ativo) =>
  mutate(http.post(`/transportadoras/${id}/${ativo ? 'ativar' : 'inativar'}`), (c) => upsert(db.carriers, c), loadCarriers)

export const loadUsers = (params) => load('users', http.get('/usuarios', params), (d) => { db.users = d })
export const loadGroups = () => load('groups', http.get('/grupos'), (d) => { db.groups = d })

export const loadGroupsAdmin = () => load('groupsAdmin', http.get('/grupos/detalhes'), (d) => { db.groupsAdmin = d })
export const loadGroupCatalog = () => load('groupCatalog', http.get('/grupos/catalogo'), (d) => { db.groupCatalog = d })

const OPS = ['ver', 'inserir', 'alterar', 'cancelar']
const CODIGO_GRUPO = /^[A-Z][A-Z0-9_]{2,29}$/

export async function saveGroup({ codigo, nome, descricao = '', tipo_usuario, perms = {}, acoes = [] }, editId = null) {
  const cod = String(codigo ?? '').trim()
  const nom = String(nome ?? '').trim()
  if (!editId && !CODIGO_GRUPO.test(cod)) return fail('O **código** deve ter de 3 a 30 caracteres: letras maiúsculas, números e sublinhado, começando por letra.')
  if (nom.length < 3) return fail('O **nome** do grupo deve ter pelo menos 3 letras.')
  const usaEscopo = new Map((db.groupCatalog?.recursos ?? []).map((r) => [r.codigo, r.usa_escopo]))
  const permissoes = {}
  for (const [rec, p] of Object.entries(perms)) {
    if (!OPS.some((o) => p?.[o])) continue
    permissoes[rec] = Object.fromEntries(OPS.map((o) => [o, !!p[o]]))
    if (usaEscopo.get(rec) !== false) permissoes[rec].escopo = p.escopo || (tipo_usuario === 'TRANSPORTADORA' ? 'TRANSPORTADORA' : 'PROPRIOS')
  }
  if (!Object.keys(permissoes).length) return fail('Conceda ao menos uma **permissão** ao grupo.')
  const acoesOk = acoes.filter((a) => permissoes[a.split('.')[0]]?.ver)
  const body = { nome: nom, descricao: String(descricao ?? '').trim() || null, permissoes, acoes: acoesOk }
  if (!editId) Object.assign(body, { codigo: cod, tipo_usuario })
  const req = editId ? http.patch(`/grupos/${editId}`, withVersion(db.groupsAdmin, editId, body)) : http.post('/grupos', body)
  return mutate(req, (g) => { upsert(db.groupsAdmin, g); loadGroups() }, loadGroupsAdmin)
}

export const setGroupActive = (id, ativo) =>
  mutate(http.post(`/grupos/${id}/${ativo ? 'ativar' : 'inativar'}`), (g) => { upsert(db.groupsAdmin, g); loadGroups() }, loadGroupsAdmin)
export const loadSellers = () => load('sellers', http.get('/vendedores'), (d) => { db.sellers = d })

export async function saveUser({ nome, login, email = '', telefone = '', senha = '', grupos, transportadora_id = '' }, editId = null) {
  if (!String(nome ?? '').trim() || !String(login ?? '').trim()) return fail('Preencha **nome** e **login**.')
  const loginTxt = String(login).trim().toLowerCase()
  if (loginTxt.length < 3 || loginTxt.length > 200 || !/^[a-z0-9._@+-]+$/.test(loginTxt)) {
    return fail('O **login** deve ter de 3 a 200 caracteres, só com letras, números, ponto, hífen, sublinhado ou @ (sem espaços).')
  }
  if (!grupos.length) return fail('Selecione ao menos um grupo de acesso.')
  if (String(telefone ?? '').trim() && !e164(telefone)) return fail('Informe um **telefone com DDD**, por exemplo (19) 99999-0001.')
  if (!editId && !senha) return fail('Informe a senha do novo usuário.')
  if (senha && String(senha).length < 8) return fail('A senha deve ter pelo menos 8 caracteres.')
  const isCarrier = grupos.some((g) => db.groups.find((x) => x.codigo === g)?.tipo_usuario === 'TRANSPORTADORA')
  if (isCarrier && !transportadora_id) return fail('Usuário de transportadora precisa estar vinculado a uma **transportadora**.')
  const body = {
    nome: String(nome).trim(), login: String(login).trim().toLowerCase(), email: String(email).trim() || null,
    telefone: String(telefone ?? '').replace(/\D+/g, '') || null, grupos: [...grupos], transportadora_id: isCarrier ? transportadora_id : null,
  }
  if (senha) body.senha = senha
  const req = editId ? http.patch(`/usuarios/${editId}`, withVersion(db.users, editId, body)) : http.post('/usuarios', body)
  return mutate(req, (u) => upsert(db.users, u), loadUsers)
}

export const setUserBlocked = (id, blocked) =>
  mutate(http.post(`/usuarios/${id}/${blocked ? 'bloquear' : 'ativar'}`), (u) => upsert(db.users, u), loadUsers)

export const searchParties = (termo, tipo) => http.get('/partes', { termo, tipo })

export async function createParty(tipo, { razao_social, nome_fantasia = '', cnpj = '', cidade = '' }) {
  const razao = String(razao_social ?? '').trim()
  const digits = String(cnpj).replace(/\D/g, '')
  if (!razao || digits.length !== 14) return fail('Informe a **razão social** e um **CNPJ** válido (14 dígitos).')
  if (!isValidCNPJ(digits)) return fail('CNPJ inválido. Confira os dígitos.')
  const c = parseCity(cidade)
  const body = {
    razao_social: razao, nome_fantasia: String(nome_fantasia).trim() || null, cnpj: digits,
    cidade: c?.cidade ?? null, uf: c?.uf ?? null,
  }
  return mutate(http.post(tipo === 'FORNECEDOR' ? '/fornecedores' : '/clientes', body))
}

export const loadQuotes = (params, opts) => load('quotes', http.get('/cotacoes', params), (d) => { db.quotes = syncList(db.quotes, d) }, opts)

export async function findQuoteByCode(codigo) {
  const r = await http.get('/cotacoes', { codigo })
  const q = r.ok ? r.data.find((x) => x.codigo === codigo) : null
  if (q) upsert(db.quotes, q)
  return q ?? null
}

export async function loadQuote(id) {
  const r = await http.get(`/cotacoes/${id}`)
  if (r.ok) {
    const cur = db.quotes.find((x) => x.id === r.data.id)
    if (!cur || JSON.stringify(cur) !== JSON.stringify(r.data)) upsert(db.quotes, r.data)
  }
  return r
}

export async function findPreviousQuote({ veiculo, origem, destino, produtoIds = [] }) {
  const o = parseCity(origem)
  const d = parseCity(destino)
  const ids = [...new Set(produtoIds.filter(Boolean))]
  if (!veiculo || !o?.cidade || !o.uf || !d?.cidade || !d.uf || !ids.length) return null
  const r = await http.get('/cotacoes/anterior', {
    tipo_veiculo_codigo: veiculo, origem_cidade: o.cidade, origem_uf: o.uf, destino_cidade: d.cidade, destino_uf: d.uf, produto_ids: ids.join(','),
  })
  return r.ok ? r.data ?? null : null
}

const versaoResposta = (id, respId) => db.quotes.find((q) => q.id === id)?.negociacao?.respostas?.find((r) => r.id === respId)?.versao

const applyQuote = (id) => async (data) => {
  if (data && data.id === id && data.itens) upsert(db.quotes, data)
  else await loadQuote(id)
}

const semAcento = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '')
const buildItems = (itens, flags = false) =>
  (itens || []).map((i) => ({
    produto_id: i.produto_id, embalagem_codigo: i.embalagem, quantidade_kg: toDec(i.qtd, 3), volumes: isGranel(i.embalagem) ? null : parseNum(i.volumes) || null,
    ...(flags ? { paletizado: !!i.paletizado, remontado: !!i.remontado } : {}),
    ...(i.cotacao_item_id ? { cotacao_item_id: i.cotacao_item_id } : {}),
  }))
const LIMITES_COTACAO = { qtdKg: 999999999, valorNF: 100000000, distanciaKm: 10000 }
const qtdOk = (itens) => itens.every((i) => Number(i.quantidade_kg) >= 0.001 && Number(i.quantidade_kg) <= LIMITES_COTACAO.qtdKg)
const itemsOk = (itens) => itens.length && itens.every((i) => i.produto_id && i.embalagem_codigo && i.quantidade_kg)

function quoteBody(form) {
  const itens = buildItems(form.itens, true)
  const origem = parseCity(form.origem)
  const destino = parseCity(form.destino)
  const valorNf = toDec(form.valorNF)
  const semCarro = temFracionada(form.itens)
  if (!form.parte || (!semCarro && !form.veiculo) || !origem || !destino || !valorNf)
    return fail('Preencha os campos obrigatórios: **cliente/fornecedor**, tipo de carro, origem, destino e valor da NF.')
  if (semAcento(origem.cidade) === semAcento(destino.cidade) && origem.uf === destino.uf) return fail('O **destino** não pode ser igual à origem.')
  if (!itemsOk(itens)) return fail('Informe ao menos um item com produto, embalagem e **QTD (KG)**.')
  if (!qtdOk(itens)) return fail(`A **QTD (KG)** de cada item deve ser de 0,001 a ${LIMITES_COTACAO.qtdKg.toLocaleString('pt-BR')} kg.`)
  if (Number(valorNf) > LIMITES_COTACAO.valorNF) return fail(`O **valor da NF** deve ser de no máximo ${brl(LIMITES_COTACAO.valorNF)}.`)
  if (parseNum(form.km) > LIMITES_COTACAO.distanciaKm) return fail(`A **distância** deve ser de no máximo ${LIMITES_COTACAO.distanciaKm.toLocaleString('pt-BR')} km.`)
  const body = {
    operacao: form.operacao, parte: { tipo: form.parte.tipo, id: form.parte.id }, tipo_veiculo_codigo: semCarro ? null : form.veiculo,
    origem, destino, valor_nf: valorNf, observacao: String(form.observacao ?? '').trim() || null, itens,
  }
  if (form.tipoCarga) body.tipo_carga_antt_codigo = form.tipoCarga
  if (parseNum(form.km)) body.distancia_km = parseNum(form.km).toFixed(1)
  return { body }
}

export async function addQuote(form) {
  const { body, ...erro } = quoteBody(form)
  if (!body) return erro
  body.usar_anterior = form.usarAnterior !== false
  return mutate(http.post('/cotacoes', body), (q) => upsert(db.quotes, q, true), null, (q) => !!q?.cotacao_origem)
}

export async function updateQuote(id, form) {
  const { body, ...erro } = quoteBody(form)
  if (!body) return erro
  return mutate(http.patch(`/cotacoes/${id}`, withVersion(db.quotes, id, body)), applyQuote(id), () => loadQuote(id))
}

export const forwardQuote = (id, transportadoraIds) =>
  mutate(http.post(`/cotacoes/${id}/encaminhar`, { transportadora_ids: transportadoraIds }), applyQuote(id))

const preco = ({ valorTon, valorFrete, veiculo }, fracionada) => (fracionada ? { valor_frete: toDec(valorFrete), tipo_veiculo_codigo: veiculo || null } : { valor_tonelada: toDec(valorTon) })
const precoOk = (p, fracionada) => (fracionada ? !!p.valor_frete && !!p.tipo_veiculo_codigo : !!p.valor_tonelada)
const LIMITES = { valorTon: 100000, valorFrete: 10000000, prazo: 90, prazoColeta: 90, validadeDias: 365 }
const diasAte = (iso) => Math.ceil((new Date(`${String(iso).slice(0, 10)}T00:00:00`) - new Date().setHours(0, 0, 0, 0)) / 864e5)
const brl = (n) => n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
const coletaVazia = (v) => v === undefined || v === null || String(v).trim() === ''
const prazoColetaNum = (v) => (coletaVazia(v) ? null : Number(v))
function prazoColetaInvalido(v, fracionada) {
  if (coletaVazia(v)) return fracionada ? 'Informe o **prazo de coleta** em dias (obrigatório na cotação fracionada).' : null
  const dias = Number(v)
  if (!Number.isInteger(dias) || dias < 0 || dias > LIMITES.prazoColeta) return `O prazo de coleta deve ser de 0 a ${LIMITES.prazoColeta} dias.`
  return null
}
function precoForaDoLimite(p, fracionada, prazo, prazoColeta) {
  const valor = Number(fracionada ? p.valor_frete : p.valor_tonelada)
  const max = fracionada ? LIMITES.valorFrete : LIMITES.valorTon
  if (!(valor > 0) || valor > max) return `O valor ${fracionada ? 'do frete' : 'por tonelada'} deve ser maior que zero e de no máximo ${brl(max)}.`
  const dias = Number(prazo)
  if (!Number.isInteger(dias) || dias < 1 || dias > LIMITES.prazo) return `O prazo deve ser de 1 a ${LIMITES.prazo} dias.`
  return prazoColetaInvalido(prazoColeta, fracionada)
}
const precoMsg = (fracionada) => (fracionada ? 'Informe o valor do frete, o tipo de carro e o prazo em dias.' : 'Informe o valor por tonelada e o prazo em dias.')

export async function answerQuote(id, respId, { valorTon, valorFrete = '', veiculo = '', prazo, prazoColeta = '', validade = '', obs = '', eixos = '', tipoCarga = '', dedicado = false }, fracionada = false) {
  const p = preco({ valorTon, valorFrete, veiculo }, fracionada)
  if (!precoOk(p, fracionada) || !prazo) return fail(precoMsg(fracionada))
  const limite = precoForaDoLimite(p, fracionada, prazo, prazoColeta)
  if (limite) return fail(limite)
  if (validade && isPast(validade)) return fail('A validade da proposta não pode ser anterior a hoje.')
  if (validade && diasAte(validade) > LIMITES.validadeDias) return fail(`A validade da proposta pode ser de no máximo ${LIMITES.validadeDias} dias.`)
  const body = { ...p, prazo_dias: Number(prazo), prazo_coleta_dias: prazoColetaNum(prazoColeta), validade: validade ? String(validade).slice(0, 10) : null, observacao: String(obs).trim() || null }
  if (dedicado) body.dedicado = true
  if (eixos) body.eixos = Number(eixos)
  if (tipoCarga) body.tipo_carga_antt_codigo = tipoCarga
  const versao = versaoResposta(id, respId)
  if (versao !== undefined) body.versao = versao
  return mutate(http.post(`/cotacoes/${id}/respostas/${respId}/responder`, body), applyQuote(id), () => loadQuote(id))
}

export const declineQuote = (id, respId, observacao = '') =>
  mutate(http.post(`/cotacoes/${id}/respostas/${respId}/declinar`, { observacao: String(observacao).trim() || null, versao: versaoResposta(id, respId) }), applyQuote(id), () => loadQuote(id))

export async function closeQuote(id, f, fracionada = false) {
  const p = preco(f, fracionada)
  if (!f.transportadoraId) return fail('Escolha a transportadora vencedora.')
  const pedido = String(f.pedidoVenda ?? '').trim()
  if (!pedido) return fail('Informe o **número do pedido**.')
  if (pedido.length > 30) return fail('O número do pedido pode ter no máximo **30 caracteres**.')
  if (!precoOk(p, fracionada) || !f.prazo) return fail(precoMsg(fracionada))
  const limite = precoForaDoLimite(p, fracionada, f.prazo, f.prazoColeta)
  if (limite) return fail(limite)
  const body = {
    transportadora_id: f.transportadoraId, ...p, prazo_dias: Number(f.prazo), prazo_coleta_dias: prazoColetaNum(f.prazoColeta),
    observacao: String(f.obs ?? '').trim() || null,
    programacao: {
      pedido_venda: pedido, deposito: f.deposito || 'VIRTUAL', modalidade_frete: f.modalidade || 'CIF',
      data_coleta_prevista: f.coleta || null, data_entrega_prevista: f.entrega || null,
    },
  }
  if (f.eixos) body.eixos = Number(f.eixos)
  if (f.tipoCarga) body.tipo_carga_antt_codigo = f.tipoCarga
  if (f.porFora) body.negociado_por_fora = true
  return mutate(http.post(`/cotacoes/${id}/fechar`, body), applyQuote(id))
}

export async function cancelQuote(id, motivo = '') {
  if (!String(motivo).trim()) return fail('Informe o motivo do cancelamento.')
  return mutate(http.post(`/cotacoes/${id}/cancelar`, { motivo: String(motivo).trim() }), applyQuote(id))
}

export const encerrarQuote = (id, motivo = '') =>
  mutate(http.post(`/cotacoes/${id}/encerrar`, { motivo: String(motivo).trim() || null }), applyQuote(id))

export const loadLoads = (params, opts) => load('loads', http.get('/programacoes', params), (d) => { db.loads = syncList(db.loads, d) }, opts)

export async function findLoadByCode(codigo) {
  const r = await http.get('/programacoes', { codigo })
  const l = r.ok ? r.data.find((x) => x.codigo === codigo) : null
  if (l) upsert(db.loads, l)
  return l ?? null
}

async function loadLoad(id) {
  const r = await http.get(`/programacoes/${id}`)
  if (r.ok) upsert(db.loads, r.data)
  return r
}
const applyLoad = (id) => async (data) => {
  if (data && data.id === id && data.itens) upsert(db.loads, data)
  else await loadLoad(id)
}

export async function saveLoad(f, editId = null) {
  if (!f.parte) return fail('Informe o Cliente / Fornecedor.')
  const coletaMsg = prazoColetaInvalido(f.prazoColeta, false)
  if (coletaMsg) return fail(coletaMsg)
  const pedido = String(f.pedidoVenda ?? '').trim()
  if (!editId && !pedido) return fail('Informe o **número do pedido**.')
  if (pedido.length > 30) return fail('O número do pedido pode ter no máximo **30 caracteres**.')
  const prazoTxt = String(f.prazo ?? '').trim()
  if (prazoTxt && (!Number.isInteger(Number(prazoTxt)) || Number(prazoTxt) < 1)) return fail('O **prazo de entrega** deve ser de pelo menos 1 dia.')
  if (!editId && f.coleta && isPast(f.coleta)) return fail('A **coleta prevista** deve ser de hoje em diante.')
  const itens = buildItems(f.itens)
  if (!itemsOk(itens)) return fail('Informe ao menos um item com produto, embalagem e **QTD (KG)**.')
  const body = {
    operacao: f.operacao, data_pedido: f.dataPedido || null, pedido_venda: pedido || null,
    nfe_numero: String(f.nfeNumero ?? '').trim() || null,
    representante_id: f.representanteId || null, parte: { tipo: f.parte.tipo, id: f.parte.id },
    deposito: f.deposito, modalidade_frete: f.modalidade, transportadora_id: f.transportadoraId || null,
    tipo_veiculo_codigo: f.veiculo || null, origem: parseCity(f.origem), destino: parseCity(f.destino),
    valor_tonelada: toDec(f.valorTon), prazo_dias: f.prazo ? Number(f.prazo) : null, prazo_coleta_dias: prazoColetaNum(f.prazoColeta),
    data_coleta_prevista: f.coleta || null, data_entrega_prevista: f.entrega || null,
    observacao: String(f.observacao ?? '').trim() || null, itens,
  }
  const req = editId ? http.patch(`/programacoes/${editId}`, withVersion(db.loads, editId, body)) : http.post('/programacoes', body)
  return mutate(req, (l) => upsert(db.loads, l, true), loadLoads)
}

export const setLoadStatus = (id, status, data = null) =>
  mutate(http.post(`/programacoes/${id}/status`, { status, data }), applyLoad(id))

export const deliverLoad = (id) => mutate(http.post(`/programacoes/${id}/entregar`, {}), applyLoad(id))

export async function cancelLoad(id, motivo = '') {
  if (!String(motivo).trim()) return fail('Informe o motivo do cancelamento.')
  return mutate(http.post(`/programacoes/${id}/cancelar`, { motivo: String(motivo).trim() }), applyLoad(id))
}

export const loadDashboard = (params, opts) => load('dashboard', http.get('/painel', params), (d) => { db.dashboard = d }, opts)

export const loadRegistrations = (status = 'PENDENTE', opts) =>
  load('registrations', http.get('/cadastros', { status }), (d, meta) => {
    db.registrations = syncList(db.registrations, d)
    if (status === 'PENDENTE') db.pendingCount = meta?.total ?? d.length
  }, opts)

export const loadPendingCount = () =>
  load('pendingCount', http.get('/cadastros', { status: 'PENDENTE' }), (d, meta) => { db.pendingCount = meta?.total ?? d.length }, { silent: true })

const leaveQueue = (c) => {
  db.registrations = db.registrations.filter((x) => x.id !== c.id)
  db.pendingCount = Math.max(0, db.pendingCount - 1)
}

export async function approveRegistration(id, { grupo, modo = 'vincular', transportadora_id = '' }) {
  const body = { grupo }
  if (grupo === 'TRANSPORTADORA') {
    if (modo === 'criar') body.criar_transportadora = true
    else if (!transportadora_id) return fail('Selecione a transportadora para vincular.', { transportadora_id: ['Selecione a transportadora.'] })
    else body.transportadora_id = transportadora_id
  }
  return mutate(http.post(`/cadastros/${id}/aprovar`, body), leaveQueue, () => loadRegistrations('PENDENTE'))
}

export async function rejectRegistration(id, motivo) {
  const m = String(motivo ?? '').trim()
  if (m.length < 3) return fail('Informe o motivo da recusa.', { motivo: ['Informe o motivo da recusa.'] })
  return mutate(http.post(`/cadastros/${id}/recusar`, { motivo: m }), leaveQueue, () => loadRegistrations('PENDENTE'))
}

const semVazios = (o) => Object.fromEntries(Object.entries(o ?? {}).filter(([, v]) => v))

export const loadNotifPrefs = () => load('notifPrefs', http.get('/notificacoes/preferencias'), (d) => { db.notifPrefs = d })

export const loadNotifHistory = (params, opts) =>
  load('notifHistory', http.get('/notificacoes/historico', semVazios(params)), (d) => { db.notifHistory = d }, opts)

export async function saveNotifPrefs(changes) {
  if (!changes.length) return fail('Nenhuma alteração para salvar.')
  return mutate(http.post('/notificacoes/preferencias', { preferencias: changes }), (d) => { db.notifPrefs = d }, loadNotifPrefs)
}

export const resendNotif = (id) =>
  mutate(http.post(`/notificacoes/${id}/reenviar`), (n) => upsert(db.notifHistory, n), () => loadNotifHistory())

export async function sendNotifTest({ canal, destino }) {
  const d = String(destino ?? '').trim()
  if (canal === 'EMAIL' && !isEmail(d)) return fail('Informe um **e-mail** válido para o teste.')
  if (canal === 'WHATSAPP' && !e164(d)) return fail('Informe um **telefone com DDD**, por exemplo (19) 99999-0001.')
  return mutate(http.post('/notificacoes/teste', { canal, destino: d }), (n) => upsert(db.notifHistory, n, true))
}

const RECARGAS = { quotes: () => loadQuotes(), loads: () => loadLoads(), products: () => loadProducts(), packagings: () => loadPackagings(), carriers: () => loadCarriers(), users: () => loadUsers() }
export const reloadList = (key) => RECARGAS[key]?.()
