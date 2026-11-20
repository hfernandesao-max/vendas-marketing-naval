# Logística das carrinhas: PO, conferência de carga, Guia de Remessa e reconciliação automática

Data: 20-11-2026. Âmbito: Portal RTM + Pinguim BO + OLAP, 11 rotas de autovenda (van sales), armazéns principais
1150 (RTM1 · FOOD) e 1151 (RTM2 · NON FOOD), armazéns de viatura 100010NN. Referências: segregação de funções e
trilho de auditoria (ISO/IEC 27001 A.5.3, A.8.15; COSO), conferência em duplo controlo (double-check / four-eyes),
gestão por excepção (Lean), idempotência de mensagens (padrão «idempotent receiver», EIP).

## 0. Ponto de partida (o que já existe e a restrição que manda em tudo)

| Peça                         | Onde                                   | O que já faz                                                                                                                                     |
| ---------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Requisição RQ-AAAA-NNNNNN    | `nucleo/servicos/requisicoes.php`      | criada sozinha pelo consumo do dia (reposição «replenish what was sold»); rascunho → emitida (PDF, imutável) → carregada (armazém confirma, com a quantidade entregue por linha) → anulada; quem emite não confirma a carga; numeração sem saltos; histórico e 2.ª via |
| Conciliação da carrinha      | `nucleo/servicos/carrinha.php`         | inicial + carregado − vendido = esperado; contagem cega; aprovação a 2 níveis com segregação; fecho automático sem diferenças; bloqueio da carga seguinte enquanto os dias anteriores não fecham (com excepção autorizada) |
| Stock                        | `nucleo/servicos/stock.php`            | `dbo.GET_STOCK` por armazém; armazém de cada carrinha pelo código 100010NN, pelo nome ou confirmado por SADM/TI                                    |
| Origem do stock              | `nucleo/servicos/origem-stock.php`     | transferências `DOCMOV` do armazém principal para a viatura (só SELECT)                                                                             |
| Plano de cargas              | `nucleo/servicos/cargas.php`           | documento de cada rota = número da requisição; quantidade = stock no corte + requisição                                                            |
| Trilho de auditoria          | `nucleo/seguranca/registos.php`        | cada acção com utilizador, perfil, IP, localização do IP e **hash SHA-256 encadeado** (inviolável: um registo apagado ou mudado parte a cadeia)      |

**Restrição:** o Pinguim BO é o software certificado e o sistema de registo do stock e dos documentos: a **Guia de
Remessa, a transferência e a devolução nascem sempre no Pinguim BO** (numeração, assinatura e série dele). Hoje o
portal só **lê** o Pinguim BO (SQL só SELECT e exportação REST em XML).

**Decisão (20-11-2026): todo o trabalho faz-se no portal, sem depender do fornecedor do Pinguim BO.** O portal dá a
ordem e um **robô do portal** (`ferramentas/robo-pinguim.mjs`) cria o documento **pelos ecrãs do próprio Pinguim BO**, no
navegador, com uma conta técnica, como uma pessoa faria: quem numera, assina e movimenta o stock é sempre o Pinguim BO.
O portal **nunca** escreve directamente nas tabelas do Pinguim BO: um documento inserido por fora não passa pela
numeração e assinatura do software certificado e não vale como documento. Se um dia o fornecedor der uma API
(`docs/PINGUIM-API-PEDIDO.md`), troca-se só o executor da fila; o fluxo e os ecrãs ficam iguais.

Enquanto a receita do robô não estiver afinada aos ecrãs reais, a mesma ordem fica na fila do portal e o Back-Office
executa-a no Pinguim BO com os dados que o portal mostra; o portal encontra o documento sozinho no Pinguim BO e fecha
o ciclo da mesma maneira (secção 7).

## 1. Arquitectura de sincronização e autoconfirmação de stocks

