
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

### Sem Redis

Comando:

    ab -n 10000 -c 50 https://localhost:32771/api/voting/questions  

Resultado:
```
This is ApacheBench, Version 2.3 <$Revision: 1903618 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/
Benchmarking localhost (be patient)
Completed 1000 requests
Completed 2000 requests
Completed 3000 requests
Completed 4000 requests
Completed 5000 requests
Completed 6000 requests
Completed 7000 requests
Completed 8000 requests
Completed 9000 requests
Completed 10000 requests
Finished 10000 requests
Server Software:        nginx
Server Hostname:        localhost
Server Port:            32771
SSL/TLS Protocol:       TLSv1.2,ECDHE-RSA-AES256-GCM-SHA384,2048,256
Server Temp Key:        X25519 253 bits
TLS Server Name:        localhost
Document Path:          /api/voting/questions
Document Length:        6871 bytes
Concurrency Level:      50
Time taken for tests:   69.256 seconds
Complete requests:      10000
Failed requests:        0
Total transferred:      73580000 bytes
HTML transferred:       68710000 bytes
Requests per second:    144.39 [#/sec] (mean)
Time per request:       346.278 [ms] (mean)
Time per request:       6.926 [ms] (mean, across all concurrent requests)
Transfer rate:          1037.54 [Kbytes/sec] received
Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        2    5   2.5      5      39
Processing:    24  340  24.4    336     532
Waiting:       24  339  24.4    336     532
Total:         42  345  24.1    342     537
Percentage of the requests served within a certain time (ms)
  50%    342
  66%    347
  75%    351
  80%    355
  90%    367
  95%    379
  98%    399
  99%    426
 100%    537 (longest request)
```
### Com Redis

Comando:

    ab -n 10000 -c 50 http://localhost:32783/api/voting/questions

Resultado:
```
This is ApacheBench, Version 2.3 <$Revision: 1903618 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking localhost (be patient)
Completed 1000 requests
Completed 2000 requests
Completed 3000 requests
Completed 4000 requests
Completed 5000 requests
Completed 6000 requests
Completed 7000 requests
Completed 8000 requests
Completed 9000 requests
Completed 10000 requests
Finished 10000 requests


Server Software:        nginx
Server Hostname:        localhost
Server Port:            32786

Document Path:          /api/voting/questions
Document Length:        6871 bytes

Concurrency Level:      50
Time taken for tests:   66.890 seconds
Complete requests:      10000
Failed requests:        0
Keep-Alive requests:    10000
Total transferred:      73630000 bytes
HTML transferred:       68710000 bytes
Requests per second:    149.50 [#/sec] (mean)
Time per request:       334.448 [ms] (mean)
Time per request:       6.689 [ms] (mean, across all concurrent requests)
Transfer rate:          1074.97 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.1      0       3
Processing:    29  333  27.9    330     474
Waiting:       29  333  27.9    330     474
Total:         29  333  27.9    330     477

Percentage of the requests served within a certain time (ms)
  50%    330
  66%    341
  75%    347
  80%    350
  90%    365
  95%    386
  98%    404
  99%    414
 100%    477 (longest request)
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
 lando drush sql-cli < ./database/pollcraft.sql
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

