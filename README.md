
# Sistema de Votação em Drupal!

## Sistema de perguntas desenvolvido em **Drupal 11**

Práticas de desenvolvimento usadas:
Drupal:
- Entidades customizadas.
- Serviços desacoplados para lógica central.
- Controladores próprios para API e interface pública.
- Formulários personalizados para CRUD administrativo.
- Templates Twig simples.
Outras tecnologias:
- Lando
- Redis
- Postman
---

## Estrutura do Projeto

O projeto contém dois modulos principais:

| Módulo | Responsabilidade | Arquivos Principais |
|--------|------------------|---------------------|
| **voting_core** | - Entities (Question, Option, Vote)<br>- Business Logic (VoteManager)<br>- Transactions & Validações<br>- Queue Processing<br>- Admin UI | - VoteManager.php<br>- QuestionManager.php<br>- ExternalSyncWorker.php<br>- AdminDashboardController.php |
| **voting_api** | - REST Endpoints<br>- JSON Responses<br>- HTTP Status Codes<br>- Rate Limiting (subscriber) | - VoteApiController.php<br>- QuestionApiController.php<br>- ResultsApiController.php |

---

# API Externa

O módulo **voting_api** fornece acesso a:

| Método | Endpoint | Função |
|--------|----------|---------|
| GET | `/api/voting/questions` | Lista perguntas ativas |
| GET | `/api/voting/questions/{identifier}` | Retorna pergunta completa |
| POST | `/api/voting/questions/{identifier}/vote` | Registra voto |
| GET | `/api/voting/questions/{identifier}/results` | Retorna resultados |

### Características:
- Implementada manualmente
- Controllers dedicados
- Postman
- JSON com:
  - question
  - options
  - vote count
  - status flags

### Endpoints

#### Listar questions

*Endpoint:*

   GET /api/voting/questions

*Exemplo de Request:*

    /api/voting/questions

*Exemplo Response:*

```
{
  "status": "success",
  "time": 1753462809,
  "data": {
      "page": 1,
      "per_page": 10,
      "total": 2,
      "total_pages": 1,
      "data": [
          {
              "identifier": "favorite-color",
              "title": "What is your favorite color?"
          },
          {
              "identifier": "best-animal",
              "title": "Which animal do you prefer?"
          }
      ]
  }
}

```
#### Detalhes question

*Endpoint:*

   GET /api/voting/questions/{identifier}

*Exemplo de Request:*

    /api/voting/questions/favorite-color

*Exemplo de Request:*

/api/voting/questions/favorite-color

Exemplo Response:

```
{
  "status": "success",
  "time": 1753462809,
  "data": {
      "identifier": "favorite-color",
      "title": "What is your favorite color?",
      "description": "Choose one option",
      "options": [
          {
              "identifier": "red-color",
              "title": "Red",
              "description": "Warm tone"
          },
          {
              "identifier": "blue-color",
              "title": "Blue",
              "description": "Cold tone"
          }
      ]
  }
}
```

#### Registrar voto
*Endpoint:*

   POST /api/voting/vote

*Exemplo de Request:*

    /api/voting/vote

*Body:*
```
{
  "question_identifier": "favorite-color",
  "option_identifier": "red-color"
}
```

*Exemplo de Response:*

```
{
  "status": "success",
  "time": 1753462809,
  "message": "Vote registered successfully."
}
```
*Exemplo de Error Response:*

```
{
  "status": "error",
  "time": 1753462809,
  "message": "Invalid JSON payload."
}
```

#### Detalhes resultado de votos

*Endpoint:*

   GET /api/voting/questions/{identifier}/results

*Exemplo de Request:*

    /api/voting/questions/favorite-color/results

*Exemplo de Reesponse:*

```
{
  "status": "success",
  "time": 1753462809,
  "data": {
    "identifier": "favorite-color",
    "title": "What is your favorite color?",
    "results": [
      {
        "option": "red-color",
        "title": "Red",
        "votes": 10,
        "percentage": 50
      },
      {
        "option": "blue-color",
        "title": "Blue",
        "votes": 5,
        "percentage": 25
      },
      {
        "option": "yellow-color",
        "title": "Yellow",
        "votes": 5,
        "percentage": 25
      }
    ]
  }
}
```
*Exemplo de Error Reesponse:*

```
{
  "status": "error",
  "time": 1753462809,
  "message": "Results are not available for this question."
}
```
```
{
  "status": "error",
  "time": 1753462809,
  "message": "Question not found or inactive."
}
```

---
## Benchmark

Comando:

    ab -n 1000 -c 1000 http://poll-craft.lndo.site/api/voting/vote

Resultado:
```
   This is ApacheBench, Version 2.3 <$Revision: 1903618 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking poll-craft.lndo.site (be patient)
Completed 100 requests
Completed 200 requests
Completed 300 requests
Completed 400 requests
Completed 500 requests
Completed 600 requests
Completed 700 requests
Completed 800 requests
Completed 900 requests
Completed 1000 requests
Finished 1000 requests


Server Software:        nginx
Server Hostname:        poll-craft.lndo.site
Server Port:            80

Document Path:          /api/voting/vote
Document Length:        19549 bytes

Concurrency Level:      1000
Time taken for tests:   22.470 seconds
Complete requests:      1000
Failed requests:        0
Non-2xx responses:      1000
Total transferred:      20041000 bytes
HTML transferred:       19549000 bytes
Requests per second:    44.50 [#/sec] (mean)
Time per request:       22470.340 [ms] (mean)
Time per request:       22.470 [ms] (mean, across all concurrent requests)
Transfer rate:          870.98 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0   17   3.1     15      25
Processing:   107 11169 6354.5  10992   22356
Waiting:       81 11169 6354.6  10992   22356
Total:        119 11186 6352.1  11007   22371

Percentage of the requests served within a certain time (ms)
  50%  11007
  66%  14586
  75%  16686
  80%  17778
  90%  20055
  95%  21150
  98%  21870
  99%  22081
 100%  22371 (longest request)
```
## Start no ambiente

### Commandos:

#### Iniciar projeto
```
 lando start
```

#### Instalar dependencias
```
 lando composer install
```

#### Importar banco de dados 
```
 lando drush db-import db.sql
```
#### Habilitar módulos 
```
 lando drush pm:en redis -y
 lando drush pm:en voting_core -y 
 lando drush pm:en voting_api -y
```

#### Logar como admin user

**admin**
```
username: admin
password: admin
```
#### Admin dashboard

<img width="1915" height="931" alt="image" src="https://github.com/user-attachments/assets/53d922ae-b4dd-4dc4-b088-d37138e625e5" />

#### Voting System Config

<img width="956" height="893" alt="image" src="https://github.com/user-attachments/assets/4c0dc292-4337-4b86-b51e-fb2fcc8974aa" />

