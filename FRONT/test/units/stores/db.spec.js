import {
  db, resetDb, loadDomains, embalagemLabel, veiculoLabel,
  loadProducts, saveProduct, setProductActive, loadCarriers, saveCarrier, setCarrierActive,
  loadUsers, loadGroups, saveUser, setUserBlocked, searchParties, createParty,
  loadQuotes, reloadList, loadQuote, addQuote, forwardQuote, answerQuote, declineQuote, closeQuote, cancelQuote, encerrarQuote,
  loadLoads, saveLoad, setLoadStatus, deliverLoad, cancelLoad, loadDashboard,
} from '../../../src/stores/db'
import { toasts } from '../../../src/stores/ui'
import { setFracionadaCodes } from '../../../src/utils/items'
import { api, ok, err } from '../../helpers/fakeApi'
import { domains, product, carrier, parte, quote, resposta, load } from '../../helpers/fixtures'

vi.mock('../../../src/services/http', async () => (await import('../../helpers/fakeApi.js')).fakeHttpModule())

beforeEach(() => {
  api.reset()
  resetDb()
  toasts.splice(0)
})
const toastText = () => toasts.map((t) => t.parts.map((p) => p.text).join(''))

describe('domínios (uma vez)', () => {
  it('carrega /dominios uma única vez e resolve rótulos e capacidade', async () => {
    api.on('GET /dominios', ok(domains))
    await Promise.all([loadDomains(), loadDomains()])
    await loadDomains()
    expect(api.called('GET', '/dominios')).toHaveLength(1)
    expect(embalagemLabel('BIG_BAG')).toBe('Big bag')
    expect(embalagemLabel('XX')).toBe('XX')
    expect(veiculoLabel('CARRETA')).toBe('Carreta')
  })
  it('falha mostra toast e permite tentar de novo', async () => {
    api.on('GET /dominios', err(500, 'Erro no servidor.'))
    await loadDomains()
    expect(toastText()[0]).toBe('Erro no servidor.')
    api.on('GET /dominios', ok(domains))
    await loadDomains()
    expect(db.domains.embalagens).toHaveLength(3)
  })
})

