import path from 'node:path'
import { fileURLToPath } from 'node:url'

const aqui = path.dirname(fileURLToPath(import.meta.url))
export const back = path.resolve(aqui, '../../../BACK')
export const banco = path.join(back, 'database', 'e2e.sqlite')

export const env = {
  ...process.env,
  APP_ENV: 'local', APP_DEBUG: 'false', DB_CONNECTION: 'sqlite', DB_DATABASE: banco, DB_PREFIX: 'frete_',
  CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync', SESSION_DRIVER: 'array',
  MAIL_MAILER: 'log', WHATSAPP_DRIVER: 'log',
  ERP_FRETES_URL: `http://127.0.0.1:${process.env.PONTE_PORT || 8199}`, ERP_FRETES_KEY: 'e2e-chave',
  FRETE_ADMIN_LOGIN: 'admin', FRETE_ADMIN_SENHA: 'admin-e2e-local', FRETE_DEV_SENHA: 'demo-e2e-local', FRETE_LOGIN_TENTATIVAS: '100000', FRETE_CADASTRO_TENTATIVAS: '100000',
}