```
                 PINGUIM BO (sistema de registo: stock e documentos fiscais)
   ┌───────────────────────────────────────────────────────────────────────────────┐
   │ DOCHEADER/DOCMOV (vendas, guias, transferências)      dbo.GET_STOCK (saldos)  │
   └───────────────▲───────────────────────────┬───────────────────────┬───────────┘
        (B) API de │escrita da guia            │ SELECT delta          │ SELECT saldos
        (futuro)   │                           │ (watermark: último    │ (corte do dia e
                   │                           │  DOC_ID / data-hora)  │  de hora a hora)
   ┌───────────────┴───────────────────────────▼───────────────────────▼───────────┐
   │ PORTAL · motor de reconciliação (tarefa de 5 em 5 minutos, já existente)      │
   │                                                                               │
   │  1. ingestão por delta ──► 2. livro de movimentos ──► 3. reconciliação ──►    │
   │     (só documentos novos      idempotente:               por rota/dia/artigo: │
   │      desde a marca d'água)    chave = DOC_ID+linha       esperado = inicial   │
   │                               hash das linhas            + carregado (guias)  │
   │                                                          − vendido            │
   │                                                          vs saldo GET_STOCK   │
   │                                                          vs contagem cega     │
   │  4. decisão:  |dif| ≤ tolerância  → AUTOCONFIRMADO (fecha sozinho, registado) │
   │               dif > tolerância    → EXCEPÇÃO: alerta + circuito de aprovação  │
   │               stock < 0 / guia sem PO / PO sem guia > 4 h → BLOQUEIO preventivo│
   └───────────────┬───────────────────────────────────────────┬───────────────────┘
                   │ ocorrências / alertas por excepção         │ factos diários
                   ▼                                            ▼
        Terreno › Ocorrências, e-mail, menu               OLAP (cubos: rupturas, OTIF,
        (só o que pede acção)                             divergências, previsão)
```

### 1.1 Mecanismo

- **Jobs agendados com marca d'água (delta), não webhooks**: o Pinguim BO não emite eventos; a tarefa de 5 em 5 minutos
  lê só os documentos com `DOC_ID` maior do que o último visto (e os alterados, pela data de alteração), de hora a hora o
  saldo `GET_STOCK` das viaturas e, no corte do dia, a fotografia que congela a base da conciliação (já existente).
- **Livro de movimentos idempotente** (`dados-privados/movimentos/AAAA-MM.jsonl`): cada linha com chave natural
  `DOC_ID|LINHA` e o hash SHA-256 de (artigo, quantidade, armazém origem, armazém destino, sinal). Reler o mesmo
  documento não duplica; o mesmo `DOC_ID` com hash diferente é uma **alteração** do documento no Pinguim BO e abre uma
  ocorrência (documento mexido depois de conciliado). Anulados entram com sinal inverso, nunca se apagam.
- **Reconciliação em três vias** por rota, dia e artigo: (a) o que os movimentos dizem, (b) o saldo do Pinguim BO, (c) a
  contagem física. (a)=(b) confirma a integridade dos dados; (b)=(c) confirma o físico.
- **Autoconfirmação do armazém da carrinha** (pedido anterior): armazém único com o código exacto 100010NN da própria rota
  → confirmado sozinho pelo sistema (registado como `sistema`); pelo nome ou com mais de um armazém → fica para SADM/TI.

### 1.2 Regras de negócio de auto-validação

| Regra                                   | Valor proposto (configurável no .env)                                                                 |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Tolerância por linha                    | 0 unidades para artigos de valor alto (fraldas); ± 1 unidade ou ± 0,5 % para os restantes (`CARRINHA_TOLERANCIA_*`) |
| Tolerância em valor por carrinha/dia    | até 5 000 Kz fecha sozinho; acima vai ao 1.º nível; acima de 50 000 Kz vai também ao 2.º nível        |
| Fecho automático                        | só sem diferenças acima da tolerância, sem alertas e com a guia do dia conferida (já existe: `CARRINHA_FECHO_AUTOMATICO`) |
| Bloqueio preventivo                     | stock negativo numa viatura; guia sem PO; PO validada sem guia ao fim de 4 h; documento alterado depois de conciliado; dias anteriores por fechar (já existe) |
| Reincidência                            | 3 diferenças acima da tolerância em 14 dias na mesma rota → contagem completa obrigatória e aviso à Direcção |

### 1.3 Integridade transaccional

- **Idempotência**: chave natural `DOC_ID|LINHA` + hash; todas as acções do portal levam a versão do documento
  (controlo optimista, já usado nas requisições: `versao`) e um identificador do pedido para repetições da rede.
