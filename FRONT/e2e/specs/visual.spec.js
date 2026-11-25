import { test, expect, USUARIOS, entrar, foto } from '../support/base.js'

const TELAS = [
  ['dash', 'Painel'],
  ['quotes', 'Cotações'],
  ['products', 'Produtos'],
  ['packagings', 'Embalagens'],
  ['carriers', 'Transportadoras'],
  ['antt', 'Piso ANTT'],
  ['users', 'Usuários'],
  ['registrations', 'Cadastros pendentes'],
  ['loads', 'Cargas'],
]

const semRolagemHorizontal = async (page) => {
  const { sw, iw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }))
  expect(sw, `a página rola na horizontal (${sw}px > ${iw}px)`).toBeLessThanOrEqual(iw + 1)
}

test.describe('Telas do administrador', () => {
  for (const [rota, nome] of TELAS) {
    test(`tela ${nome}`, async ({ page }, info) => {
      await entrar(page, USUARIOS.admin)
      await page.goto('/' + rota)
      await expect(page.locator('main, .main, .content, .page').first()).toBeVisible()
      await expect(page.locator('.empty .big, .tbl, .kpis, .qgrid, .card, .panel, .reg-tabs, form').first()).toBeVisible()
      await page.waitForLoadState('networkidle')
      await semRolagemHorizontal(page)
      await foto(page, info, `tela-${rota}`, { fullPage: true })
    })
  }
})

test.describe('Formulários e estados', () => {
  test('transportadora, embalagem, produto, cotação e usuário', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    const abrir = async (rota, botao, nome, fecharCom = 'Escape') => {
      await page.goto('/' + rota)
      await page.locator(`[data-act="${botao}"]`).click()
      await expect(page.locator('.modal')).toBeVisible()
      await semRolagemHorizontal(page)
      await foto(page, info, nome)
      await page.keyboard.press(fecharCom)
      await expect(page.locator('.modal')).toHaveCount(0)
    }
    await abrir('carriers', 'new-carrier', 'form-transportadora')
    await abrir('packagings', 'new-packaging', 'form-embalagem')
    await abrir('products', 'new-product', 'form-produto')
    await abrir('quotes', 'new-quote', 'form-cotacao')
    await abrir('users', 'new-user', 'form-usuario')
    await abrir('antt', 'new-antt', 'form-antt')
  })

  test('estado vazio e carregando da lista de cotações do vendedor da Lucia', async ({ page }, info) => {
    await entrar(page, USUARIOS.vendedora)
    await page.goto('/quotes')
    await page.waitForLoadState('networkidle')
    await foto(page, info, 'cotacoes-vendedora', { fullPage: true })
  })

  test('telas da transportadora', async ({ page }, info) => {
    await entrar(page, USUARIOS.transportadora)
    await page.waitForLoadState('networkidle')
    await foto(page, info, 'transportadora-cargas', { fullPage: true })
    await page.goto('/quotes')
    await page.waitForLoadState('networkidle')
    await semRolagemHorizontal(page)
    await foto(page, info, 'transportadora-cotacoes', { fullPage: true })
  })
})
