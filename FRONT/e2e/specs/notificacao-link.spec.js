import { test, expect, USUARIOS, api, foto } from '../support/base.js'

test.describe.configure({ mode: 'serial' })

let cot
let crg
const toast = (page) => page.locator('.toast').last()

test.beforeAll(async () => {
  const admin = await api(USUARIOS.admin)
  const cotacoes = (await (await admin.get('cotacoes')).json()).data
  const programacoes = (await (await admin.get('programacoes')).json()).data
  cot = cotacoes.find((c) => c.negociacao?.fechamento)?.codigo
  crg = programacoes[0]?.codigo
  await admin.dispose()
  expect(cot, 'cotação fechada deixada por cotacao.spec').toBeTruthy()
  expect(crg, 'carga deixada por cotacao.spec').toBeTruthy()
})

/** Login na tela em que o app já está (sem navegar), como quem chegou pelo link. */
async function logarNaTela(page, [login, senha]) {
  await page.locator('#lgUser').fill(login)
  await page.locator('#lgPass').fill(senha)
  await page.getByRole('button', { name: /Entrar na operação/ }).click()
}

test.describe('deslogado: link → login → registro', () => {
  test('E1–E3: transportadora abre o link da cotação, entra e cai no modal certo', async ({ page }, info) => {
    await page.goto(`/quotes?abrir=${cot}`)
    await expect(page).toHaveURL(/\/login\?next=/)
    await foto(page, info, 'link-pede-login')
    await logarNaTela(page, USUARIOS.transportadora)
    await expect(page.locator('#quote-title')).toContainText(cot, { timeout: 25_000 })
    await expect(page).toHaveURL(/\/quotes$/)
    await foto(page, info, 'link-abre-cotacao')
    await page.reload()
    await expect(page.locator('#quote-title')).toHaveCount(0)
  })

  test('E5: transportadora abre o link da carga, entra e vê o detalhe', async ({ page }, info) => {
    await page.goto(`/loads?abrir=${crg}`)
    await expect(page).toHaveURL(/\/login\?next=/)
    await logarNaTela(page, USUARIOS.transportadora)
    await expect(page.locator('#load-title')).toContainText(crg, { timeout: 25_000 })
    await foto(page, info, 'link-abre-carga')
  })

  test('vendedor e administrador chegam ao mesmo registro pelo mesmo link', async ({ page }) => {
    for (const perfil of [USUARIOS.vendedor, USUARIOS.admin]) {
      await page.goto('/login')
      await page.evaluate(() => { try { sessionStorage.clear(); localStorage.clear() } catch {} })
      await page.goto(`/quotes?abrir=${cot}`)
      await expect(page).toHaveURL(/\/login\?next=/)
      await logarNaTela(page, perfil)
      await expect(page.locator('#quote-title')).toContainText(cot, { timeout: 25_000 })
      await page.locator('.modal-h .x').click()
      await page.locator('.btn-out').click()
      await expect(page).toHaveURL(/\/login/)
    }
  })

  test('next hostil no login é ignorado', async ({ page }) => {
    await page.goto('/login?next=//example.com')
    await logarNaTela(page, USUARIOS.vendedor)
    await expect(page).not.toHaveURL(/login/, { timeout: 25_000 })
    expect(new URL(page.url()).host).toBe(new URL(test.info().project.use.baseURL ?? page.url()).host)
  })
})

test.describe('já logado', () => {
  test('abre o modal direto', async ({ page }) => {
    await page.goto('/login')
    await logarNaTela(page, USUARIOS.admin)
    await expect(page).not.toHaveURL(/login/, { timeout: 25_000 })
    await page.goto(`/quotes?abrir=${cot}`)
    await expect(page.locator('#quote-title')).toContainText(cot, { timeout: 25_000 })
  })
})

test.describe('acesso negado e casos de erro (sem vazar dados)', () => {
  test('outra transportadora: toast, nenhum modal e nada da cotação na tela', async ({ page }, info) => {
    await page.goto('/login')
    await logarNaTela(page, USUARIOS.outraTransportadora)
    await expect(page).not.toHaveURL(/login/, { timeout: 25_000 })
    await page.goto(`/quotes?abrir=${cot}`)
    await expect(toast(page)).toContainText(cot, { timeout: 25_000 })
    await expect(toast(page)).toContainText('não foi encontrada')
    await expect(page.locator('#quote-title')).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText('Alfaquim')
    await foto(page, info, 'link-sem-acesso')
  })

  test('código inexistente: toast claro, tela normal', async ({ page }) => {
    await page.goto('/login')
    await logarNaTela(page, USUARIOS.admin)
    await expect(page).not.toHaveURL(/login/, { timeout: 25_000 })
    await page.goto('/quotes?abrir=COT-999999')
    await expect(toast(page)).toContainText('COT-999999')
    await expect(page.locator('#quote-title')).toHaveCount(0)
  })

  test('vendedor num link de tela sem permissão: volta à home com aviso', async ({ page }) => {
    await page.goto('/login')
    await logarNaTela(page, USUARIOS.vendedor)
    await expect(page).not.toHaveURL(/login/, { timeout: 25_000 })
    await page.goto('/users')
    await expect(page).toHaveURL(/\/quotes$/)
    await expect(toast(page)).toContainText('não tem acesso')
  })
})
