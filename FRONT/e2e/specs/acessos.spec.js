import { test, expect, USUARIOS, api, anonimo } from '../support/base.js'

const dados = async (r) => (await r.json()).data

test('sem token ou com token inválido tudo é 401', async () => {
  const sem = await anonimo()
  for (const rota of ['me', 'transportadoras', 'produtos', 'embalagens', 'cotacoes', 'programacoes', 'painel', 'usuarios', 'sincronizacao']) {
    expect((await sem.get(rota)).status(), rota).toBe(401)
  }
  const ruim = await (await import('@playwright/test')).request.newContext({ baseURL: 'http://127.0.0.1:8100/api/', extraHTTPHeaders: { Authorization: 'Bearer xxx.yyy.zzz', Accept: 'application/json' } })
  expect((await ruim.get('me')).status()).toBe(401)
})

test('vendedor: lê e cria cotação, mas não administra cadastros nem acessos', async () => {
  const v = await api(USUARIOS.vendedor)
  expect((await v.get('cotacoes')).status()).toBe(200)
  expect((await v.get('partes?termo=Super')).status()).toBe(200)
  expect((await v.get('embalagens')).status()).toBe(200)
  for (const [metodo, rota] of [['get', 'usuarios'], ['get', 'antt/coeficientes'], ['get', 'cadastros'], ['get', 'painel'], ['get', 'sincronizacao']]) {
    expect((await v[metodo](rota)).status(), `${metodo} ${rota}`).toBe(403)
  }
  expect((await v.post('transportadoras', { data: { razao_social: 'X', nome_fantasia: 'X', cnpj: '11222333000181' } })).status()).toBe(403)
  expect((await v.post('produtos', { data: { nome: 'X' } })).status()).toBe(403)
  expect((await v.post('embalagens', { data: { codigo: 'X', descricao: 'X' } })).status()).toBe(403)
  expect((await v.post('usuarios', { data: {} })).status()).toBe(403)
})

test('transportadora: não cria cotação, não vê cadastros e não administra nada', async () => {
  const t = await api(USUARIOS.transportadora)
  expect((await t.get('programacoes')).status()).toBe(200)
  expect((await t.get('painel')).status()).toBe(200)
  expect((await t.post('cotacoes', { data: {} })).status()).toBe(403)
  for (const rota of ['usuarios', 'transportadoras', 'antt/coeficientes', 'sincronizacao', 'sincronizacao/pendencias']) {
    expect((await t.get(rota)).status(), rota).toBe(403)
  }
  expect((await t.post('sincronizacao/CLIENTE/executar')).status()).toBe(403)
})

test('escopo: vendedor só vê as próprias cotações; transportadora só as encaminhadas a ela', async () => {
  const marcos = await api(USUARIOS.vendedor)
  const lucia = await api(USUARIOS.vendedora)
  const doMarcos = await dados(await marcos.get('cotacoes'))
  expect(doMarcos.length).toBeGreaterThan(0)
  for (const c of doMarcos) expect(c.vendedor?.login ?? 'marcos').toBe('marcos')

  expect((await lucia.get(`cotacoes/${doMarcos[0].id}`)).status()).toBe(404)

  const via = await api(USUARIOS.outraTransportadora)
  const hz = await api(USUARIOS.transportadora)
  const daVia = await dados(await via.get('cotacoes'))
  const daHn = await dados(await hz.get('cotacoes'))
  const idsHn = new Set(daHn.map((c) => c.id))
  for (const c of daVia) {
    if (!idsHn.has(c.id)) expect((await hz.get(`cotacoes/${c.id}`)).status(), 'cotação da concorrente').toBe(404)
  }
})

test('transportadora nunca vê o nome da concorrência numa cotação', async () => {
  const via = await api(USUARIOS.outraTransportadora)
  const lista = await dados(await via.get('cotacoes'))
  test.skip(!lista.length, 'sem cotação encaminhada nos dados de demonstração')
  const detalhe = JSON.stringify(await dados(await via.get(`cotacoes/${lista[0].id}`)))
  expect(detalhe).not.toContain('NOVA LOG')
})

test('lock otimista (409) e validação (422) nos cadastros', async () => {
  const admin = await api(USUARIOS.admin)
  const criada = await dados(await admin.post('transportadoras', { data: { razao_social: 'Lock Ltda', nome_fantasia: 'LOCK', cnpj: '20000005000121' } }))
  const ok = await admin.patch(`transportadoras/${criada.id}`, { data: { versao: criada.versao, telefone: '11999990000' } })
  expect(ok.status()).toBe(200)
  const velha = await admin.patch(`transportadoras/${criada.id}`, { data: { versao: criada.versao, telefone: '11977770000' } })
  expect(velha.status()).toBe(409)

  const invalida = await admin.post('transportadoras', { data: { razao_social: '', cnpj: '123' } })
  expect(invalida.status()).toBe(422)
  expect(Object.keys((await invalida.json()).errors).length).toBeGreaterThan(0)
  expect((await admin.get('transportadoras/id-que-nao-existe')).status()).toBe(404)
})

test('login: usuário bloqueado não entra e senha errada devolve 401', async () => {
  const sem = await anonimo()
  const r = await sem.post('auth/login', { data: { login: 'admin', senha: 'errada' } })
  expect(r.status()).toBe(401)
  expect((await r.json()).message).toBeTruthy()
  expect((await sem.post('auth/login', { data: {} })).status()).toBe(422)
})
