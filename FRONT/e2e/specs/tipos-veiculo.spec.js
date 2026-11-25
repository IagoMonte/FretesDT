import { test, expect, USUARIOS, entrar, irPara, foto } from '../support/base.js'

const ROTULOS = ['Picape', 'Vuc/Van', 'Toco', 'Toco 3/4', 'Carreta LS', 'Rodotrem', 'Prancha']

async function escolherNaLista(page, input, texto) {
  await input.fill('')
  await input.pressSequentially(texto, { delay: 30 })
  const opcao = page.getByRole('option', { name: new RegExp(texto, 'i') }).first()
  await expect(opcao).toBeVisible()
  await opcao.dispatchEvent('mousedown')
}

async function criarCotacaoCom(page, rotulo) {
  await page.locator('[data-act="new-quote"]').click()
  await escolherNaLista(page, page.locator('#qc-parte'), 'Alfaquim')
  const item = page.locator('[data-item="0"]')
  await item.locator('select').first().selectOption({ label: 'HCL' })
  await item.locator('select').nth(1).selectOption('BOMBONA')
  await item.getByLabel('QTD (KG)').pressSequentially('12000')
  const volumes = item.getByLabel('Volumes')
  if (!(await volumes.inputValue())) await volumes.fill('240')
  await page.locator('#qc-veiculo').selectOption({ label: rotulo })
  await page.locator('#qc-valor').pressSequentially('15000')
  await escolherNaLista(page, page.locator('#qc-origem'), 'Paulínia')
  const destino = page.locator('#qc-destino')
  if ((await destino.getAttribute('readonly')) === null) await escolherNaLista(page, destino, 'Campinas')
  await expect(destino).not.toHaveValue('')
  await page.locator('[data-act="save-quote"]').click()
  await expect(page.locator('#qc-parte')).toHaveCount(0)
}

test('vendedor vê os tipos de veículo novos e cria cotações com Vuc/Van e Toco 3/4', async ({ page }, info) => {
  await entrar(page, USUARIOS.vendedor)
  await irPara(page, 'Solicitar Cotação')
  await page.locator('[data-act="new-quote"]').click()

  await expect(page.locator('#qc-veiculo option', { hasText: 'Picape' })).toHaveCount(1, { timeout: 25_000 })
  const opcoes = await page.locator('#qc-veiculo option').allTextContents()
  for (const rotulo of ROTULOS) expect(opcoes, `opção "${rotulo}" em #qc-veiculo`).toContain(rotulo)
  await page.keyboard.press('Escape')

  for (const rotulo of ['Vuc/Van', 'Toco 3/4']) {
    await criarCotacaoCom(page, rotulo)
    const primeiro = page.locator('[data-group="ABERTA"] .qcard').first()
    await expect(primeiro).toContainText(rotulo)
  }
  await foto(page, info, 'cotacoes-tipos-veiculo')
})
