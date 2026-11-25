import { spawnSync } from 'node:child_process'
import { back, env } from '../support/env.mjs'
import { test, expect, USUARIOS, api, anonimo, ponte, ponteDados, chamadasDaPonte, entrar, irPara, foto } from '../support/base.js'

test.describe.configure({ mode: 'serial' })

let admin
let vendedor

const dados = async (r) => (await r.json()).data

/** Dispara a execução e espera a trava soltar (a entidade inteira roda depois da resposta). */
async function sincronizar(entidade, { completa = false } = {}) {
  const r = await admin.post(`sincronizacao/${entidade}/executar`, { data: completa ? { completa: true } : {} })
  expect(r.status(), `executar ${entidade}`).toBe(202)
  for (let i = 0; i < 60; i++) {
    const linha = (await dados(await admin.get('sincronizacao'))).find((l) => l.entidade === entidade)
    if (!linha.executando && linha.ultima_execucao_em) return linha
    await new Promise((ok) => setTimeout(ok, 500))
  }
  throw new Error(`sincronização de ${entidade} não terminou`)
}
const pendencias = async () => dados(await admin.get('sincronizacao/pendencias'))
const hoje = () => new Date().toISOString().slice(0, 19)

test.beforeAll(async () => {
  await ponte('reset')
  admin = await api(USUARIOS.admin)
  vendedor = await api(USUARIOS.vendedor)
})

test('antes de rodar: cinco entidades, ligadas para o agendador e ainda sem execução', async () => {
  const linhas = await dados(await admin.get('sincronizacao'))
  expect(linhas.map((l) => l.entidade).sort()).toEqual(['CLIENTE', 'EMBALAGEM', 'FORNECEDOR', 'PRODUTO', 'TRANSPORTADORA'])
  for (const l of linhas) {
    expect(l.ativo).toBe(true)
    expect(l.executando).toBe(false)
    expect(l.ultima_execucao_status).toBeNull()
  }
})

test('permissões: só quem tem SINCRONIZACAO vê e executa; sem login é 401', async () => {
  expect((await vendedor.get('sincronizacao')).status()).toBe(403)
  expect((await vendedor.get('sincronizacao/pendencias')).status()).toBe(403)
  expect((await vendedor.post('sincronizacao/EMBALAGEM/executar')).status()).toBe(403)
  expect((await vendedor.post('sincronizacao/EMBALAGEM/registro', { data: { codigo: '4' } })).status()).toBe(403)
  const sem = await anonimo()
  expect((await sem.get('sincronizacao')).status()).toBe(401)
  expect((await admin.post('sincronizacao/INEXISTENTE/executar')).status()).toBe(404)
})

test('embalagem: casa as locais pelo nome e cria as novas para revisar', async () => {
  const l = await sincronizar('EMBALAGEM')
  expect(l.ultima_execucao_status).toBe('SUCESSO')
  expect(l.resumo).toContain('completa')
  expect(l.resumo).toContain('vinculados 3')
  expect(l.resumo).toContain('criados 4')

  const emb = Object.fromEntries((await dados(await admin.get('embalagens'))).map((e) => [e.codigo, e]))
  expect(emb.CONTAINER).toMatchObject({ codigo_erp: '4', origem_cadastro: 'ERP', revisao_pendente: false })
  expect(emb.SACARIA.codigo_erp).toBe('17')
  expect(emb.BOMBONA.codigo_erp).toBe('18')
  expect(emb.GRANEL).toMatchObject({ codigo_erp: '8', granel: true, permite_paletizar: false, revisao_pendente: true })
  expect(emb.BIG_BAG).toMatchObject({ codigo_erp: '16', granel: false, permite_paletizar: true, revisao_pendente: true })
  expect(emb.TAMBOR).toMatchObject({ fracionada: true, revisao_pendente: true })
  expect(emb.VOLUME.revisao_pendente).toBe(true)
  expect(emb.GRANEL_LIQUIDO.codigo_erp).toBeNull()
})

