import { test, expect, USUARIOS, entrar, foto } from '../support/base.js'

const LARGURAS = [360, 390, 768, 1024, 1440]
const ROTAS = ['dash', 'quotes', 'products', 'packagings', 'carriers', 'antt', 'users', 'loads']

/** Elementos cuja borda direita passa da janela, fora de áreas que rolam por conta própria (.tbl-wrap) ou escondidas. */
const vazamentos = (page) => page.evaluate(() => {
  const iw = window.innerWidth
  const rolavel = (el) => el.closest('.tbl-wrap, [style*="overflow"], .toasts, .modal-ov')
  const achados = []
  for (const el of document.body.querySelectorAll('*')) {
    const r = el.getBoundingClientRect()
    if (!r.width || !r.height || r.right <= iw + 1 || rolavel(el)) continue
    const cs = getComputedStyle(el)
    if (cs.position === 'fixed' || cs.visibility === 'hidden' || cs.display === 'none') continue
    achados.push(`${el.tagName.toLowerCase()}${el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''} (direita ${Math.round(r.right)} > ${iw})`)
    if (achados.length >= 5) break
  }
  return achados
})

test('nenhuma tela rola na horizontal em nenhuma largura', async ({ page }, info) => {
  test.setTimeout(300_000)
  await entrar(page, USUARIOS.admin)
  for (const w of LARGURAS) {
    await page.setViewportSize({ width: w, height: 900 })
    for (const rota of ROTAS) {
      await page.goto('/' + rota)
      await page.locator('main, .main, .content, .page').first().waitFor()
      await page.waitForTimeout(700)
      const { sw, iw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }))
      expect.soft(sw, `/${rota} a ${w}px: página rola na horizontal`).toBeLessThanOrEqual(iw + 1)
      const v = await vazamentos(page)
      expect.soft(v, `/${rota} a ${w}px: elementos fora da janela`).toEqual([])
      if (v.length || sw > iw + 1) await foto(page, info, `vazamento-${rota}-${w}`, { fullPage: true })
    }
  }
})

test('menu lateral abre e fecha no celular', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await entrar(page, USUARIOS.vendedor)
  const toggle = page.locator('[data-act="toggle-nav"]')
  await expect(toggle).toBeVisible()
  await toggle.click()
  await expect(page.locator('.nav-item').first()).toBeVisible()
  await foto(page, info, 'menu-aberto-celular')
  await page.locator('.nav-item', { hasText: /Programação/i }).first().click()
  await expect(page).toHaveURL(/\/loads/)
})

test('topbar a 360px: chip mostra só o perfil e o título não estoura', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 640 })
  await entrar(page, USUARIOS.transportadora)
  await expect(page.locator('.rolechip .rc-role')).toBeVisible()
  await expect(page.locator('.rolechip .rc-name')).toBeHidden()
  const chip = await page.locator('.rolechip').boundingBox()
  expect(chip.x + chip.width).toBeLessThanOrEqual(360)
  const h1 = await page.locator('.topbar h1').evaluate((el) => el.scrollWidth <= el.clientWidth)
  expect(h1).toBe(true)
})

test('modal cabe na tela do celular e o botão principal fica alcançável', async ({ page }, info) => {
  await page.setViewportSize({ width: 360, height: 640 })
  await entrar(page, USUARIOS.admin)
  await page.goto('/quotes')
  await page.locator('[data-act="new-quote"]').click()
  const salvar = page.locator('[data-act="save-quote"]')
  await expect(salvar).toBeVisible()
  const caixa = await salvar.boundingBox()
  expect(caixa.x + caixa.width).toBeLessThanOrEqual(360 + 1)
  await foto(page, info, 'modal-cotacao-360')
})
