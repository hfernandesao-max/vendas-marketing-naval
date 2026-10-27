# Arquitectura do portal REPORTS

Documento de referência para quem mantém o código. O guia de utilização e instalação está no [README](../README.md).

## Visão geral

```
Navegador ──► reports/index.php  (casca autenticada: sessão, perfil, código CSRF em <meta>)
   │               └─ entrega reports/index.html + reports/css/*.css + reports/js/main.js (front-end estático)
   │
   └─ reports/js/api.js ──► api/index.php/v1/<recurso>   (API central; Authorization: Bearer <JWT de 15 min>)
                                 └─ nucleo/api/router.php  (origem, rota, método, token, ritmo, corpo)
                                       └─ nucleo/api/v1/*.php  (serviços da API: pedido → serviço → JSON)
                                             └─ nucleo/servicos/ → Pinguim BO · dados-privados/ (fora da web)
```

Módulos separados: **front-end** em `reports/` (HTML, CSS e JS estáticos, sem acesso a dados); **back-end** em
`nucleo/` (privado, recusado pelo Apache); **API** em `api/` (único ponto público de dados). Referência: `docs/API.md`.

- **Front-end:** módulos ES nativos do navegador, sem passo de compilação. O que está no GitHub é exactamente o que o Apache serve; publicar continua a ser `git pull`.
- **Back-end:** PHP 7.1 ou posterior (verificado no GitHub da 7.1 à 8.3), em camadas no `nucleo/`. Toda a autorização é feita no servidor (`naval_exigir`, `naval_pode`); o front-end só esconde o que o perfil não pode ver.
- **Dados privados:** `dados-privados/` (contas, registos JSONL, planos, lotes SAP, token de email) nunca é servido pelo Apache nem vai para o GitHub.

## Módulos do front-end (`reports/js/`)

Camadas, de baixo para cima. Um módulo só importa das camadas iguais ou inferiores (excepto `app.js`, que as páginas chamam para redesenhar).

| Camada      | Módulo                       | Responsabilidade                                                                          |
| ----------- | ---------------------------- | ----------------------------------------------------------------------------------------- |
| Base        | `i18n/i18n.js`, `i18n/en.js` | Idioma (PT/EN) e dicionário inglês indexado pelo texto português                          |
|             | `formatos.js`                | Números, kwanzas, percentagens, datas dd-mm-aaaa                                          |
|             | `util.js`                    | `DB` (dados carregados), DOM, somas, agrupamentos, ícones, avisos                         |
|             | `perfil.js`                  | Sessão do utilizador e permissões da matriz                                               |
|             | `servidor.js`                | `postJson` (POST com CSRF) e `carregarScript` (bibliotecas de `vendor/` a pedido)         |
|             | `api.js`                     | cliente central da API v1: token JWT em memória, renovação, `apiFetch`, `apiDescarregar`  |
|             | `periodo.js`                 | Período escolhido e memória                                                               |
| Regras      | `calculos.js`                | Regras de negócio: vendas do período, pagamentos, pendentes, rotas, visitas               |
|             | `filtros.js`                 | Barra de filtros comum, aplicação aos dados (`prepararDados`), endereço partilhável       |
|             | `fontes.js`                  | Ligação às fontes (Pinguim BO, cadastros, plano) e indicador de estado                    |
| Componentes | `componentes/blocos.js`      | Tabelas, quadros de indicadores, secções                                                  |
|             | `componentes/graficos.js`    | Gráficos SVG acessíveis (dicas, teclado, vista em tabela)                                 |
|             | `componentes/janelas.js`     | Janelas de diálogo e campos                                                               |
|             | `excel.js`                   | Exportação para Excel com registo no servidor                                             |
| Páginas     | `paginas/catalogo.js`        | Lista das páginas por área de negócio (menu)                                              |
|             | `paginas/registo.js`         | Objecto `R`: cada página regista aqui a sua função de desenho                             |
|             | `paginas/*.js`               | Início, Vendas, Recebimentos, Clientes, Terreno, Logística, Plano de cargas, SAP, Acessos |
| Aplicação   | `navegacao.js`               | Menu em árvore e separadores de relatórios                                                |
|             | `app.js`                     | Desenho da página actual e encaminhamento `#página/relatório?filtros`                     |
|             | `main.js`                    | Arranque: sessão, dados, primeiro desenho                                                 |

### Fluxo de um desenho

1. `main.js` lê a sessão e carrega os dados para `DB` (`fontes.js`).
2. `app.js` lê o endereço (`#vendas/por-promotor?rota=1001`), aplica os filtros (`filtros.js` → `prepararDados`) e chama `R[pagina]()`.
3. A página desenha com os componentes; `navegacao.js` divide-a em relatórios (separadores) e marca o menu.
4. Qualquer alteração (filtro, período, idioma) volta ao passo 2 — o estado vive no endereço e em `localStorage`, nunca em variáveis espalhadas.

