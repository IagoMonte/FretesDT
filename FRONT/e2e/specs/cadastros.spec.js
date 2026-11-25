import { test, expect, USUARIOS, entrar, irPara, foto, api } from '../support/base.js'

test.describe.configure({ mode: 'serial' })

const toast = (page) => page.locator('.toast').last()

test.describe('Transportadoras', () => {
  test('incluir, ver na lista, editar, inativar e reativar', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Transportadoras')

    await page.locator('[data-act="new-carrier"]').click()
    await foto(page, info, 'transportadora-form-vazio')
    await page.locator('#cr-fantasia').fill('e2e log')
    await page.locator('#cr-razao').fill('E2E Logistica Ltda')
    await page.locator('#cr-cnpj').pressSequentially('11444777000161')
    await expect(page.locator('#cr-cnpj')).toHaveValue('11.444.777/0001-61')
    await page.locator('#cr-tel').pressSequentially('11987654321')
    await expect(page.locator('#cr-tel')).toHaveValue('(11) 98765-4321')
    await page.locator('#cr-email').fill('contato@e2e.com.br')
    await page.locator('[data-act="save-carrier"]').click()
    await expect(toast(page)).toContainText('incluída')

    const linha = page.locator('tr', { hasText: '11.444.777/0001-61' })
    await expect(linha).toContainText('E2E LOG')
    await expect(linha).toContainText('ATIVA')
    await foto(page, info, 'transportadora-incluida')

    await linha.getByRole('button', { name: 'Editar' }).click()
    await expect(page.locator('#cr-razao')).toHaveValue('E2E Logistica Ltda')
    await page.locator('#cr-razao').fill('E2E Logistica Brasil Ltda')
    await page.locator('[data-act="save-carrier"]').click()
    await expect(toast(page)).toContainText('atualizada')
    await expect(page.locator('tr', { hasText: '11.444.777/0001-61' })).toContainText('E2E Logistica Brasil Ltda')

    await page.locator('tr', { hasText: '11.444.777/0001-61' }).getByRole('button', { name: 'Inativar' }).click()
    await foto(page, info, 'transportadora-confirma-inativar')
    await page.getByRole('button', { name: 'Inativar', exact: true }).last().click()
    await expect(page.locator('tr', { hasText: '11.444.777/0001-61' })).toContainText('INATIVA')

    await page.locator('tr', { hasText: '11.444.777/0001-61' }).getByRole('button', { name: 'Ativar' }).click()
    await expect(page.locator('tr', { hasText: '11.444.777/0001-61' })).toContainText('ATIVA')
  })

  test('CNPJ repetido é recusado pela API e o erro aparece para o usuário', async ({ page }) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Transportadoras')
    await page.locator('[data-act="new-carrier"]').click()
    await page.locator('#cr-fantasia').fill('REPETIDA')
    await page.locator('#cr-razao').fill('Repetida Ltda')
    await page.locator('#cr-cnpj').pressSequentially('11444777000161')
    await page.locator('[data-act="save-carrier"]').click()
    await expect(page.locator('.toast.err, .toast').last()).toBeVisible()
    await expect(page.locator('#cr-fantasia')).toBeVisible()
  })

  test('Esc fecha o formulário sem salvar', async ({ page }) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Transportadoras')
    await page.locator('[data-act="new-carrier"]').click()
    await expect(page.locator('#cr-fantasia')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.locator('#cr-fantasia')).toHaveCount(0)
  })
})

test.describe('Embalagens', () => {
  test('incluir embalagem a granel desliga paletizar/remontar; editar e inativar', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Cadastro Embalagens')

    await page.locator('[data-act="new-packaging"]').click()
    await page.locator('#pk-code').fill('e2e_granel')
    await page.locator('#pk-desc').fill('E2E Granel')
    await page.locator('input[data-f="granel"]').check()
    await expect(page.locator('input[data-f="fracionada"]')).toBeDisabled()
    await expect(page.getByLabel('Paletizado')).toBeDisabled()
    await foto(page, info, 'embalagem-granel-form')
    await page.locator('[data-act="save-packaging"]').click()
    await expect(toast(page)).toContainText('incluída')

    const linha = page.locator('tr', { hasText: 'E2E_GRANEL' })
    await expect(linha).toContainText('Granel')
    await linha.getByRole('button', { name: 'Editar' }).click()
    await expect(page.locator('#pk-code')).toBeDisabled()
    await page.locator('#pk-desc').fill('E2E Granel Editado')
    await page.locator('[data-act="save-packaging"]').click()
    await expect(page.locator('tr', { hasText: 'E2E_GRANEL' })).toContainText('E2E Granel Editado')

    await page.locator('tr', { hasText: 'E2E_GRANEL' }).getByRole('button', { name: 'Inativar' }).click()
    await page.getByRole('button', { name: 'Inativar', exact: true }).last().click()
    await expect(page.locator('tr', { hasText: 'E2E_GRANEL' })).toContainText('INATIVO')
  })

  test('embalagem inativa não é oferecida na cotação', async () => {
    const admin = await api(USUARIOS.admin)
    const dominios = await (await admin.get('dominios')).json()
    expect(dominios.data.embalagens.map((e) => e.codigo)).not.toContain('E2E_GRANEL')
  })
})

test.describe('Produtos', () => {
  test('incluir produto com embalagem e fator, e conferir na lista', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Cadastro Produtos')

    await page.locator('[data-act="new-product"]').click()
    await page.locator('#pr-name').fill('e2e acido')
    await page.locator('#pr-desc').fill('Líquido ácido de teste')
    await page.locator('#pr-onu').fill('1789')
    await page.locator('#pr-risco').fill('80')
    await page.locator('#pr-dens').fill('1,19')
    await page.locator('.chipz label', { hasText: 'Bombona' }).first().click()
    await page.locator('#pr-fat-BOMBONA').fill('50')
    await foto(page, info, 'produto-form')
    await page.locator('[data-act="save-product"]').click()
    await expect(toast(page)).toContainText('incluído')

    const linha = page.locator('tr', { hasText: 'E2E ACIDO' })
    await expect(linha).toContainText('1789')
    await expect(linha).toContainText('1,19')
    await expect(linha).toContainText('ATIVO')
  })

  test('produto sem nome ou sem embalagem não é salvo', async ({ page }) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Cadastro Produtos')
    await page.locator('[data-act="new-product"]').click()
    await page.locator('[data-act="save-product"]').click()
    await expect(page.locator('#pr-name')).toBeVisible()
  })
})

test.describe('Usuários', () => {
  test('lista usuários e abre o formulário de inclusão', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Usuários e Acessos')
    await expect(page.locator('tr', { hasText: 'marcos' }).first()).toBeVisible()
    await page.locator('[data-act="new-user"]').click()
    await foto(page, info, 'usuario-form')
    await page.keyboard.press('Escape')
  })
})
