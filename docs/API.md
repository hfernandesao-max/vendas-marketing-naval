# API central do portal (v1)

Único ponto de comunicação entre o front-end (`reports/`, ficheiros estáticos) e o back-end (`nucleo/`,
privado). Entrada pública: `api/index.php` → `nucleo/api/router.php` → serviços em `nucleo/api/v1/`.

```
Navegador (reports/js/api.js) ──HTTPS──► api/index.php/v1/<recurso>   Authorization: Bearer <JWT>
                                            │  origem · rota · método · token · ritmo · corpo
                                            ▼
                                      nucleo/api/v1/<serviço>.php  ──► nucleo/servicos/ ──► Pinguim BO / pasta privada
```

## Autenticação (JWT)

| Passo | Como                                                                                                                                                                                           |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1     | Entrada em `geo/login.php` (palavra-passe, dois passos, bloqueio, CSRF) → sessão PHP (cookie `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS).                                                   |
| 2     | `POST v1/auth/token` com o cookie da sessão e o cabeçalho `X-CSRF-Token` (código em `<meta name="csrf-token">` da página do portal) → `{access_token, token_type: "Bearer", expires_in: 900}`. |
| 3     | Pedidos à API com `Authorization: Bearer <token>`. O cliente (`reports/js/api.js`) guarda o token **só em memória** e pede outro 1 minuto antes de expirar ou depois de um 401.                |

Token: HS256 (algoritmo fixo; recusa `none` e outros), chave `JWT_SEGREDO` no `.env` (≥ 32 caracteres; rotação com
`JWT_SEGREDO_ANTERIOR`, identificada por `kid`), 15 minutos, reivindicações `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`, `jti`,
`ver` (versão das credenciais) e `sid` (ligação à sessão). Sem dados pessoais no token.

Revogação imediata: terminar a sessão, mudar ou repor a palavra-passe, mudar os dois passos ou desactivar a conta
invalidam todos os tokens dessa pessoa (o token só vale com a mesma sessão e a mesma versão das credenciais).

Gerar a chave (uma vez, no servidor): `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` → `JWT_SEGREDO=` no `.env`.

## Recursos

| Método   | Rota                                            | Serviço (`nucleo/api/v1/`) | Acesso (matriz)                         |
| -------- | ----------------------------------------------- | -------------------------- | --------------------------------------- |
| POST     | `auth/token`                                    | `token.php`                | sessão + `X-CSRF-Token`                 |
| GET      | `sessao`                                        | `sessao.php`               | conta (perfil, rotas, permissões, CSRF) |
| GET      | `vendas?start_date&end_date&include_items`      | `vendas.php`               | páginas de vendas                       |
| GET      | `vendas/estado`                                 | `vendas.php`               | conta (indicador das fontes)            |
| GET      | `vendas/diagnostico`, `vendas/estrutura`        | `vendas.php`               | `admin-config` (SADM)                   |
| GET      | `dados/rotas`, `dados/cargas`, `dados/clientes` | `dados.php`                | conforme o ficheiro                     |
| GET      | `cadastros`                                     | `cadastros.php`            | cadastros, carteira, auditoria          |
| GET      | `sincronizacao`                                 | `sincronizacao.php`        | `sincronizacao`                         |
| GET/POST | `plano`, `plano/historico`, `plano/excel?data`  | `plano.php`                | `plano-cargas`                          |
| GET/POST | `sap`, `sap/modelo`, `sap/excel?numero`         | `sap.php`                  | `sap-exportar`                          |
| GET/POST | `gestao`                                        | `gestao.php`               | `acessos` (alterações: SADM)            |
| POST     | `exportacoes`                                   | `exportacoes.php`          | nível E da página                       |
| GET      | `estrutura-dados`                               | `estrutura-dados.php`      | `estrutura-dados`                       |
| POST     | `csp-relatorio`                                 | `csp.php`                  | público (relatórios da CSP, limitados)  |

No projecto com o stock do SQL Server há também `GET stock` e `GET stock/estado`.

## Protecções em cada pedido

- **Origem:** só o próprio site; sem CORS (um `Origin` de outro site → 403).
- **Rotas e métodos:** lista fechada (404; 405 com `Allow`). `POST` só com `application/json` (415) e até 25 MB (413).
- **Ritmo:** 300 pedidos/min por pessoa (`API_RITMO`), 30 tokens/min por pessoa (`API_RITMO_TOKEN`) → 429 com `Retry-After`.
- **Autorização:** cada serviço verifica a matriz (`naval_exigir`) e o âmbito (rotas, NIF); as alterações exigem também o código CSRF no corpo.
- **Erros:** sempre JSON `{ok: false, erro, codigo?}`; detalhes técnicos só para SADM/TI ou com `APP_DEBUG=1`.

## Optimização (fase 4)

- **Cache no servidor** (`naval_cache`, pasta privada `cache/`): vendas 2 min (período com hoje/ontem) ou 30 min (período
  fechado), sincronização 1 min, stock 5 min / 1 h; um só pedido consulta a fonte de cada vez; o botão «Actualizar»
  pede dados frescos (`fresco=1`). O âmbito do perfil aplica-se depois da cache, a cada pedido.
- **ETag / 304:** as respostas GET levam `ETag` e `Cache-Control: private, no-cache`; o navegador confirma com
  `If-None-Match` e recebe 304 sem corpo quando nada mudou.
- **Compressão gzip** das respostas grandes (nunca nas que têm o token ou o código CSRF).
- **Carregamento por página:** bibliotecas pesadas (Excel, ZIP, QR) e, no projecto com stock, os dados de stock só são
  pedidos quando uma página precisa deles.
