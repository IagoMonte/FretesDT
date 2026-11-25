import { defineConfig } from '@playwright/test'

const FRONT = 5174
const BACK = 8100
const PONTE = 8199

export default defineConfig({
  testDir: './specs',
  outputDir: './resultados',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: './relatorio', open: 'never' }]],
  use: {
    baseURL: `http://localhost:${FRONT}`,
    channel: process.env.BROWSER || 'msedge',
    headless: !process.env.E2E_HEADED,
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'desktop', testIgnore: /responsivo\.spec/, use: { viewport: { width: 1440, height: 900 } } },
    { name: 'mobile', testMatch: /(visual|responsivo)\.spec\.js/, use: { viewport: { width: 390, height: 844 }, isMobile: false, hasTouch: true } },
  ],
  webServer: [
    { cwd: '..', command: 'node e2e/support/ponte-stub.mjs', url: `http://127.0.0.1:${PONTE}/__health`, reuseExistingServer: false, env: { PONTE_PORT: String(PONTE) }, timeout: 30_000 },
    { cwd: '..', command: 'node e2e/support/back.mjs', url: `http://127.0.0.1:${BACK}/up`, reuseExistingServer: false, env: { BACK_PORT: String(BACK), PONTE_PORT: String(PONTE) }, timeout: 180_000 },
    { cwd: '..', command: `npx vite build --outDir dist-e2e --emptyOutDir && npx vite preview --outDir dist-e2e --port ${FRONT} --strictPort`, url: `http://localhost:${FRONT}/`, reuseExistingServer: false, env: { API_PROXY: `http://127.0.0.1:${BACK}` }, timeout: 120_000 },
  ],
})
