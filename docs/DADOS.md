# Estrutura de dados

Todas as tabelas usam o prefixo `frete_`. O banco em desenvolvimento e nos testes é SQLite; a aplicação também roda em MySQL. Os esquemas estão em `BACK/database/migrations`.

## Convenções

- Chave primária `id` (UUID) nas entidades; `codigo` (texto) nas tabelas de domínio.
- Colunas de controle em todos os cadastros: `criado_em`, `criado_por`, `atualizado_em`, `atualizado_por`.
- `versao`: controle de concorrência otimista.
- `ativo`: inativação lógica, sem apagar.
- Colunas do espelho do ERP: `codigo_erp`, `erp_hash`, `sincronizado_em`, `origem_cadastro` e `revisao_pendente`.

## Modelo

```
usuario ──< usuario_grupo >── grupo ──< grupo_permissao >── recurso
   │                            └─< grupo_acao >── recurso_acao
   └── transportadora (usuários do perfil transportadora)

cliente / fornecedor
        │
        ▼
cotacao ──< cotacao_item >── produto ──< produto_embalagem >── embalagem
   │
   └──< negociacao ──< negociacao_resposta >── transportadora
            │
            └─ fechamento (transportadora, valor, prazo, piso ANTT)

programacao ──< programacao_item        (pode nascer de uma cotacao)
```

## Tabelas por assunto

| Assunto | Tabelas |
|---|---|
| Acesso | `usuario`, `usuario_identidade`, `usuario_grupo`, `grupo`, `grupo_permissao`, `grupo_acao`, `recurso`, `recurso_acao`, `refresh_token` |
| Cadastros | `cliente`, `fornecedor`, `transportadora`, `produto`, `embalagem`, `produto_embalagem`, `produto_erp` |
| Domínios | `tipo_veiculo`, `tipo_carga_antt`, `municipio` |
| Cotação | `cotacao`, `cotacao_item`, `negociacao`, `negociacao_resposta` |
| Programação | `programacao`, `programacao_item` |
| Piso ANTT | `antt_coeficiente`, `distancia` (pares de municípios IBGE, em km) |
| ERP | `sincronizacao` (estado por entidade, marca d'água e último resultado) |
| Notificações | `notificacao`, `notificacao_preferencia` |
| Auditoria | `log_acesso`, `log_operacao`, `log_sistema` (com dados antes e depois e correlação por requisição) |
| Infra | `sequencia` (códigos como COT-000001 e CRG-000001), `jobs`, `failed_jobs` |

## Tabelas principais

| Tabela | Campos relevantes |
|---|---|
| `cotacao` | `codigo`, `vendedor_id`, `operacao`, `cliente_id` ou `fornecedor_id`, `tipo_veiculo_codigo`, origem e destino (IBGE, cidade, UF), `valor_nf`, `status`, `fracionada`, `dedicado`, `distancia_km`, `cotacao_origem_id` |
| `cotacao_item` | `produto_id`, `embalagem_codigo`, `quantidade_kg`, `volumes`, `paletizado`, `remontado`, `paletes` |
| `negociacao` | `cotacao_id`, `status`, fechamento (`transportadora_id`, `valor_tonelada`, `valor_frete`, `prazo_dias`, `prazo_coleta_dias`), snapshot do piso ANTT |
| `negociacao_resposta` | `transportadora_id`, `status`, `valor_tonelada`, `valor_frete`, `prazo_dias`, `validade`, `encaminhado_em`, `respondido_em` |
| `programacao` | `codigo`, `cotacao_id`, `status`, `pedido_venda`, dados da NF-e, `representante_id`, `transportadora_id`, datas previstas e realizadas de coleta e entrega |
| `notificacao` | `evento`, `canal`, `grupo_codigo`, `destino_nome`, `endereco`, `template`, `status`, `tentativas`, `idempotencia_key` |

## Valores de status

| Entidade | Valores |
|---|---|
| Cotação | `ABERTA`, `EM_NEGOCIACAO`, `FECHADA`, `PROGRAMADA`, `CANCELADA`, `ENCERRADA` |
| Negociação | `ABERTA`, `FECHADA`, `CANCELADA` |
| Resposta | `AGUARDANDO`, `RESPONDIDA`, `DECLINADA` |
| Programação | `EM_COTACAO`, `AGUARDANDO_CARREGAMENTO`, `EM_TRANSITO`, `ENTREGUE`, `CANCELADO`, `DEVOLVIDO` |
| Operação | `CARREGAMENTO`, `RECEBIMENTO` |
