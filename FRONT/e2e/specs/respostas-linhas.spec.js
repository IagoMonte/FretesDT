import { test, expect, USUARIOS, entrar, irPara, foto } from '../support/base.js'

test.describe.configure({ mode: 'serial' })

let codigo
const toast = (page) => page.locator('.toast').last()
const cartao = (page, cod) => page.locator('.qcard', { hasText: cod })

async function escolherNaLista(page, input, texto) {
  await input.fill('')
  await input.pressSequentially(texto, { delay: 30 })
  const opcao = page.getByRole('option', { name: new RegExp(texto, 'i') }).first()
  await expect(opcao).toBeVisible()
  await opcao.dispatchEvent('mousedown')
}

async function responder(browser, usuario, ton, coleta, prazo) {
  const p = await (await browser.newContext()).newPage()
  await entrar(p, usuario)
  await irPara(p, 'Minhas Cotações')
  await cartao(p, codigo).locator('[data-act="answer-quote"]').click()
  await p.locator('input[id^="rt-"]').pressSequentially(ton)
  await p.locator('input[id^="rpc-"]').fill(coleta)
  await p.locator('input[id^="rp-"]').fill(prazo)
  await p.locator('[data-act="respond-quote"]').click()
  await expect(toast(p)).toContainText(/resposta|registrad/i)
}

test('prepara: vendedor cria, admin encaminha a 2 transportadoras e ambas respondem', async ({ browser }) => {
  test.setTimeout(240_000)
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
  await admin.locator('#fwSearch').fill('VIA')
  await admin.locator('[data-carrier]', { hasText: /VIA/i }).first().locator('input').check()
  await admin.locator('[data-act="forward-selected"]').click()
  await expect(toast(admin)).toContainText(/encaminhad/i)

  await responder(browser, USUARIOS.transportadora, '250', '2', '5')
  await responder(browser, USUARIOS.outraTransportadora, '200', '1', '4')
})

async function abrirComoAdmin(page) {
  await entrar(page, USUARIOS.admin)
  await irPara(page, 'Solicitar Cotação')
  await cartao(page, codigo).locator('[data-act="view-quote"]').click()
  await expect(page.locator('ul.resp-list')).toBeVisible()
}

test('desktop: lista de linhas, menor valor primeiro, coleta antes de entrega', async ({ page }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await abrirComoAdmin(page)
  const linhas = page.locator('ul.resp-list > li[data-resp]')
  await expect(linhas).toHaveCount(2)
  await expect(page.locator('.resp-cards')).toHaveCount(0)
  await expect(linhas.first()).toContainText('MENOR VALOR')
  await expect(linhas.first()).toContainText(/VIA/i)
  await expect(linhas.nth(1)).not.toContainText('MENOR VALOR')
  for (const i of [0, 1]) {
    const t = (await linhas.nth(i).innerText()).toLowerCase()
    expect(t).toContain('prazo de coleta')
    expect(t).toContain('prazo de entrega')
    expect(t.indexOf('prazo de coleta')).toBeLessThan(t.indexOf('prazo de entrega'))
  }
  await foto(page, info, 'respostas-linhas-desktop')
})

test('mobile 360: sem rolagem horizontal e botões clicáveis', async ({ page }, info) => {
  await page.setViewportSize({ width: 360, height: 740 })
  await abrirComoAdmin(page)
  const linhas = page.locator('ul.resp-list > li[data-resp]')
  await linhas.first().scrollIntoViewIfNeeded()
  const larguras = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, corpo: document.body.scrollWidth }))
  expect(larguras.doc).toBeLessThanOrEqual(360)
  expect(larguras.corpo).toBeLessThanOrEqual(360)
  const lista = await page.locator('ul.resp-list').evaluate((el) => ({ sw: el.scrollWidth, cw: el.clientWidth }))
  expect(lista.sw).toBeLessThanOrEqual(lista.cw)
  const botao = linhas.first().locator('[data-act="answer-quote"]')
  if (await botao.count()) {
    const caixa = await botao.boundingBox()
    expect(caixa.height).toBeGreaterThanOrEqual(36)
  }
  await foto(page, info, 'respostas-linhas-mobile')
})

test('transportadora vê só a própria linha e Responder abre o modal', async ({ page }, info) => {
  await entrar(page, USUARIOS.transportadora)
  await irPara(page, 'Minhas Cotações')
  await cartao(page, codigo).locator('[data-act="view-quote"]').click()
  await expect(page.locator('ul.resp-list > li[data-resp]')).toHaveCount(1)
  await expect(page.locator('[data-ref]')).toHaveCount(0)
  await page.locator('ul.resp-list [data-act="answer-quote"]').click()
  await expect(page.locator('input[id^="rt-"]')).toBeVisible()
  await foto(page, info, 'respostas-linhas-transportadora')
})