describe('produtos', () => {
  it('loadProducts guarda a lista e repassa filtros', async () => {
    api.on('GET /produtos', ok([product()], { total: 1 }))
    await loadProducts({ ativo: 1 })
    expect(db.products).toHaveLength(1)
    expect(api.calls[0].query).toEqual({ ativo: 1 })
    expect(db.loading.products).toBe(false)
  })
  it('erro ao carregar vira toast', async () => {
    api.on('GET /produtos', err(403, 'Sem permissão.'))
    await loadProducts()
    expect(toastText()).toEqual(['Sem permissão.'])
  })
  it('saveProduct valida nome e embalagens sem chamar a API', async () => {
    expect((await saveProduct({ nome: ' ', embalagens: ['BIG_BAG'] })).ok).toBe(false)
    expect((await saveProduct({ nome: 'xyz', embalagens: [] })).error).toContain('embalagem')
    expect(api.calls).toHaveLength(0)
  })
  it('C3: kg por volume e por palete recusam texto; zero continua permitido e fica sem fator', async () => {
    for (const fatores of [{ BOMBONA: 'abc' }, { BOMBONA: '12abc' }, { BOMBONA: '1e5' }, { BOMBONA: '--' }]) {
      const r = await saveProduct({ nome: 'hcl', embalagens: ['BOMBONA'], fatores })
      expect(r.ok, JSON.stringify(fatores)).toBe(false)
      expect(r.error).toContain('kg por volume')
    }
    setFracionadaCodes([{ codigo: 'BOMBONA', fracionada: true }])
    const pal = await saveProduct({ nome: 'hcl', embalagens: ['BOMBONA'], fatores: { BOMBONA: '10' }, fatoresPalete: { BOMBONA: 'xx' } })
    setFracionadaCodes([])
    expect(pal.ok).toBe(false)
    expect(pal.error).toContain('kg por palete')
    expect(api.calls).toHaveLength(0)
    api.on('POST /produtos', ({ body }) => ok({ ...product(), ...body, id: 'p-new' }, {}, 201))
    expect((await saveProduct({ nome: 'hcl', embalagens: ['BOMBONA'], fatores: { BOMBONA: '0' } })).ok).toBe(true)
    expect(api.calls[0].body.fatores).toEqual({})
  })
  it('cria com nome em maiúsculas e densidade com ponto', async () => {
    api.on('POST /produtos', ({ body }) => ok({ ...product(), ...body, id: 'p-new' }, {}, 201))
    const r = await saveProduct({ nome: 'hcl', densidade: '1.19', embalagens: ['GRANEL_LIQUIDO'], fatores: {} })
    expect(r.ok).toBe(true)
    expect(api.calls[0].body).toEqual({ nome: 'HCL', descricao: null, onu: null, risco: null, densidade: '1.19', embalagens: ['GRANEL_LIQUIDO'], fatores: {} })
    expect(db.products.map((p) => p.id)).toEqual(['p-new'])
  })
  it('saveProduct envia fatores só de embalagens marcadas e não granel (vírgula vira ponto)', async () => {
    api.on('POST /produtos', ok(product(), {}, 201))
    await saveProduct({ nome: 'hcl', embalagens: ['BOMBONA', 'GRANEL_LIQUIDO'], fatores: { BOMBONA: '25,5', GRANEL_LIQUIDO: '9', BIG_BAG: '10' } })
    expect(api.calls[0].body.fatores).toEqual({ BOMBONA: '25.500' })
  })
  it('edita enviando a versao atual no PATCH e atualiza o cache', async () => {
    db.products = [product({ versao: 3 })]
    api.on('PATCH /produtos/:id', ({ params, body }) => ok({ ...product(), ...body, id: params.id, versao: body.versao + 1 }))
    const r = await saveProduct({ nome: 'soda', densidade: '', embalagens: ['GRANEL_LIQUIDO'] }, 'p-soda')
    expect(api.calls[0].body).toMatchObject({ versao: 3, densidade: null })
    expect(r.ok).toBe(true)
    expect(db.products).toHaveLength(1)
    expect(db.products[0]).toMatchObject({ nome: 'SODA', versao: 4 })
  })
  it('409 devolve o erro e recarrega a lista (versão atual)', async () => {
    db.products = [product({ versao: 1 })]
    api.on('PATCH /produtos/:id', err(409, 'Registro alterado por outro usuário.'))
    api.on('GET /produtos', ok([product({ versao: 2 })]))
    const r = await saveProduct({ nome: 'xyz', embalagens: ['GRANEL_LIQUIDO'] }, 'p-soda')
    expect(r).toMatchObject({ ok: false, status: 409, error: 'Registro alterado por outro usuário.' })
    await vi.waitFor(() => expect(db.products[0].versao).toBe(2))
  })
  it('inativar/ativar usa POST e atualiza a linha', async () => {
    db.products = [product()]
    api.on('POST /produtos/:id/inativar', ({ params }) => ok(product({ id: params.id, ativo: false })))
    api.on('POST /produtos/:id/ativar', ({ params }) => ok(product({ id: params.id, ativo: true })))
    await setProductActive('p-soda', false)
    expect(db.products[0].ativo).toBe(false)
    await setProductActive('p-soda', true)
    expect(db.products[0].ativo).toBe(true)
  })
})

