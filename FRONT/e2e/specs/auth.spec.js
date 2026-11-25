import { test, expect, USUARIOS, entrar, foto } from '../support/base.js'

test.describe('Login e sessão', () => {
  test('tela de login e erro de credencial', async ({ page }, info) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: /Acesso ao painel/ })).toBeVisible()
    await foto(page, info, 'login')

    await page.locator('#lgUser').fill('admin')
    await page.locator('#lgPass').fill('senha-errada')
    await page.getByRole('button', { name: /Entrar na operação/ }).click()
    await expect(page.locator('.lg-err')).toContainText('inválid')
    await expect(page).toHaveURL(/\/login/)
    await foto(page, info, 'login-erro')
  })

  test('campos vazios não chamam a API', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: /Entrar na operação/ }).click()
    await expect(page.locator('.lg-err')).toContainText('Informe usuário e senha')
  })

  test('administrador entra no painel e vê todo o menu', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await expect(page).toHaveURL(/\/dash/)
    const menu = await page.locator('.nav-item').allInnerTexts()
    const texto = menu.join('|').toLowerCase()
    for (const item of ['Painel Operacional', 'Solicitar Cotação', 'Cadastro Produtos', 'Cadastro Embalagens', 'Transportadoras', 'Piso Mínimo ANTT', 'Usuários e Acessos', 'Programação de Cargas']) {
      expect(texto).toContain(item.toLowerCase())
    }
    await foto(page, info, 'dash-admin')
  })

  test('vendedor: menu enxuto e sem telas de administração', async ({ page }) => {
    await entrar(page, USUARIOS.vendedor)
    const texto = (await page.locator('.nav-item').allInnerTexts()).join('|').toLowerCase()
    expect(texto).toContain('solicitar cotação')
    expect(texto).not.toContain('usuários e acessos')
    expect(texto).not.toContain('piso mínimo antt')
    await page.goto('/users')
    await expect(page).not.toHaveURL(/\/users/)
  })

  test('transportadora começa no Painel Operacional e só vê o que é dela', async ({ page }, info) => {
    await entrar(page, USUARIOS.transportadora)
    await expect(page).toHaveURL(/\/dash/)
    const texto = (await page.locator('.nav-item').allInnerTexts()).join('|').toLowerCase()
    expect(texto).toContain('painel operacional')
    expect(texto).toContain('minhas cotações')
    expect(texto).not.toContain('usuários e acessos')
    expect(texto).not.toContain('cadastro produtos')
    await page.goto('/carriers')
    await expect(page).not.toHaveURL(/\/carriers/)
    await foto(page, info, 'cargas-transportadora')
  })

  test('sem sessão, qualquer tela redireciona para o login', async ({ page }) => {
    for (const rota of ['/dash', '/quotes', '/carriers', '/loads']) {
      await page.goto(rota)
      await expect(page).toHaveURL(/\/login/)
    }
  })

  test('sessão sobrevive ao recarregar a página (refresh token)', async ({ page }) => {
    await entrar(page, USUARIOS.admin)
    await page.reload()
    await expect(page.locator('.nav-item').first()).toBeVisible()
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('sair encerra a sessão', async ({ page }) => {
    await entrar(page, USUARIOS.vendedor)
    await page.getByRole('button', { name: /sair/i }).first().click()
    await expect(page).toHaveURL(/\/login/)
    await page.goto('/quotes')
    await expect(page).toHaveURL(/\/login/)
  })

  test('tela de cadastro público abre e valida campos obrigatórios', async ({ page }, info) => {
    await page.goto('/login')
    await page.locator('[data-act="signup"]').click()
    await expect(page).toHaveURL(/\/cadastro/)
    await foto(page, info, 'cadastro-publico')
    await page.getByRole('button', { name: 'Enviar cadastro' }).click()
    await expect(page.locator('[aria-invalid="true"]').first()).toBeVisible()
  })
})
