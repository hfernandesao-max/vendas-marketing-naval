# Contrato de dados do OLAP para o Portal RTM (versão 1.0)

Data: 14-11-2026. Normas: ISO/IEC 25012 (qualidade dos dados), ISO 8000, ISO/IEC 27001 (A.5.15, A.8.24), modelo
dimensional de Kimball, DAMA-DMBOK 2.

## Princípios

- O portal só lê o esquema **`rtm`** do OLAP, com uma conta própria com SELECT apenas nesse esquema
  (`svc_portal_rtm_olap`). Nunca lê tabelas internas do armazém de dados.
- **Factos em bruto, ao nível mais fino**, com as chaves do Pinguim BO. As regras de negócio do portal (vendas sem IVA,
  notas de crédito, anulados, devoluções, substituições de facturas) aplicam-se no portal, às duas fontes por igual: o
  OLAP não as repete.
- Datas em ISO 8601 e hora de Luanda (UTC+01:00); valores em kwanzas com duas casas decimais.
- O portal confirma as colunas ao arrancar (de hora a hora). Sem o contrato completo, lê do Pinguim BO e mostra as
  colunas em falta em Administração › Conformidade.
- Versões: colunas novas numa versão menor (1.1); mudanças que quebram, só numa versão maior (2.0), com aviso.

## Vistas obrigatórias da versão 1.0

| Vista               | Grão (uma linha por…)     | Colunas                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------- | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `rtm.f_documento`   | documento                 | `DOC_ID` (= DOCHEADER.ID), `DATA` (date), `HORA` (hh:mm), `ROTA` (código da loja/rota), `TIPO_CODIGO`, `TIPO_DESCRICAO`, `DOCUMENTO` (prefixo e número), `CLIENTE`, `CLIENTE_CODIGO`, `NIF`, `LIQUIDADO` (0/1), `SINAL_VENDAS` (= CALC_SALES_SIGNAL), `TOTAL_COM_IVA` (= CALC_TOTAL_W_TAX, sem sinal aplicado), `TOTAL_PAGO`, `ELIMINADO` (0/1), `ESTADO_ID` (= DOCSTATUS_ID), `MOTIVO_ANULACAO` (texto do motivo de cancelamento, vazio se não anulado) |
| `rtm.f_venda_linha` | linha de documento        | `DOC_ID`, `LINHA`, `CODIGO`, `ARTIGO`, `UNIDADE`, `QUANTIDADE`, `PRECO`, `VALOR_SEM_IVA`, `DESCONTO`, `IVA`, `TOTAL_COM_IVA`, `TOTAL_BRUTO` (= GROSS_TOTAL), `ELIMINADA` (0/1) — valores sem sinal aplicado                                                                                                                                                                                                                                              |
| `rtm.f_pagamento`   | pagamento de um documento | `DOC_ID`, `MEIO_CODIGO` (NU, MC, TB, CS…), `MEIO`, `VALOR`, `ELIMINADO` (0/1)                                                                                                                                                                                                                                                                                                                                                                            |
| `rtm.ctl_carga`     | carga de um dia           | `DIA`, `INICIO`, `FIM`, `ESTADO` (`concluida` quando terminou bem), `LINHAS`, `VERSAO_CONTRATO` (`1.0`)                                                                                                                                                                                                                                                                                                                                                  |

As dimensões (`rtm.d_data`, `rtm.d_cliente`, `rtm.d_artigo`, `rtm.d_rota`, `rtm.d_armazem`, com histórico SCD tipo 2)
e a fotografia diária do stock (`rtm.f_stock_dia`) entram nas versões seguintes, domínio a domínio.

## Carga e corte conferido

1. A carga nocturna recarrega sempre os **últimos 7 dias** (notas de crédito, anulações e substituições chegam depois
   do dia da factura) e regista cada dia em `rtm.ctl_carga`.
2. Todas as manhãs (`FONTES_CONFERENCIA_HORA`, 06:00) o portal confere cada dia carregado com o Pinguim BO, por dia e
   rota: número de documentos, totais com e sem IVA, linhas, quantidades, total bruto e pagamentos por meio.
3. Um dia só passa a ser lido do OLAP (`FONTE_VENDAS=olap`) depois de conferido **sem diferenças** e **depois da última
   carga desse dia**: uma recarga obriga a conferir de novo. Hoje vem sempre do Pinguim BO.
4. Diferenças, cargas em falta e contrato incompleto vão por correio (`ACTUALIZACAO_EMAIL`) e aparecem em
   Administração › Conformidade › Vigilância e conferência.

## Continuidade

- Disjuntor: 3 falhas do OLAP em 5 minutos param as consultas durante 5 minutos; o portal lê do Pinguim BO.
- Se um período do OLAP falhar a meio de um pedido, esse período é lido do Pinguim BO.

## Fases (ver o relatório de arquitectura)

`FONTE_VENDAS=pinguim` → `paralelo` (20 dias úteis seguidos sem diferenças) → `olap` (aprovação da Direcção
Financeira). O ficheiro para o SAP e a Comunicação AGT só passam a usar o OLAP numa fase posterior, depois de um mês de
fecho igual nas duas fontes; a estrutura do ficheiro para o SAP não muda.
