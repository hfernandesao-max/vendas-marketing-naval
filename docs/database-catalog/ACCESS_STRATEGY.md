# Estratégia de acesso aos dados · Pinguim BO e OLAP

Como o Portal de Mobilidade Comercial lê o Pinguim BO (ERP, produção) e o OLAP sem pôr em risco a facturação nem a
integridade dos dados. Complementa `docs/ARQUITECTURA.md`.

> **O catálogo completo (`schema-map.json`, `SCHEMA_OVERVIEW.md`) não vai para o repositório**, que é público: a
> estrutura do ERP de produção ajudaria quem o quisesse atacar. Gera-se e guarda-se localmente (e no servidor) com
> `php ferramentas/catalogo-esquema.php`. Ver o `.gitignore`.

## 1. As duas fontes e para que serve cada uma

| | Pinguim BO (`PingWinBO`) | OLAP |
|---|---|---|
| O que é | o ERP: documentos, stock, caixa, telemóveis, em tempo real | armazém de dados alimentado pelo Pinguim BO (ETL): dimensões e factos já calculados |
| Tamanho (27-11-2026) | 332 tabelas, 194 com dados (138 vazias) | 41 tabelas, 33 com dados |
| Usar para | **tudo o que tem de estar certo agora**: validação de POs, stock das carrinhas (`dbo.GET_STOCK`), fecho do dia (`FCL`, `SESSION`), conciliação, extracto de movimentos, sincronização dos telemóveis | **análise e histórico**: visitas e mapa R1/R2, objectivos e prémios (`FACT_R1_GOAL`, `FACT_BONUS`, `FACT_TEAM_RANK_*`), carteira de clientes por rota (`DIM_CLIENT_HISTORY`), canais de distribuição |
| Atraso | nenhum | o do ETL (a confirmar com quem o mantém) |
| Ligação | `.env`: `DB_*` | `.env`: `OLAP_*` (ou `OLAP_DSN`) |

**Regra:** se o número aparece numa decisão operacional do dia (carregar, contar, fechar, cobrar), lê-se no Pinguim BO.
Se é uma tendência, um prémio ou um ranking, lê-se no OLAP. O portal nunca escreve em nenhuma das duas.

Para ligar as duas bases, usa-se sempre o **código** e não o `ID`:
- `DIM_ROUTE.CODE` = `STORE.CODE` (a rota é a loja do Pinguim BO);
- `DIM_PRODUCT.CODE` = `PRODUCT.CODE`;
- `DIM_CLIENT.CODE` = `ENTITY.CODE`;
- `DIM_DOCUMENT.CODE` = `DOCCONFIG.CODE`.

O documento liga-se pelo `ID` do Pinguim BO: `FACT_R1_MAP.PBO_DOC_ID` e `FACT_BONUS.DOCHEADER_ID` são o `DOCHEADER.ID`.

## 2. Garantias de só leitura (já em vigor)

1. **Contas de leitura** no SQL Server (`*_READER`). É a única garantia que não depende do código: pedir ao DBA que a conta do portal **não tenha** `db_datawriter` nem `db_ddladmin`.
2. **Guarda no código**: `naval_bd_so_leitura()` (`nucleo/servicos/bd.php`) recusa tudo o que não seja um único `SELECT` ou `WITH`, ou que contenha `INSERT`, `UPDATE`, `DELETE`, `MERGE`, `ALTER`, `DROP`, `EXEC`, `SET`, `INTO`, `xp_*` ou `sp_*`. Todas as consultas passam por `naval_bd_consultar` ou `naval_bd_consultar_em`.
3. **Ligação declarada só de leitura** (`ApplicationIntent=ReadOnly`), com o nome da aplicação (`APP=PortalRTM…`), para o DBA ver quem faz cada consulta.
4. **Sem bloqueios demorados**:
   - `LOCK_TIMEOUT` de 30 s: uma consulta do portal nunca fica à espera da facturação mais do que isso;
   - consultas com mais de 5 s ficam registadas no log (`[bd lenta]`).

### `WITH (NOLOCK)`: onde sim e onde não
- **Sim** no catálogo (`sys.*`), nos diagnósticos e nas contagens aproximadas, como em `ferramentas/catalogo-esquema.php`.
- **Não** nos números de negócio (vendas, stock, dinheiro). O `NOLOCK` lê transacções ainda não confirmadas e pode contar duas vezes, ou saltar, linhas que estão a mudar de página. Um total de vendas ou um stock «quase certo» não serve à conciliação.

Para estas consultas fica o `READ COMMITTED` com o `LOCK_TIMEOUT`. Se algum dia houver bloqueios, a solução certa é o DBA ligar o `READ_COMMITTED_SNAPSHOT` na base: as leituras deixam de bloquear e de ser bloqueadas, sem leituras sujas.

## 3. Como o portal lê: padrões obrigatórios

