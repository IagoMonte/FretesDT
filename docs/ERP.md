# Contrato da API-ponte do ERP

O sistema espelha cadastros de um ERP por meio de uma API-ponte somente leitura. O contrato abaixo é o que o back-end espera; a ponte real deve expor esses campos (a de testes está em `FRONT/e2e/support/ponte-stub.mjs`).

## Configuração

| Variável | Uso |
|---|---|
| `ERP_FRETES_URL` | URL base da ponte |
| `ERP_FRETES_KEY` | Chave enviada no cabeçalho `X-API-Key` |

## Endpoints

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/v1/{entidade}` | Lista paginada. Entidades: `transportadoras`, `clientes`, `fornecedores`, `produtos`, `embalagens` |
| GET | `/api/v1/status` | Última carga completa de cada entidade |

Parâmetros da listagem: `pagina` (inicia em 0), `tamanho`, `codigo`, `cnpj` (só dígitos) e `alteradoDesde` (`AAAA-MM-DDTHH:MM:SS`, não vale para `embalagens`).

Resposta:

```json
{ "itens": [ ... ], "pagina": 0, "tamanho": 200, "totalItens": 1, "totalPaginas": 1 }
```

Status:

```json
[ { "entidade": "clientes", "modo": "COMPLETA", "status": "SUCESSO", "ultimoSucessoEm": "2026-10-13T19:17:57" } ]
```

## Campos

| Entidade | Campos usados |
|---|---|
| Transportadora | `codigo`, `razao_social`, `nome_fantasia`, `cnpj`, `cidade`, `uf`, `telefone`, `email`, `situacao`, `alterado_em` |
| Cliente e fornecedor | `codigo`, `razao_social`, `nome_fantasia`, `cnpj`, `cidade`, `uf`, `situacao`, `alterado_em` |
| Produto | `codigo`, `descricao`, `situacao`, `alterado_em` |
| Embalagem | `codigo`, `descricao` |

- `situacao`: `A` (ativo) ou `I` (inativo); qualquer outro valor inativa o cadastro e gera aviso.
- `cnpj` pode chegar como número, sem os zeros à esquerda; o back recompõe os 14 dígitos. CNPJ vazio, zerado ou com dígito inválido entra em revisão pendente.
- Produtos: vários códigos da ponte podem virar um produto só (nome-base), e cada código fica guardado em `produto_erp`.

## Execução

```bash
php artisan erp:sincroniza                      # todas as entidades habilitadas
php artisan erp:sincroniza CLIENTE --completa   # leitura completa de uma entidade
```

O agendador roda a sincronização a cada minuto e respeita o intervalo configurado por entidade.
