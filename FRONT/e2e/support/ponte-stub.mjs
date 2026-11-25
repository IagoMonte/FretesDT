import http from 'node:http'

export const CHAVE = 'e2e-chave'
const PORTA = Number(process.env.PONTE_PORT || 8199)

const T = (cod, nom, ape, cnpj, extra = {}) => ({
  codigo: cod, razao_social: nom, nome_fantasia: ape, cnpj, cidade: 'SAO PAULO', uf: 'SP', telefone: '(11)4000-7100',
  email: 'FATURAMENTO@EXEMPLO.COM.BR', situacao: 'A', criado_em: '2019-06-26T00:00:00', alterado_em: '2026-08-10T00:00:00', ...extra,
})
const P = (cod, nom, ape, cnpj, cid, extra = {}) => ({
  codigo: cod, razao_social: nom, nome_fantasia: ape, cnpj, cidade: cid, uf: 'SP', situacao: 'A',
  criado_em: '2019-07-04T00:00:00', alterado_em: '2026-05-19T00:00:00', ...extra,
})

const INICIAL = () => ({
  transportadoras: [
    T(1, 'ALFA TRANSPORTES RODOVIARIOS LTDA', 'ALFA TRANSPORTES', 99887766000105, { cidade: 'SAO BERNARDO DO CAMPO' }),
    T(2, 'TRANSPORTADORA BETA LTDA', 'BETA', 77665544000105, { email: 'BETA@EXEMPLO.COM.BR' }),
    T(3, 'FANTASIA VAZIA TRANSPORTES LTDA', ' ', 11222333000181),
    T(4, 'SEM CNPJ TRANSPORTES', 'SEM CNPJ', 0),
    T(5, 'DUPLICADA DA ALFA', 'DUPLICADA', 99887766000105),
    T(6, 'INATIVA TRANSPORTES', 'INATIVA', 123456000149, { situacao: 'I' }),
  ],
  clientes: [
    P(1, 'USINA EXEMPLO S/A ACUCAR E ALCOOL', 'USINA EXEMPLO', 200001000180, 'VALPARAISO'),
    P(2, 'COMERCIAL MODELO LTDA', 'MODELO', 20000002000198, 'ARARAQUARA'),
    P(3, 'CLIENTE INATIVO LTDA', 'INATIVO', 11444777000161, 'CAMPINAS', { situacao: 'I' }),
    P(4, 'CLIENTE SEM CNPJ', ' ', 0, 'CAMPINAS'),
    P(5, 'SITUACAO ESTRANHA LTDA', 'ESTRANHA', 20000004000187, 'CAMPINAS', { situacao: 'X' }),
  ],
  fornecedores: [
    P(1, 'REPRESENTACOES EXEMPLO LTDA', 'REP EXEMPLO', 20000003000132, 'SANTANA DE PARNAIBA'),
    P(2, 'INDUSTRIA MODELO LTDA', 'QMODELO', 123456000149, 'BATATAIS'),
    P(3, 'FORNECEDOR SEM CNPJ', ' ', 0, 'BATATAIS'),
  ],
  produtos: [
    { codemp: 1, codigo: '0000001', descricao: 'SERVIÇO DE FRETE', situacao: 'A', criado_em: '2026-04-09T00:00:00', alterado_em: '2026-04-09T00:00:00' },
    { codemp: 1, codigo: '0000002', descricao: 'PRESTAÇÃO DE SERVIÇO EM GERAL', situacao: 'I', criado_em: '2026-04-09T00:00:00', alterado_em: '2026-04-09T00:00:00' },
    { codemp: 1, codigo: '100320093', descricao: 'SODA CAUSTICA LIQUIDA 50%', situacao: 'A', criado_em: '2026-10-09T00:00:00', alterado_em: '2026-10-09T00:00:00' },
    { codemp: 1, codigo: '100720001', descricao: 'ACIDO SULFURICO 98%', situacao: 'A', criado_em: '2026-10-07T00:00:00', alterado_em: '2026-10-07T00:00:00' },
    { codemp: 1, codigo: '100100001', descricao: 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA', situacao: 'A', criado_em: '2026-01-10T00:00:00', alterado_em: '2026-01-10T00:00:00' },
    { codemp: 1, codigo: '100100002', descricao: 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO BETA', situacao: 'A', criado_em: '2026-01-10T00:00:00', alterado_em: '2026-01-10T00:00:00' },
    { codemp: 1, codigo: '100100003', descricao: 'STK HIDROXIDO DE SODIO SOLUCAO 49%', situacao: 'A', criado_em: '2026-01-10T00:00:00', alterado_em: '2026-01-10T00:00:00' },
  ],
  embalagens: [
    { codigo: 4, descricao: 'CONTAINER(S)', abremb: 'CONTAINER(S)' },
    { codigo: 8, descricao: 'GRANEL', abremb: 'GRANEL' },
    { codigo: 16, descricao: 'BIG-BAG', abremb: 'BIG-BAG' },
    { codigo: 17, descricao: 'SACARIA', abremb: 'SACARIA' },
    { codigo: 18, descricao: 'BOMBONA', abremb: 'BOMBONA' },
    { codigo: 19, descricao: 'VOLUME', abremb: 'VOL' },
    { codigo: 20, descricao: 'TAMBOR', abremb: 'TAMB' },
  ],
})

const ENTIDADES = ['transportadoras', 'clientes', 'fornecedores', 'produtos', 'embalagens']

let dados = INICIAL()
let chamadas = []
let falha = false

const json = (res, status, corpo) => {
  res.writeHead(status, { 'Content-Type': 'application/json' })
  res.end(JSON.stringify(corpo))
}
const lerCorpo = (req) => new Promise((ok) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => ok(b ? JSON.parse(b) : {})) })

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x')
  const p = url.pathname

  if (p === '/__reset') { dados = INICIAL(); chamadas = []; falha = false; return json(res, 200, { ok: true }) }
  if (p === '/__set') { Object.assign(dados, await lerCorpo(req)); return json(res, 200, { ok: true }) }
  if (p === '/__falha') { falha = Boolean((await lerCorpo(req)).ligada); return json(res, 200, { falha }) }
  if (p === '/__calls') return json(res, 200, chamadas)
  if (p === '/__health') return json(res, 200, { ok: true })

  if (req.headers['x-api-key'] !== CHAVE) return json(res, 401, { error: 'chave invalida' })
  chamadas.push(p + url.search)
  if (falha) return json(res, 500, { error: 'ponte indisponivel (simulado)' })

  if (p === '/api/v1/status') {
    const agora = new Date().toISOString().slice(0, 19)
    return json(res, 200, ENTIDADES.map((r) => ({ entidade: r, modo: 'COMPLETA', status: 'SUCESSO', itens: dados[r].length, ultimoSucessoEm: agora })))
  }

  const m = p.match(/^\/api\/v1\/(\w+)$/)
  if (!m || !dados[m[1]]) return json(res, 404, { error: 'nao encontrado' })
  const rota = m[1]
  const q = url.searchParams
  let lista = dados[rota]

  if (q.has('codigo')) lista = lista.filter((x) => String(x.codigo) === q.get('codigo'))
  if (q.has('cnpj')) {
    if (!/^\d+$/.test(q.get('cnpj'))) return json(res, 400, { error: `For input string: "${q.get('cnpj')}"` })
    lista = lista.filter((x) => String(x.cnpj) === String(Number(q.get('cnpj'))))
  }
  if (q.has('alteradoDesde')) {
    const desde = q.get('alteradoDesde')
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(desde)) return json(res, 400, { error: 'alteradoDesde invalido, use ISO 2026-10-01T00:00:00' })
    if (rota !== 'embalagens') lista = lista.filter((x) => (x.alterado_em || '') >= desde)
  }

  const tamanho = Math.min(Number(q.get('tamanho') || 20), 200)
  const pagina = Number(q.get('pagina') || 0)
  const totalPaginas = Math.ceil(lista.length / tamanho)
  return json(res, 200, { itens: lista.slice(pagina * tamanho, pagina * tamanho + tamanho), pagina, tamanho, totalItens: lista.length, totalPaginas })
}).listen(PORTA, '127.0.0.1', () => console.log(`ponte falsa em http://127.0.0.1:${PORTA}`))
