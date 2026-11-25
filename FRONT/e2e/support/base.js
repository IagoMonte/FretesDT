import { test as base, expect, request as pwRequest } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export { expect }

export const API = 'http://127.0.0.1:8100/api'
export const PONTE = 'http://127.0.0.1:8199'
export const SHOTS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../shots')

/** login, senha (vêm do DevSeeder / AdminSeeder do back) */
export const USUARIOS = {
  admin: ['admin', 'admin-e2e-local'],
  vendedor: ['marcos', 'demo-e2e-local'],
  vendedora: ['lucia', 'demo-e2e-local'],
  transportadora: ['arco', 'demo-e2e-local'],
  outraTransportadora: ['rotasul', 'demo-e2e-local'],
}

/** Erros esperados pelo app (4xx de validação, 401 de sessão...) aparecem no console do navegador; só o que é bug entra. */
const RUIDO = [/Failed to load resource: the server responded with a status of 4\d\d/]

export const test = base.extend({
  auditoria: [async ({ page }, use) => {
    const problemas = []
    page.on('pageerror', (e) => problemas.push(`pageerror: ${e.message}`))
    page.on('console', (m) => { if (m.type() === 'error' && !RUIDO.some((r) => r.test(m.text()))) problemas.push(`console: ${m.text()}`) })
    page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 500) problemas.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`) })
    await use(problemas)
    expect(problemas, 'erros de JS, console ou 5xx durante o teste').toEqual([])
  }, { auto: true }],
})

/** Login pela tela. */
export async function entrar(page, [login, senha]) {
  await page.goto('/login')
  await page.locator('#lgUser').fill(login)
  await page.locator('#lgPass').fill(senha)
  await page.getByRole('button', { name: /Entrar na operação/ }).click()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 25_000 })
  await expect(page.locator('.nav-item').first()).toBeAttached()
}

/** Vai a uma tela pelo menu lateral (como o usuário). */
export async function irPara(page, rotulo) {
  const toggle = page.locator('[data-act="toggle-nav"]')
  if (await toggle.isVisible()) await toggle.click()
  await page.locator('.nav-item', { hasText: rotulo }).first().click()
}

/** Print para auditoria visual: e2e/shots/<projeto>/<nome>.png */
export async function foto(page, testInfo, nome, opcoes = {}) {
  const dir = path.join(SHOTS, testInfo.project.name)
  fs.mkdirSync(dir, { recursive: true })
  await page.waitForTimeout(350)
  const arquivo = path.join(dir, `${nome}.png`)
  await page.screenshot({ path: arquivo, fullPage: opcoes.fullPage ?? false })
  await testInfo.attach(nome, { path: arquivo, contentType: 'image/png' })
  return arquivo
}

/** Cliente da API autenticado (para preparar/conferir dados e testar permissões sem passar pela tela). */
export async function api([login, senha]) {
  const ctx = await pwRequest.newContext({ baseURL: API + '/' })
  const r = await ctx.post('auth/login', { data: { login, senha } })
  if (!r.ok()) throw new Error(`login da API falhou para ${login}: ${r.status()}`)
  const { access_token } = await r.json()
  await ctx.dispose()
  const c = await pwRequest.newContext({ baseURL: API + '/', extraHTTPHeaders: { Authorization: `Bearer ${access_token}`, Accept: 'application/json' } })
  return c
}

/** Sem token. */
export const anonimo = () => pwRequest.newContext({ baseURL: API + '/', extraHTTPHeaders: { Accept: 'application/json' } })

/** Controle da ponte falsa. */
export async function ponte(acao, corpo) {
  const c = await pwRequest.newContext()
  const r = await c.post(`${PONTE}/__${acao}`, { data: corpo ?? {} })
  const j = await r.json()
  await c.dispose()
  return j
}
/** Dados atuais de uma rota da ponte falsa. */
export async function ponteDados(rota) {
  const c = await pwRequest.newContext()
  const r = await c.get(`${PONTE}/api/v1/${rota}?tamanho=200`, { headers: { 'X-API-Key': 'e2e-chave' } })
  const j = await r.json()
  await c.dispose()
  return j.itens
}
export async function chamadasDaPonte() {
  const c = await pwRequest.newContext()
  const r = await c.get(`${PONTE}/__calls`)
  const j = await r.json()
  await c.dispose()
  return j
}
