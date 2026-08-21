# Voting Core

Módulo central do sistema de votação do **Poll Craft**. Define as entidades de domínio (`Question`, `Option` e `Vote`), a lógica de negócio, a interface administrativa e o processamento assíncrono de sincronização externa.

> O `voting_api` depende deste módulo e consome os serviços `VoteManager` e `QuestionManager` para expor a API REST.

---

## Sumário

- [Visão geral](#visão-geral)
- [Entidades](#entidades)
- [Serviços](#serviços)
- [Regras de negócio do voto](#regras-de-negócio-do-voto)
- [Eventos e assinantes](#eventos-e-assinantes)
- [Fila assíncrona](#fila-assíncrona)
- [Comando Drush](#comando-drush)
- [Interface administrativa](#interface-administrativa)
- [Permissões](#permissões)
- [Configuração](#configuração)
- [Cache](#cache)
- [Dependências](#dependências)
- [Estrutura de arquivos](#estrutura-de-arquivos)

## Visão geral

O módulo segue os princípios **SOLID** e o padrão de injeção de dependência do Drupal (`create()` + `ContainerInterface`). Toda a regra de negócio fica nos serviços, mantendo controllers e formulários finos.

Em vez de reutilizar o tipo de conteúdo `node`, o sistema usa **entidades próprias** (`ContentEntityType`), o que:

- desacopla o domínio de votação da camada de conteúdo padrão;
- permite constraints e índices próprios no nível do banco;
- expõe um identificador de máquina (`identifier`) estável para integrações externas.

## Entidades

### `question` — Pergunta

Tabela: `voting_question`

| Campo                 | Tipo         | Descrição                                                               |
| --------------------- | ------------ | ----------------------------------------------------------------------- |
| `identifier`          | string (128) | Identificador único para aplicações externas (constraint `UniqueField`) |
| `title`               | string (255) | Título da pergunta (label da entidade)                                  |
| `description`         | string_long  | Descrição opcional                                                      |
| `show_results`        | boolean      | Se `FALSE`, resultados não são expostos via API/UI                      |
| `status`              | boolean      | Pergunta ativa para votação                                             |
| `voting_end_date`     | datetime     | Data de encerramento automático da votação                              |
| `created` / `changed` | timestamp    | Datas de criação e alteração                                            |

### `option` — Opção

Tabela: `voting_option`

| Campo                 | Tipo             | Descrição                                  |
| --------------------- | ---------------- | ------------------------------------------ |
| `question`            | entity_reference | Referência obrigatória à pergunta          |
| `identifier`          | string (128)     | Identificador único **dentro da pergunta** |
| `title`               | string (255)     | Título da opção                            |
| `description`         | string_long      | Descrição opcional                         |
| `image`               | image            | Imagem opcional (png/jpg/gif, máx. 2 MB)   |
| `weight`              | integer          | Ordem de exibição                          |
| `created` / `changed` | timestamp        | Datas de criação e alteração               |

> Índice único `question_identifier_unique (question, identifier)` garante que não haja duas opções com o mesmo `identifier` na mesma pergunta.

### `vote` — Voto

Tabela: `voting_vote`

| Campo      | Tipo             | Descrição         |
| ---------- | ---------------- | ----------------- |
| `question` | entity_reference | Pergunta votada   |
| `option`   | entity_reference | Opção escolhida   |
| `user_id`  | entity_reference | Usuário que votou |
| `created`  | timestamp        | Data do voto      |

> Índice único `question_user_unique (question, user_id)` — cada usuário vota **uma única vez** por pergunta, inclusive sob concorrência.

## Serviços

### `VoteManager` (`voting_core.vote_manager`)

Centraliza o registro de votos com **transação ACID** e validações em camadas.

- `castVote(string $questionIdentifier, string $optionIdentifier): void`
  - valida todas as regras de negócio;
  - abre transação para a checagem de duplicidade + inserção;
  - trata violação de unicidade (corrida) e converte em `VoteException::DUPLICATE`;
  - dispara o evento `VoteEvent` após o commit.

### `QuestionManager` (`voting_core.question_manager`)

Operações de leitura e normalização para a API.

- `getActiveQuestionsForApi(): array` — lista perguntas ativas normalizadas (com cache).
- `getQuestionForApi(string $identifier): ?array` — detalhes de uma pergunta com opções.
- `getResultsForApi(string $identifier): ?array` — resultados agregados.
- Agrega os votos em **uma única consulta com `GROUP BY`** (sem N+1).
- Normaliza os dados para o formato JSON da API (inclui `image_url` quando houver).

## Regras de negócio do voto

O `VoteManager::castVote()` valida, em ordem:

1. Votação habilitada globalmente (`voting_enabled`).
2. Voto anônimo permitido (quando aplicável).
3. Rate limit por usuário (`max_votes_per_hour`).
4. Pergunta existente.
5. Pergunta ativa (`status`).
6. Prazo de votação (`voting_end_date`).
7. Opção pertencente à pergunta.
8. Voto duplicado — com tratamento de violação de unicidade para evitar corrida.

Cada violação lança uma `VoteException` tipada (em `src/Exception/VoteException.php`) com um código de erro:

| Código                  | Significado                    |
| ----------------------- | ------------------------------ |
| `DISABLED`              | Votação desativada             |
| `ANONYMOUS_NOT_ALLOWED` | Usuário anônimo não permitido  |
| `RATE_LIMIT`            | Limite de tentativas excedido  |
| `QUESTION_NOT_FOUND`    | Pergunta não encontrada        |
| `QUESTION_INACTIVE`     | Pergunta inativa               |
| `VOTING_CLOSED`         | Prazo de votação encerrado     |
| `INVALID_OPTION`        | Opção inválida para a pergunta |
| `DUPLICATE`             | Voto duplicado                 |

## Eventos e assinantes

- **`VoteEvent`** (`src/Event/VoteEvent.php`) — disparado após um voto ser registrado.
- **`ExternalSyncSubscriber`** — enfileira um job de sincronização externa quando `external_sync_enabled` está ativo.
- **`VoteCacheInvalidationSubscriber`** — invalida o cache de resultados (`question:{id}`, `question_results`) após cada voto, via `CacheTagsInvalidatorInterface` injetado.

## Fila assíncrona

**`ExternalSyncWorker`** (`src/Plugin/QueueWorker/ExternalSyncWorker.php`)

- `@QueueWorker(id = "voting_core_external_sync", cron = {"time" = 60})`.
- Processa os itens enfileirados e envia os votos para uma API externa via `http_client` (Guzzle).
- Mantém as chamadas HTTP **fora** do ciclo request/response, garantindo escalabilidade e desacoplamento.

## Comando Drush

**`voting:create-questions`** (alias `vcq`)

```bash
lando drush vcq 10 4   # cria 10 perguntas com 4 opções cada
```

Cria perguntas e opções de exemplo para desenvolvimento/teste.

## Interface administrativa

| Rota                                     | Descrição                                              |
| ---------------------------------------- | ------------------------------------------------------ |
| `/admin/voting/dashboard`                | Dashboard com estatísticas (contagens via count query) |
| `/admin/config/system/voting`            | Configurações globais do sistema                       |
| `/admin/content/questions`               | Lista de perguntas (CRUD)                              |
| `/admin/content/options`                 | Lista de opções (CRUD)                                 |
| `/admin/content/votes`                   | Lista de votos registrados                             |
| `/voting/questions`                      | Lista pública de perguntas                             |
| `/voting/questions/{identifier}`         | Página de votação                                      |
| `/voting/questions/{identifier}/results` | Resultados públicos                                    |

## Permissões

| Permissão                     | Descrição                                  |
| ----------------------------- | ------------------------------------------ |
| `administer_voting_questions` | Criar, editar e excluir perguntas e opções |
| `administer_voting_votes`     | Visualizar, analisar e excluir votos       |
| `administer_voting_settings`  | Configurar o sistema globalmente           |
| `view_voting_results`         | Ver resultados agregados                   |
| `cast_vote`                   | Votar em perguntas ativas                  |
| `access_voting_api`           | Acessar os endpoints da API                |

## Configuração

Arquivo de defaults: `config/install/voting_core.settings.yml`

| Chave                    | Default | Descrição                                 |
| ------------------------ | ------- | ----------------------------------------- |
| `voting_enabled`         | `TRUE`  | Habilita/desabilita a votação globalmente |
| `allow_anonymous_voting` | `FALSE` | Permite votos de usuários anônimos        |
| `max_votes_per_question` | `1`     | Votos por usuário por pergunta            |
| `max_votes_per_hour`     | `0`     | Rate limit por usuário (0 = ilimitado)    |
| `cache_results_ttl`      | `300`   | TTL do cache de resultados (segundos)     |
| `api_rate_limit_per_ip`  | `100`   | Requisições por IP (janela)               |
| `api_rate_limit_window`  | `300`   | Janela do rate limit da API (segundos)    |
| `external_sync_enabled`  | `FALSE` | Habilita a sincronização externa          |
| `external_api_url`       | `""`    | URL da API externa                        |
| `external_api_key`       | `""`    | Chave da API externa                      |

## Cache

- Lista de perguntas: cache com tag `question_list`.
- Detalhes da pergunta: cache com tag `question:{id}`.
- Resultados: cache com tags `question:{id}` e `question_results`, com TTL configurável.
- Invalidação automática por tags na criação/edição/exclusão de entidades e após cada voto.

## Dependências

- `drupal:user`
- `drupal:system`
- `drupal:field`
- `drupal:image`

## Estrutura de arquivos

```text
voting_core/
├── config/
│   ├── install/voting_core.settings.yml   # Configuração padrão
│   └── schema/voting_core.schema.yml      # Schema de configuração
├── css/
│   └── voting_core.front.css              # Estilos do front
├── src/
│   ├── Commands/VotingCoreCommands.php    # Comando Drush
│   ├── Controller/                        # Dashboard e interface pública
│   ├── Entity/                            # Question, Option, Vote
│   │   └── Handler/                       # List builders e view builder
│   ├── Event/VoteEvent.php                # Evento pós-voto
│   ├── EventSubscriber/                   # Sync externo e invalidação de cache
│   ├── Exception/VoteException.php        # Exceções de domínio
│   ├── Form/                              # Formulários
│   ├── Plugin/QueueWorker/                # Worker da fila
│   └── Service/                           # VoteManager, QuestionManager
├── templates/                             # Templates Twig
├── tests/                                 # Testes Unit e Kernel
├── voting_core.info.yml
├── voting_core.install                    # Schema e updates
├── voting_core.module                     # Hooks
├── voting_core.permissions.yml
├── voting_core.routing.yml
├── voting_core.services.yml
└── voting_core.links.{menu,action}.yml
```