test('transportadora: normaliza CNPJ, fantasia, e-mail, telefone e pula os inválidos', async () => {
  const l = await sincronizar('TRANSPORTADORA')
  expect(l.ultima_execucao_status).toBe('SUCESSO')
  expect(l.resumo).toContain('criados 6')
  expect(l.resumo).toContain('pulados 0')

  const todas = await dados(await admin.get('transportadoras'))
  const por = (erp) => todas.find((t) => t.codigo_erp === erp)
  expect(por('1')).toMatchObject({ cnpj: '99887766000105', nome_fantasia: 'ALFA TRANSPORTES', email: 'faturamento@exemplo.com.br', telefone: '1140007100', cidade: 'SAO BERNARDO DO CAMPO', uf: 'SP', origem_cadastro: 'ERP', ativo: true })
  expect(por('2').email).toBe('beta@exemplo.com.br')
  expect(por('3').nome_fantasia).toBe('FANTASIA VAZIA TRANSPORTES LTDA')
  expect(por('6')).toMatchObject({ cnpj: '00123456000149', ativo: false })
  expect(por('4')).toMatchObject({ cnpj: null, revisao_pendente: true, ativo: true })
  expect(por('5')).toMatchObject({ cnpj: null, revisao_pendente: true })
  expect(por('1').revisao_pendente).toBe(false)
  expect((await dados(await admin.get('transportadoras?revisao_pendente=1'))).map((t) => t.codigo_erp).sort()).toEqual(['4', '5'])
})

test('cliente e fornecedor: IBGE resolvido por cidade+UF, situação desconhecida inativa', async () => {
  const c = await sincronizar('CLIENTE')
  expect(c.ultima_execucao_status).toBe('SUCESSO')
  expect(c.resumo).toContain('criados 5')
  expect(c.resumo).toContain('pulados 0')
  expect(c.resumo).toContain('avisos')

  const f = await sincronizar('FORNECEDOR')
  expect(f.resumo).toContain('criados 3')
  expect(f.resumo).toContain('pulados 0')

  const achados = await dados(await admin.get('partes?termo=USINA EXEMPLO'))
  expect(achados.find((p) => p.codigo_erp === '1')).toMatchObject({ tipo: 'CLIENTE', cnpj: '00200001000180', cidade: 'VALPARAISO', uf: 'SP', ativo: true })
  expect((await dados(await admin.get('partes?termo=ESTRANHA'))).length).toBe(0)
  expect((await dados(await admin.get('partes?termo=QMODELO'))).find((p) => p.tipo === 'FORNECEDOR').cnpj).toBe('00123456000149')
})

test('produto: tudo é produto (serviço inclusive); campos nossos ficam vazios', async () => {
  const l = await sincronizar('PRODUTO')
  expect(l.ultima_execucao_status).toBe('SUCESSO')
  expect(l.resumo).toContain('criados 5')
  expect(l.resumo).toContain('vinculados 2')
  const prod = await dados(await admin.get('produtos'))
  const nomes = prod.map((p) => p.nome)
  expect(nomes).toEqual(expect.arrayContaining(['SERVIÇO DE FRETE', 'SODA CAUSTICA LIQUIDA 50%', 'ACIDO SULFURICO 98%']))
  const soda = prod.filter((p) => p.nome === 'HIDROXIDO DE SODIO SOLUCAO 49%')
  expect(soda.length).toBe(1)
  expect(soda[0]).toMatchObject({ codigos_erp: 3, origem_cadastro: 'ERP', ativo: false })
  const frete = prod.find((p) => p.codigo_erp === '0000001')
  expect(frete).toMatchObject({ origem_cadastro: 'ERP', ativo: false, embalagens: [], onu: null, densidade: null })
  expect(prod.filter((p) => p.origem_cadastro === 'ERP' && p.ativo)).toEqual([])
  expect(prod.find((p) => p.codigo_erp === '0000002').ativo).toBe(false)
})

test('pendências batem com o que o espelho deixou e aparecem no painel', async () => {
  const p = await pendencias()
  expect(p).toMatchObject({
    embalagens_a_revisar: 4,
    produtos_sem_embalagem: 0,
    clientes_sem_ibge: 1,
    fornecedores_sem_ibge: 3,
    transportadoras_sem_ibge: 1,
    clientes_sem_cnpj: 1,
    fornecedores_sem_cnpj: 1,
    transportadoras_sem_cnpj: 2,
    registros_pulados_7d: 0,
  })
  const painel = await dados(await admin.get('painel'))
  expect(painel.pendencias_erp).toEqual(p)
  expect((await vendedor.get('painel')).status()).toBe(403)
})

