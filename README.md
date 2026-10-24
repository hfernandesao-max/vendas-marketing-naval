# Portal RTM · Grupo Naval

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

## Dados (`data/`)

| Ficheiro | Origem | Conteúdo |
|---|---|---|
| `vendas.json` | Pinguim BO, vendas | Um documento por linha: data, hora, rota, promotor, tipo, documento, cliente, NIF, liquidado, total, meio de pagamento |
| `clientes.json` | Pinguim BO, clientes | Rota, promotor, código, nome, NIF, tipo de estabelecimento, dia de visita, estado (sem contactos, telefones, GPS nem moradas) |
| `cargas.json` | Plano de cargas (transportes) | Carrinha, motorista, zonas e caixas carregadas por categoria |
| `rotas.json` | Pinguim BO | Rota → promotor |

Ainda por receber do Pinguim BO: meio de pagamento de cada documento, linhas de venda por artigo (com IVA e desconto) e stock por armazém/carrinha. As páginas correspondentes mostram os campos necessários.

## Aviso

O site é público: qualquer pessoa com o link vê vendas, promotores e clientes.
