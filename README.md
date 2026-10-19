# Beauty Hub

[![CI](https://github.com/FernandaSpineli/beauty-hub/actions/workflows/ci.yml/badge.svg)](https://github.com/FernandaSpineli/beauty-hub/actions/workflows/ci.yml)

Plataforma de e-commerce para lojas de cosméticos, em Laravel. Várias lojas
convivem na mesma aplicação e no mesmo banco, cada uma no seu subdomínio
(`bella.beautyhub…`, `glow.beautyhub…`), com catálogo, clientes, pedidos,
cupons, estoque e pagamentos próprios, isolados pelo próprio PostgreSQL.

O que é específico de cosméticos: estoque por **lote e validade**, venda
sempre do lote que vence primeiro, bloqueio automático de lotes perto do
vencimento e rastreio de qual lote foi para qual cliente (para recalls).

Inclui uma vitrine de demonstração para testar tudo no navegador.

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 18 · Redis 8 · Horizon ·
Sanctum · Stripe Connect · FrankenPHP · Pest · Larastan · Pint · Scramble ·
Docker · GitHub Actions

## Destaques

- **Isolamento entre lojas garantido pelo banco.** Cada tabela de loja tem
  *row-level security* forçado no PostgreSQL, e a aplicação se conecta com
  um usuário que não consegue contorná-lo. Mesmo SQL escrito à mão, uma
  regra de validação ou um job na fila só enxerga a loja atual. Chaves
  estrangeiras compostas impedem que um produto aponte para a marca de outra
  loja, e testes de arquitetura falham se uma tabela nova esquecer a
  proteção. Veja a [ADR 001](docs/decisions/001-multi-tenancy.md).
- **Estoque por lote, o que vence primeiro sai primeiro.** A reserva trava
  os lotes sempre na mesma ordem (sem *deadlocks*) e reserva tudo ou nada.
  Lotes dentro da validade mínima da loja (30 dias por padrão) saem da
  venda sozinhos, a vitrine mostra o mês de validade do item que será
  enviado, kits têm disponibilidade calculada pelos componentes e um recall
  lista todo cliente que recebeu o lote. Veja a
  [ADR 002](docs/decisions/002-stock-by-lot.md).
- **Concorrência testada com processos reais.** 8 clientes disputando 3
  unidades terminam com exatamente 3 pedidos; 6 pedidos para um cupom com 2
  usos, com exatamente 2; dois donos se rebaixando ao mesmo tempo deixam a
  loja com um dono.
- **Pagamentos com Stripe Connect.** Cada loja conecta sua conta e recebe
  direto; a plataforma retém uma taxa. Só webhook assinado confirma
  pagamento, cada evento é aplicado uma única vez, boleto é aguardado, e
  valor divergente ou pagamento que chega para pedido expirado é marcado
  para reembolso em vez de sumir. Veja a
  [ADR 003](docs/decisions/003-payments.md).
- **Filas que respeitam a loja.** Um job criado numa requisição da loja A
  roda como loja A no worker, e o próximo job não herda nada. E-mails em
  português, com o nome da loja como remetente, saem depois do commit.
- **SaaS de verdade.** Qualquer pessoa cria sua loja e vira dona; ela
  convida a equipe por e-mail, define a validade mínima e a cor da vitrine, e
  tudo o que a equipe altera fica no registro de auditoria.
- **Qualidade.** 193 testes (241 com os casos de dataset) contra PostgreSQL e
  Redis reais, 96% de cobertura, Larastan no nível máximo, regras críticas
  conferidas com mutações (o teste precisa falhar quando a regra é removida)
  e a especificação OpenAPI gerada do código, com um teste que acusa se ela
  ficar desatualizada.

## Como rodar

Requer Docker com Compose v2.

```bash
cp .env.example .env
make install                                          # dependências (Composer, em Docker)
docker compose run --rm dev php artisan key:generate
make up                                               # app, horizon, scheduler, postgres, redis, mailpit
make seed                                             # duas lojas de exemplo
```

| O quê | Onde |
| --- | --- |
| Plataforma (criar loja) | <http://beautyhub.localhost:8000> |
| Loja Bella Cosméticos | <http://bella.beautyhub.localhost:8000> |
| Loja Glow Skincare | <http://glow.beautyhub.localhost:8000> |
| E-mails enviados (Mailpit) | <http://localhost:8025> |
| Filas (Horizon, só em ambiente local) | <http://beautyhub.localhost:8000/horizon> |
| Documentação da API (só em ambiente local) | <http://beautyhub.localhost:8000/docs/api> |

Endereços `*.localhost` apontam para a própria máquina nos navegadores e no
curl, sem mexer no arquivo de hosts.

Contas de exemplo, todas com a senha `demo12345`:

| Loja | Dona | Equipe | Cliente |
| --- | --- | --- | --- |
| Bella | `dona@bella.demo` | `equipe@bella.demo` | `cliente@bella.demo` |
| Glow | `dona@glow.demo` | `equipe@glow.demo` | `cliente@glow.demo` |

Na Bella, o tom "Coral" do batom e um lote do sérum já estão dentro da
validade mínima: aparecem como esgotados na vitrine e "fora de venda" no
painel. O cupom `BEMVINDA10` dá 10% a partir de R$ 50.

Se alguma porta já estiver em uso, mude `FORWARD_APP_PORT`, `FORWARD_DB_PORT`,
`FORWARD_REDIS_PORT` ou `FORWARD_MAILPIT_UI_PORT` no `.env`.

### Pagamentos (Stripe em modo de teste)

Opcionais no ambiente local: sem chaves, o pagamento responde
`503 PAYMENTS_UNAVAILABLE` e todo o resto funciona.

1. No `.env`, coloque a chave secreta **de teste** da conta da plataforma:
   `STRIPE_SECRET_KEY=sk_test_...` (chaves de produção são recusadas fora de
   `APP_ENV=production`).
2. Suba o Stripe CLI, que encaminha os eventos da plataforma e das contas
   conectadas para o webhook:
   ```bash
   docker compose --profile stripe up -d
   docker compose logs stripe   # "Your webhook signing secret is whsec_..."
   ```
3. Copie o `whsec_...` para `STRIPE_WEBHOOK_SECRET` e reinicie:
   `docker compose up -d app horizon`.
4. Entre como dona, abra **Painel → Pagamentos** e conecte a conta Stripe
   (cadastro de teste do Stripe).
5. Como cliente, faça um pedido e clique em **Pagar com Stripe**. Use o
   cartão `4242 4242 4242 4242`, qualquer data futura e qualquer CVC. O
   pedido vira "Pago" quando o webhook assinado chega.

## API

Cada loja serve sua API em `http://{loja}.beautyhub.localhost:8000/api/v1`;
o cadastro de lojas fica no domínio da plataforma. O contrato completo,
gerado a partir do código, está em [docs/openapi.json](docs/openapi.json)
(OpenAPI 3.1).

| Área | Rotas |
| --- | --- |
| Loja | `GET /store` |
| Catálogo | `GET /categories` · `GET /brands` · `GET /products?q=&category=&brand=&skin_type=&vegan=&cruelty_free=&in_stock=&min_price=&max_price=&sort=` · `GET /products/{slug}` |
| Conta | `POST /auth/register` · `/auth/login` · `/auth/logout` · `/auth/password/forgot` · `/auth/password/reset` · `GET`/`PATCH /me` · `PUT /me/password` |
| Carrinho | `GET`/`DELETE /cart` · `POST /cart/items` · `PATCH`/`DELETE /cart/items/{variant}` · `PUT`/`DELETE /cart/coupon` |
| Pedidos | `POST /orders` (`Idempotency-Key`) · `GET /orders` · `GET /orders/{id}` · `POST /orders/{id}/cancel` · `POST /orders/{id}/checkout` |
| Gestão (dona e equipe) | marcas, categorias, produtos, tons/tamanhos, kits, lotes (entrada, ajuste, validade, recall), cupons, pedidos (envio), painel |
| Só a dona | `GET`/`PATCH /admin/store` (edição) · `/admin/team` · `/admin/payments` · `/admin/audit-log` |
| Plataforma | `POST /stores` (criar loja) · `POST /stripe/webhook` |

Todo erro tem o mesmo formato, com um `code` estável:

```json
{
  "error": {
    "code": "INSUFFICIENT_STOCK",
    "message": "Not enough units in stock for some items.",
    "details": [{ "variant_id": "…", "requested": 4, "available": 3 }],
    "request_id": "9b69a44c5000acbf81baf8dedce4f07a"
  }
}
```

```bash
# Buscar com erro de digitação
curl 'http://bella.beautyhub.localhost:8000/api/v1/products?q=hidratnte'

# Entrar e fechar o pedido do carrinho
TOKEN=$(curl -s -X POST http://bella.beautyhub.localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"cliente@bella.demo","password":"demo12345"}' | jq -r .data.token)
curl -X POST http://bella.beautyhub.localhost:8000/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"shipping_address":{"postal_code":"01310-100","street":"Av. Paulista","number":"1578","district":"Bela Vista","city":"São Paulo","state":"SP"}}'
```

## Operação da plataforma

Não há API de "super admin": a operação é pela linha de comando, sem
superfície exposta.

```bash
docker compose exec app php artisan stores list
docker compose exec app php artisan stores suspend bella    # a loja passa a responder 403
docker compose exec app php artisan stores activate bella
```

## Desenvolvimento

| Comando | O que faz |
| --- | --- |
| `make test` | Todos os testes (PostgreSQL e Redis reais, via Docker) |
| `make cover` | Testes com resumo de cobertura |
| `make lint` | Pint (formatação) e Larastan (nível máximo) |
| `make fmt` | Formata o código |
| `make docs` | Regenera `docs/openapi.json` a partir do código |
| `make seed` | Cria as lojas de exemplo |
| `make up` / `make down` / `make logs` | Sobe, derruba e acompanha a stack |

Os testes cobrem regras isoladas, a API de ponta a ponta (com a matriz de
permissões e os caminhos de falha), o isolamento entre lojas direto no
banco, workers reais e corridas entre processos PHP. A aplicação conecta
como o usuário sem privilégios também nos testes, então o RLS vale ali como
em produção.

O CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) roda Pint,
Larastan, a suíte completa com cobertura, `composer audit`, gitleaks no
histórico, hadolint e o build da imagem Docker. O Dependabot mantém
dependências, actions e imagens atualizadas.

## Configuração

Tudo vem de variáveis de ambiente; veja o [.env.example](.env.example).

| Variável | Padrão | Função |
| --- | --- | --- |
| `CENTRAL_DOMAIN` | `beautyhub.localhost` | Domínio da plataforma; as lojas ficam em `{loja}.CENTRAL_DOMAIN` |
| `DB_USERNAME` / `DB_OWNER_USERNAME` | `beauty_app` / `beauty` | Usuário da aplicação (sujeito ao RLS) e dono do schema (migrations) |
| `TOKEN_TTL_MINUTES` | `10080` | Validade dos tokens (7 dias) |
| `ORDER_RESERVATION_MINUTES` | `45` | Por quanto tempo um pedido não pago segura o estoque |
| `CATALOG_CACHE_TTL_SECONDS` | `600` | Limite de desatualização do cache de categorias e marcas |
| `RATE_LIMIT_LOGIN_PER_MINUTE` / `_AUTH_` / `_API_` | `5` / `10` / `300` | Limites de requisições |
| `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` | vazio | Pagamentos (desligados se vazios) |
| `PLATFORM_FEE_BPS` | `500` | Taxa da plataforma por pedido (500 = 5%) |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Fuso do app e da sessão do banco |

## Documentação

A documentação técnica está em inglês:

- [Arquitetura](docs/architecture.md): ciclo da requisição, isolamento,
  modelo de dados, estados do pedido, checkout, concorrência, filas,
  observabilidade, segurança.
- Decisões: [001 multi-loja](docs/decisions/001-multi-tenancy.md) ·
  [002 estoque por lote](docs/decisions/002-stock-by-lot.md) ·
  [003 pagamentos](docs/decisions/003-payments.md)
- [OpenAPI](docs/openapi.json)

## Limitações conhecidas

- Frete não é calculado (frete grátis) e o endereço não é buscado pelo CEP.
- Reembolsos são manuais; os pagamentos que precisam de um ficam sinalizados.
- Devoluções não voltam ao estoque sozinhas.
- Imagens de produto são URLs, sem upload.
- A busca não tem sinônimos ("rímel" não encontra "máscara").
- Quem trabalha em duas lojas tem duas contas.

Mais detalhes em [architecture.md](docs/architecture.md#known-limitations).
