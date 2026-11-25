# API REST

Base: `/api`. Corpo e resposta em JSON. Exigem `Authorization: Bearer <access_token>` todas as rotas, exceto `/auth/login`, `/auth/refresh` e `/auth/cadastro`. O endpoint `/up` (verificação de saúde) é público.

## Convenções

- **Sucesso:** `{ "data": ..., "meta": { ... } }`. Listas trazem `meta.total` e parâmetros de paginação; operações de escrita podem trazer `meta.alertas`.
- **Erro de validação:** HTTP 422 com `{ "message": "...", "errors": { campo: [...] } }`.
- **Sem permissão ou fora do escopo:** 403 ou 404, conforme o caso.
- **Conflito de versão:** registros editáveis têm o campo `versao`. O `PATCH` deve enviar a `versao` lida; se outra pessoa alterou antes, a API recusa.
- **Idempotência:** `POST /cotacoes` aceita o cabeçalho `Idempotency-Key`. Repetir a mesma chave devolve a cotação já criada.
- **Ativar e inativar:** cadastros não são apagados; usam `POST .../inativar` e `POST .../ativar`.
- **Escopo por perfil:** cada rota exige uma permissão do perfil logado, e a transportadora só enxerga os próprios registros.

## Autenticação

| Método | Rota | Descrição |
|---|---|---|
| POST | `/auth/login` | `{ login, senha }`. Devolve `access_token`, `refresh_token` e o usuário. |
| POST | `/auth/refresh` | `{ refresh_token }`. Renova o par de tokens. |
| POST | `/auth/cadastro` | Auto-cadastro, que fica pendente de aprovação. |
| POST | `/auth/logout` | Encerra a sessão. |
| GET | `/me` | Usuário logado, grupos e permissões. |
| GET | `/dominios` | Listas de domínio (tipos de veículo, embalagens, status, etc.). |

## Cotações

| Método | Rota | Descrição |
|---|---|---|
| GET | `/cotacoes` | Lista com filtros e paginação. |
| GET | `/cotacoes/anterior` | Cotação anterior reaproveitável (mesma rota, veículo e produto). |
| GET | `/cotacoes/{id}` | Detalhe, com negociação e respostas. |
| POST | `/cotacoes` | Cria a cotação (fracionada ou granel). |
| PATCH | `/cotacoes/{id}` | Edita, com controle de versão. |
| POST | `/cotacoes/{id}/encaminhar` | Envia a cotação às transportadoras escolhidas. |
| POST | `/cotacoes/{id}/respostas/{respostaId}/responder` | A transportadora responde com valor, prazo e validade. |
| POST | `/cotacoes/{id}/respostas/{respostaId}/declinar` | A transportadora declina. |
| POST | `/cotacoes/{id}/fechar` | Fecha a negociação com uma resposta. |
| POST | `/cotacoes/{id}/encerrar` | Encerra sem fechar. |
| POST | `/cotacoes/{id}/cancelar` | Cancela. |

## Programações (cargas)

| Método | Rota | Descrição |
|---|---|---|
| GET | `/programacoes` | Lista com filtros. |
| GET | `/programacoes/{id}` | Detalhe. |
| POST | `/programacoes` | Cria, com ou sem cotação de origem. |
| PATCH | `/programacoes/{id}` | Edita. |
| POST | `/programacoes/{id}/status` | Muda o status (aguardando carregamento, em trânsito, etc.). |
| POST | `/programacoes/{id}/entregar` | Confirma a entrega. |
| POST | `/programacoes/{id}/cancelar` | Cancela. |

## Cadastros

Cada recurso abaixo tem listagem (`GET`), criação (`POST`), edição (`PATCH`) e `inativar`/`ativar`.

| Recurso | Rotas |
|---|---|
| Transportadoras | `/transportadoras`, `/transportadoras/{id}` |
| Produtos | `/produtos`, `/produtos/{id}` |
| Embalagens | `/embalagens`, `/embalagens/{codigo}` |
| Clientes e fornecedores | `GET /partes` (busca unificada), `POST/PATCH /clientes`, `POST/PATCH /fornecedores` |
| Usuários | `/usuarios`, `/usuarios/{id}`, `POST /usuarios/{id}/bloquear` e `/ativar` |
| Grupos | `/grupos`, `/grupos/catalogo`, `/grupos/detalhes`, `/grupos/{id}` |
| Vendedores | `GET /vendedores` |
| Cadastros pendentes | `GET /cadastros`, `POST /cadastros/{id}/aprovar` e `/recusar` |

## Piso mínimo (ANTT)

| Método | Rota | Descrição |
|---|---|---|
| GET/POST/PATCH | `/antt/coeficientes[/{id}]` | Coeficientes por tipo de carga e eixos. |
| GET | `/distancias/consulta` | Distância rodoviária entre dois municípios. |
| GET | `/antt/frete-minimo` | Piso calculado para a rota, o veículo e o tipo de carga. |

## Sincronização com o ERP

| Método | Rota | Descrição |
|---|---|---|
| GET | `/sincronizacao` | Estado da sincronização de cada entidade. |
| GET | `/sincronizacao/pendencias` | Cadastros que pedem revisão. |
| POST | `/sincronizacao/{entidade}/executar` | Executa a sincronização sob demanda. |
| POST | `/sincronizacao/{entidade}/registro` | Sincroniza um registro específico. |

## Notificações

| Método | Rota | Descrição |
|---|---|---|
| GET/POST | `/notificacoes/preferencias` | Matriz evento x canal x grupo. |
| GET | `/notificacoes/historico` | Histórico de envios. |
| POST | `/notificacoes/teste` | Envio de teste. |
| POST | `/notificacoes/{id}/reenviar` | Reenvia uma notificação. |
| GET | `/evolution/status`, `/evolution/qr` | Estado e pareamento do WhatsApp (Evolution API). |
| POST | `/evolution/teste` | Mensagem de teste pelo WhatsApp. |

## Painel

| Método | Rota | Descrição |
|---|---|---|
| GET | `/painel` | Indicadores e rankings operacionais. |

## Painel administrativo (web)

O back também serve um painel em `/admin` (login por sessão), com visão geral, transportadoras, vendedores, custos e auditoria.