- **Exclusão mútua**: cada documento é alterado dentro de `naval_exclusivo` (bloqueio de ficheiro), já usado.
- **Nunca stock negativo**: a PO valida contra o disponível do armazém principal (`naval_req_validar_stock`), descontando
  as reservas abertas (secção 3.3); a viatura com saldo negativo bloqueia a PO seguinte até ser explicada.
- **Fonte única da verdade**: o stock é sempre o do Pinguim BO; o portal guarda reservas e conferências (estado), nunca
  saldos paralelos.

## 2. Matriz de automação (Portal, Pinguim BO e OLAP)

| Sistema    | O que automatizar                                  | Gatilho                                   | Acção automática                                                                 | Benefício                                    | Estado      |
| ---------- | -------------------------------------------------- | ----------------------------------------- | -------------------------------------------------------------------------------- | -------------------------------------------- | ----------- |
| Portal     | PO de reposição pelo consumo                       | contagem da carrinha validada             | cria a PO em rascunho com o vendido do dia                                       | zero digitação; carga igual à saída          | **existe**  |
| Portal     | Sugestão de reposição pelo histórico               | 18:00 de cada dia                         | PO sugerida = previsão do dia seguinte da rota (carteira: dia habitual, ritmo) − stock da carrinha, arredondada à embalagem | menos rupturas e menos stock parado          | novo        |
| Portal     | Validação de stock disponível                      | gravar / emitir a PO                      | recusa acima do disponível − reservas                                            | sem promessas impossíveis                    | parcial     |
| Portal     | Limite de crédito do cliente                       | encomenda / visita                        | lê o saldo em aberto (Pinguim BO) e avisa acima do limite; bloqueia acima de 120 % | menos dívida                                 | novo        |
| Portal     | Limite de peso da carga                            | emitir a PO                               | soma o peso pelos artigos e avisa acima da capacidade da viatura (frota)         | segurança e lei                              | **existe**  |
| Portal     | Autoconfirmação do armazém da carrinha             | stock lido                                | código 100010NN único → confirmado pelo sistema                                  | sem cliques de SADM/TI                       | novo (rápido) |
| Portal     | Casamento PO ↔ Guia de Remessa                     | guia nova em `DOCMOV`                     | encontra a PO pela referência, compara linha a linha, fecha a PO ou abre ocorrência | transferência confirmada sem papel          | novo        |
| Portal     | Notificações por excepção                          | divergência, bloqueio, PO parada          | Ocorrências + menu + e-mail só a quem tem de agir                                | ninguém procura problemas                    | parcial     |
| Pinguim BO | Guia de Remessa a partir da PO                     | PO validada pelo Back-Office              | (A) cópia da PO para a guia no Pinguim BO; (B) API de escrita                     | uma só digitação / nenhuma                   | depende do fornecedor |
| Pinguim BO | Conversão documental (guia → transferência)        | guia emitida                              | movimento do armazém principal para o 100010NN                                   | stock certo na viatura                       | no Pinguim BO |
| Pinguim BO | Fecho do ciclo de carga/descarga                   | conciliação aprovada no portal            | (B) devolução ao armazém pela diferença aprovada; (A) lista pronta para o BO      | ciclo fechado no dia                         | parcial     |
| Pinguim BO | Integração contabilística/fiscal                   | facturas e guias                          | exportação SAP já existente (localização pela origem do stock); SAF-T AO pelo Pinguim BO | fecho mensal sem acertos               | **existe**  |
| OLAP       | Cubo de rupturas                                   | carga nocturna                            | artigo × rota × dia: vendas perdidas (sem stock na viatura e procura habitual)   | o que falta onde                             | novo        |
| OLAP       | Previsão de procura por rota/vendedor              | carga nocturna                            | média móvel sazonal por dia da semana (e Holt-Winters com 12+ semanas)           | base da sugestão de reposição                | novo        |
| OLAP       | OTIF da carga                                      | PO fechada                                | on-time (carregada até à hora de saída) × in-full (conferido = pedido)           | qualidade do armazém                         | novo        |
| OLAP       | Divergências de inventário                         | conciliação fechada                       | valor e unidades por rota, artigo, motivo e pessoa                               | padrões de perda / fraude                    | parcial (portal) |
| OLAP       | Anomalias                                          | carga nocturna                            | z-score robusto (mediana/MAD) por rota e artigo; alerta acima de 3                | alerta antes do prejuízo                     | novo        |

## 3. Especificação do fluxo PO → conferência no cais → Guia de Remessa

