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

test('validação: cotação vazia não é gerada e mostra o que falta', async ({ page }, info) => {
  await entrar(page, USUARIOS.vendedor)
  await irPara(page, 'Solicitar Cotação')
  await page.locator('[data-act="new-quote"]').click()
  await page.locator('[data-act="save-quote"]').click()
  await expect(toast(page)).toContainText('Revise os campos')
  await expect(page.locator('[data-err="parte"]')).toBeVisible()
  await foto(page, info, 'cotacao-validacao')
})

test('vendedor cria a cotação', async ({ page }, info) => {
  await entrar(page, USUARIOS.vendedor)
  await irPara(page, 'Solicitar Cotação')
  await page.locator('[data-act="new-quote"]').click()

  await escolherNaLista(page, page.locator('#qc-parte'), 'Alfaquim')
  const item = page.locator('[data-item="0"]')
  await item.locator('select').first().selectOption({ label: 'HCL' })
  await item.locator('select').nth(1).selectOption('BOMBONA')
  await item.getByLabel('QTD (KG)').pressSequentially('12000')
  const volumes = item.getByLabel('Volumes')
  if (!(await volumes.inputValue())) await volumes.fill('240')
  await page.locator('#qc-veiculo').selectOption('TRUCK')
  await page.locator('#qc-valor').pressSequentially('15000')
  await escolherNaLista(page, page.locator('#qc-origem'), 'Paulínia')
  const destino = page.locator('#qc-destino')
  if ((await destino.getAttribute('readonly')) === null) await escolherNaLista(page, destino, 'Campinas')
  await expect(destino).not.toHaveValue('')
  await expect(page.locator('#qc-km')).toHaveAttribute('readonly', '')
  await expect(page.locator('#qc-carga')).toHaveCount(0)
  await foto(page, info, 'cotacao-form-preenchido')

  await page.locator('[data-act="save-quote"]').click()
  await expect(page.locator('#qc-parte')).toHaveCount(0)
  const grupo = page.locator('[data-group="ABERTA"]')
  await expect(grupo).toBeVisible()
  codigo = (await grupo.locator('.qcard, [data-act="view-quote"], .card').first().innerText()).match(/COT-\d+/)?.[0]
  expect(codigo, 'código da cotação no cartão').toBeTruthy()
  await foto(page, info, 'cotacoes-vendedor')
})

test('administrador encaminha à transportadora ARCO (o vendedor não tem a ação)', async ({ page }, info) => {
  await entrar(page, USUARIOS.admin)
  await irPara(page, 'Solicitar Cotação')
  await cartao(page, codigo).locator('[data-act="view-quote"]').click()
  await expect(page.locator('#quote-title')).toContainText(codigo)
  await foto(page, info, 'cotacao-detalhe')
  await page.locator('button.x[aria-label="Fechar"]').click()

  await cartao(page, codigo).locator('[data-act="def-quote"]').click()
  await page.locator('#fwSearch').fill('ARCO')
  await page.locator('[data-carrier]', { hasText: /^\s*ARCO\s*$/ }).first().locator('input').check()
  await foto(page, info, 'cotacao-encaminhar')
  await page.locator('[data-act="forward-selected"]').click()
  await expect(toast(page)).toContainText(/encaminhad/i)
})

test('transportadora vê só a cotação dela e responde com o valor por tonelada', async ({ page }, info) => {
  await entrar(page, USUARIOS.transportadora)
  await irPara(page, 'Minhas Cotações')
  await expect(page.locator('.note-strip')).toContainText('ARCO')
  await cartao(page, codigo).locator('[data-act="answer-quote"]').click()
  await page.locator('input[id^="rt-"]').pressSequentially('250')
  await page.locator('input[id^="rp-"]').fill('3')
  await page.locator('input[id^="rpc-"]').fill('1')
  await expect(page.locator('b[id^="rf-"]')).toContainText('R$')
  await foto(page, info, 'cotacao-responder')
  await page.locator('[data-act="respond-quote"]').click()
  await expect(toast(page)).toContainText(/resposta|registrad/i)
  await expect(cartao(page, codigo)).toContainText(/coleta 1 dia(?!s)/)
  await expect(cartao(page, codigo)).toContainText(/entrega 3 dias/)
  await foto(page, info, 'cotacao-prazo-coleta')
})

test('administrador fecha a negociação e a carga é programada', async ({ page }, info) => {
  await entrar(page, USUARIOS.admin)
  await irPara(page, 'Solicitar Cotação')
  await cartao(page, codigo).locator('[data-act="close-quote"]').click()
  await foto(page, info, 'cotacao-fechar')
  await page.locator('#fc-transp').selectOption({ index: 1 })
  await expect(page.locator('#fc-transp option:checked')).toContainText('ARCO')
  await expect(page.locator('#fc-prazo-coleta')).toHaveValue('1')
  await expect(page.locator('#fc-prazo')).toHaveValue('3')
  await page.locator('#fc-pedido').fill('PV-E2E-1')
  await page.locator('[data-act="close-negotiation"]').click()
  await expect(toast(page)).toContainText(/fechad|programad/i)

  await irPara(page, 'Programação de Cargas')
  const carga = page.locator('tr', { hasText: 'ARCO' }).filter({ hasText: /Alfaquim/i }).first()
  await expect(carga).toBeVisible()
  await foto(page, info, 'cargas-apos-fechamento')
  await carga.locator('.ibtn.ed').click()
  await expect(page.locator('#lp-prazo-coleta')).toHaveValue('1')
  await page.locator('button.x[aria-label="Fechar"]').click()
})

test('transportadora coloca a carga em trânsito', async ({ page }, info) => {
  await entrar(page, USUARIOS.transportadora)
  await expect(page).toHaveURL(/\/loads/)
  const carga = page.locator('tr', { hasText: /Alfaquim/i }).first()
  await expect(carga).toBeVisible()
  await carga.locator('[data-act="transit-load"]').click()
  const confirmar = page.getByRole('button', { name: /Confirmar|Sim|Colocar|Iniciar/i }).last()
  if (await confirmar.isVisible().catch(() => false)) await confirmar.click()
  await expect(page.locator('tr', { hasText: /Alfaquim/i }).first()).toContainText(/Em trânsito/i)
  await foto(page, info, 'carga-em-transito')
})

test('painel reflete a carga em trânsito', async ({ page }, info) => {
  await entrar(page, USUARIOS.admin)
  await expect(page).toHaveURL(/\/dash/)
  await expect(page.locator('.kpis')).toContainText('Em trânsito')
  await foto(page, info, 'dash-apos-fluxo')
})
