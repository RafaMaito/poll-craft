# Voting API

Módulo de **API REST** do sistema de votação do **Poll Craft**. Expõe perguntas, votação e resultados em JSON, com códigos HTTP semânticos, rate limiting e validação de payload.

> Depende do módulo [`voting_core`](../voting_core), cujos serviços (`VoteManager` e `QuestionManager`) concentram toda a lógica de negócio. Os controllers aqui são finos — apenas traduzem HTTP ↔ domínio.

---

## Sumário

- [Endpoints](#endpoints)
- [Segurança](#segurança)
- [Mapeamento de erros](#mapeamento-de-erros)
- [Exemplos](#exemplos)
- [Dependências](#dependências)
- [Estrutura de arquivos](#estrutura-de-arquivos)

## Endpoints

| Método | Endpoint                                     | Descrição                             | Controller                             |
| ------ | -------------------------------------------- | ------------------------------------- | -------------------------------------- |
| `GET`  | `/api/voting/questions`                      | Lista as perguntas ativas             | `QuestionApiController::listQuestions` |
| `GET`  | `/api/voting/questions/{identifier}`         | Detalhes de uma pergunta (com opções) | `QuestionApiController::getQuestion`   |
| `POST` | `/api/voting/vote`                           | Registra um voto                      | `VoteApiController::castVote`          |
| `GET`  | `/api/voting/questions/{identifier}/results` | Resultados agregados                  | `ResultsApiController::getResults`     |

Todas as rotas exigem a permissão `access content` e são registradas em `voting_api.routing.yml`.

## Segurança

O **`ApiSecuritySubscriber`** (`src/EventSubscriber/ApiSecuritySubscriber.php`) roda em `KernelEvents::REQUEST` (prioridade 28, após o roteamento) e protege todas as rotas `voting_api.*`:

1. **Rate limiting por IP** — usa a **Flood API** (backed pelo banco), sem registrar o IP nos logs (evita PII).
   - Configuração: `api_rate_limit_per_ip` e `api_rate_limit_window` (em `voting_core.settings`).
   - Resposta `429 Too Many Requests` com headers `X-RateLimit-Limit`, `X-RateLimit-Remaining` e `X-RateLimit-Reset`.
2. **Validação do `POST /api/voting/vote`**:
   - `Content-Type` deve ser `application/json`.
   - Payload limitado a 1 MB.
   - JSON válido e com os campos obrigatórios `question_identifier` e `option_identifier`.

## Mapeamento de erros

As exceções de domínio (`VoteException`) lançadas pelo `VoteManager` são convertidas em códigos HTTP pelo `VoteApiController`:

| `VoteException`         | HTTP  | Significado                   |
| ----------------------- | ----- | ----------------------------- |
| `DISABLED`              | `403` | Votação desativada            |
| `ANONYMOUS_NOT_ALLOWED` | `403` | Usuário anônimo não permitido |
| `VOTING_CLOSED`         | `403` | Prazo de votação encerrado    |
| `RATE_LIMIT`            | `429` | Limite de tentativas excedido |
| `QUESTION_NOT_FOUND`    | `404` | Pergunta não encontrada       |
| `QUESTION_INACTIVE`     | `404` | Pergunta inativa              |
| `INVALID_OPTION`        | `404` | Opção inválida                |
| `DUPLICATE`             | `409` | Voto duplicado                |

Outros códigos usados:

- `400` — JSON inválido, campos ausentes ou `Content-Type` incorreto.
- `403` — resultados desabilitados para a pergunta (`show_results = FALSE`).
- `500` — erro inesperado (a mensagem interna nunca é exposta ao cliente).

## Exemplos

### Listar perguntas

```http
GET /api/voting/questions
```

```json
{
  "questions": [
    {
      "identifier": "favorite-color",
      "title": "What is your favorite color?",
      "description": "Choose one option",
      "show_results": true,
      "created": 1753462809,
      "changed": 1753462809
    }
  ]
}
```

### Detalhes de uma pergunta

```http
GET /api/voting/questions/favorite-color
```

```json
{
  "question": {
    "identifier": "favorite-color",
    "title": "What is your favorite color?",
    "description": "Choose one option",
    "show_results": true,
    "options": [
      {
        "identifier": "red-color",
        "title": "Red",
        "description": "Warm tone",
        "weight": 0
      }
    ]
  }
}
```

### Registrar voto

```http
POST /api/voting/vote
Content-Type: application/json

{
  "question_identifier": "favorite-color",
  "option_identifier": "red-color"
}
```

Resposta de sucesso (`200`):

```json
{
  "message": "Vote registered successfully.",
  "question_identifier": "favorite-color",
  "option_identifier": "red-color"
}
```

Resposta de erro (`400`, `403`, `404`, `409` ou `429`):

```json
{
  "error": "You have already voted for this question."
}
```

### Resultados de uma pergunta

```http
GET /api/voting/questions/favorite-color/results
```

```json
{
  "question": {
    "identifier": "favorite-color",
    "title": "What is your favorite color?",
    "description": "Choose one option",
    "show_results": true
  },
  "results": [
    {
      "option_id": 1,
      "option_identifier": "red-color",
      "option_title": "Red",
      "vote_count": 10,
      "percentage": 50
    }
  ],
  "total_votes": 20
}
```

## Cache

As leituras são cacheadas pelo `QuestionManager` (do `voting_core`):

- Lista de perguntas: tag `question_list`.
- Detalhes: tag `question:{id}`.
- Resultados: tags `question:{id}` e `question_results`, com TTL configurável (`cache_results_ttl`).

O cache é invalidado automaticamente após cada voto e na edição/exclusão de entidades.

## Dependências

- `voting_core:voting_core`

## Estrutura de arquivos

```text
voting_api/
├── src/
│   ├── Controller/
│   │   ├── QuestionApiController.php   # GET /questions e /questions/{identifier}
│   │   ├── ResultsApiController.php    # GET /questions/{identifier}/results
│   │   └── VoteApiController.php       # POST /vote
│   └── EventSubscriber/
│       └── ApiSecuritySubscriber.php   # Rate limiting e validação de payload
├── tests/
│   └── src/Functional/                 # Testes funcionais da API
├── voting_api.info.yml
├── voting_api.routing.yml
└── voting_api.services.yml
```

> Coleção Postman com todos os endpoints: [`postman_colection/voting_api.postman_collection.json`](../../../postman_colection/voting_api.postman_collection.json).