test('segunda leitura é incremental e não relê o que não mudou', async () => {
  await ponte('reset')
  const l = await sincronizar('CLIENTE')
  expect(l.ultima_execucao_status).toBe('SUCESSO')
  expect(l.resumo).toContain('incremental')
  expect(l.resumo).toContain('lidos 0')
  const chamadas = (await chamadasDaPonte()).filter((c) => c.includes('/clientes'))
  expect(chamadas.length).toBe(1)
  expect(chamadas[0]).toMatch(/alteradoDesde=\d{4}-\d{2}-\d{2}T00%3A00%3A00/)
})

test('incremental traz só o alterado: atualiza um, cria outro, não inativa ninguém', async () => {
  const clientes = await ponteDados('clientes')
  const alterado = { ...clientes[0], razao_social: 'USINA EXEMPLO S/A ACUCAR E ALCOOL (RENOMEADO)', alterado_em: hoje() }
  const novo = { ...clientes[0], codigo: 6, razao_social: 'CLIENTE NOVO DO DIA', nome_fantasia: 'NOVO', cnpj: 20000005000121, alterado_em: hoje(), criado_em: hoje() }
  await ponte('set', { clientes: [alterado, ...clientes.slice(1), novo] })

  const l = await sincronizar('CLIENTE')
  expect(l.resumo).toContain('incremental')
  expect(l.resumo).toContain('lidos 2')
  expect(l.resumo).toContain('atualizados 1')
  expect(l.resumo).toContain('criados 1')
  expect(l.resumo).toContain('inativados 0')
  expect((await dados(await admin.get('partes?termo=RENOMEADO'))).length).toBe(1)
  expect((await dados(await admin.get('partes?termo=CLIENTE NOVO'))).length).toBe(1)
})

test('leitura completa inativa o que sumiu do ERP (e só ela)', async () => {
  const clientes = await ponteDados('clientes')
  await ponte('set', { clientes: clientes.filter((c) => c.codigo !== 2) })

  const l = await sincronizar('CLIENTE', { completa: true })
  expect(l.resumo).toContain('completa')
  expect(l.resumo).toContain('inativados 1')
  expect((await dados(await admin.get('partes?termo=COMERCIAL MODELO'))).length).toBe(0)
})

test('um registro só: por código e por CNPJ (formatado ou não), com validação', async () => {
  await ponte('reset')
  const porCnpj = await admin.post('sincronizacao/TRANSPORTADORA/registro', { data: { cnpj: '99.887.766/0001-05' } })
  expect(porCnpj.status()).toBe(200)
  const j = (await dados(porCnpj)).totais
  expect(j).toMatchObject({ modo: 'REGISTRO', lidos: 2, inalterados: 2, pulados: 0, criados: 0 })
  expect((await chamadasDaPonte()).some((c) => c.includes('cnpj=99887766000105'))).toBe(true)

  const porCodigo = await admin.post('sincronizacao/PRODUTO/registro', { data: { codigo: '100320093' } })
  expect((await dados(porCodigo)).totais).toMatchObject({ modo: 'REGISTRO', lidos: 1 })
  expect((await chamadasDaPonte()).some((c) => c.includes('codigo=100320093'))).toBe(true)

  expect((await admin.post('sincronizacao/PRODUTO/registro', { data: { cnpj: '123' } })).status()).toBe(422)
  expect((await admin.post('sincronizacao/CLIENTE/registro', { data: {} })).status()).toBe(422)
  expect((await admin.post('sincronizacao/CLIENTE/registro', { data: { codigo: '1', cnpj: '2' } })).status()).toBe(422)

  const nada = await admin.post('sincronizacao/CLIENTE/registro', { data: { codigo: '999999' } })
  expect((await dados(nada)).totais).toMatchObject({ lidos: 0, inativados: 0 })
})

test('confirmar a embalagem no painel tira a marca de revisão', async () => {
  const lista = await dados(await admin.get('embalagens?revisao_pendente=1'))
  expect(lista.length).toBe(4)
  const granel = lista.find((e) => e.codigo === 'GRANEL')
  const r = await admin.patch('embalagens/GRANEL', { data: { versao: granel.versao, descricao: 'Granel (ERP)' } })
  expect(r.status()).toBe(200)
  expect((await dados(r)).revisao_pendente).toBe(false)
  expect((await dados(await admin.get('embalagens?revisao_pendente=1'))).length).toBe(3)
  expect((await pendencias()).embalagens_a_revisar).toBe(3)
})