### 3.1 Documento

- Nome: **Requisição Interna / PO** · número `PO-AAAA-NNNNNN` (sequência anual sem saltos; as RQ antigas continuam
  válidas e são lidas como PO). No PDF: título «Requisição Interna / PO», número em grande, **QR Code** (conteúdo:
  `PO-AAAA-NNNNNN|versão|hash curto`, para o scan e para detectar uma impressão antiga) e código de barras Code 128
  do número, rota, vendedor, viatura, data, linhas (código, artigo, unidade, quantidade pedida), campo
  «Carregado» em branco e assinaturas.

### 3.2 Estados e transições

```
 rascunho ──emitir──► emitida (PO impressa, imutável)
                         │ Fiel de Armazém: scan/número → confere linha a linha
                         ▼
                   conferida_cais  (stock RESERVADO; sem guia; sem transferência)
                         │ diferenças? ── sim ──► motivo por linha obrigatório
                         │ Back-Office: revê e valida (1 clique)
                         ▼
                   validada_bo  ──► (A) BO cria a guia no Pinguim BO com a referência da PO
                         │          (B) portal envia a guia pela API do Pinguim BO
                         ▼
                   transferida  (guia encontrada em DOCMOV e igual ao conferido: reserva libertada)
                         │ conciliação do dia fechada
                         ▼
                   conciliada (fim)

 anulada: de rascunho, emitida ou conferida_cais (com motivo; liberta a reserva)
 divergente: guia diferente do conferido ou guia sem PO → ocorrência; volta a transferida quando corrigida
```

| De → para                     | Quem (perfil)            | Pré-condições automáticas                                                    | Registo automático                         |
| ----------------------------- | ------------------------ | ---------------------------------------------------------------------------- | ------------------------------------------ |
| rascunho → emitida            | vendedor / supervisor    | stock disponível − reservas; peso ≤ capacidade; dias anteriores fechados     | utilizador, hora, IP, versão, hash do PDF  |
| emitida → conferida_cais      | Fiel de Armazém (LOG)    | PO lida pelo QR/número; não foi ele que emitiu; cada linha com visto         | idem + dispositivo (app do telemóvel pelo QR) |
| conferida_cais → validada_bo  | Back-Office (BO)         | não conferiu nem emitiu; diferenças com motivo                                | idem                                       |
| validada_bo → transferida     | sistema                  | guia em `DOCMOV` com a referência da PO, origem 1150/1151, destino 100010NN da rota e linhas iguais ao conferido | DOC_ID da guia, hash das linhas            |
| transferida → conciliada      | sistema                  | conciliação do dia aprovada                                                   | número da conciliação                      |

### 3.3 Reserva de stock (sem movimento no Pinguim BO)

- Na conferência no cais, as quantidades **carregadas** ficam **reservadas** no portal contra o armazém principal:
  disponível para novas PO = `GET_STOCK(1150/1151) − Σ reservas abertas`.
- A reserva cai quando a guia aparece (passa a ser stock da viatura no Pinguim BO), quando a PO é anulada ou, sem
  guia, ao fim de 24 h (ocorrência ao Back-Office: mercadoria fora do armazém sem documento).
- Na conciliação, a mercadoria «conferida no cais mas sem guia» aparece à parte: está fisicamente na carrinha e ainda
  não no Pinguim BO (evita uma falsa falta na contagem do dia).

### 3.4 Ecrã do Fiel de Armazém (telemóvel ou tablet no cais)

```
┌──────────────────────────────────────────────────────────────┐
│ Conferência de carga                       [ 📷 Ler QR da PO ] │
│ Nº da PO: [ PO-2026-000412        ] [Abrir]                    │
├──────────────────────────────────────────────────────────────┤
│ PO-2026-000412 · Rota 1008 · Daniel M. · LDA-19-07-AI · 20-11 │
│ Armazém de saída: 1151 HIGITEC NON FOOD                        │
├───────────┬─────────────────────────┬────────┬──────────┬────┤
│ Código    │ Artigo                  │ Pedido │ Carregado│ ✔  │
├───────────┼─────────────────────────┼────────┼──────────┼────┤
│ 10000332  │ Fraldas Naval Medium 4×48│   400  │ [ 400 ]  │ ☑  │
│ 10000292  │ Sabão verde 100g 6×8     │    30  │ [  28 ]  │ ☑  │  ⚠ motivo: [Falta no armazém ▾]
│ 10000293  │ Soap brown 100g 6×8      │    30  │ [  30 ]  │ ☐  │
├───────────┴─────────────────────────┴────────┴──────────┴────┤
│ 2 de 3 conferidas · diferenças: 1 (−2 un.)                    │
│ [ Marcar todas iguais ao pedido ]      [ Concluir conferência ]│
└──────────────────────────────────────────────────────────────┘
```

