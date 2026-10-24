# GeoMobilidade Naval · Grupo Naval

Sistema Integrado de Operações. A página principal (`index.html`) dá acesso a dois módulos:

| Módulo | Endereço | Estado |
|---|---|---|
| **REPORTS** | `reports/` | Portal RTM (abaixo) |
| **GEO** | por configurar (`GEO_URL` em `index.html`) | Rotas, zonas de entrega e distribuidores |

## REPORTS · Portal RTM

Portal da equipa RTM (Route to Market) do Grupo Naval Angola, no estilo do Portal do Grupo. Mostra **só dados do Pinguim BO e do plano de cargas**.

Site: https://hfernandesao-max.github.io/vendas-marketing-naval/

## Menu

| Secção | Páginas |
|---|---|
| Vendas RTM | Vendas por promotor · Vendas por dia · Vendas por cliente · Documentos |
| Recebimentos | Métodos de pagamento (dinheiro, Multicaixa, por liquidar) |
| Stock | Stock por promotor / carrinha (carga do plano de cargas) · Stock por armazém · Vendas por artigo · Vendas por promotor e marca |
| Carteira | Carteira de clientes · Plano de visitas |

Todas as páginas têm filtro de datas, idioma PT/EN e exportação **Excel (.xlsx)** (por tabela, por página ou completa): capa com índice, resumo de indicadores e uma folha por tabela, com cabeçalho fixo, filtros e totais `SUBTOTAL`.

O ecrã ajusta-se sozinho para evitar barras de deslocamento: as tabelas mostram 20 linhas de cada vez e, quando falta largura, ficam compactas e escondem primeiro as colunas menos importantes (há sempre a opção de as mostrar).

## Dados (`reports/data/`)

| Ficheiro | Origem | Conteúdo |
|---|---|---|
| `vendas.json` | Pinguim BO, vendas | Um documento por linha: data, hora, rota, promotor, tipo, documento, cliente, NIF, liquidado, total, meio de pagamento |
| `clientes.json` | Pinguim BO, clientes | Rota, promotor, código, nome, NIF, tipo de estabelecimento, dia de visita, estado (sem contactos, telefones, GPS nem moradas) |
| `cargas.json` | Plano de cargas (transportes) | Carrinha, motorista, zonas e caixas carregadas por categoria |
| `rotas.json` | Pinguim BO | Rota → promotor |

Ainda por receber do Pinguim BO: meio de pagamento de cada documento, linhas de venda por artigo (com IVA e desconto) e stock por armazém/carrinha. As páginas correspondentes mostram os campos necessários.

## Aviso

O site é público: qualquer pessoa com o link vê vendas, promotores e clientes.

## API do Pinguim BO (`reports/data/api.php`)

As credenciais e o endereço ficam no `.env` na raiz do projecto (não vai para o GitHub):

```
USERNAME=...
PASSWORD=...
PINGUIM_URL=http://127.0.0.1:8089/rest/exportdata,https://bo.naval.co.ao:2053/rest/exportdata
```

`PINGUIM_URL` aceita vários endereços separados por vírgula, tentados por ordem. No servidor responde o local (porta 8089); num computador fora do servidor usa-se o externo (2053). Sem `PINGUIM_URL`, são estes os valores usados.

Diagnóstico, com sessão iniciada: `reports/data/api.php?diag=1`.

## Cadastros de clientes (`reports/data/cadastros.php`)

As páginas **Cadastros** (visão geral, análise e clientes registados), a **Carteira de clientes** e o **plano de visitas** da auditoria usam o ficheiro `saida/portal_rtm.json` do repositório privado **rtm-clientes**, actualizado pelo GitHub Actions sempre que se carrega a exportação «Clientes» do RTM.

No servidor:

1. Clonar o repositório ao lado da pasta do site (fica fora da pasta pública):
   ```
   cd C:\Apache24\htdocs
   git clone https://github.com/hfernandesao-max/rtm-clientes.git
   ```
2. Agendar no Windows (Agendador de Tarefas), por exemplo de hora a hora:
   ```
   git -C C:\Apache24\htdocs\rtm-clientes pull --ff-only
   ```
3. Se o repositório ficar noutra pasta, indicar o ficheiro no `.env`:
   ```
   RTM_CLIENTES_JSON=D:\caminho\rtm-clientes\saida\portal_rtm.json
   ```

Sem estes dados, as páginas de cadastros mostram como configurar e a carteira usa `reports/data/clientes.json`.
