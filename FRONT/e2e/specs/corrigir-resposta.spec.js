import { test, expect, USUARIOS, entrar, irPara, foto, api } from '../support/base.js'

test.describe.configure({ mode: 'serial' })

let codigo
let cotacaoId
const toast = (page) => page.locator('.toast').last()
const cartao = (page, cod) => page.locator('.qcard', { hasText: cod })

async function escolherNaLista(page, input, texto) {
  await input.fill('')
  await input.pressSequentially(texto, { delay: 30 })
  const opcao = page.getByRole('option', { name: new RegExp(texto, 'i') }).locator('visible=true').first()
  await expect(opcao).toBeVisible()
  await opcao.dispatchEvent('mousedown')
}

async function digitar(input, valor) {
  await input.click()
  await input.press('Control+A')
  await input.press('Delete')
  await input.pressSequentially(valor)
}

test('prepara: vendedor cria, admin encaminha à ARCO e a ARCO responde R$ 250/t em 3 dias', async ({ browser }, info) => {
  const nova = async (u) => { const p = await (await browser.newContext()).newPage(); await entrar(p, u); return p }

  const vendedor = await nova(USUARIOS.vendedor)
  await irPara(vendedor, 'Solicitar Cotação')
  await vendedor.locator('[data-act="new-quote"]').click()
  await escolherNaLista(vendedor, vendedor.locator('#qc-parte'), 'Alfaquim')
  const item = vendedor.locator('[data-item="0"]')
  await item.locator('select').first().selectOption({ label: 'HCL' })
  await item.locator('select').nth(1).selectOption('BOMBONA')
  await item.getByLabel('QTD (KG)').pressSequentially('12000')
  const volumes = item.getByLabel('Volumes')
  if (!(await volumes.inputValue())) await volumes.fill('240')
  await vendedor.locator('#qc-veiculo').selectOption('TRUCK')
  await vendedor.locator('#qc-valor').pressSequentially('15000')
  await escolherNaLista(vendedor, vendedor.locator('#qc-origem'), 'Paulínia')
  const destino = vendedor.locator('#qc-destino')
  if ((await destino.getAttribute('readonly')) === null) await escolherNaLista(vendedor, destino, 'Campinas')
  await vendedor.locator('[data-act="save-quote"]').click()
  await expect(vendedor.locator('#qc-parte')).toHaveCount(0)
  const grupo = vendedor.locator('[data-group="ABERTA"]')
  await expect(grupo).toBeVisible()
  codigo = (await grupo.locator('.qcard').first().innerText()).match(/COT-\d+/)?.[0]
  expect(codigo).toBeTruthy()

  const admin = await nova(USUARIOS.admin)
  await irPara(admin, 'Solicitar Cotação')
  await cartao(admin, codigo).locator('[data-act="def-quote"]').click()
  await admin.locator('#fwSearch').fill('ARCO')
  await admin.locator('[data-carrier]', { hasText: /^\s*ARCO\s*$/ }).first().locator('input').check()
  await admin.locator('[data-act="forward-selected"]').click()
  await expect(toast(admin)).toContainText(/encaminhad/i)

  const transp = await nova(USUARIOS.transportadora)
  await irPara(transp, 'Minhas Cotações')
  const card = cartao(transp, codigo)
  await foto(transp, info, '01-transportadora-recebe-cotacao')
  await card.locator('[data-act="answer-quote"]').click()
  await transp.locator('input[id^="rt-"]').pressSequentially('250')
  await transp.locator('input[id^="rp-"]').fill('3')
  await foto(transp, info, '02-primeira-resposta-250-por-tonelada')
  await transp.locator('[data-act="respond-quote"]').click()
  await expect(toast(transp)).toContainText(/resposta|registrad/i)
  await expect(card.locator('[data-resp]')).toContainText('3.000,00')
  await foto(transp, info, '03-cartao-mostra-resposta-enviada')
})