Regras: «Concluir» só com todas as linhas com visto; carregado ≠ pedido exige motivo (lista fechada: falta no armazém,
avaria, pedido do vendedor, outro + texto); carregado > pedido só com motivo e fica para o Back-Office; leitura do código
de barras do artigo marca o visto da linha (opcional); funciona no telemóvel registado pelo QR (dispositivo identificado).

### 3.5 Ecrã do Back-Office

```
┌────────────────────────────────────────────────────────────────────────────────┐
│ Cargas por validar (3)                      Filtro: [Hoje ▾] [Todas as rotas ▾] │
├──────────────┬──────┬──────────────┬──────────┬────────────┬────────┬──────────┤
│ PO           │ Rota │ Conferida por│ Às       │ Diferenças │ Valor  │          │
├──────────────┼──────┼──────────────┼──────────┼────────────┼────────┼──────────┤
│ PO-…-000410  │ 1005 │ fiel.ana     │ 07:12    │ —          │ 0 Kz   │ [✔ Validar]│
│ PO-…-000412  │ 1008 │ fiel.ana     │ 07:20    │ 1 (−2 un.) │ −9 600 │ [Rever]  │
│ PO-…-000413  │ 1011 │ fiel.rui     │ 07:31    │ —          │ 0 Kz   │ [✔ Validar]│
├──────────────┴──────┴──────────────┴──────────┴────────────┴────────┴──────────┤
│ [✔ Validar todas sem diferenças (2)]                                            │
│ Depois de validar: criar a guia no Pinguim BO com a referência PO-…  (opção A)  │
│ Guias encontradas no Pinguim BO: 410 ✔ igual · 412 ⏳ por criar                  │
└────────────────────────────────────────────────────────────────────────────────┘
```

## 4. Controlo, segregação de funções e trilho de auditoria

### 4.1 RACI (R = faz, A = responde, C = consultado, I = informado)

| Passo                               | Vendedor | Supervisor | Fiel de Armazém | Back-Office | Coord. Logística | Direcção / Finanças | Sistema |
| ----------------------------------- | -------- | ---------- | --------------- | ----------- | ---------------- | ------------------- | ------- |
| Sugestão / criação da PO            | R        | A          | I               | I           |                  |                     | R (sugestão) |
| Emissão da PO                       | R        | A          |                 | I           |                  |                     | valida stock e peso |
| Conferência no cais                 | C        |            | R/A             | I           | I                |                     | reserva |
| Validação Back-Office               | C        |            |                 | R/A         | I                |                     | verifica segregação |
| Guia de Remessa no Pinguim BO       |          |            |                 | R           | A                |                     | (B) cria |
| Casamento guia ↔ PO                 |          |            |                 | I           | I                |                     | R/A     |
| Conciliação do dia (contagem cega)  | C        |            | R               | I           | A (1.º nível)    | A (2.º nível, acima do limite) | fecha sem diferenças |
| Tratamento de divergências          | C        | C          | C               | R           | A                | I                   | alerta  |
| Revisão mensal de divergências      |          | I          |                 | C           | R                | A                   | relatório OLAP |

### 4.2 Regras de segregação (impostas pelo sistema, não por procedimento)

- Quem emite a PO não confere a carga; quem confere não valida no Back-Office; quem conta não aprova (já existe para a
  conciliação e para a carga; estende-se ao novo passo do Back-Office).
- Excepções (carga antes do fecho, carregado acima do pedido) exigem uma terceira pessoa com o perfil de fecho.
- Os perfis novos ou ajustados: «Fiel de Armazém» (LOG, G em `conferencia-cais`), «Back-Office» (BO, G em
  `validacao-carga`); a matriz de acessos já impõe um recurso por passo.

### 4.3 Trilho de auditoria

