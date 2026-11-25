import { test, expect, USUARIOS, entrar, irPara, foto } from '../support/base.js'

test.describe.configure({ mode: 'serial' })

const toast = (page) => page.locator('.toast').last()

test.describe('Notificações', () => {
  test('matriz: ligar o WhatsApp do cancelamento para o vendedor, salvar e ver o toast', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Notificações')

    const caixa = page.locator('[data-pref="COTACAO_CANCELADA|WHATSAPP|VENDEDOR"]')
    await expect(caixa).not.toBeChecked()
    await expect(page.locator('[data-pref="COTACAO_CANCELADA|EMAIL|TRANSPORTADORA"]')).toHaveCount(0)
    await expect(page.locator('[data-act="save-prefs"]')).toBeDisabled()
    await foto(page, info, 'notificacoes-matriz')

    await caixa.check()
    await page.locator('[data-act="save-prefs"]').click()
    await expect(toast(page)).toContainText('Preferências salvas')

    await page.reload()
    await expect(page.locator('[data-pref="COTACAO_CANCELADA|WHATSAPP|VENDEDOR"]')).toBeChecked()

    await page.locator('[data-pref="COTACAO_CANCELADA|WHATSAPP|VENDEDOR"]').uncheck()
    await page.locator('[data-act="save-prefs"]').click()
    await expect(toast(page)).toContainText('Preferências salvas')
  })

  test('teste de e-mail e de WhatsApp (simulado) aparecem no histórico, com toasts', async ({ page }, info) => {
    await entrar(page, USUARIOS.admin)
    await irPara(page, 'Notificações')
    await page.locator('label', { hasText: 'Histórico e teste' }).click()

    await page.locator('#nt-canal').selectOption('EMAIL')
    await page.locator('#nt-destino').fill('invalido')
    await page.locator('[data-act="send-test"]').click()
    await expect(toast(page)).toHaveClass(/err/)

    await page.locator('#nt-destino').fill('teste@example.test')
    await page.locator('[data-act="send-test"]').click()
    await expect(toast(page)).toContainText('Teste enviado')
    await expect(page.locator('[data-notif]').first()).toContainText('Teste de notificação')

    await page.locator('#nt-canal').selectOption('WHATSAPP')
    await page.locator('#nt-destino').fill('(19) 99999-0001')
    await page.locator('[data-act="send-test"]').click()
    await expect(page.locator('.toast', { hasText: 'modo simulado' })).toBeVisible()
    await foto(page, info, 'notificacoes-historico')
  })

  test('quem não administra notificações não vê o menu', async ({ page }) => {
    await entrar(page, USUARIOS.vendedor)
    await expect(page.locator('.nav-item', { hasText: 'Notificações' })).toHaveCount(0)
    await page.goto('/notificacoes')
    await expect(page).not.toHaveURL(/notificacoes/)
  })
})
