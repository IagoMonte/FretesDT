# API x controller

Relação dos 83 endpoints de `/api` com o controller e a ação que os atende. Os controllers ficam em `BACK/app/Http/Controllers`; a regra de negócio fica nos serviços em `BACK/app/Services`. Gerada a partir de `php artisan route:list`.

### `AnttController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/antt/coeficientes` | `index` |
| POST | `/api/antt/coeficientes` | `store` |
| PATCH | `/api/antt/coeficientes/{id}` | `update` |
| POST | `/api/antt/coeficientes/{id}/ativar` | `ativar` |
| POST | `/api/antt/coeficientes/{id}/inativar` | `inativar` |
| GET | `/api/antt/frete-minimo` | `freteMinimo` |
| GET | `/api/distancias/consulta` | `distancia` |

### `AuthController`

| Método | Endpoint | Ação |
|---|---|---|
| POST | `/api/auth/login` | `login` |
| POST | `/api/auth/logout` | `logout` |
| POST | `/api/auth/refresh` | `refresh` |
| GET | `/api/me` | `me` |

### `CadastroController`

| Método | Endpoint | Ação |
|---|---|---|
| POST | `/api/auth/cadastro` | `cadastrar` |
| GET | `/api/cadastros` | `index` |
| POST | `/api/cadastros/{id}/aprovar` | `aprovar` |
| POST | `/api/cadastros/{id}/recusar` | `recusar` |

### `CotacaoController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/cotacoes` | `index` |
| POST | `/api/cotacoes` | `store` |
| GET | `/api/cotacoes/anterior` | `anterior` |
| GET | `/api/cotacoes/{id}` | `show` |
| PATCH | `/api/cotacoes/{id}` | `update` |
| POST | `/api/cotacoes/{id}/cancelar` | `cancelar` |
| POST | `/api/cotacoes/{id}/encaminhar` | `encaminhar` |
| POST | `/api/cotacoes/{id}/encerrar` | `encerrar` |
| POST | `/api/cotacoes/{id}/fechar` | `fechar` |
| POST | `/api/cotacoes/{id}/respostas/{respostaId}/declinar` | `declinar` |
| POST | `/api/cotacoes/{id}/respostas/{respostaId}/responder` | `responder` |

### `EmbalagemController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/embalagens` | `index` |
| POST | `/api/embalagens` | `store` |
| PATCH | `/api/embalagens/{codigo}` | `update` |
| POST | `/api/embalagens/{codigo}/ativar` | `ativar` |
| POST | `/api/embalagens/{codigo}/inativar` | `inativar` |

### `EvolutionController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/evolution/qr` | `qr` |
| GET | `/api/evolution/status` | `status` |
| POST | `/api/evolution/teste` | `teste` |

### `GrupoController`

| Método | Endpoint | Ação |
|---|---|---|
| POST | `/api/grupos` | `store` |
| GET | `/api/grupos/catalogo` | `catalogo` |
| GET | `/api/grupos/detalhes` | `detalhes` |
| PATCH | `/api/grupos/{id}` | `update` |
| POST | `/api/grupos/{id}/ativar` | `ativar` |
| POST | `/api/grupos/{id}/inativar` | `inativar` |

### `NotificacaoController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/notificacoes/historico` | `historico` |
| GET | `/api/notificacoes/preferencias` | `preferencias` |
| POST | `/api/notificacoes/preferencias` | `salvarPreferencias` |
| POST | `/api/notificacoes/teste` | `teste` |
| POST | `/api/notificacoes/{id}/reenviar` | `reenviar` |

### `PainelController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/painel` | `__invoke` |

### `ParteController`

| Método | Endpoint | Ação |
|---|---|---|
| POST | `/api/clientes` | `storeCliente` |
| PATCH | `/api/clientes/{id}` | `updateCliente` |
| POST | `/api/fornecedores` | `storeFornecedor` |
| PATCH | `/api/fornecedores/{id}` | `updateFornecedor` |
| GET | `/api/partes` | `index` |

### `ProdutoController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/produtos` | `index` |
| POST | `/api/produtos` | `store` |
| GET | `/api/produtos/{id}` | `show` |
| PATCH | `/api/produtos/{id}` | `update` |
| POST | `/api/produtos/{id}/ativar` | `ativar` |
| POST | `/api/produtos/{id}/inativar` | `inativar` |

### `ProgramacaoController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/programacoes` | `index` |
| POST | `/api/programacoes` | `store` |
| GET | `/api/programacoes/{id}` | `show` |
| PATCH | `/api/programacoes/{id}` | `update` |
| POST | `/api/programacoes/{id}/cancelar` | `cancelar` |
| POST | `/api/programacoes/{id}/entregar` | `entregar` |
| POST | `/api/programacoes/{id}/status` | `status` |

### `ReferenciaController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/dominios` | `dominios` |
| GET | `/api/grupos` | `grupos` |
| GET | `/api/vendedores` | `vendedores` |

### `SincronizacaoController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/sincronizacao` | `index` |
| GET | `/api/sincronizacao/pendencias` | `pendencias` |
| POST | `/api/sincronizacao/{entidade}/executar` | `executar` |
| POST | `/api/sincronizacao/{entidade}/registro` | `registro` |

### `TransportadoraController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/transportadoras` | `index` |
| POST | `/api/transportadoras` | `store` |
| GET | `/api/transportadoras/{id}` | `show` |
| PATCH | `/api/transportadoras/{id}` | `update` |
| POST | `/api/transportadoras/{id}/ativar` | `ativar` |
| POST | `/api/transportadoras/{id}/inativar` | `inativar` |

### `UsuarioController`

| Método | Endpoint | Ação |
|---|---|---|
| GET | `/api/usuarios` | `index` |
| POST | `/api/usuarios` | `store` |
| GET | `/api/usuarios/{id}` | `show` |
| PATCH | `/api/usuarios/{id}` | `update` |
| POST | `/api/usuarios/{id}/ativar` | `ativar` |
| POST | `/api/usuarios/{id}/bloquear` | `bloquear` |