Cada visto ou clique grava: utilizador, perfil, data e hora (servidor), IP e localização do IP, dispositivo (app
registada pelo QR), estado antes/depois, versão do documento, hash das linhas e o motivo. O registo é encadeado por
SHA-256 (já existente): qualquer alteração ou apagamento parte a cadeia e aparece em Administração › Conformidade.
O PDF emitido guarda o seu hash; a 2.ª via fica marcada. Retenção: 10 anos (documentos ligados a movimentos de stock).

## 5. Plano de implementação

### Ganhos rápidos (1 a 2 semanas, só no portal)

1. **Autoconfirmação do armazém da carrinha** pelo código 100010NN único (fim das etiquetas «por confirmar»).
2. **PO no nome e no PDF**: «Requisição Interna / PO», número `PO-…`, QR Code e código de barras (as RQ continuam a
   abrir).
3. **Ecrã do Fiel de Armazém** com scan do QR, visto por linha, «marcar todas iguais» e motivo obrigatório (evolução do
   «confirmar carga» que já existe e já grava a quantidade entregue por linha).
4. **Estado conferida_cais + reserva** no disponível das PO seguintes.
5. **Ocorrências logísticas** no Terreno › Ocorrências: PO parada, guia em falta, divergência de carga.

### Evoluções estruturais (1 a 3 meses)

6. **Validação Back-Office** (1 clique, «validar todas sem diferenças») e **casamento automático PO ↔ guia** em `DOCMOV`
   pela referência (opção A), com o estado transferida e a reserva libertada sozinha.
