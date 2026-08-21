<div align="center">

# 🗳️ Poll Craft

**Sistema de votação construído sobre Drupal 11** - entidades próprias, API REST, fila assíncrona e cache em Redis.

![Drupal](https://img.shields.io/badge/Drupal-11-009CDE?logo=drupal&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545?logo=mariadb&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-7-DC382D?logo=redis&logoColor=white)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)

</div>

---

## Sumário

- [Visão geral](#visão-geral)
- [Funcionalidades](#funcionalidades)
- [Arquitetura](#arquitetura)
- [Requisitos](#requisitos)
- [Instalação e execução](#instalação-e-execução)
- [API REST](#api-rest)
- [Configuração](#configuração)
- [Testes e qualidade de código](#testes-e-qualidade-de-código)
- [Benchmark](#benchmark)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Licença](#licença)

## Visão geral

O **Poll Craft** é um sistema de votação implementado como módulos customizados do Drupal 11. Em vez de reutilizar o tipo de conteúdo `node`, o projeto define **entidades próprias** (`question`, `option` e `vote`), separando claramente o domínio de negócio da camada de apresentação.

A lógica central fica em **serviços desacoplados** (`VoteManager` e `QuestionManager`), seguindo os princípios **SOLID** e o padrão de injeção de dependência do Drupal (`create()` + `ContainerInterface`). A exposição ao mundo externo é feita por uma **API REST** com respostas em JSON e códigos HTTP semânticos.

## Funcionalidades

- Entidades customizadas: **Question**, **Option** e **Vote**.
- API REST com JSON e códigos HTTP semânticos (400, 403, 404, 409, 429).
- Rate limiting da API (via Flood API), sem gravar IP em logs.
- Registro de voto **transacional (ACID)** e protegido contra votos duplicados (inclusive em corrida).
- Resultados agregados em **consulta única** (sem N+1).
- Processamento assíncrono com **Queue API** (`ExternalSyncWorker`).
- Invalidação de cache por tags na criação/edição/exclusão de entidades.
- UI administrativa: dashboard, CRUD e configurações.
- Cache em Redis com fallback para o cache padrão.

## Arquitetura

O projeto é composto por dois módulos:

| Módulo                                          | Responsabilidade        | Componentes principais                                                                                              |
| ----------------------------------------------- | ----------------------- | ------------------------------------------------------------------------------------------------------------------- |
| [`voting_core`](web/modules/custom/voting_core) | Domínio de negócio e UI | Entidades (`Question`, `Option`, `Vote`), `VoteManager`, `QuestionManager`, formulários, dashboard e worker de fila |
| [`voting_api`](web/modules/custom/voting_api)   | API REST                | `VoteApiController`, `QuestionApiController`, `ResultsApiController`, `ApiSecuritySubscriber`                       |

### Regras de negócio

O registro de voto (`VoteManager`) valida, em ordem:

1. Votação habilitada globalmente.
2. Voto de usuário anônimo permitido (quando aplicável).
3. Limite de votos por usuário/IP (rate limit).
4. Pergunta existente e ativa.
5. Período de votação dentro do prazo (`voting_end_date`).
6. Opção pertencente à pergunta.
7. Voto duplicado com tratamento de violação de unicidade para evitar corrida.

Cada regra violada lança uma `VoteException` tipada, que a API converte no código HTTP adequado.

### Camadas

- **Entities** - `Question`, `Option`, `Vote` (`ContentEntityType`).
- **Services** - `VoteManager`, `QuestionManager`.
- **Controllers** - interface web (`/voting/...`) e API (`/api/voting/...`).
- **EventSubscribers** - sincronização externa, invalidação de cache e segurança da API.
- **QueueWorker** - `ExternalSyncWorker` para processamento assíncrono.

## Requisitos

| Ferramenta | Versão |
| ---------- | ------ |
| Drupal     | 11     |
| PHP        | 8.3    |
| MariaDB    | 10.11  |
| Redis      | 7      |
| Lando      | 3.x    |
| Composer   | 2.x    |
| Drush      | 13.x   |

> O ambiente é provisionado pelo [Lando](https://lando.dev/). Consulte o [`.lando.yml`](.lando.yml).

## Instalação e execução

### 1. Iniciar o ambiente

```bash
lando start
```

### 2. Instalar as dependências

```bash
lando composer install
```

### 3. Importar o banco de dados

```bash
lando drush sql:cli < database/pollcraft.sql
```

### 4. Aplicar as atualizações de schema

```bash
lando drush updatedb -y
```

### 5. Habilitar os módulos

```bash
lando drush pm:en redis -y
lando drush pm:en voting_core -y
lando drush pm:en voting_api -y
```

### 6. Acessar

A URL principal é exibida ao final do `lando start`. Para conferir todas as URLs (site, phpMyAdmin etc.), execute:

```bash
lando info
```

| Serviço      | URL                                 |
| ------------ | ----------------------------------- |
| Site (HTTPS) | `https://poll-craft.lndo.site:444/` |
| Site (HTTP)  | `http://poll-craft.lndo.site:8000/` |
| phpMyAdmin   | exibida em `lando info`             |

> As portas `8000`/`444` são usadas porque a porta `80` costuma estar ocupada. Elas podem ser ajustadas em `~/.lando/config.yml` (`proxyHttpPort` / `proxyHttpsPort`).

### Credenciais

| Usuário | Senha   |
| ------- | ------- |
| `admin` | `admin` |

### Banco de dados

| Campo    | Valor      |
| -------- | ---------- |
| Host     | `database` |
| Porta    | `3306`     |
| Database | `drupal`   |
| Usuário  | `drupal`   |
| Senha    | `drupal`   |

## API REST

O módulo `voting_api` expõe os seguintes endpoints:

| Método | Endpoint                                     | Descrição                             |
| ------ | -------------------------------------------- | ------------------------------------- |
| `GET`  | `/api/voting/questions`                      | Lista as perguntas ativas             |
| `GET`  | `/api/voting/questions/{identifier}`         | Detalhes de uma pergunta (com opções) |
| `POST` | `/api/voting/vote`                           | Registra um voto                      |
| `GET`  | `/api/voting/questions/{identifier}/results` | Resultados agregados                  |

> Coleção Postman disponível em [`postman_colection/voting_api.postman_collection.json`](postman_colection/voting_api.postman_collection.json).

### Listar perguntas

`GET /api/voting/questions`

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

`GET /api/voting/questions/favorite-color`

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
      },
      {
        "identifier": "blue-color",
        "title": "Blue",
        "description": "Cold tone",
        "weight": 1
      }
    ]
  }
}
```

### Registrar voto

`POST /api/voting/vote`

```json
{
  "question_identifier": "favorite-color",
  "option_identifier": "red-color"
}
```

Resposta de sucesso:

```json
{
  "message": "Vote registered successfully.",
  "question_identifier": "favorite-color",
  "option_identifier": "red-color"
}
```

Resposta de erro:

```json
{
  "error": "Invalid JSON payload."
}
```

### Resultados de uma pergunta

`GET /api/voting/questions/favorite-color/results`

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

## Configuração

- **Dashboard administrativo**: `/admin/voting/dashboard`
- **Configurações do sistema**: `/admin/config/system/voting`

Permissões disponíveis:

| Permissão                     | Descrição                                  |
| ----------------------------- | ------------------------------------------ |
| `administer_voting_questions` | Criar, editar e excluir perguntas e opções |
| `administer_voting_votes`     | Visualizar, analisar e excluir votos       |
| `administer_voting_settings`  | Configurar o sistema globalmente           |
| `view_voting_results`         | Ver resultados agregados                   |
| `cast_vote`                   | Votar em perguntas ativas                  |
| `access_voting_api`           | Acessar os endpoints da API                |

## Testes e qualidade de código

O projeto usa **PHPUnit**, **PHPStan** (nível 6) e **PHP_CodeSniffer** (padrões `Drupal` + `DrupalPractice`).

### Comandos (via Lando)

```bash
lando test      # PHPUnit com --testdox
lando phpstan   # Análise estática
lando phpcs     # Verificação de padrões de código
lando phpcbf    # Correção automática de padrões
```

### Comandos (via Composer)

```bash
composer test
composer stan
composer cs
composer cbf
composer qa    # cs + stan + test
```

### Suítes de teste

| Suíte      | Comando                                       |
| ---------- | --------------------------------------------- |
| Unit       | `lando test -- --testsuite voting-unit`       |
| Kernel     | `lando test -- --testsuite voting-kernel`     |
| Functional | `lando test -- --testsuite voting-functional` |

> Os testes Kernel e Functional exigem um banco de dados de teste (`SIMPLETEST_DB`), já configurado no [`.lando.yml`](.lando.yml).

## Benchmark

Carga aplicada com `ab -n 10000 -c 50 <URL>/api/voting/questions`:

| Cenário   | Requisições/s | Latência média | Erros |
| --------- | ------------- | -------------- | ----- |
| Sem Redis | 144,39        | 346 ms         | 0     |
| Com Redis | 149,50        | 334 ms         | 0     |

## Estrutura do projeto

```text
.
├── .lando.yml                # Configuração do Lando
├── composer.json             # Dependências e scripts
├── phpstan.neon              # Configuração do PHPStan
├── phpunit.xml.dist          # Configuração do PHPUnit
├── config/sync/              # Configuração exportada do Drupal
├── database/pollcraft.sql    # Dump do banco de dados
├── postman_colection/        # Coleção Postman
└── web/
    └── modules/
        └── custom/
            ├── voting_core/
            │   ├── src/Service/            # VoteManager, QuestionManager
            │   ├── src/Entity/             # Question, Option, Vote
            │   ├── src/Controller/         # Dashboard e interface web
            │   ├── src/Form/               # Formulários
            │   ├── src/EventSubscriber/    # Sync externo e cache
            │   ├── src/Plugin/QueueWorker/ # Worker de fila
            │   └── tests/                  # Testes Unit e Kernel
            └── voting_api/
                ├── src/Controller/         # Endpoints REST
                ├── src/EventSubscriber/    # Segurança/rate limiting
                └── tests/                  # Testes Functional
```

