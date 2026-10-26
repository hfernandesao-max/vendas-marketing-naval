# Arquitectura do portal REPORTS

Documento de referência para quem mantém o código. O guia de utilização e instalação está no [README](../README.md).

## Visão geral

```
Navegador ──► reports/index.php  (controlador autenticado: sessão, perfil, páginas permitidas)
   │               └─ entrega reports/index.html (casca) + reports/css/*.css + reports/js/main.js (módulo)
   │
   └─ fetch ──► reports/data/*.php  (serviços JSON, cada um verifica sessão, perfil e CSRF)
                    ├─ api.php        → Pinguim BO (http://127.0.0.1:8089/rest/exportdata)
                    ├─ cadastros.php  → cadastros de clientes
                    ├─ plano.php      → plano de cargas (+ email Microsoft 365)
                    ├─ sap.php        → ficheiro para o SAP (lotes, correspondência de rotas)
                    ├─ gestao.php     → contas e matriz de acessos
                    ├─ sessao.php     → sessão e perfil do utilizador
                    ├─ registar.php   → registo das exportações
                    └─ dados.php      → ficheiros fixos (rotas, cargas, carteira) filtrados pelo perfil
                         │
                         └─ geo/auth/*.php  (biblioteca: acessos, email, cargas) e dados-privados/ (fora da web)
```

- **Front-end:** módulos ES nativos do navegador, sem passo de compilação. O que está no GitHub é exactamente o que o Apache serve; publicar continua a ser `git pull`.
- **Back-end:** PHP 7.1+ (sem extensões opcionais obrigatórias). Toda a autorização é feita no servidor (`naval_exigir`, `naval_pode`); o front-end só esconde o que o perfil não pode ver.
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

## Serviços PHP (`reports/data/`)

Cada serviço começa por `require auth_check.php` e `naval_exigir([...recursos])`. Escritas exigem POST com o token CSRF da sessão e o nível certo na matriz (`naval_pode_exportar`, nível E/G). Todas as acções relevantes ficam em registos JSONL (`naval_registar`).

## Testes e qualidade

| Ferramenta                                   | O que garante                                                                                       |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| ESLint 10 (`eslint.config.js`)               | Erros e más práticas nos módulos e testes                                                           |
| Prettier (`.prettierrc.json`) + EditorConfig | Formatação uniforme                                                                                 |
| Playwright `portal`                          | Todas as páginas em 4 tamanhos de ecrã, filtros, relatórios, gráficos, ficheiro SAP igual ao modelo |
| Playwright `servidor`                        | PHP real com contas de cada perfil e um Pinguim BO simulado                                         |
| GitHub Actions                               | `php -l`, ESLint, Prettier e todos os testes a cada alteração                                       |

## Próximas fases

1. ~~Front-end em módulos, normas e testes automáticos~~ (concluída).
2. API PHP em camadas (encaminhamento, serviços, acesso a dados) e um único leitor de `.env`.
3. Base de dados SQL Server com sincronização do Pinguim BO.
4. Optimização (cache dos dados do dia, carregamento por página).