describe('transportadoras e usuários', () => {
  it('saveCarrier normaliza (CNPJ só dígitos, fantasia em maiúsculas) e valida', async () => {
    expect((await saveCarrier({ razao_social: '', nome_fantasia: 'xx' })).ok).toBe(false)
    api.on('POST /transportadoras', ({ body }) => ok({ ...carrier(), ...body }, {}, 201))
    const r = await saveCarrier({ razao_social: 'ARCO LTDA', nome_fantasia: 'arco', cnpj: '11.222.333/0001-81', email: '', telefone: '' })
    expect(r.ok).toBe(true)
    expect(api.calls[0].body).toEqual({ razao_social: 'ARCO LTDA', nome_fantasia: 'ARCO', cnpj: '11222333000181', email: null, telefone: null, whatsapp_grupo_id: null })
    expect(db.carriers).toHaveLength(1)
  })
  it('saveCarrier envia o ID do grupo de WhatsApp (sem espaços) e recusa formato inválido sem chamar a API', async () => {
    for (const whatsapp_grupo_id of ['12345', '120363123456789012', 'abc@g.us', '5519999990001@s.whatsapp.net']) {
      const r = await saveCarrier({ razao_social: 'ARCO LTDA', nome_fantasia: 'arco', cnpj: '11.222.333/0001-81', whatsapp_grupo_id })
      expect(r.ok, whatsapp_grupo_id).toBe(false)
      expect(r.error).toContain('grupo')
    }
    expect(api.calls).toHaveLength(0)
    api.on('POST /transportadoras', ({ body }) => ok({ ...carrier(), ...body }, {}, 201))
    const r = await saveCarrier({ razao_social: 'ARCO LTDA', nome_fantasia: 'arco', cnpj: '11.222.333/0001-81', whatsapp_grupo_id: ' 120363123456789012@g.us ' })
    expect(r.ok).toBe(true)
    expect(api.calls[0].body.whatsapp_grupo_id).toBe('120363123456789012@g.us')
  })
  it('saveCarrier mostra o erro da Evolution (grupo não encontrado) como falha para o toast', async () => {
    api.on('POST /transportadoras', err(422, 'Grupo não encontrado na Evolution.'))
    const r = await saveCarrier({ razao_social: 'ARCO LTDA', nome_fantasia: 'arco', cnpj: '11.222.333/0001-81', whatsapp_grupo_id: '120363123456789012@g.us' })
    expect(r.ok).toBe(false)
    expect(r.error).toContain('Grupo não encontrado')
  })
  it('T21: saveCarrier e saveProduct recusam nome curto e telefone incompleto, sem chamar a API', async () => {
    const a = await saveCarrier({ razao_social: 'Trans Log Ltda', nome_fantasia: 'a', cnpj: '11.222.333/0001-81' })
    expect(a.ok).toBe(false)
    expect(a.error).toContain('nome fantasia')
    for (const telefone of ['(1', '123', '(19) 9999']) {
      const r = await saveCarrier({ razao_social: 'Trans Log Ltda', nome_fantasia: 'TL', cnpj: '11.222.333/0001-81', telefone })
      expect(r.ok, telefone).toBe(false)
      expect(r.error).toContain('telefone')
    }
    const p = await saveProduct({ nome: 'ab', embalagens: ['SACARIA'] })
    expect(p.ok).toBe(false)
    expect(p.error).toContain('3 letras')
    expect(api.calls).toHaveLength(0)
  })
  it('inativar transportadora', async () => {
    db.carriers = [carrier()]
    api.on('POST /transportadoras/:id/inativar', ({ params }) => ok(carrier({ id: params.id, ativo: false })))
    await setCarrierActive('t-arco', false)
    expect(db.carriers[0].ativo).toBe(false)
  })
  it('loadCarriers / loadUsers / loadGroups', async () => {
    api.on('GET /transportadoras', ok([carrier()]))
    api.on('GET /usuarios', ok([{ id: 'u1' }]))
    api.on('GET /grupos', ok([{ codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' }]))
    await Promise.all([loadCarriers(), loadUsers(), loadGroups()])
    expect([db.carriers.length, db.users.length, db.groups.length]).toEqual([1, 1, 1])
  })
  it('T12: saveUser recusa login com espaço/símbolo, curto demais e senha menor que 8, sem chamar a API', async () => {
    db.groups = [{ codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' }]
    const base = { nome: 'Ana', login: 'ana', senha: 'senha1234', grupos: ['VENDEDOR'] }
    for (const login of ['g min espaço!', 'com espaco', 'a#b', 'ab']) {
      const r = await saveUser({ ...base, login })
      expect(r.ok, login).toBe(false)
      expect(r.error).toContain('login')
    }
    for (const senha of ['abcd', 'abc1234']) {
      const r = await saveUser({ ...base, senha })
      expect(r.ok, senha).toBe(false)
      expect(r.error).toContain('8 caracteres')
    }
    expect(api.calls).toHaveLength(0)
    api.on('POST /usuarios', ({ body }) => ok({ id: 'u9', ...body }, {}, 201))
    expect((await saveUser(base)).ok).toBe(true)
  })
  it('saveUser: senha obrigatória na criação, transportadora para grupo de transportadora', async () => {
    db.groups = [
      { codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' },
      { codigo: 'TRANSPORTADORA', nome: 'Transportadora', tipo_usuario: 'TRANSPORTADORA' },
    ]
    expect((await saveUser({ nome: 'A', login: 'ana', grupos: ['VENDEDOR'] })).error).toContain('senha')
    expect((await saveUser({ nome: 'A', login: 'ana', senha: 'senha1234', grupos: [] })).error).toContain('grupo')
    expect((await saveUser({ nome: 'A', login: 'ana', senha: 'senha1234', grupos: ['TRANSPORTADORA'] })).error).toContain('transportadora')
    expect(api.calls).toHaveLength(0)
    api.on('POST /usuarios', ({ body }) => ok({ id: 'u1', situacao: 'ATIVO', ...body }, {}, 201))
    const r = await saveUser({ nome: ' Ana ', login: 'ANA', senha: 'senha1234', grupos: ['TRANSPORTADORA'], transportadora_id: 't-arco' })
    expect(r.ok).toBe(true)
    expect(api.calls[0].body).toEqual({ nome: 'Ana', login: 'ana', email: null, telefone: null, grupos: ['TRANSPORTADORA'], transportadora_id: 't-arco', senha: 'senha1234' })
  })
  it('saveUser: edição sem senha não envia o campo; grupo interno zera a transportadora', async () => {
    db.groups = [{ codigo: 'VENDEDOR', nome: 'Vendedor', tipo_usuario: 'INTERNO' }]
    db.users = [{ id: 'u1', versao: 2 }]
    api.on('PATCH /usuarios/:id', ({ body }) => ok({ id: 'u1', ...body }))
    await saveUser({ nome: 'A', login: 'ana', senha: '', grupos: ['VENDEDOR'], transportadora_id: 't-arco' }, 'u1')
    expect(api.calls[0].body).not.toHaveProperty('senha')
    expect(api.calls[0].body).toMatchObject({ versao: 2, transportadora_id: null })
  })
  it('bloquear e ativar usuário', async () => {
    db.users = [{ id: 'u1', situacao: 'ATIVO' }]
    api.on('POST /usuarios/:id/bloquear', ok({ id: 'u1', situacao: 'BLOQUEADO' }))
    api.on('POST /usuarios/:id/ativar', ok({ id: 'u1', situacao: 'ATIVO' }))
    await setUserBlocked('u1', true)
    expect(db.users[0].situacao).toBe('BLOQUEADO')
    await setUserBlocked('u1', false)
    expect(db.users[0].situacao).toBe('ATIVO')
  })
})

describe('partes', () => {
  it('searchParties repassa termo e tipo', async () => {
    api.on('GET /partes', ok([parte()]))
    const r = await searchParties('super', 'CLIENTE')
    expect(r.data).toHaveLength(1)
    expect(api.calls[0].query).toEqual({ termo: 'super', tipo: 'CLIENTE' })
  })
  it('createParty valida CNPJ, escolhe a rota pelo tipo e mostra os alertas', async () => {
    expect((await createParty('CLIENTE', { razao_social: 'X', cnpj: '123' })).ok).toBe(false)
    api.on('POST /fornecedores', ({ body }) => ok(parte({ tipo: 'FORNECEDOR', ...body }), { alertas: ['CNPJ já cadastrado.'] }, 201))
    const r = await createParty('FORNECEDOR', { razao_social: ' Forn ', cnpj: '11.222.333/0001-81', cidade: 'Campinas - SP' })
    expect(r.ok).toBe(true)
    expect(api.calls[0].body).toEqual({ razao_social: 'Forn', nome_fantasia: null, cnpj: '11222333000181', cidade: 'CAMPINAS', uf: 'SP' })
    expect(toastText()).toEqual(['CNPJ já cadastrado.'])
  })
})

const quoteForm = (o = {}) => ({
  operacao: 'CARREGAMENTO', parte: parte(), veiculo: 'CARRETA', valorNF: '15.000,00', observacao: '', origem: 'Paulínia - SP', destino: 'Campinas - SP',
  itens: [{ produto_id: 'p-soda', embalagem: 'BIG_BAG', qtd: '12.000', volumes: '10' }, { produto_id: 'p-hcl', embalagem: 'GRANEL_LIQUIDO', qtd: '8.000', volumes: '' }],
  ...o,
})

describe('cotações', () => {
  it('addQuote monta o payload do contrato com vários itens e insere no topo', async () => {
    db.quotes = [quote({ id: 'old' })]
    api.on('POST /cotacoes', ok(quote({ id: 'new' }), {}, 201))
    const r = await addQuote(quoteForm())
    expect(r.ok).toBe(true)
    expect(api.calls[0].body).toEqual({
      operacao: 'CARREGAMENTO', parte: { tipo: 'CLIENTE', id: 'c-1' }, tipo_veiculo_codigo: 'CARRETA',
      origem: { cidade: 'PAULÍNIA', uf: 'SP', ibge: null }, destino: { cidade: 'CAMPINAS', uf: 'SP', ibge: null },
      valor_nf: '15000.00', observacao: null,
      itens: [
        { produto_id: 'p-soda', embalagem_codigo: 'BIG_BAG', quantidade_kg: '12000.000', volumes: 10, paletizado: false, remontado: false },
        { produto_id: 'p-hcl', embalagem_codigo: 'GRANEL_LIQUIDO', quantidade_kg: '8000.000', volumes: null, paletizado: false, remontado: false },
      ],
      usar_anterior: true,
    })
    expect(db.quotes.map((q) => q.id)).toEqual(['new', 'old'])
  })
  it('C9: falha ao carregar a lista marca db.loadError e a recarga bem-sucedida limpa', async () => {
    api.on('GET /cotacoes', err(500, 'Erro no servidor. Tente novamente.'))
    await loadQuotes()
    expect(db.loadError.quotes).toBe('Erro no servidor. Tente novamente.')
    expect(db.quotes).toEqual([])
    api.on('GET /cotacoes', ok([quote({ id: 'q-1' })]))
    await reloadList('quotes')
    expect(db.loadError.quotes).toBeUndefined()
    expect(db.quotes).toHaveLength(1)
  })
  it('addQuote valida campos e itens antes de chamar a API', async () => {
    expect((await addQuote(quoteForm({ parte: null }))).error).toContain('obrigatórios')
    expect((await addQuote(quoteForm({ valorNF: '' }))).ok).toBe(false)
    expect((await addQuote(quoteForm({ itens: [] }))).error).toContain('item')
    expect((await addQuote(quoteForm({ itens: [{ produto_id: 'p', embalagem: 'GRANEL_LIQUIDO', qtd: '' }] }))).error).toContain('QTD')
    expect(api.calls).toHaveLength(0)
  })
  it('T06: addQuote recusa quantidade, valor da NF e distância fora dos limites, igual à API', async () => {
    const item = (qtd) => [{ produto_id: 'p-soda', embalagem: 'GRANEL_LIQUIDO', qtd, volumes: '' }]
    for (const qtd of ['0,0004', '0', '1.000.000.000,000', '999999999999']) {
      const r = await addQuote(quoteForm({ itens: item(qtd) }))
      expect(r.ok, `qtd ${qtd}`).toBe(false)
      expect(r.error).toContain('QTD')
    }
    for (const valorNF of ['', ' ', '0', '0,00', '1.000.000.000,00']) {
      const r = await addQuote(quoteForm({ valorNF }))
      expect(r.ok, `NF ${valorNF}`).toBe(false)
    }
    const km = await addQuote(quoteForm({ km: '99999999' }))
    expect(km.ok).toBe(false)
    expect(km.error).toContain('distância')
    expect(api.calls).toHaveLength(0)
    api.on('POST /cotacoes', ok(quote({ id: 'ok' }), {}, 201))
    expect((await addQuote(quoteForm({ itens: item('12.000'), valorNF: '15.000,00', km: '250,5' }))).ok).toBe(true)
    expect((await addQuote(quoteForm({ itens: item('999.999.999') }))).ok).toBe(true)
  })
  it('T07: addQuote recusa origem igual ao destino, sem chamar a API', async () => {
    const r = await addQuote(quoteForm({ origem: 'Paulínia - SP', destino: 'PAULINIA - sp' }))
    expect(r.ok).toBe(false)
    expect(r.error).toContain('destino')
    expect(api.calls).toHaveLength(0)
  })
  it('erro 422 da API é devolvido com errors', async () => {
    api.on('POST /cotacoes', err(422, 'Embalagem inválida.', { 'itens.0.embalagem_codigo': ['x'] }))
    const r = await addQuote(quoteForm())
    expect(r).toMatchObject({ ok: false, status: 422, error: 'Embalagem inválida.' })
    expect(r.errors).toHaveProperty('itens.0.embalagem_codigo')
  })
  it('loadQuotes / loadQuote (upsert)', async () => {
    api.on('GET /cotacoes', ok([quote()]))
    api.on('GET /cotacoes/:id', ({ params }) => ok(quote({ id: params.id, status: 'EM_NEGOCIACAO' })))
    await loadQuotes({ status: 'ABERTA' })
    await loadQuote('q-1')
    expect(db.quotes).toHaveLength(1)
    expect(db.quotes[0].status).toBe('EM_NEGOCIACAO')
  })
  it('forwardQuote envia os ids; sem cotação completa na resposta recarrega a cotação', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/encaminhar', ok(null, {}, 204))
    api.on('GET /cotacoes/:id', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: { id: 'n', status: 'ABERTA', respostas: [resposta()], fechamento: null } })))
    const r = await forwardQuote('q-1', ['t-arco', 't-via'])
    expect(r.ok).toBe(true)
    expect(api.called('POST', '/cotacoes/q-1/encaminhar')[0].body).toEqual({ transportadora_ids: ['t-arco', 't-via'] })
    expect(db.quotes[0].negociacao.respostas).toHaveLength(1)
  })
  it('forwardQuote usa a cotação devolvida pela API quando vier completa', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/encaminhar', ok(quote({ status: 'EM_NEGOCIACAO' })))
    await forwardQuote('q-1', ['t-arco'])
    expect(db.quotes[0].status).toBe('EM_NEGOCIACAO')
    expect(api.called('GET', '/cotacoes/q-1')).toHaveLength(0)
  })
  it('T04: responder e fechar recusam valor, prazo e validade fora dos limites, igual à API', async () => {
    api.on('POST /cotacoes/:id/respostas/:rid/responder', ok(quote()))
    api.on('POST /cotacoes/:id/fechar', ok(quote()))
    for (const valorTon of ['0', '-5', '1.000.000,00', '999999999999', 'abc']) {
      const r = await answerQuote('q-1', 'r-1', { valorTon, prazo: '3' })
      expect(r.ok, `valor ${valorTon}`).toBe(false)
    }
    for (const prazo of ['0', '-3', '366', '91']) {
      const r = await answerQuote('q-1', 'r-1', { valorTon: '200,00', prazo })
      expect(r.ok, `prazo ${prazo}`).toBe(false)
      expect(r.error).toContain('prazo')
    }
    const longe = new Date(Date.now() + 400 * 864e5).toISOString().slice(0, 10)
    const v = await answerQuote('q-1', 'r-1', { valorTon: '200,00', prazo: '3', validade: longe })
    expect(v.ok).toBe(false)
    expect(v.error).toContain('validade')
    expect((await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '1.000.000,00', prazo: '3' })).ok).toBe(false)
    expect((await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '200,00', prazo: '366' })).ok).toBe(false)
    expect(api.calls).toHaveLength(0)
    expect((await answerQuote('q-1', 'r-1', { valorTon: '200,00', prazo: '2' })).ok).toBe(true)
  })
  it('T11: responder e declinar enviam a versão da resposta; 409 recarrega a cotação', async () => {
    db.quotes = [quote({ status: 'EM_NEGOCIACAO', negociacao: { id: 'n', status: 'ABERTA', fechamento: null, respostas: [resposta({ versao: 4 })] } })]
    api.on('POST /cotacoes/:id/respostas/:r/responder', err(409, 'Registro alterado por outro usuário. Recarregue e tente novamente.'))
    api.on('POST /cotacoes/:id/respostas/:r/declinar', err(409, 'Registro alterado por outro usuário. Recarregue e tente novamente.'))
    api.on('GET /cotacoes/:id', ok(quote({ status: 'EM_NEGOCIACAO', negociacao: { id: 'n', status: 'ABERTA', fechamento: null, respostas: [resposta({ versao: 5 })] } })))

    const r = await answerQuote('q-1', 'r-1', { valorTon: '200,00', prazo: '2' })
    expect(r).toMatchObject({ ok: false, status: 409 })
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/responder')[0].body.versao).toBe(4)
    await new Promise((res) => setTimeout(res, 0))
    expect(api.called('GET', '/cotacoes/q-1')).toHaveLength(1)
    expect(db.quotes[0].negociacao.respostas[0].versao).toBe(5)

    await declineQuote('q-1', 'r-1')
    expect(api.called('POST', '/cotacoes/q-1/respostas/r-1/declinar')[0].body.versao).toBe(5)
  })
  it('answerQuote converte valor/t e valida', async () => {
    db.quotes = [quote()]
    expect((await answerQuote('q-1', 'r-1', { valorTon: '', prazo: '3' })).ok).toBe(false)
    api.on('POST /cotacoes/:id/respostas/:r/responder', ({ params }) => ok(quote({ id: params.id })))
    await answerQuote('q-1', 'r-1', { valorTon: '180,00', prazo: '3', validade: '', obs: '' })
    expect(api.calls[0].body).toEqual({ valor_tonelada: '180.00', prazo_dias: 3, prazo_coleta_dias: null, validade: null, observacao: null })
  })
  it('declineQuote', async () => {
    db.quotes = [quote()]
    api.on('POST /cotacoes/:id/respostas/:r/declinar', ({ params }) => ok(quote({ id: params.id })))
    expect((await declineQuote('q-1', 'r-1', 'sem carga')).ok).toBe(true)
    expect(api.calls[0].body).toEqual({ observacao: 'sem carga' })
  })
  it('closeQuote monta o fechamento com dados da programação', async () => {
    db.quotes = [quote({ status: 'EM_NEGOCIACAO' })]
    api.on('POST /cotacoes/:id/fechar', ({ params }) => ok(quote({ id: params.id, status: 'PROGRAMADA' }), { programacao: load() }))
    expect((await closeQuote('q-1', { transportadoraId: '', valorTon: '1', prazo: '1' })).ok).toBe(false)
    const r = await closeQuote('q-1', { transportadoraId: 't-arco', pedidoVenda: 'PV-1', valorTon: '175,00', prazo: '3', obs: 'telefone', deposito: 'VIRTUAL', modalidade: 'FOB', coleta: '2026-10-06', entrega: '' })
    expect(api.calls[0].body).toEqual({
      transportadora_id: 't-arco', valor_tonelada: '175.00', prazo_dias: 3, prazo_coleta_dias: null, observacao: 'telefone',
      programacao: { pedido_venda: 'PV-1', deposito: 'VIRTUAL', modalidade_frete: 'FOB', data_coleta_prevista: '2026-10-06', data_entrega_prevista: null },
    })
    expect(r.meta.programacao.codigo).toBe('CRG-000001')
    expect(db.quotes[0].status).toBe('PROGRAMADA')
  })
  it('encerrarQuote: motivo é opcional e a cotação passa a ENCERRADA', async () => {
    db.quotes = [quote({ status: 'EM_NEGOCIACAO' })]
    api.on('POST /cotacoes/:id/encerrar', ({ params }) => ok(quote({ id: params.id, status: 'ENCERRADA' })))
    await encerrarQuote('q-1', '  ')
    expect(api.calls[0].body).toEqual({ motivo: null })
    expect(db.quotes[0].status).toBe('ENCERRADA')
    await encerrarQuote('q-1', ' comprou de outro ')
    expect(api.calls[1].body).toEqual({ motivo: 'comprou de outro' })
  })
  it('cancelQuote exige motivo', async () => {
    db.quotes = [quote()]
    expect((await cancelQuote('q-1', '  ')).ok).toBe(false)
    api.on('POST /cotacoes/:id/cancelar', ({ params }) => ok(quote({ id: params.id, status: 'CANCELADA' })))
    await cancelQuote('q-1', 'cliente desistiu')
    expect(api.calls[0].body).toEqual({ motivo: 'cliente desistiu' })
    expect(db.quotes[0].status).toBe('CANCELADA')
  })
})