### Regras de estado partilhado

Os módulos ES não permitem alterar uma variável importada. O estado partilhado é exportado como `let` e alterado só pela função `definir…` do próprio módulo (`definirIdioma`, `definirSessao`, `definirAtual`, `definirReg`, …). Objectos partilhados (`DB`, `R`, `FV`) são alterados nas propriedades.

## Back-end PHP em camadas (`nucleo/`)

```
nucleo/api/router.php   API central v1: origem, rota, método, token JWT (nucleo/seguranca/jwt.php), ritmo.
nucleo/api/v1/*.php     Serviços da API: lêem o pedido, verificam o acesso, chamam um serviço e respondem.
        │               Não têm regras de negócio nem acesso directo a ficheiros.
        ▼
nucleo/servicos/        Serviços de negócio (casos de uso), sem HTTP: devolvem dados ou lançam NavalErro.
  pinguim.php           ligação ao Pinguim BO (endereços alternativos, estado, diagnóstico, estrutura)
  vendas.php            documentos FR/FT, pagamentos e troco, linhas de artigos, âmbito por perfil
  cargas.php            plano de cargas: rascunho, conflito de versões, publicação, listas, email
  sap.php               correspondência de rotas e lotes do ficheiro para o SAP
  gestao.php            contas, confirmação da identidade, dois passos, matriz de acessos
  cadastros.php, rotas.php, email.php (Microsoft 365)
        │
        ▼
nucleo/seguranca/       Matriz de acessos, contas e palavras-passe, TOTP, bloqueios, sessão, registos
nucleo/http.php         Respostas JSON, corpo do pedido, CSRF, NavalErro e erros fatais em JSON
nucleo/armazem.php      Ficheiros JSON na pasta privada (escrita atómica, leitura tolerante)
nucleo/cliente-http.php Pedidos a serviços externos (cURL ou, sem cURL, o cliente nativo do PHP)
nucleo/config.php       O único leitor do .env
```

Entradas:

| Quem                                    | Inclui                    | Faz                                                     |
| --------------------------------------- | ------------------------- | ------------------------------------------------------- |
| API central (`api/index.php/v1/…`)      | `nucleo/api/router.php`   | núcleo, erros em JSON, token JWT obrigatório (401 JSON) |
| Páginas HTML (`reports/index.php`, GEO) | `geo/auth/auth_check.php` | núcleo, sessão; sem sessão → página de entrada          |
| Linha de comandos (`ferramentas/`)      | `nucleo/arranque.php`     | só o núcleo                                             |

Regras:

- **Erros:** um serviço lança `new NavalErro('Mensagem para o utilizador', códigoHTTP, [campos extra])`; o tratador da API responde `{ok:false, erro, ...}`. Qualquer outro erro é registado no log do PHP e devolvido como 500 em JSON, nunca como HTML.
- **Autorização** sempre no servidor: `naval_exigir([...])` no controlador; as escritas exigem POST com o código CSRF da sessão (`naval_exigir_csrf`) e o nível certo da matriz.
- **Registos:** todas as acções relevantes ficam em `dados-privados/registos/` (`naval_registar`).
- **Compatibilidade:** `geo/auth/acessos.php`, `email.php` e `cargas.php` ficam como entradas antigas que carregam o núcleo.
- **Ficheiros do núcleo:** começam por `defined('NAVAL_NUCLEO') || exit;` e a pasta tem `Require all denied`.

### Configuração (`.env`, na raiz do site)