test('ponte fora do ar: a execução termina em ERRO sem apagar nem inativar nada', async () => {
  const antes = await dados(await admin.get('produtos'))
  await ponte('falha', { ligada: true })

  const l = await sincronizar('PRODUTO', { completa: true })
  expect(l.ultima_execucao_status).toBe('ERRO')
  expect(l.resumo).toContain('erro')
  expect(l.executando).toBe(false)

  const depois = await dados(await admin.get('produtos'))
  expect(depois.length).toBe(antes.length)
  expect(depois.filter((p) => p.ativo).length).toBe(antes.filter((p) => p.ativo).length)
  await ponte('reset')
})

test('depois do erro a ponte volta e a próxima execução termina bem', async () => {
  const l = await sincronizar('PRODUTO', { completa: true })
  expect(l.ultima_execucao_status).toBe('SUCESSO')
  expect(l.resumo).toContain('inalterados 7')
})

test('o comando do agendador puxa sozinho só o que está vencido', async () => {
  const artisan = (...args) => spawnSync('php', ['artisan', ...args], { cwd: back, env, encoding: 'utf-8' })
  const linhas = async () => Object.fromEntries((await dados(await admin.get('sincronizacao'))).map((l) => [l.entidade, l]))
  const antes = await linhas()

  const produtos = await ponteDados('produtos')
  await ponte('set', { produtos: [...produtos, { codemp: 1, codigo: 'AUTO001', descricao: 'PRODUTO PUXADO SOZINHO', desnfv: 'AUTO', situacao: 'A', criado_em: hoje(), alterado_em: hoje() }] })
  const duasHorasAtras = new Date(Date.now() - 2 * 3600_000).toISOString().replace('T', ' ').slice(0, 23)
  const t = artisan('tinker', '--execute', `DB::table("sincronizacao")->where("entidade","PRODUTO")->update(["ultima_execucao_em"=>"${duasHorasAtras}"]);`)
  expect(t.status, t.stderr).toBe(0)

  const r = artisan('erp:sincroniza')
  expect(r.status, r.stdout + r.stderr).toBe(0)
  expect(r.stdout).toContain('PRODUTO: SUCESSO')
  expect(r.stdout).toContain('incremental')
  expect(r.stdout).not.toContain('CLIENTE:')

  const depois = await linhas()
  expect(depois.CLIENTE.ultima_execucao_em).toBe(antes.CLIENTE.ultima_execucao_em)
  expect((await dados(await admin.get('produtos'))).map((p) => p.nome)).toContain('PRODUTO PUXADO SOZINHO')

  const de_novo = artisan('erp:sincroniza')
  expect(de_novo.stdout.trim()).toBe('')
  await ponte('reset')
})

test.describe('o que o painel mostra do espelho', () => {
  test('embalagens, transportadoras e produtos importados aparecem nas telas', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)

    await irPara(page, 'Cadastro Embalagens')
    await expect(page.locator('tr', { hasText: 'BIG_BAG' })).toBeVisible()
    await expect(page.locator('tr', { hasText: 'TAMBOR' })).toContainText('Fracionada')
    await expect(page.locator('tr', { hasText: 'TAMBOR' })).toContainText('REVISAR')
    await foto(page, info, 'embalagens-com-espelho')

    await irPara(page, 'Transportadoras')
    const linha = page.locator('tr', { hasText: '99.887.766/0001-05' })
    await expect(linha).toContainText('ALFA TRANSPORTES')
    await expect(linha).toContainText('(11) 4000-7100')
    await expect(page.locator('tr', { hasText: 'INATIVA' }).first()).toContainText('INATIVA')
    await expect(page.locator('tr', { hasText: 'SEM CNPJ TRANSPORTES' })).toContainText('REVISAR CNPJ')
    await foto(page, info, 'transportadoras-com-espelho')

    await irPara(page, 'Cadastro Produtos')
    await expect(page.locator('tr', { hasText: 'SODA CAUSTICA LIQUIDA 50%' })).toBeVisible()
    await expect(page.locator('tr', { hasText: 'HIDROXIDO DE SODIO SOLUCAO 49%' })).toContainText('3 códigos ERP')
    await foto(page, info, 'produtos-com-espelho')
  })
})