test('transportadora corrige a resposta por cima: o botão continua lá e o formulário vem preenchido', async ({ page }, info) => {
  await entrar(page, USUARIOS.transportadora)
  await irPara(page, 'Minhas Cotações')
  const card = cartao(page, codigo)
  await expect(card.locator('[data-resp]')).toContainText('3.000,00')
  await expect(card.locator('[data-act="answer-quote"]')).toBeVisible()
  await foto(page, info, '04-resposta-enviada-botao-responder-continua')

  await card.locator('[data-act="answer-quote"]').click()
  const valor = page.locator('input[id^="rt-"]')
  await expect(valor).toHaveValue('250,00')
  await expect(page.locator('input[id^="rp-"]')).toHaveValue('3')
  await foto(page, info, '05-formulario-vem-com-a-resposta-atual')

  await digitar(valor, '230')
  await page.locator('input[id^="rp-"]').fill('2')
  await page.locator('input[id^="ro-"]').fill('Corrigindo: desconto concedido')
  await foto(page, info, '06-alterando-para-230-e-2-dias')
  await page.locator('[data-act="respond-quote"]').click()
  await expect(toast(page)).toContainText(/resposta|registrad/i)

  await expect(card.locator('[data-resp]')).toContainText('2.760,00')
  await expect(card.locator('[data-resp]')).not.toContainText('3.000,00')
  await foto(page, info, '07-cartao-mostra-valor-corrigido')
})

test('a correção substitui a resposta (continua uma só) e o admin vê o valor novo', async ({ page }, info) => {
  const admin = await api(USUARIOS.admin)
  const lista = await (await admin.get(`cotacoes?busca=${codigo}`)).json()
  cotacaoId = (lista.data ?? lista).find((c) => c.codigo === codigo).id
  const det = (await (await admin.get(`cotacoes/${cotacaoId}`)).json()).data
  const respostas = det.negociacao.respostas
  expect(respostas).toHaveLength(1)
  expect(respostas[0]).toMatchObject({ status: 'RESPONDIDA', prazo_dias: 2, observacao: 'Corrigindo: desconto concedido' })
  expect(Number(respostas[0].valor_tonelada)).toBe(230)
  await admin.dispose()

  await entrar(page, USUARIOS.admin)
  await irPara(page, 'Solicitar Cotação')
  await cartao(page, codigo).locator('[data-act="view-quote"]').click()
  const modal = page.locator('.modal').first()
  await expect(modal).toContainText('2.760,00')
  await expect(modal).not.toContainText('3.000,00')
  await expect(modal).toContainText('Corrigindo: desconto concedido')
  await foto(page, info, '08-admin-ve-so-o-valor-corrigido')
})

test('depois de encerrada, a transportadora não consegue mais corrigir', async ({ browser }, info) => {
  const adminPage = await (await browser.newContext()).newPage()
  await entrar(adminPage, USUARIOS.admin)
  await irPara(adminPage, 'Solicitar Cotação')
  await cartao(adminPage, codigo).locator('[data-act="end-quote"]').click()
  await adminPage.locator('#mt-motivo').fill('encerrada para validar o bloqueio')
  await adminPage.locator('[data-act="confirm-motivo"]').click()
  await expect(toast(adminPage)).toContainText(/encerrada/i)

  const page = await (await browser.newContext()).newPage()
  await entrar(page, USUARIOS.transportadora)
  await irPara(page, 'Minhas Cotações')
  const card = cartao(page, codigo)
  await expect(card).toBeVisible()
  await expect(card.locator('[data-resp]')).toContainText('2.760,00')
  await expect(card.locator('[data-act="answer-quote"]')).toHaveCount(0)
  await expect(card.locator('[data-act="decline-quote"]')).toHaveCount(0)
  await foto(page, info, '09-cotacao-encerrada-sem-botao-de-corrigir')

  const t = await api(USUARIOS.transportadora)
  const det = (await (await t.get(`cotacoes/${cotacaoId}`)).json()).data
  const r = await t.post(`cotacoes/${cotacaoId}/respostas/${det.negociacao.respostas[0].id}/responder`, {
    data: { valor_tonelada: '100.00', prazo_dias: 1, versao: det.negociacao.respostas[0].versao },
  })
  expect(r.status()).toBe(422)
  expect((await r.json()).message).toMatch(/encerrada/i)
  await t.dispose()
})
