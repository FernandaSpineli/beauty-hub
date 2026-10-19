# Beauty Hub

[![CI](https://github.com/FernandaSpineli/beauty-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/FernandaSpineli/beauty-hub/actions/workflows/ci.yml)

Plataforma multi-loja de e-commerce para lojas de cosméticos, em Laravel.
Projeto em desenvolvimento.

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 18 · Redis 8 · FrankenPHP ·
Pest · Larastan · Pint · Docker · GitHub Actions

## Como rodar

Requer Docker com Compose v2.

```bash
cp .env.example .env
make install                                    # dependências do Composer
docker compose run --rm dev php artisan key:generate
make up                                         # app, postgres, redis, mailpit
curl -H 'Accept: application/json' localhost:8000/up
```

## Desenvolvimento

| Comando | O que faz |
| --- | --- |
| `make test` | Roda os testes (Pest) contra PostgreSQL e Redis reais |
| `make lint` | Pint (formatação) e Larastan (nível máximo) |
| `make fmt` | Formata o código com Pint |