1. **Um serviço por domínio** em `nucleo/servicos/` (ex.: `movimentos.php`, `stock.php`, `pinguim-dia.php`). As páginas e a API nunca escrevem SQL. Nenhuma tabela é exposta directamente na API.
2. **Consultas parametrizadas** (`:parametro`).
   - Cada parâmetro aparece **uma só vez** na consulta: o driver ODBC recusa o mesmo nome repetido («COUNT field incorrect»).
   - As datas vão já calculadas em PHP (`>= :de AND < :ate`), sem `CAST(:x AS DATE)`: o ODBC pode passar o parâmetro como `text`, que o SQL Server não converte em data.
   - Os códigos que tenham de ir no texto do SQL são validados antes (`^[A-Z0-9]{1,10}$`).
3. **Colunas explícitas**, nunca `SELECT *` (excepto nas ferramentas de diagnóstico).
4. **Colunas proibidas** no portal: credenciais e dados pessoais sensíveis das tabelas de utilizadores e clientes (palavras-passe, PIN, impressões digitais, números de cartão) e os contactos de email das rotas no OLAP. Os dados de clientes (NIF, morada) só para os perfis que vêem clientes.
5. **Sempre limitado:**
   - intervalo de datas obrigatório (até 366 dias), `TOP` nas listas;
   - totais agregados no SQL Server (`GROUP BY`, `GROUPING SETS`) em vez de trazer milhares de linhas para o PHP;
   - cache no servidor para o que muda pouco (`naval_cache`).
6. **Filtrar pelas colunas com índice.** O catálogo mostra os índices:
   - **documentos:** `DOCHEADER` tem índices em `DOC_DATE`, `STORE_ID + DOC_DATE`, `DOCCONFIG_ID + DELETED + DOC_DATE` e `CLOCK_DATE`;
   - **artigos:** `DOCMOVDETAIL` tem `PRODUCT_ID + WAREHOUSE_ID` e `DOCHEADER_ID`. Filtrar pelo artigo com `DMD.PRODUCT_ID = (SELECT ID FROM PRODUCT WHERE CODE = :art)` usa o índice; `LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art` lê a tabela toda. Hoje as tabelas são pequenas (cerca de 1 100 documentos), mas vão crescer;
   - **datas:** `COALESCE(CLOCK_DATE, DOC_DATE)` não usa índice. Para períodos grandes, pré-filtrar por `DOC_DATE` com um dia de margem.
7. **Ligações exactas do Pinguim BO em vez de deduções:**
   - `DOCHEADERSOURCE`: o documento gerado a partir de outro (SA → GTC). Já é usada nas transferências;
   - `DOCMOVDETAILSOURCE`: a linha gerada a partir de outra, com a quantidade. Usar quando for preciso ligar linha a linha;
   - `dbo.GET_STOCK`: o stock calculado pelo próprio ERP. Serve de referência para conferir qualquer cálculo do portal.

## 4. Vistas dedicadas: quando valem a pena

- **No Pinguim BO, não criar objectos.** A base é do fornecedor do ERP: vistas ou índices criados por nós podem perder-se numa actualização, ou partir uma. O «contrato» fica nos serviços do portal, testados (`tests/unidade`) e conferidos com o próprio Pinguim BO (`ferramentas/pinguim-artigo.php`, `vigia` por rota).
- **No OLAP, sim.** As vistas com o contrato do portal (ex.: `OLAP_VISTA`, com as colunas `DATA_HORA`, `ROTA`, `LATITUDE`, `LONGITUDE`) ficam num esquema próprio (ex.: `portal`), criadas e versionadas por quem mantém o OLAP (`SCHEMA_MIGRATION_HISTORY`), com permissão só de `SELECT` para a conta do portal.

## 5. Tempo real e sincronização

| Necessidade | Onde ler | Notas |
|---|---|---|
| Validação de PO e carga da carrinha | Pinguim BO: `DOCHEADER`/`DOCMOVDETAIL` (PO, SA, GTC) + `DOCHEADERSOURCE` | a ligação SA → GTC diz o que foi carregado |
| Stock da carrinha agora | Pinguim BO: `dbo.GET_STOCK(data, produto, armazém, …)` | o mesmo número que o ERP mostra |
| Fecho do dia | Pinguim BO: documento `FCL`, `SESSION` | prova de que as vendas do dia estão completas |
| Telemóvel sincronizou? | Pinguim BO: `SYNC_PHSE_HISTORY` (`STORE_ID`, `START_TIMESTAMP`, `END_TIMESTAMP`) | para a sala comercial: «sincronizou depois de entrar» |
| Resultados, objectivos, rankings | OLAP: `FACT_R1_*`, `FACT_TEAM_RANK_*`, `FACT_BONUS` | painel do LCD e relatórios |
| Mapa de visitas | OLAP: `FACT_R1_MAP` (com `LAT`, `LNG`, `DISTANCE`) | liga ao documento por `PBO_DOC_ID` |

## 6. Manter o catálogo actualizado

1. Depois de cada actualização do Pinguim BO ou do OLAP, correr `php ferramentas/catalogo-esquema.php`. Só lê o catálogo e demora segundos.
2. Comparar o `schema-map.json` novo com o anterior: tabelas novas, colunas alteradas, relações.
3. Antes de cada funcionalidade nova, consultar o catálogo e preferir sempre as tabelas já usadas e testadas pelo portal (marcadas «Portal ✓» no `SCHEMA_OVERVIEW.md`).