describe('programações', () => {
  const form = (o = {}) => ({
    operacao: 'CARREGAMENTO', dataPedido: '2026-10-05', pedidoVenda: 'PV-1', nfeNumero: '', representanteId: 'u-marcos', parte: parte(),
    deposito: 'FISICO', modalidade: 'CIF', transportadoraId: 't-arco', veiculo: 'CARRETA', origem: 'Paulínia - SP', destino: 'Campinas - SP',
    valorTon: '175,00', prazo: '3', coleta: '', entrega: '', observacao: '',
    itens: [{ produto_id: 'p-soda', embalagem: 'BIG_BAG', qtd: '20.000', volumes: '', cotacao_item_id: 'i-1' }], ...o,
  })

  it('saveLoad cria carga avulsa com itens e mostra meta.alertas em toast', async () => {
    api.on('POST /programacoes', ({ body }) => ok(load({ id: 'l-new' }), { alertas: ['Quantidade acima da cotada.'] }, 201))
    const r = await saveLoad(form())
    expect(r.ok).toBe(true)
    const body = api.calls[0].body
    expect(body).toMatchObject({
      representante_id: 'u-marcos', parte: { tipo: 'CLIENTE', id: 'c-1' }, transportadora_id: 't-arco', valor_tonelada: '175.00',
      deposito: 'FISICO', modalidade_frete: 'CIF', prazo_dias: 3, pedido_venda: 'PV-1',
    })
    expect(body.itens).toEqual([{ produto_id: 'p-soda', embalagem_codigo: 'BIG_BAG', quantidade_kg: '20000.000', volumes: null, cotacao_item_id: 'i-1' }])
    expect(db.loads[0].id).toBe('l-new')
    expect(toastText()).toEqual(['Quantidade acima da cotada.'])
  })
  it('saveLoad valida parte e itens; edição envia versao', async () => {
    expect((await saveLoad(form({ parte: null }))).error).toContain('Cliente')
    expect((await saveLoad(form({ itens: [] }))).error).toContain('item')
    db.loads = [load({ versao: 5 })]
    api.on('PATCH /programacoes/:id', ({ params }) => ok(load({ id: params.id, versao: 6 })))
    await saveLoad(form(), 'l-1')
    expect(api.calls[0].body.versao).toBe(5)
    expect(db.loads[0].versao).toBe(6)
  })
  it('status, entregar e cancelar', async () => {
    db.loads = [load()]
    api.on('POST /programacoes/:id/status', ({ params, body }) => ok(load({ id: params.id, status: body.status })))
    api.on('POST /programacoes/:id/entregar', ({ params }) => ok(load({ id: params.id, status: 'ENTREGUE', entregue_no_prazo: false })))
    api.on('POST /programacoes/:id/cancelar', ({ params }) => ok(load({ id: params.id, status: 'CANCELADO' })))
    await setLoadStatus('l-1', 'EM_TRANSITO', '2026-10-06')
    expect(api.calls[0].body).toEqual({ status: 'EM_TRANSITO', data: '2026-10-06' })
    expect(db.loads[0].status).toBe('EM_TRANSITO')
    await deliverLoad('l-1')
    expect(api.calls[1].body).toEqual({})
    expect(db.loads[0]).toMatchObject({ status: 'ENTREGUE', entregue_no_prazo: false })
    expect((await cancelLoad('l-1', '')).ok).toBe(false)
    await cancelLoad('l-1', 'erro de pedido')
    expect(db.loads[0].status).toBe('CANCELADO')
  })
  it('loadLoads e painel', async () => {
    api.on('GET /programacoes', ok([load()]))
    api.on('GET /painel', ok({ cotacoes_abertas: 3, por_status: {}, feed: [] }))
    await loadLoads({ status: 'EM_TRANSITO' })
    await loadDashboard()
    expect(db.loads).toHaveLength(1)
    expect(db.dashboard.cotacoes_abertas).toBe(3)
  })
})