7. **Livro de movimentos idempotente** por delta (marca d'água) e reconciliação em três vias com tolerâncias
   configuráveis e bloqueios preventivos.
8. **Sugestão de reposição** pela previsão da rota (carteira já calcula dia habitual e ritmo de cada cliente).
9. **Cubos OLAP**: rupturas, OTIF da carga, divergências e anomalias, alimentados pelos factos do portal (contrato
   `rtm.*` do OLAP, nova vista `rtm.f_carga_linha`).
10. **Opção B com o fornecedor do Pinguim BO**: API de escrita (guia e devolução) para a guia nascer da validação do
    Back-Office, sem digitação; até lá a opção A mantém o controlo completo.

### Indicadores de sucesso

PO conferidas sem diferenças ≥ 97 %; tempo da conferência ≤ 5 min por carrinha; guias casadas sozinhas ≥ 95 %;
conciliações fechadas sozinhas ≥ 80 %; divergências em valor −50 % em 3 meses; OTIF da carga ≥ 95 %.

### Decisões em aberto

- O Pinguim BO aceita a referência da PO num campo da guia que se leia no SQL (ex.: observações ou referência externa)?
  Sem isso, o casamento faz-se por rota + dia + linhas (menos robusto).
- O fornecedor do Pinguim BO tem API de escrita ou importação de documentos (opção B)? Prazo e custo.
- Tolerâncias por família de artigo e limites de valor por nível de aprovação (secção 1.2) — validar com a Direcção e as
  Finanças.

## 6. Ordens do portal ao Pinguim BO (documentos gerados no software certificado)

O portal é o único sítio onde se trabalha; o Pinguim BO gera e assina os documentos que o portal lhe ordena.

```
 PORTAL                                                        PINGUIM BO (certificado)
 ┌──────────────────────────────┐   1. ordem (HTTPS, JSON,     ┌─────────────────────────────────┐
 │ validação BO (1 clique)      │      chave de idempotência)  │ API de documentos               │
 │  └► fila de ordens (outbox)  │ ───────────────────────────► │  valida (stock, série, armazém) │
 │     estado: por_enviar       │                              │  cria a guia: numeração, hash,  │
 │            enviada           │ ◄─────────────────────────── │  assinatura, movimento de stock │
 │            aceite (nº doc)   │   2. resposta: nº do         │                                 │
 │            recusada (motivo) │      documento ou erro       └───────────────┬─────────────────┘
 │  └► confirmação por leitura  │ ◄──── 3. o portal lê o documento em DOCMOV (SELECT) e confere
 │     (DOCMOV = ordem?)        │          linhas, armazéns e quantidades com a conferência do cais
 └──────────────────────────────┘
```

| Ordem do portal                    | Quando                                        | Documento no Pinguim BO                         |
| ---------------------------------- | --------------------------------------------- | ----------------------------------------------- |
| Guia de Remessa / transferência    | Back-Office valida a carga conferida no cais  | guia 1150/1151 → 100010NN, com a referência da PO |
| Devolução ao armazém               | conciliação do dia aprovada com mercadoria a voltar | transferência 100010NN → 1150/1151          |
| Acerto de inventário da viatura    | diferença aprovada no 2.º nível               | documento de regularização com o motivo         |
| Anulação de uma ordem              | ordem recusada ou PO anulada antes do envio   | nenhum (só se anula o que ainda não foi gerado) |

Regras da fila (padrão «transactional outbox»):

- **Uma ordem por acção, com chave de idempotência** (`PO-2026-000412/guia`): reenviar nunca cria dois documentos; o
  Pinguim BO devolve o mesmo número à segunda vez.
- **Tentativas automáticas** com espera crescente (1, 5, 15, 60 min); recusa de negócio (sem stock, artigo inactivo)
  não se repete e volta ao Back-Office com o motivo, no portal.
- **Confirmação independente**: o portal não confia só na resposta; lê o documento em `DOCMOV` e compara-o com a
  ordem (hash das linhas). Diferente → ocorrência.
- **Sem API (fase de transição)**: a ordem fica «por executar no Pinguim BO» com tudo o que o Back-Office tem de copiar;
  o portal encontra o documento pela referência e marca-a como executada. O fluxo e os ecrãs são os mesmos.
- **Auditoria**: a ordem regista quem a deu (utilizador, hora, IP, dispositivo), o pedido e a resposta, no registo
  encadeado.

## 7. O que já está feito (20-11-2026) e como ligar o robô

- **Numeração PO** (`PO-AAAA-NNNNNN`, as RQ antigas continuam válidas) e PDF «Requisição Interna / PO» com o **QR** do
  número e da versão.
- **Logística › Cargas (PO)**: «Conferir no cais» (número ou QR, visto por linha, carregado, motivo obrigatório em cada
  diferença, «marcar todas iguais ao pedido»), «Validar» (Back-Office, 1 clique ou todas as sem diferenças, com
  segregação de funções) e «Ordens ao Pinguim BO» (estado de cada guia, execução à mão, repetir, cancelar).
- **Reserva**: a carga conferida desconta no disponível do armazém principal até a guia existir (24 h no máximo).
- **Fila de ordens** (`nucleo/servicos/ordens-pinguim.php`): idempotente, com tentativas automáticas e erros de
  negócio devolvidos ao Back-Office; a tarefa de 5 em 5 minutos confirma cada guia no Pinguim BO (pelo número ou pela
  rota, dia e linhas) e marca a carga como «transferida».
- **Confirmação automática do armazém da carrinha** quando o único armazém tem o código 100010NN da rota.

### Ligar o robô (depois de ver os ecrãs do Pinguim BO)

1. Criar no Pinguim BO a conta técnica `portal-robo`, só com permissão para Guias e Transferências nos armazéns 1150,
   1151 e 100010NN.
2. No `.env` do portal: `ROBO_PINGUIM_CHAVE` (32+ caracteres aleatórios) e, quando a receita estiver pronta,
   `ORDENS_PINGUIM_MODO=robo`; `ORDENS_PINGUIM_TIPOS` com o código do tipo de documento da guia (omissão `GTC`).
3. Copiar `ferramentas/robo-pinguim.receita.exemplo.json` para `robo-pinguim.receita.json` e acertar os seletores
   pelos ecrãs reais (entrar, nova guia, armazém de destino, referência, linhas, gravar, ler o número, procurar pela
   referência). Afinar com `node ferramentas/robo-pinguim.mjs --uma-vez --ver`.
4. No servidor (ou num posto com acesso ao portal e ao Pinguim BO): `ROBO_PORTAL_URL`, `ROBO_PINGUIM_URL`,
   `ROBO_PINGUIM_UTILIZADOR`, `ROBO_PINGUIM_SENHA` e uma tarefa agendada com `node ferramentas/robo-pinguim.mjs`.
5. Ensaio: uma PO de teste do princípio ao fim; confirmar no Pinguim BO o documento gerado e no portal o estado
   «Confirmada no Pinguim BO». Os erros guardam uma captura do ecrã na pasta privada (`robo-pinguim/`).