| Chave                                                                                                                      | Para quê                                                                       | Omissão                                            |
| -------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ | -------------------------------------------------- |
| `USERNAME`, `PASSWORD`                                                                                                     | credenciais do Pinguim BO                                                      | —                                                  |
| `PINGUIM_URL`                                                                                                              | endereços do Pinguim BO, por ordem, separados por vírgula                      | — (obrigatória)                                    |
| `PINGUIM_VERIFICAR_SSL`                                                                                                    | verificação do certificado HTTPS do Pinguim BO                                 | `1` (ligada)                                       |
| `DB_HOST`, `DB_NAME` (ou `DB_DSN`), `DB_USER`, `DB_PASS`, `DB_PORT`                                                        | base de dados do Pinguim BO (só leitura)                                       | — (obrigatórias); porta `1433`                     |
| `GOOGLE_MAPS_API_KEY`                                                                                                      | chave de navegador do Google Maps (GEO), com restrições                        | — (obrigatória para os mapas)                      |
| `LOGIN_MAX_FALHAS_IP`                                                                                                      | entradas falhadas por IP em 15 min antes de suspender                          | `20`                                               |
| `PROXY_HTTPS`, `CSP_APLICAR`, `APP_DEBUG`                                                                                  | proxy HTTPS de confiança; CSP aplicada; diagnóstico                            | `0` (nunca `APP_DEBUG=1` em produção)              |
| `MFA_OBRIGATORIA`, `EXIGIR_HTTPS`, `PROXY_CONFIAVEIS`, `DB_ENCRYPT`, `DB_CONFIAR_CERTIFICADO`, `EMAIL_DOMINIOS_PERMITIDOS` | dois passos obrigatórios; HTTPS; proxy; cifra do SQL Server; domínios de email | `1`, `0`, —, `1`, `1`, — (ver `docs/SEGURANCA.md`) |
| `DADOS_PRIVADOS`                                                                                                           | pasta privada (contas, registos, planos, lotes)                                | `../dados-privados` ao lado do site                |
| `UTILIZADORES_JSON`                                                                                                        | ficheiro das contas                                                            | `<DADOS_PRIVADOS>/utilizadores.json`               |
| `RTM_CLIENTES_JSON`                                                                                                        | cadastros gerados pelo repositório rtm-clientes                                | ver README                                         |
| `EMAIL_TENANT_ID`, `EMAIL_CLIENT_ID`, `EMAIL_CLIENT_SECRET`, `EMAIL_REMETENTE`                                             | envio pelo Microsoft 365                                                       | —                                                  |
| `EMAIL_MODO`                                                                                                               | `ficheiro` grava os emails em vez de os enviar (testes)                        | `graph`                                            |
| `PORTAL_URL`                                                                                                               | endereço do portal nas ligações dos emails                                     | —                                                  |
| `FUSO_HORARIO`                                                                                                             | fuso das datas e horas                                                         | `Africa/Luanda`                                    |

| `SYNC_ALERTA_HORAS`, `SYNC_CRITICO_HORAS` | limites da comunicação dos smartphones (em dia / atenção / crítico) | `2` e `24` horas |

A variável de ambiente `NAVAL_ENV` aponta para outro `.env` (testes automáticos). Não há valores de ligação
predefinidos no código: sem as chaves obrigatórias, o serviço responde com os **nomes** das chaves em falta (nunca
com valores nem caminhos). Segurança da plataforma e instruções para o servidor: `docs/SEGURANCA.md`.

Configuração guardada no portal (pasta privada, não no `.env`): correspondência com o SAP em `sap/config.json`
(armazéns principais 1150 → RTM1 e 1151 → RTM2, SAP location por omissão RTM2), perfis criados e alterações à matriz.

## Testes e qualidade

| Ferramenta                                   | O que garante                                                                                              |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| ESLint 10 (`eslint.config.js`)               | Erros e más práticas nos módulos e testes                                                                  |
| Prettier (`.prettierrc.json`) + EditorConfig | Formatação uniforme                                                                                        |
| Playwright `portal`                          | Todas as páginas em 4 tamanhos de ecrã, filtros, relatórios, gráficos, ficheiro SAP igual ao modelo        |
| Playwright `servidor`                        | PHP real com contas de cada perfil e um Pinguim BO simulado; contrato de todos os serviços JSON            |
| Testes unitários (`tests/unidade/`)          | Núcleo e serviços isolados: .env, JSON, matriz, TOTP (RFC 6238), JWT (RFC 8725), cache, vendas, plano, SAP |
| PHPStan nível 5 (`phpstan.neon.dist`)        | Análise estática do back-end                                                                               |
| `tests/apache/verificar.sh`                  | Regras dos `.htaccess` num Apache 2.4 real: ficheiros internos recusados, cabeçalhos de segurança          |
| GitHub Actions                               | Sintaxe, testes unitários e dos serviços no PHP 7.1, 7.4, 8.1 e 8.3; PHPStan; ESLint, Prettier e testes    |

## Próximas fases

1. ~~Front-end em módulos, normas e testes automáticos~~ (concluída).
2. ~~Back-end PHP em camadas e um único leitor do `.env`~~ (concluída).
3. Base de dados SQL Server do Pinguim BO: leitura directa, só `SELECT` (projecto com as duas fontes), com a cache da
   fase 4. Base própria sincronizada: por decidir (exigiria escrita numa base nova).
4. ~~Optimização (cache dos dados do dia, ETag/304, compressão, carregamento por página)~~ (concluída).
5. ~~Módulos separados (front-end, back-end) e API central v1 com JWT~~ (concluída; `docs/API.md`).
