# Comandos úteis

## Back-end (`BACK/`)

| Comando | O que faz |
|---|---|
| `php artisan serve --host=127.0.0.1 --port=8000` | Sobe a API local |
| `php artisan migrate:fresh --seed` | Recria o banco e popula (exige `FRETE_ADMIN_LOGIN`, `FRETE_ADMIN_SENHA` e, fora de produção, `FRETE_DEV_SENHA`) |
| `php artisan frete:jwt-keys [--force]` | Gera o par de chaves RSA para os tokens. `--force` invalida os tokens existentes |
| `php artisan antt:importa-coeficientes [arquivo.csv]` | Importa os coeficientes do piso mínimo |
| `php artisan antt:baixa-distancias [--csv= --municipios= --force]` | Baixa municípios do IBGE e distâncias rodoviárias (idempotente) |
| `php artisan erp:sincroniza [ENTIDADE] [--completa] [--codigo=] [--cnpj=]` | Espelha dados do ERP: `PRODUTO`, `CLIENTE`, `FORNECEDOR`, `TRANSPORTADORA` ou `EMBALAGEM` |
| `php artisan schedule:work` | Roda o agendador local (sincronização do ERP e limpeza de logs antigos) |
| `php artisan queue:work` | Processa a fila de notificações |
| `php artisan test` | Suíte PHPUnit |
| `vendor/bin/pint` | Formatação do código PHP |

## Front-end (`FRONT/`)

| Comando | O que faz |
|---|---|
| `npm run dev` | Servidor de desenvolvimento. `/api` é encaminhado para `127.0.0.1:8000`; use `API_PROXY=http://host:porta` para outra API |
| `npm test` | Testes unitários (Vitest) |
| `npm run test:watch` | Vitest em modo contínuo |
| `npm run e2e` | Testes ponta a ponta (Playwright), com back, ponte de ERP simulada e front descartáveis |
| `npm run e2e:report` | Abre o relatório do último E2E |
| `npm run build` | Build de produção em `dist/` |

## WhatsApp local (`evolution/`)

```bash
cd evolution
cp .env.example .env     # preencha AUTHENTICATION_API_KEY e POSTGRES_PASSWORD
docker compose up -d
```

No back, use `WHATSAPP_DRIVER=evolution` (ou `log` para só registrar as mensagens) e aponte `WHATSAPP_EVOLUTION_URL` para `http://127.0.0.1:8181`.
