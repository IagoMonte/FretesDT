import { test, USUARIOS, entrar, irPara, foto } from '../support/base.js'
const trocar = (page) => page.route('**/api/painel**', async (route) => {
  const r = await route.fetch(); const j = await r.json()
  j.data.por_status = { ENTREGUE: 11, AGUARDANDO_CARREGAMENTO: 12, EM_COTACAO: 0, EM_TRANSITO: 1, DEVOLVIDO: 0 }
  await route.fulfill({ response: r, json: j })
})
for (const [nome, u] of [['admin', USUARIOS.admin], ['transp', USUARIOS.transportadora]]) {
  for (const w of [1440, 1000, 820, 390]) {
    test(`painel visual ${nome} ${w}`, async ({ page }, info) => {
      await page.setViewportSize({ width: w, height: 900 })
      await trocar(page)
      await entrar(page, u); await irPara(page, 'Painel')
      await page.waitForTimeout(1500)
      await page.locator('.toast').last().evaluate((e) => e.remove()).catch(() => {})
      await foto(page, info, `aud-${nome}-${w}`, { fullPage: true })
    })
  }
}
