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

test('prepara: vendedor cria, admin encaminha à ARCO e a ARCO responde', async ({ browser }) => {
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
  await expect(cartao(vendedor, codigo).locator('[data-act="end-quote"]')).toHaveCount(0)

  const admin = await nova(USUARIOS.admin)
  await irPara(admin, 'Solicitar Cotação')
  await cartao(admin, codigo).locator('[data-act="def-quote"]').click()
  await admin.locator('#fwSearch').fill('ARCO')
  await admin.locator('[data-carrier]', { hasText: /^\s*ARCO\s*$/ }).first().locator('input').check()
  await admin.locator('[data-act="forward-selected"]').click()
  await expect(toast(admin)).toContainText(/encaminhad/i)

  const transp = await nova(USUARIOS.transportadora)
  await irPara(transp, 'Minhas Cotações')
  await cartao(transp, codigo).locator('[data-act="answer-quote"]').click()
  await transp.locator('input[id^="rt-"]').pressSequentially('250')
  await transp.locator('input[id^="rp-"]').fill('3')
  await transp.locator('[data-act="respond-quote"]').click()
  await expect(toast(transp)).toContainText(/resposta|registrad/i)
})

test('administrador encerra sem fechar: respostas ficam e as ações somem', async ({ page }, info) => {
  await entrar(page, USUARIOS.admin)
  await irPara(page, 'Solicitar Cotação')
  const card = cartao(page, codigo)
  await expect(card.locator('[data-act="end-quote"]')).toBeVisible()
  await card.locator('[data-act="end-quote"]').click()
  await expect(page.locator('#mt-motivo')).toBeVisible()
  await foto(page, info, 'cotacao-encerrar')
  await page.locator('#mt-motivo').fill('cliente comprou de outro fornecedor')
  await page.locator('[data-act="confirm-motivo"]').click()
  await expect(toast(page)).toContainText(/encerrada/i)

  const grupo = page.locator('[data-group="ENCERRADA"]')
  await expect(grupo).toBeVisible()
  const encerrada = grupo.locator('.qcard', { hasText: codigo })
  await expect(encerrada).toContainText('Encerrada sem fechamento')
  await expect(encerrada.locator('[data-resp]')).toContainText('respondeu')
  for (const act of ['def-quote', 'close-quote', 'end-quote', 'cancel-quote']) {
    await expect(encerrada.locator(`[data-act="${act}"]`)).toHaveCount(0)
  }
  await expect(encerrada.locator('[data-act="view-quote"]')).toBeVisible()
  await foto(page, info, 'cotacao-encerrada')
})

test('transportadora vê a cotação encerrada sem poder responder', async ({ page }) => {
  await entrar(page, USUARIOS.transportadora)
  await irPara(page, 'Minhas Cotações')
  const card = cartao(page, codigo)
  await expect(card).toBeVisible()
  await expect(card.locator('[data-act="answer-quote"]')).toHaveCount(0)
  await expect(card.locator('[data-act="decline-quote"]')).toHaveCount(0)
})
