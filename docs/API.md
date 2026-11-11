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

| Método    | Rota                                                                                                   | Serviço (`nucleo/api/v1/`) | Acesso (matriz)                                                                     |
| --------- | ------------------------------------------------------------------------------------------------------ | -------------------------- | ----------------------------------------------------------------------------------- |
| POST      | `auth/token`                                                                                           | `token.php`                | sessão + `X-CSRF-Token`                                                             |
| GET       | `sessao`                                                                                               | `sessao.php`               | conta (perfil, rotas, permissões, CSRF)                                             |
| GET, POST | `novidades`                                                                                            | `novidades.php`            | resumo das actualizações por ver e marcação como vistas (só SADM)                   |
| GET, POST | `actualizacoes`                                                                                        | `actualizacoes.php`        | aprovação das versões do portal (só SADM, com confirmação da identidade)            |
| GET       | `vendas?start_date&end_date[&include_items=1\|only]`                                                   | `vendas.php`               | páginas de vendas                                                                   |
| GET       | `vendas/estado`                                                                                        | `vendas.php`               | conta (indicador das fontes)                                                        |
| GET       | `vendas/diagnostico`, `vendas/estrutura`                                                               | `vendas.php`               | `admin-config` (SADM)                                                               |
| GET       | `dados/rotas`, `dados/cargas`, `dados/clientes`                                                        | `dados.php`                | conforme o ficheiro                                                                 |
| GET       | `cadastros`                                                                                            | `cadastros.php`            | cadastros, carteira, auditoria                                                      |
| GET       | `sincronizacao`                                                                                        | `sincronizacao.php`        | `sincronizacao`                                                                     |
| GET/POST  | `plano`, `plano/historico`, `plano/excel?data`                                                         | `plano.php`                | `plano-cargas`                                                                      |
| GET/POST  | `conciliacao-carrinha?data`                                                                            | `carrinha.php`             | `conciliacao-carrinha` (contar e aprovar: G; CSRF)                                  |
| GET/POST  | `prospeccao[?actualizar=1]`                                                                            | `prospeccao.php`           | `geo-prospeccao` (Vr: só as suas rotas; marcar com CSRF; actualizar: G)             |
| GET/POST  | `sap`, `sap/modelo`, `sap/excel?numero`                                                                | `sap.php`                  | `sap-exportar`                                                                      |
| GET/POST  | `gestao`                                                                                               | `gestao.php`               | `acessos` (alterações: SADM)                                                        |
| POST      | `exportacoes`                                                                                          | `exportacoes.php`          | nível E da página                                                                   |
| GET       | `estrutura-dados`                                                                                      | `estrutura-dados.php`      | `estrutura-dados`                                                                   |
| GET       | `stock?data&armazem`                                                                                   | `stock.php`                | stock por carrinha / armazém, Início                                                |
| GET       | `agt?start_date&end_date`                                                                              | `agt.php`                  | `agt` (Comunicação AGT)                                                             |
| GET/POST  | `equipamentos`, `equipamentos/ficha?id`, `equipamentos/documentos[?numero]`, `equipamentos/pdf?numero` | `equipamentos.php`         | `equipamentos` (alterações: `equipamentos-movimentar`, `equipamentos-administrar`)  |
| GET       | `alertas[?dia]`, `previsao`, `indicadores`                                                             | `analise.php`              | `alertas`, `previsao`, `app-gestao` (âmbito por rota)                               |
| GET/POST  | `assistente`, `resumo`                                                                                 | `assistente.php`           | `assistente`, `resumo-diario` (com CSRF)                                            |
| GET/POST  | `sala/ligacoes`                                                                                        | `sala.php`                 | `painel-sala` (G)                                                                   |
| GET/POST  | `vistas`                                                                                               | `vistas.php`               | sessão; vista do Início por pessoa (`ordem` ou `null` = origem)                     |
| GET       | `sala` (cabeçalho `X-Painel-Codigo`)                                                                   | `sala.php`                 | público, com a ligação do ecrã (só totais por rota)                                 |
| GET/POST  | `pedidos-clientes`, `clientes-qr?rota`                                                                 | `pedidos-clientes.php`     | `pedidos-clientes` (validar: G), `clientes-qr` (G)                                  |
| GET/POST  | `cliente?k=<código>`                                                                                   | `cliente.php`              | público, com o código do cartaz (pedidos ficam pendentes; limites por cliente e IP) |
| POST      | `csp-relatorio`                                                                                        | `csp.php`                  | público (relatórios da CSP, limitados)                                              |
| GET       | `saude`                                                                                                | `saude.php`                | público (verificação de saúde: `{ok, hora}`, sem informação interna)                |

As rotas públicas são só quatro, numa lista fechada verificada pelos testes: `csp-relatorio`, `saude` (verificação de saúde para a monitorização, sem informação interna), `sala` (ligação de 256 bits
criada e revogada no portal, guardada só como SHA-256) e `cliente` (código de 128 bits do cartaz). O assistente nunca
acede à base de dados: chama ferramentas só de leitura que aplicam a matriz de acessos e o âmbito de quem pergunta.

O stock (`GET stock`) e a Comunicação AGT (`GET agt`) lêem o SQL Server do Pinguim BO, só com SELECT.

Os Equipamentos usam uma base de dados própria, com escrita (`ATV_DB_*` no `.env`). `POST equipamentos` recebe
`{accao, csrf, …}`: `registar` e `colaborador` (Administrar), `evento`, `emitir` e `anular` (Movimentar; o abate exige
Administrar), `assinar` (Movimentar, ou o próprio colaborador com a sua conta) e `verificar`. A página pública do
código QR (`equipamentos/ficha.php`) não consulta a base de dados.

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
  `If-None-Match` e recebe 304 sem corpo quando nada mudou. Nas vendas, nos dados de base e nos cadastros o ETag
  calcula-se antes de ler os dados (`naval_etag_antecipado`: data e tamanho da origem + conta, perfil, rotas, NIF e
  níveis da matriz + versão do código), e o 304 sai sem ler nem converter os dados.
- **Linhas de artigos à parte:** `vendas` sem `include_items` devolve só os documentos; `include_items=only` só as
  linhas (`{artigos}`), com cache própria; `include_items=1` as duas (exportação de todos os dados).
- **Compressão gzip** das respostas grandes (nunca nas que têm o token ou o código CSRF).
- **Carregamento por página:** bibliotecas pesadas (Excel, ZIP, QR) e, no projecto com stock, os dados de stock só são
  pedidos quando uma página precisa deles.
