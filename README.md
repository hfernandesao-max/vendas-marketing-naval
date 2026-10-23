# Vendas Marketing · Naval

Dashboard de gestão diária das vendas Marketing do Grupo Naval: consolidado do mês, vendas do dia, reconciliação de caixa (numerário e TPA), vendedores, logística e escoamento da carga, produtos, cobertura de stock e facturas.

Site: https://hfernandesao-max.github.io/vendas-marketing-naval/

## Estrutura

| Caminho | Conteúdo |
|---|---|
| `index.html` | O dashboard (HTML, CSS e JavaScript num só ficheiro) |
| `data/*.json` | Histórico de todos os dias: `resumo`, `vendedores`, `produtos`, `facturas`, `stock`, `transportes`, `rtm` (Pinguim BO) |
| `data/rotas.json` | Correspondência rota Pinguim BO ↔ vendedor SAP (por validar). Editar aqui e mudar `validado` para `true` quando confirmada |
| `tools/mkt_parser.py` | Processador que lê os relatórios do dia e actualiza `data/` |
| `vendor/d3.min.js` | Biblioteca de gráficos d3 v7.9.0 |

## Actualização diária

Relatórios reconhecidos pelo conteúdo, não pelo nome:

- **SAP do dia** (os seis juntos): Sales register, Consolidated sales report, Sales report Items wise, Stock report for marketing, Sales report, CASH CONTROLE.
- **Plano de cargas** (normalmente de manhã): REPORT DRIVER MARKETING TRANSPORTES.
- **Vendas RTM do Pinguim BO** (qualquer período): folha com Rota, Vendedor, Data, Hora, Tipo, Documento, Nome fiscal, Nº contribuinte, Liquidado, Total. Cada documento substitui o anterior com o mesmo número e é casado com a factura SAP do mesmo vendedor, mesmo valor e no máximo um dia de diferença. Estes valores nunca se somam aos do SAP.

Pode enviar só o plano de cargas de manhã (as vendas ficam pendentes) e os seis SAP ao fim do dia: o processador reutiliza o plano já carregado. Reprocessar um dia substitui-o.

```bash
pip install openpyxl
mkdir -p entrada && cp /caminho/dos/relatorios/*.xlsx entrada/
python3 tools/mkt_parser.py entrada data data
git add data && git commit -m "Dados de AAAA-MM-DD" && git push
rm -rf entrada
```

O GitHub Pages republica o site em cerca de um minuto.

## Aviso

O site é público: qualquer pessoa com o link vê os números, os vendedores e os clientes.
