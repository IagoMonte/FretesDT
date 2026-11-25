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

test('vendedor cria a cotação que será editada', async ({ page }) => {
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
  await page.locator('[data-act="save-quote"]').click()
  await expect(page.locator('#qc-parte')).toHaveCount(0)

  const grupo = page.locator('[data-group="ABERTA"]')
  await expect(grupo).toBeVisible()
  codigo = (await grupo.locator('.qcard').first().innerText()).match(/COT-\d+/)?.[0]
  expect(codigo, 'código da cotação no cartão').toBeTruthy()
})

test('vendedor edita a cotação aberta: o formulário vem preenchido e o salvamento avisa', async ({ page }, info) => {
  await entrar(page, USUARIOS.vendedor)
  await irPara(page, 'Solicitar Cotação')
  await cartao(page, codigo).locator('[data-act="edit-quote"]').click()

  await expect(page.locator('.modal-h h3')).toContainText(`Editar cotação ${codigo}`)
  await expect(page.locator('#qc-veiculo')).toHaveValue('TRUCK')
  await expect(page.locator('#qc-origem')).not.toHaveValue('')
  await expect(page.locator('#qc-destino')).not.toHaveValue('')
  await expect(page.locator('[data-use-prev]')).toHaveCount(0)

  await page.locator('#qc-obs').fill('Entregar pela manhã')
  await page.locator('#qc-veiculo').selectOption('CARRETA')
  await foto(page, info, 'cotacao-editar')
  await page.locator('[data-act="save-quote"]').click()

  await expect(toast(page)).toContainText(`${codigo}`)
  await expect(toast(page)).toContainText(/atualizada/i)
  await expect(page.locator('#qc-obs')).toHaveCount(0)

  await cartao(page, codigo).locator('[data-act="edit-quote"]').click()
  await expect(page.locator('#qc-obs')).toHaveValue('Entregar pela manhã')
  await expect(page.locator('#qc-veiculo')).toHaveValue('CARRETA')
})

test('administrador encaminha a cotação', async ({ page }) => {
  await entrar(page, USUARIOS.admin)
  await irPara(page, 'Solicitar Cotação')
  await cartao(page, codigo).locator('[data-act="def-quote"]').click()
  await page.locator('#fwSearch').fill('ARCO')
  await page.locator('[data-carrier]', { hasText: /^\s*ARCO\s*$/ }).first().locator('input').check()
  await page.locator('[data-act="forward-selected"]').click()
  await expect(toast(page)).toContainText(/encaminhad/i)
})

for (const perfil of ['vendedor', 'admin']) {
  test(`depois de encaminhada ninguém edita (${perfil}): sem botão no cartão nem no detalhe`, async ({ page }, info) => {
    await entrar(page, USUARIOS[perfil])
    await irPara(page, 'Solicitar Cotação')
    await expect(cartao(page, codigo)).toBeVisible()
    await expect(cartao(page, codigo).locator('[data-act="edit-quote"]')).toHaveCount(0)
    await cartao(page, codigo).locator('[data-act="view-quote"]').click()
    await expect(page.locator('#quote-title')).toContainText(codigo)
    await expect(page.locator('.modal [data-act="edit-quote"]')).toHaveCount(0)
    await foto(page, info, `cotacao-encaminhada-sem-editar-${perfil}`)
  })
}

test('transportadora nunca vê o botão Editar', async ({ page }) => {
  await entrar(page, USUARIOS.transportadora)
  await irPara(page, 'Minhas Cotações')
  await expect(cartao(page, codigo)).toBeVisible()
  await expect(page.locator('[data-act="edit-quote"]')).toHaveCount(0)
})