describe('P0 da validação do MVP', () => {
  it('T01: saveCarrier recusa CNPJ com dígito verificador inválido, sem chamar a API', async () => {
    for (const cnpj of ['11.111.111/1111-11', '99.999.999/9999-99', '12.345.678/0001-00']) {
      const r = await saveCarrier({ razao_social: 'X LTDA', nome_fantasia: 'xx', cnpj })
      expect(r.ok).toBe(false)
      expect(r.error).toContain('CNPJ inválido')
    }
    expect(api.calls).toHaveLength(0)
  })
  it('T01: saveCarrier aceita CNPJ válido com e sem máscara e CNPJ vazio (revisão)', async () => {
    api.on('POST /transportadoras', ({ body }) => ok({ ...carrier(), ...body }, {}, 201))
    expect((await saveCarrier({ razao_social: 'A LTDA', nome_fantasia: 'aa', cnpj: '11.222.333/0001-81' })).ok).toBe(true)
    expect((await saveCarrier({ razao_social: 'B LTDA', nome_fantasia: 'bb', cnpj: '11444777000161' })).ok).toBe(true)
    expect((await saveCarrier({ razao_social: 'C LTDA', nome_fantasia: 'cc', cnpj: '' })).ok).toBe(true)
  })
  it('T01: createParty recusa CNPJ inválido, sem chamar a API', async () => {
    const r = await createParty('CLIENTE', { razao_social: 'X', cnpj: '12.345.678/0001-00' })
    expect(r.ok).toBe(false)
    expect(r.error).toContain('CNPJ inválido')
    expect(api.calls).toHaveLength(0)
  })
  it('T01: erro 422 de CNPJ vindo da API chega como mensagem para o toast', async () => {
    api.on('POST /transportadoras', err(422, 'CNPJ inválido.'))
    const r = await saveCarrier({ razao_social: 'A LTDA', nome_fantasia: 'aa', cnpj: '11.222.333/0001-81' })
    expect(r.ok).toBe(false)
    expect(r.error).toBe('CNPJ inválido.')
  })
  it('T02: entrega recusada pela API (data fora do intervalo) devolve a mensagem em português', async () => {
    db.loads = [load()]
    api.on('POST /programacoes/:id/entregar', err(422, 'A data não pode ser futura.'))
    const r = await deliverLoad('l-1')
    expect(r.ok).toBe(false)
    expect(r.error).toBe('A data não pode ser futura.')
  })
})
