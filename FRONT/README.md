# FretesDT: FRONT

Aplicação web do sistema de gestão de fretes. Vue 3, Vite e Tailwind 4.

## Rodar em desenvolvimento

```bash
npm install
npm run dev
```

O Vite encaminha `/api` para `http://127.0.0.1:8000` (o back-end). Outra API: `API_PROXY=http://host:porta npm run dev`.

## Testes

```bash
npm test        # unitários (vitest)
npm run e2e     # ponta a ponta (playwright)
npm run build   # build de produção em dist/
```
