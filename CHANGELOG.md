# Registo de alterações

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em dd-mm-aaaa.

## 03-11-2026

### Apresentação do portal, com dados reais e sempre actualizada
- **Apresentação do portal (Gestão):** experiência interactiva, cena a cena, focada no negócio e na automação, para
  comunicar à administração o que o portal já faz e o que se propõe a seguir. Navega-se com as setas do teclado, os
  botões ou os separadores, e abre em ecrã inteiro.
  - **Negócio:** vendas líquidas dos últimos 6 meses, directamente do Pinguim BO; mês até hoje face ao mesmo período do
    mês anterior; documentos, clientes, rotas e valor médio por compra.
  - **Carteira:** clientes em dia, a abrandar e parados, encomendas sugeridas, compra habitual dos parados a
    recuperar, anomalias e tarefas de qualidade dos dados, calculados sozinhos todas as noites.
  - **O dia automático:** cada tarefa da rotina, à hora configurada, com quantas vezes foi feita nos últimos 30 dias
    (resumos, cálculos, planos de cargas propostos, fechos do dia e versões instaladas).
  - **Evolução, futuro, fases e próximos passos propostos.**
- **Sempre actualizada, sem ninguém executar nada:** os números refrescam a cada 10 minutos enquanto a página está
  aberta e crescem com os dados; as páginas vêm do catálogo do portal, as novidades deste registo de alterações e o
  roteiro é revisto em cada versão.
- Disponível para todos os perfis. Os valores de negócio só aparecem a quem vê as páginas de vendas e no seu âmbito
  de rotas. Só agregados: sem clientes, documentos, NIF nem nomes.

### Preparação para o servidor OLAP e rotina diária automática
- **Visitas do dia (Terreno):** os clientes de cada rota para o dia escolhido: os do dia habitual de compra e os que
  estão atrasados em relação ao seu próprio ritmo. Os parados aparecem primeiro e, dentro de cada estado, os de maior
  valor. Cada cliente tem uma ficha de preparação da visita:
  - ritmo de compra (em dia, a abrandar ou parado) e última compra;
  - encomenda sugerida, pela quantidade habitual de cada artigo;
  - o que deixou de comprar;
  - o que propor (artigos que a maioria dos clientes da rota compra e este não);
  - risco de crédito, só para quem vê as facturas pendentes.
- **Qualidade dos dados e anomalias (Gestão):** auditoria contínua dos últimos 7 dias, refeita todas as noites. O que
  for corrigido no Pinguim BO sai sozinho da lista.
  - Anomalias: documentos sem meio de cobrança, notas de crédito acima de 20 % das vendas do cliente, três ou mais
    documentos do mesmo cliente no mesmo dia e descontos muito acima do habitual do artigo.
  - Tarefas de qualidade: clientes sem coordenadas ou no mesmo ponto, artigos sem peso e documentos por comunicar à
    AGT há mais de 2 dias.
- **Plano de cargas proposto às 18:00:** se ainda não houver plano para amanhã, o portal cria um rascunho com as
  rotas que costumam sair nesse dia da semana (último registo e previsão, dentro do peso de cada viatura) e avisa a
  Logística. Nunca publica nem envia sozinho.
- **Fecho do dia às 20:00:** correio por rota com as vendas líquidas, o recebido por meio de cobrança, as notas de
  crédito, os valores em aberto e o estado da conciliação da carrinha. Cada supervisor recebe só as suas rotas
  (função «Fecho do dia» na matriz de acessos).
- **Terreno do dia (GeoMobilidade):** página nova, pronta para quando houver ligação ao OLAP.
  - Percurso de cada rota no mapa, com repetição animada do dia.
  - Paragens e prova de visita: uma venda a mais de 150 m das coordenadas do cliente fica assinalada.
  - Tempos: nos clientes, em deslocação e parado sem vender; quilómetros percorridos.
  - Pontos fora do território da rota.
  - Coordenadas sugeridas pelas vendas: clientes com 3 ou mais vendas no mesmo sítio.

  Acesso só a quem acompanha as rotas: a localização é um dado pessoal (Lei 22/11).
- **Ligação ao OLAP preparada:** só leitura, configurada no `.env` (OLAP_*), com o formato dos dados definido e a
  consulta de exemplo em `ferramentas/sql/olap-localizacao.exemplo.sql`. Os dados são validados (dentro de Angola,
  com precisão aceitável) e fica guardada uma cópia diária.

### GeoMobilidade › Prospecção: carteira, coordenadas e resultados
- A página tem quatro separadores: **Potenciais**, **Sem compras**, **Coordenadas** e **Resultados**. Cada vendedor vê
  só a sua rota.
- **Sem compras.** Mostra os clientes regulares que deixaram de comprar: compravam em 3 ou mais dias nos últimos
  4 meses e estão parados há 14 dias ou mais. Os de maior valor em risco aparecem primeiro.
  - No mapa e na lista, por escalão: 14–29, 30–59 e 60 ou mais dias, com cor e texto.
  - Cada cliente mostra a última compra, o n.º de compras, o valor médio e o botão «Ir».
- **Coordenadas.** Lista os clientes com compras sem coordenadas válidas no Pinguim BO e os clientes diferentes
  registados no mesmo ponto (a menos de 5 m), para corrigir junto ao cliente.
- **Resultados.** Funil da prospecção por rota: por visitar → visitados → interessados → clientes conquistados nos
  últimos 90 dias, com a taxa de conversão. O vendedor tem um novo resultado de visita: «Interessado: a preparar a
  primeira compra».
- Dados só do Pinguim BO e dos registos do portal, recalculados todas as noites.

### Cartazes QR: só o cartaz na folha impressa
- Ao imprimir ou guardar em PDF, os cartazes deixam de levar o título da página, o endereço, a data e o número da
  página nos cantos. Cada cartaz ocupa uma folha A4 sem margens do navegador, com margem de segurança de 12 mm por
  dentro, exactamente como o modelo.

### Painel da sala comercial redesenhado (ecrã de TV)
- Feito segundo as regras para ecrãs de parede vistos à distância:
  - uma mensagem por ecrã, no máximo seis números, o principal em destaque;
  - letra medida pela altura do ecrã, nunca abaixo de ~30 px num ecrã 1080p (legível a 5 m);
  - contraste do texto ≥ 7:1 (WCAG AAA) e margens de segurança de 5 % (área útil dos televisores);
  - variações sempre com símbolo e palavra («▲ +12 % face ao habitual»), nunca só com a cor;
  - valores grandes abreviados (30,54 M Kz) e o número do dia por extenso;
  - real contra a previsão do dia, com barra e percentagem.
- O título do ecrã fica no topo; no rodapé, a barra do tempo até ao ecrã seguinte, o nome do ecrã seguinte e a hora
  dos dados. Se passarem 10 minutos sem dados novos, aparece um aviso em âmbar.
- Classificações com as 8 primeiras rotas e pódio numerado. Continua o reconhecimento positivo: nunca o último lugar.
- Protecção do ecrã: deslocação mínima a cada 4 minutos e brilho reduzido fora de horas (21:00–06:00). Sem piscar e
  sem animação para quem pede menos movimento.
- Mesmo endereço e mesmas ligações; os dados continuam a vir do Pinguim BO, só com totais por rota.

### Meios de cobrança: compensação e «Sem meio indicado» separados
- **Factura anulada por nota de crédito** (liquidada no Pinguim BO sem rodapé de pagamento e corrigida por uma nota
  de crédito do mesmo cliente): foi a nota que a liquidou, sem dinheiro. As duas passam a «Compensação com nota de
  crédito» (encontro de contas, IAS 32) e saem de dinheiro, Multicaixa e outros meios. A nota de valor igual é
  emparelhada primeiro.
- **Em dinheiro + Em Multicaixa (+ transferências) = recebido líquido**, sem a linha «Outros meios» indevida.
- O que continuar liquidado sem rodapé aparece como **«Sem meio indicado»** (âmbar, com ligação às Ocorrências para
  corrigir no Pinguim BO), nunca escondido em «Outros meios».

- A compensação também se faz quando o crédito da nota é usado para pagar uma **factura seguinte** do mesmo cliente
  (ex.: nota de crédito a 30-10 e factura de igual valor a 03-11, liquidada sem dinheiro).

### Detalhe do documento: o documento como foi emitido
- O painel lateral passa a mostrar **o documento numa página única**, maior e organizada como uma factura:
  - tipo e número, data, hora, rota, cliente, NIF e código do cliente;
  - as linhas com código, descrição, quantidade, unidade, preço unitário, desconto, taxa de IVA e total;
  - o resumo do IVA por taxa, os meios de pagamento e os totais (ilíquido, descontos, IVA, total do documento,
    recebido, em falta).
- Letra legível, números alinhados, sem nada cortado nem deslocamento lateral. Imprimir e Fechar ficam sempre visíveis
  no topo. Imprime-se só o documento.

### Plano de cargas: pré-preenchido a partir do registo
- O pré-preenchimento só usava planos já publicados no portal; sem nenhum publicado, os cartões vinham vazios.
- O **registo histórico de cargas** passa a fazer parte do registo do portal. Entra uma vez, sozinho, como dias
  publicados marcados «importado», e nunca por cima de um dia que já tenha plano.
- As Listas aprendem os motoristas, as viaturas, as transportadoras e as zonas desse registo.
- Num dia novo, cada rota vem preenchida com o último registo dela: motorista, matrícula, viatura, transportadora,
  zonas, quantidades e armazém. Aparece a etiqueta «pré-preenchido com o registo de dd-mm · confirme».
- A factura e as observações continuam a não se copiar.

### Comunicação AGT: pedidos de todas as séries
- O estado lia só o campo extra n.º 26046 (request_number). No Pinguim BO cada tipo de documento / série pode ter o
  seu próprio campo com o mesmo nome, por isso os documentos das outras séries apareciam todos «Por comunicar».
  Passam a contar todos os campos extra chamados **request_number** (e «Motivo de cancelamento» para as anulações),
  com o número do pedido em texto ou em número. Só leitura (SELECT), como sempre.

### Excel dos documentos e da AGT para todos
- Todos os perfis que vêem os Documentos e a Comunicação AGT passam a exportá-los para Excel (supervisores e
  promotores só das suas rotas).

### Actualizações: pacote único
- As versões por instalar juntam-se num **pacote de instalação único** (ISO/IEC 20000-1, «release package»): um só
  cartão com todas as versões incluídas e tudo o que muda, uma só aprovação, uma só instalação, uma só cópia de
  segurança e um só ponto de reposição.

### Documentos: nomes de secção normalizados
- «Vendas por rota» passa a **«Resumo por rota»** e «Documentos Pinguim» a **«Registo de documentos de venda»**
  (diário de vendas), sem nome de sistema no título.

### Dinheiro + Multicaixa = vendas cobradas (notas de crédito no meio da factura)
- As **notas de crédito sem meio de devolução** no Pinguim BO passam a debitar o meio com que o cliente pagou a factura
  que corrigem (regra «devolução ao meio original» dos sistemas de ponto de venda): a factura mais recente do mesmo
  cliente e rota até à data da nota (Consumidor Final: só no mesmo dia), repartida pelos meios dessa factura, sem
  nunca devolver mais do que ela recebeu nesse meio. Sem factura correspondente, numerário marcado como presumido.
- Resultado: **Em dinheiro + Em Multicaixa (+ outros) = vendas líquidas cobradas**, como na conciliação de caixa.
  No detalhe do documento vê-se de que factura veio cada devolução («meio da FR …») ou se foi presumida.

### Detalhe do documento
- Clicar no número de um documento (Documentos, Cobranças, Terreno, AGT) abre um painel com a representação do
  documento (talão), os artigos, os meios de cobrança e o estado; no telemóvel abre de baixo para cima. Fecha com Esc,
  com o botão ou clicando fora; imprime-se só o documento. Os dados são os do Pinguim BO.

### Actualizações: aprovação automática às 02:45 e instalação às 03:00
- As versões **não decididas durante o dia** são aprovadas automaticamente às 02:45 (só a mais recente, que inclui as
  anteriores) e tudo o que estiver aprovado é **instalado às 03:00**, fora do horário de trabalho, com cópia de
  segurança, verificação e reposição automática se falhar. «Instalar já» continua a instalar em 5 minutos.
- Salvaguardas (gestão de alterações ITIL): só versões publicadas há pelo menos uma hora; uma versão reprovada nunca é
  aprovada assim; se a última instalação falhou ou foi revertida, nada é aprovado sozinho até alguém decidir; a
  decisão fica registada como «aprovação automática» e o super administrador recebe um correio.
- Corre na tarefa de 5 em 5 minutos que já existe: não é preciso nenhum comando no servidor.

### Perfil EXT para técnicos externos (validação)
- Perfil novo do sistema **«Técnico externo (validação, só leitura)»**, já configurado na matriz segundo o menor
  privilégio (ISO 27001, NIST SP 800-53): **só ver** todas as páginas de relatórios, cobranças, cargas, conciliação,
  gestão e GeoMobilidade, **em todas as rotas**; nunca exportar, gerir ou administrar; sem NIF, sem registos de
  actividade, sem matriz de acessos, sem ficheiro SAP e sem assistente de IA. Verificação em dois passos obrigatória.
  Criar uma conta por técnico no portal (Acessos › Utilizadores), com o perfil EXT e «Válida até» preenchido.

### Botões de filtro seleccionados legíveis
- O botão seleccionado (ex.: «Todos» nos alertas da Gestão) perdia o texto; passa a invertido (fundo claro, texto
  azul naval) e o estado vai também para os leitores de ecrã.

### Meios de cobrança sempre como no Pinguim BO
- **Nunca se presume o meio**: uma factura liquidada sem rodapé de pagamento no Pinguim BO deixava de ser contada como
  Multicaixa e passa a «Sem meio indicado» (e aparece em Terreno › Ocorrências para corrigir no Pinguim BO).
- **Troco**: o valor por meio é acertado ao valor pago do documento (o troco sai do numerário), também na leitura
  directa da base de dados.
- **Notas de crédito por meio**: a devolução ao cliente registada no Pinguim BO (numerário, Multicaixa…) debita esse
  meio; as notas de crédito sem meio de devolução ficam numa linha própria. Dinheiro + Multicaixa + outros − notas de
  crédito sem meio = recebido; se não fechar, a página Cobranças avisa. O ficheiro para o SAP não muda.
### Valor médio e artigos por compra: uma cor por rota
- Cada rota tem a sua cor, sempre a mesma em qualquer filtro (atribuída pelo código da rota): paleta categórica
  validada para daltonismo e contraste nos dois temas, sem verde; da 8.ª rota em diante o mesmo tom em anel (sem
  preenchimento), como manda a boa prática (não inventar cores novas). Legenda por baixo do gráfico e rótulos ao lado
  das bolas sem se sobreporem (o que não cabe fica na dica e na tabela).

### Recebido líquido: as notas de crédito debitam o recebido
- O **Recebido** passa a ser o cobrado nas facturas **menos as notas de crédito a clientes** (devoluções e créditos
  debitam o recebido, nunca o aumentam), no Início, em Recebimentos › Cobranças, nos documentos, nas vendas por
  promotor e por dia e na classificação. Com tudo pago, o recebido é igual às vendas líquidas (facturas − notas de
  crédito) e a taxa de cobrança é 100 %; recebido + facturas pendentes = vendas líquidas.
- **Por meio de cobrança**: os meios mostram o que foi cobrado (dinheiro, Multicaixa…) e uma linha «Notas de crédito a
  clientes» a negativo, para o total da tabela ser o recebido líquido; nas tabelas por promotor e por dia, coluna
  «Notas de crédito». O ficheiro para o SAP não muda (as notas de crédito seguem como documentos próprios).

### Vendas por promotor só com rotas com movimento
- A tabela mostra só as rotas com movimento no período e nos filtros escolhidos (sem linhas a zero); as rotas sem
  movimento ficam indicadas numa linha por baixo da tabela. Valores que arredondam a zero aparecem sem sinal (nunca
  «−0,00 Kz»), em todo o portal.

### Actualizações: estado em tempo real e protecções
- Depois de aprovar, a página mostra o estado da instalação: **«Na fila: a tarefa agendada instala até às HH:MM»**,
  **«A instalar…»** e o resultado, actualizando-se sozinha de 15 em 15 segundos enquanto está em curso.
- **Só a versão mais recente se aprova** (já inclui todas as anteriores); as anteriores ficam recolhidas, sem botões. O
  servidor recusa aprovar uma versão mais antiga do que a mais recente por instalar, a não ser que esta seja reprovada.
- **GitHub App tolerante à hora do servidor**: se o GitHub recusar por a hora estar fora de tempo, o actualizador mede a
  diferença pela hora do GitHub, acerta a assinatura e tenta de novo (e regista o aviso para acertar o relógio).
- **Repor a versão anterior** com erros já não apaga a cópia de segurança (pode repetir-se sem recuar para uma cópia
  mais antiga); a mensagem do certificado HTTPS indica o caminho do ficheiro `cacert.pem` (não o endereço).

### GeoMobilidade: onde procurar clientes novos
- **Prospecção** (GeoMobilidade › Prospecção): mapa e lista, por rota e dia de visita, dos estabelecimentos comerciais
  que ainda não são clientes, ordenados por prioridade (confirmado em duas fontes, tipo de estabelecimento, zona
  comercial e proximidade dos clientes da rota), com o caminho até lá e o resultado da visita registado pelo vendedor.
  - Clientes e coordenadas lidos do Pinguim BO (onde os promotores já os registam com GPS e fotografia); rota, dia
    habitual de visita e ritmo de compras pelas vendas dos últimos 56 dias.
  - Estabelecimentos do OpenStreetMap (gratuito, todas as semanas) e do Google Places (limite mensal de pedidos; pelas
    regras do Google as coordenadas apagam-se ao fim de 30 dias); os que já são clientes (a menos de 30 m, ou de 80 m
    com nome parecido) ficam de fora; quando um potencial cliente passa a cliente conta como convertido.
- **Território automático de cada rota**: zona à volta dos seus clientes, que cresce sozinha à medida que os clientes
  aumentam; cada potencial cliente entra na rota e no dia dos clientes mais próximos.
- **Capacidade das rotas** (30 visitas por dia × 6 dias): ocupação, cobertura (clientes ÷ estabelecimentos) e aviso
  por correio electrónico quando uma rota passa 90 % durante 14 dias seguidos — **é preciso uma nova rota (vendedor +
  viatura)**, com a proposta de divisão do território no mapa; aviso também das zonas sem rota com muitos potenciais
  clientes.
- Nova linha da matriz de acessos «geo-prospeccao»; API `v1/prospeccao`.

## 02-11-2026

### Logística: conciliação da carrinha e limite de peso da carga
- **Conciliação da carrinha** (Logística › Conciliação da carrinha): por rota, dia e artigo, o que estava na carrinha,
  o que foi carregado, vendido e devolvido, lido do Pinguim BO; o que tem de voltar é calculado sozinho.
  - **Alertas automáticos**: vendido acima do que estava na carrinha, stock negativo e movimentos sem documento.
  - **Contagem cega** pelo técnico do armazém no regresso (não vê o esperado antes de gravar), na app no telemóvel.
  - As **diferenças ficam a aguardar a aprovação do técnico**, com motivo obrigatório (produto danificado, fora de
    validade, erro na carga, venda não registada, erro de registo, falta sem justificação ou outro, com nota).
  - Valor das diferenças ao preço médio de venda do dia; histórico de contagens e aprovações; aviso às 19:30 das
    carrinhas por contar ou por aprovar; aviso à Direcção e ao Financeiro quando a falta aprovada passa o limite.
  - Nova linha da matriz de acessos «conciliacao-carrinha» (contar e aprovar: Logística).
- **Limite de peso da carga** no plano de cargas: peso de cada unidade de carga dos artigos e capacidade de cada viatura
  (pela matrícula) nas listas do plano. O plano não deixa carregar acima do que ainda cabe: capacidade menos o que a
  carrinha ainda leva (stock actual no Pinguim BO × peso de cada artigo, que baixa à medida que o promotor vende).
  O servidor recusa também a gravação e a publicação acima do limite.

### Recebimentos: antiguidade da dívida em escalões internacionais
- A **antiguidade da dívida** passa aos escalões usados internacionalmente nas contas a receber: **0–30, 31–60, 61–90
  e mais de 90 dias** (contados desde a emissão da factura), no Início, em Recebimentos › Facturas pendentes e nas
  tabelas por rota e por cliente, com cores de risco crescente e sempre com o rótulo.
- Novo indicador **Prazo médio de recebimento** (PMR, em inglês DSO): dívida por receber ÷ vendas facturadas no
  período × dias do período.

### Painel por perfil, arrastar e largar, vistas guardadas
- **Início conforme o perfil** (prática internacional: cada função vê primeiro os indicadores que gere):
  Direcção (e TI, Auditoria, Administração) — resultado, tendência, ponte, cobrança e dívida; Comercial — crescimento,
  cobertura (distribuição numérica), valor por compra, carteira de clientes; Supervisão — execução das rotas, clientes
  visitados, novos e perdidos; Financeiro — cobrança, antiguidade da dívida, rotas e promotores. Os perfis operacionais
  mantêm o Início de sempre.
- **Arrastar e largar os gráficos** no Início (rato pelo título, ou teclado: Alt + ← / →). A nova ordem vale só até sair;
  **Guardar vista** grava-a para a pessoa (em qualquer computador); **Desfazer** e **Repor origem** voltam atrás.
- **Ecrã de TV configurável**: em Gestão › Painel da sala › Ecrãs escolhem-se os ecrãs, a ordem (arrastar) e os
  segundos de cada um; o ecrã recebe a configuração na actualização seguinte.
- API: `v1/vistas` (GET/POST, com CSRF) e `configurar` em `v1/sala/ligacoes`.

### Gestão com inteligência artificial
- **Alertas** (Gestão › Alertas): rotas sem vendas ou muito abaixo do habitual (comparação com o mesmo dia da semana
  nas últimas 6 semanas, por mediana), semanas em queda ou em subida, clientes regulares que deixaram de comprar,
  documentos por comunicar à AGT e equipamentos sem contacto com o MDM; cada alerta diz o valor, o esperado e porquê.
- **Previsão de vendas** (próximos 7 dias, por rota): média ponderada do mesmo dia da semana × tendência, com intervalo
  de 80 % e a precisão medida todos os dias; as quantidades sugeridas no **plano de cargas** passam a usar a média das
  últimas cargas × a tendência das vendas da rota (a Logística confirma sempre).
- **Início com o painel de gestão RTM** (num só ecrã, sem deslocação, grelha 4 × 3), com os gráficos de referência da
  distribuição porta-a-porta, mantendo os de recebimentos («Como foi cobrado» e «Antiguidade da dívida»):
  tendência diária com média móvel de 7 dias e previsão; mês até hoje por rota (balas) e ponte da variação do mês;
  curvas ABC de clientes e de artigos; distribuição numérica por artigo; valor médio e artigos por compra por rota;
  mapa de calor rota × dia da semana; clientes activos, novos e perdidos por mês. Cada gráfico tem a vista em tabela.
- **Assistente IA** (perfis com «Assistente IA» na matriz): perguntas em linguagem natural sobre vendas, rotas,
  clientes, alertas, previsão, plano de cargas e AGT; só leitura, com o âmbito de quem pergunta; os números vêm sempre
  do portal e cada resposta indica as fontes; limite mensal e diário; tudo registado. Configuração: `php
  ferramentas/configurar-ia.php`.
- **Resumo diário** por correio electrónico às 07:00 (perfis com «Resumo diário» na matriz): vendas de ontem face ao
  habitual, semana, mês, rotas, alertas e previsão do dia, com um parágrafo escrito pela IA.
- **App de gestão** («Naval Gestão», instalável no iPhone e no Android): vendas de hoje face à previsão, ontem, semana,
  mês, classificação das rotas, alertas e assistente.
- **Painel da sala comercial** (ecrã de TV): vendas do dia ao vivo, classificação do dia e do mês, destaques e
  sequências; só reconhecimento positivo; ligação própria revogável, sem conta e sem dados de clientes.

### Cartaz QR do cliente
- **Cartazes A4** (Clientes › Cartazes QR dos clientes): um código aleatório por cliente, impresso em A4 para colar no
  estabelecimento; pode substituir-se a qualquer momento.
- **Página do cliente** (sem conta): encomenda já sugerida pelas compras habituais do cliente e por artigos que outros
  clientes da zona levam, actualização dos dados da empresa (com localização) e consentimento para mensagens por
  WhatsApp.
- **Validação no back-office** (Clientes › Pedidos dos clientes): nada muda sem aprovação; cada linha da encomenda vem
  marcada como dentro ou fora do habitual; encomendas aprovadas avisam o promotor da rota; dados aprovados ficam na
  lista para lançar no Pinguim BO.

### Automatização completa do servidor
- **GitHub App** em vez do código de acesso pessoal: o servidor assina um pedido (JWT RS256) com a chave da App e recebe
  uma credencial de instalação que dura 1 hora; a chave não expira (sem renovação anual); só leitura e só no
  repositório do portal.
- **Certificados HTTPS:** renovação automática no servidor (ACME, win-acme); o portal confere todas as noites a
  validade de cada endereço, mostra-a na página «Actualizações» e avisa por correio electrónico a 30, 14, 7, 3 e 1 dias.
- **Apache como código:** configuração do portal em `ferramentas/apache/portal-rtm.conf`, incluída uma vez no
  `httpd.conf` (`php ferramentas/actualizar.php apache`); quando muda, o actualizador valida (`httpd -t`), reinicia o
  Apache e repõe a anterior se falhar. Apache sem versão no cabeçalho `Server`.
- **Requisitos do PHP:** cada versão declara a versão mínima e as extensões (`ferramentas/requisitos.json`); as
  extensões em falta são activadas no `php.ini` (com cópia e verificação) antes da instalação.
- **Ficheiros alterados à mão:** a página «Actualizações» mostra-os e permite «Aplicar mesmo assim» (ficam na cópia
  de segurança), sem ir ao servidor.
- **Migrações:** conta própria só para alterar a estrutura da base de dados do módulo Equipamentos.

### Aprovação das actualizações
- **Página «Actualizações»** (Administração, só o super administrador): versões publicadas e testadas à espera de
  decisão, com o resumo do que muda; **Aprovar** (instala nessa noite às 02:15), **Aprovar e instalar já** (em
  5 minutos) ou **Reprovar** (com motivo); anular uma decisão ainda não instalada; repor a versão anterior; histórico
  com quem decidiu, quando e o resultado da instalação.
- **Controlo:** cada decisão pede a confirmação da identidade (palavra-passe ou código de dois passos) e fica no registo
  de auditoria; a aprovação fica presa ao SHA-256 do pacote aprovado.
- **Servidor:** o actualizador só instala versões aprovadas (`ACTUALIZACAO_EXIGE_APROVACAO=1`); tarefa de 5 em 5 minutos
  para «instalar já» e reposições; aviso por correio de cada versão nova à espera de aprovação.

### Resumo das actualizações
- **Super administrador:** na primeira entrada depois de cada actualização (manual ou automática), o portal mostra
  uma janela com o resumo do que mudou, tirado deste registo de alterações; fechada, não volta a aparecer.

### Actualizações automáticas
- **Publicação:** cada alteração no main que passa nos testes dá uma versão no GitHub com o pacote do servidor, o
  SHA-256 e a proveniência assinada; um Windows com PHP ensaia a actualização a partir da versão publicada.
- **Actualizador do servidor** (`ferramentas/actualizar.php`, tarefa agendada às 02:15): descarrega e confere a versão,
  pára se houver ficheiros alterados à mão, aplica as migrações da base de dados do módulo Equipamentos, guarda cópia,
  copia só o que mudou, verifica o portal (linha de comandos e HTTP) e repõe a versão anterior sozinho se falhar.
  Nunca escreve o `.env` nem os dados. Avisos por correio electrónico.
- **Migrações:** tabela `ATV_MIGRACAO` no ficheiro SQL do módulo Equipamentos e pasta `ferramentas/sql/migracoes`.

### Velocidade (2.ª parte)
- **Páginas a pedido:** o arranque traz só o núcleo do portal e a página que abre; o módulo de cada outra página
  descarrega-se quando a página se abre pela primeira vez (ou antes, quando o rato ou o dedo passa na ligação do menu).
  O import map continua a dar a versão a cada módulo; o pré-carregamento fica só para o arranque e a página principal.
- **Idiomas a pedido:** os dicionários de inglês e tigrínia (100 a 150 KB cada) descarregam-se só quando o idioma é
  usado; em português não se descarrega nenhum. O dicionário do idioma da conta pré-carrega-se.
- **Arranque em paralelo:** sessão, rotas, carteira, cargas, cadastros, vendas, dicionário e página partem ao mesmo
  tempo (antes em quatro etapas seguidas).
- **Linhas de artigos só quando são precisas:** o portal abre com os documentos; as linhas pedem-se à parte
  (`include_items=only`), uma vez por período, ao abrir «Vendas por artigo», «Vendas para o SAP», o filtro de artigo
  ou o relatório consolidado. Na página principal o gráfico de artigos aparece quando as linhas chegam.
- **Pedidos condicionais resolvidos cedo:** o ETag das vendas, dos dados de base e dos cadastros calcula-se da data e
  do tamanho da origem (ficheiros, entradas da memória temporária) e do âmbito de quem pede, antes de ler e converter os dados;
  quando nada mudou o servidor responde 304 logo (ex.: carteira de clientes 8,3 ms → 3,2 ms).
- Medido numa rede móvel simulada (150 ms, 1,5 Mbps), no Apache: portal 3,4 s → 2,4 s na primeira vez (303 KB →
  152 KB, 61 → 46 pedidos) e 1,4 s → 0,9 s nas seguintes. Sem alterações à configuração do Apache.

### Velocidade
- **Pedidos à API em paralelo:** a sessão PHP fecha-se para escrita logo a seguir à autenticação; os outros pedidos
  da mesma pessoa deixam de esperar pelas consultas lentas (medido: 1,8 s → 6 ms enquanto as vendas são lidas).
- **Compressão** do HTML, CSS, JS e SVG no Apache (mod_deflate): cerca de 3 vezes menos bytes.
- **Memória temporária longa com versões:** os módulos e estilos do portal levam a versão no endereço (import map) e ficam no
  navegador um ano; uma actualização muda só a versão dos ficheiros alterados. Pré-carregamento dos módulos.
- **Sem dependências externas ao abrir:** letras Inter e Poppins servidas pelo portal (sem Google Fonts) e os ícones
  da página de entrada sem a biblioteca de 458 KB.
- Ecrã de carregamento sai logo que os dados chegam.
- Medido numa rede móvel simulada (150 ms, 1,5 Mbps): entrada 3,6 s → 1,0 s; portal 7,4 s → 3,4–3,9 s na primeira
  vez e 1,4 s nas seguintes (de 869 KB para 14 KB).

## 01-11-2026

### Equipamentos (fase 1)
- **Novo módulo «Equipamentos»** no menu, entre Logística e Integrações: lista dos smartphones e impressoras móveis
  do terreno, ficha de cada equipamento (estado, rota, responsável, equipamento associado, identificação técnica,
  histórico e documentos), registo de equipamentos e de colaboradores, eventos (atribuição, devolução, reparação,
  perda ou roubo, recuperação, abate, etiquetagem, inventário) e associação smartphone ↔ impressora.
- **Documentos com assinatura no ecrã:** Termo de Entrega e Responsabilidade, Autos de Devolução, Substituição e
  Abate, Participação de Perda ou Roubo e Convenção de Prova Electrónica (exigida antes do primeiro termo). PDF com
  o logótipo, numeração XXX-AAAA-000001, impressão digital SHA-256 na emissão, assinatura do colaborador (presencial,
  com testemunha, ou com a sua conta) e do responsável, e página de assinaturas acrescentada ao original sem o
  alterar. As cláusulas são modelos provisórios, a validar pelo departamento jurídico.
- **Código QR das etiquetas:** `equipamentos/ficha.php?id=` abre a ficha a quem tem sessão e permissão; sem
  sessão mostra só a página pública «propriedade do Grupo Naval» com o contacto de devolução.
- **App para iPhone e Android (PWA):** manifesto, ícones, página «Sem ligação» e service worker que nunca guarda
  fichas, documentos nem assinaturas no telefone.
- **Matriz de acessos:** `equipamentos` (consultar), `equipamentos-movimentar` e `equipamentos-administrar`.
- Base de dados própria do módulo (`ATV_DB_*`; ficheiro SQL `ferramentas/sql/equipamentos-sqlserver.sql`), separada do
  Pinguim BO, que continua só de leitura. Testes do módulo numa base SQLite.
- Preparação para o SQL Server: número gerado lido com `@@IDENTITY` (as tabelas com triggers não aceitam `OUTPUT`
  directo) e verificação da instalação com `php ferramentas/verificar-equipamentos.php` (escrita de ensaio desfeita).
- **Segunda validação:** testes num SQL Server real (Windows com o ODBC Driver 17, como em produção, e Linux com
  pdo_sqlsrv) e verificação da ligação ao MDM Plus, só de leitura (`php ferramentas/verificar-mdm.php`): código de acesso OAuth,
  lista dos equipamentos e cruzamento com o portal pelo ID do MDM, IMEI ou n.º de série.
- **Configuração do MDM num só passo:** `php ferramentas/configurar-mdm.php` pede o Client ID, o Client Secret e o código
  do Self Client, troca o código pelo código de renovação (refresh token), grava as chaves no `.env` (com cópia do anterior, sem mostrar os
  segredos) e verifica a ligação.
- Fase 2: selagem PAdES com selo temporal, ordens ao MDM Plus, etiquetas, inventário e reconciliação com o MDM.

### Junção (servidor + GitHub)
- **Uma só versão do código:** junta o que estava no servidor (pacotes de 28 a 30-10: stock e vendas pela base de dados,
  sincronização, tigrínia e idioma por conta, modo claro, notas de crédito, Comunicação AGT) com o que estava no GitHub
  (API central v1 com JWT, segurança ASVS, pré-preenchimento do plano de cargas, localização das entradas, plano sem
  Excel). Ponto comum: revisão ca0b85a (27-10, 11:37).
- Serviços do servidor passados para a API v1 (`reports/data/` não serve PHP): `v1/stock` e `v1/agt`; a página de
  escolha pede o perfil e o idioma da conta pela API (`v1/auth/token` + `v1/sessao`).
- Ficheiro SAP: fica o formato que estava em produção (uma folha, um cabeçalho, sem estilos).
- Página de entrada e «Mudar a palavra-passe»: regras novas de palavra-passe (12 caracteres, sem palavras-passe comuns),
  verificação em dois passos na entrada, tema claro/escuro e PT/EN/TI.
- Retirado do repositório: `Clientes (59).csv` (dados pessoais de clientes); o diagnóstico SQL do stock passa a
  ferramenta de linha de comandos, sem valores de ligação predefinidos.

### Segurança
- **Localização das entradas nos Registos de actividade:** país, região e cidade aproximados de cada IP (com o raio de
  precisão e ligação ao mapa), calculados no servidor com uma base de dados local no formato MaxMind DB
  (`nucleo/seguranca/geoip.php`, leitor próprio sem dependências); o IP nunca sai do servidor. Endereços privados
  aparecem como «Rede interna». Actualização mensal da base (DB-IP City Lite, CC BY 4.0) com
  `ferramentas/actualizar-geoip.php`. Os registos passam a usar o IP do cliente atrás de proxy de confiança.

### Dados
- **Plano de cargas sem dados de Excel:** o pré-preenchimento, as listas e as páginas de stock por carrinha e
  escoamento usam só os planos publicados no portal; o histórico importado dos Excel de transportes
  (`reports/data/cargas.json`) deixa de ser lido.

## 31-10-2026

### Plano de cargas
- **Pré-preenchimento automático dos cartões das rotas:** num dia novo, cada rota vem preenchida com o seu último
  registo — primeiro a última versão publicada do plano em que a rota saiu, e em reserva o histórico importado dos
  Excel de transportes (`reports/data/cargas.json`). Preenche motorista, telefone, matrícula, viatura e
  transportadora (actualizados pela ficha do motorista nas Listas), zonas de descarga, quantidades por artigo,
  armazém e viagens; o número de factura e as observações nunca se copiam. Nunca escreve por cima do que já foi
  preenchido nem altera planos publicados. Ao marcar uma rota vazia como «sai», preenche-se da mesma forma. Cada
  cartão mostra «pré-preenchido com o registo de dd-mm · confirme». A API devolve as sugestões só a quem gere o
  plano (`sugestoes` em `GET v1/plano`).

## 30-10-2026

### Adicionado (Integrações)
- **Comunicação AGT** (`#agt`, menu Integrações): cada documento de vendas do período com rota, tipo de documento,
  documento, total, data do documento, data fiscal, estado AGT (número do pedido de comunicação), estado do documento
  (activo / anulado, com o motivo de cancelamento), nome da entidade e estado (comunicado / por comunicar).
  Fonte: `DOCHEADEREXTRA` ligada ao `DOCHEADER` por `DOCHEADER_ID`, campos extra 26046 «request_number» e 26003
  «Motivo de cancelamento» (tabela `DOCEXTRA`; mudam-se no .env com `AGT_PEDIDO_ID` e `AGT_ANULACAO_ID`), só SELECT.
  O Pinguim BO guarda o pedido, não a resposta da AGT. Nova linha «agt» na matriz de acessos (TI, DIR e BO ver;
  FIN e AUD exportar), editável no portal.

### Corrigido (cálculo)
- **Total de vendas líquido de notas de crédito**: Total de Vendas Líquidas = Facturas − Notas de Crédito. Antes, as
  consultas ao Pinguim BO só traziam documentos com total positivo (e a exportação REST só FR/FT), pelo que as notas
  de crédito ficavam de fora e o total de vendas era o bruto facturado.
  - Origem (`nucleo/servicos/vendas.php`, só SELECT): as notas de crédito não anuladas (sinal de vendas negativo no
    `DOCHEADER`; `NC` na exportação REST) passam a vir com o total e as linhas em negativo e `nc: true`, sem pagamentos.
  - Portal (`reports/js/calculos.js` e páginas): qualquer total de vendas é líquido (Início, Vendas por promotor, dia,
    cliente e artigo, Classificação, Documentos, Auditoria, Plano de visitas). As notas de crédito não contam como
    documentos, clientes com compras, visitas, recebido nem pendentes; «Facturas pendentes» passa a somar o que falta em
    cada factura; a taxa de cobrança mede-se sobre o facturado. Quando há notas de crédito, o quadro das vendas mostra
    o valor descontado.
  - Ficheiro SAP: continua só com facturas (as notas de crédito não entram; decisão de 30-10-2026).
  - Testes: cenários sem, com uma, com várias e com nota de crédito parcial, sem vendas, fora do período e anuladas.

### Adicionado
- **Modo claro** no Portal RTM, na página de escolha (`index.html`) e na página de entrada (`geo/login.php`), com o
  selector **Sistema / Claro / Escuro** (por omissão segue o sistema operativo). A escolha fica no navegador (`vm-tema`)
  e vale nas três páginas; aplica-se antes do primeiro desenho (`reports/js/tema-arranque.js`), sem clarão.
  Só visual: regras de negócio, serviços, consultas e base de dados não mudam.
- **Cores centralizadas em tokens** (`reports/css/cores.css`): as cores fixas dos estilos do portal passaram a tokens
  semânticos com valores para cada tema; o modo escuro mantém exactamente os valores anteriores. Azuis Naval no modo
  claro, textos com contraste WCAG 2.2 AA (≥ 4,5:1).
- Testes do tema (`tests/portal/tema.spec.js`, `tests/servidor/tema.spec.js`): selector, persistência, seguir o
  sistema, partilha entre páginas e contraste AA no modo claro.

### Corrigido
- **Cartão da conta no menu lateral**: o nome da conta, quando é genérico (ex.: «Administrador»), passa a aparecer na
  língua escolhida, como o nome do perfil; nomes de pessoas ficam como estão registados. O mesmo no «Gerado por» dos Excel.
- **Cartão dos telemóveis no menu lateral**: passa a mudar de língua com o resto do portal (antes só mudava na leitura
  seguinte da sincronização, ao fim de um minuto). Só visual e idioma.
- **Página de escolha (Reports / GEO)** (`index.html`): passa a ter o tigrínia (botão TI, textos e tipo de letra ge'ez);
  antes só conhecia PT e EN e, com o tigrínia escolhido, ficava em português. A cada nova entrada abre no idioma da
  ficha da conta, como o portal. Só visual e idioma.

## 29-10-2026 (página de entrada)

### Adicionado
- **Selector de idioma (PT/EN/TI) na página de entrada** (`geo/login.php` e `geo/login-idioma.js`): textos, campos,
  passos de verificação e de nova palavra-passe e mensagens de erro na língua escolhida, com o tipo de letra ge'ez do
  portal. Só visual e idioma: a entrada, as validações e os dados não mudam. Depois de entrar, o portal abre no idioma
  da ficha da conta.

## 29-10-2026 (idioma por conta e menu)

### Adicionado
- **Idioma do portal na ficha de cada conta** (Português, English, ትግርኛ), campo obrigatório ao criar e alterar contas
  (validado também no servidor) e coluna «Idioma» na lista de utilizadores. Sempre que a pessoa entra, o portal abre
  nesse idioma; a escolha no topo (PT/EN/TI) vale até à entrada seguinte. Contas antigas sem idioma abrem em português
  até serem alteradas. A alteração do idioma fica no registo de actividade.

### Alterado
- **Menu**: abrir uma área selecciona logo a primeira página dessa área, e escolher uma página abre sempre o primeiro
  relatório (o que está imediatamente a seguir). O «Início» mantém o comportamento: fecha as áreas.
- **Pré-visualização do ficheiro para o SAP**: os nomes das colunas ficam iguais em todas as línguas (INVOICENO,
  DOCUMENT_DATE, Artigo, QUANTITY, Determinação da location, NETSALES, TAXAMOUNT, GROSSSALES, Invoice Total, CASH AMOUNT,
  CARD AMOUNT, CUSTOMER, SALES EMPLOYEE, e as colunas ocultas LOJA, PRODUCT, UNIT, STORAGELOCATION), porque são os que
  a integração entre os dois sistemas reconhece.

## 29-10-2026 (revisão das três línguas)

### Corrigido
- **Tigrínia e inglês sem textos em português**: nomes das páginas e funções da matriz de acessos, nomes dos perfis,
  registos de actividade (acção, campos e valores), mensagens do servidor (com partes variáveis, como «Lote 12 não
  encontrado»), tipos e estados de cliente, meios de cobrança, folhas e colunas dos Excel (relatório consolidado,
  «Exportar dados» e dicionário técnico), etiquetas de acessibilidade e nomes dos ficheiros transferidos.
- Siglas portuguesas em tigrínia: IVA → ግ.ወ.ዋ (ግብሪ ወሰኽ ዋጋ), NIF → መ.ቁ.ግ (መለለዪ ቁጽሪ ግብሪ); símbolos dos níveis de
  acesso pela inicial do verbo em cada língua (PT V/E/G/A · EN V/E/M/A · TI ር/ው/ሕ/ም).
- **Português sem anglicismos**: «depósito SAP» (SAP location), «Classificação» (Ranking), «telemóveis» (smartphones),
  «correio electrónico» / «mensagem» (email), «Apoio administrativo RTM» (Back-office), «numerário» (Cash), «face a» (vs).
  Mantém-se «stock», como no SAP em português de Portugal.
- Pré-visualização do ficheiro para o SAP com os nomes das colunas na língua escolhida (o código do SAP aparece na dica);
  o ficheiro gerado continua igual ao modelo do SAP.
- O registo de auditoria das exportações guarda sempre o nome em português, seja qual for a língua do ecrã.

### Adicionado
- Controlo de qualidade da tradução: `t()` regista os textos pedidos sem tradução; o teste `idiomas.spec.js` percorre
  todas as páginas em tigrínia e em inglês e falha se aparecer algum, e verifica que não há português nas traduções
  inglesas.

## 29-10-2026

### Adicionado
- **Tigrínia (ትግርኛ)** como terceira língua do portal de vendas, ao lado de PT e EN (botão «TI» no topo; a escolha
  fica guardada no navegador). Todos os textos da interface traduzidos (1186), com ortografia da Eritreia e a
  terminologia da tradução tigrínia do MediaWiki; glossário em `docs/GLOSSARIO-TIGRINIA.md`.
- Atributo `lang="ti"` na página (BCP 47), meses e dias da semana em ge'ez (CLDR), números e moeda sem alteração.
- Tipo de letra Noto Sans Ethiopic (licença OFL) servido pelo próprio portal, só para caracteres ge'ez
  (`unicode-range`), sem depender da internet nem do Windows ter o tipo de letra instalado.
- Teste `tests/portal/idiomas.spec.js`: nenhum texto sem tradução, marcadores `{x}` iguais, sem palavras em português ou
  inglês fora de siglas e nomes de sistemas, e todas as páginas sem erros em tigrínia.

## 28-10-2026

### Alterado
- **Sincronização dos smartphones**: uma única consulta à base de dados, a definida pela gestão (última
  sincronização por rota em `SYNC_PHSE_HISTORY`, sem a loja de formação, da mais recente para a mais antiga). A página
  mostra, por esta ordem: Rota, Data última sincronização e Distribuição das horas (barra com as horas desde a última
  sincronização, cor do estado e marcas nos limites). Sai o histórico de 14 dias, que precisava de outra consulta.
- **Botão «Actualizar»**: recarrega o portal (como F5), mantendo a página, o período e os filtros.
- Texto das consultas e parâmetros enviado pelo ODBC em Windows-1252 (comparações com acentos, como «FORMAÇÃO»).
- **Filtros consolidados nas páginas de stock**: deixam de ter filtros próprios (data, armazém, botão «Consultar» e
  barra de pesquisa separada). Usam só os filtros gerais do portal, como as restantes páginas: a data do stock é o fim
  do período escolhido no topo; «Rotas» e «Artigo» no stock por carrinha; «Armazéns» (novo filtro geral) e «Artigo» no
  stock por armazém. Um só pedido à base de dados por data; mudar rotas, armazéns ou artigo não repete a consulta; os
  dados renovam-se ao fim de 5 minutos, como o resto do portal.
- **Cálculo do stock** (`dbo.GET_STOCK`): artigos de cada armazém a partir dos movimentos, das contagens de inventário e
  dos saldos registados; só armazéns reais e registos não apagados; a consulta só usa colunas que existem na base de
  dados (lidas em `INFORMATION_SCHEMA`); data no formato canónico do ODBC.
- O diagnóstico do stock usa exactamente a consulta do portal (desde 01-11-2026 na linha de comandos: `php ferramentas/teste-stock-sql.php AAAAMMDD [armazém]`).

### Corrigido
- Acentos vindos da base de dados pelo driver ODBC (Windows-1252) passam a UTF-8 («Armazém», «SABÃO», «BEBÉ»).
- Quando o cálculo do stock falha, o portal mostra o motivo (tempo excedido, permissão, estrutura, ligação) e
  «Tentar de novo», em vez de mostrar sem aviso dados antigos de um ficheiro.

### Removido
- Cópias antigas sem uso: `stockold*.php`, `vendasold*.php`, `stockold2.php`, `teste_vendas_sqlold.php`,
  `fontesold.js`, `logisticaold.js`, `sapold*.js`. O ficheiro de stock para testes sem base de dados é `reports/data/stock.json`.

## 27-10-2026

### Arquitectura
- **Módulos separados e API central v1 com JWT** (`docs/API.md`): o front-end (`reports/`) fica só com ficheiros
  estáticos e fala com o back-end apenas pela API (`api/index.php/v1/…`, cliente `reports/js/api.js`); os serviços
  passaram de `reports/data/*.php` para o back-end privado (`nucleo/api/v1/`). Cada pedido leva um código de acesso
  JWT (HS256, 15 min, só em memória, ligado à sessão e à versão das credenciais), obtido com a sessão da página de
  entrada e o código CSRF; origem própria sem CORS, rotas e métodos fechados, tipo de conteúdo e tamanho verificados,
  ritmo limitado. Nova chave obrigatória no `.env`: `JWT_SEGREDO`.
- **Fase 4 — optimização:** memória temporária das consultas no servidor (vendas 2 min / 30 min, sincronização 1 min), um só
  pedido à fonte de cada vez, «Actualizar» com dados frescos, ETag/304 e compressão gzip das respostas grandes.

### Segurança
- **Segunda validação (OWASP ASVS 4.0.3 nível 2, NIST SP 800-63B) e verificação automática**:
  - dois passos obrigatórios nos perfis marcados (a pessoa configura-os na entrada, com código QR); códigos de uso
    único também na confirmação das alterações de acessos;
  - palavras-passe: mínimo 12 caracteres, frases aceites, sem regras de composição, recusa das comuns/divulgadas e do
    nome da conta ou da empresa; temporárias expiram em 24 h; página «Mudar a palavra-passe» (botão no início);
  - sessões: mudar ou repor a palavra-passe ou os dois passos termina as outras sessões; cookie próprio
    (`__Host-` em HTTPS); opção `EXIGIR_HTTPS`;
  - gravações das contas, bloqueios e limites em exclusivo (sem alterações perdidas); lista de contas ilegível →
    ninguém entra; pasta privada protegida com `.htaccess`; registos sem o texto escrito em contas inexistentes;
  - CSRF no registo das exportações; código próprio na ligação «Terminar sessão»; ligação cifrada ao SQL Server;
    domínios de correio electrónico autorizados; CSV do GEO sem fórmulas; dependência `uuid` corrigida; `geo/csv.html` retirado;
  - verificação automática: fluxo «Segurança» (gitleaks, npm audit, OSV-Scanner, Semgrep, CodeQL, OWASP ZAP) em cada
    envio e às segundas-feiras, com _issue_ automático se falhar; Dependabot com integração automática das correcções.
- **Auditoria de segurança do PHP** (OWASP ASVS 4.0 nível 2; guia em `docs/SEGURANCA.md`):
  - configuração: sem valores de ligação predefinidos no código (Pinguim BO, base de dados, endereço do portal);
    chave do Google Maps no `.env` (`GOOGLE_MAPS_API_KEY`); `geo/api.txt` retirado; chaves obrigatórias validadas;
  - ficheiros públicos: `.htaccess` recusa `.env*`, `.git`, ficheiros de dados (CSV/Excel/SQL), cópias, registos,
    documentação, configurações e as pastas internas; cabeçalhos de segurança (nosniff, frame, referrer, permissions,
    HSTS em HTTPS, CSP); verificação num Apache real (`tests/apache/verificar.sh`, também no GitHub);
  - entrada: código CSRF no formulário, limite de falhas por IP (força bruta), mesma resposta e mesmo tempo para contas
    inexistentes, códigos de dois passos de uso único; saída protegida contra CSRF;
  - sessão: cookie `Secure` em HTTPS, modo estrito e só cookies; novo código CSRF depois de entrar;
  - erros sem detalhes técnicos nem endereços internos (excepto SADM/TI ou `APP_DEBUG=1`);
  - pedidos: datas reais e período máximo de 400 dias; XML sem acesso à rede; TLS do Pinguim BO verificado por omissão;
  - GEO: escape dos nomes de clientes, rotas e zonas (XSS); bibliotecas locais com versão fixa (lucide 1.62.0, PapaParse 5.3.0).

### Adicionado
- **Exportação para Excel em todos os menus**: a matriz passa a ter nível de exportação também na Sincronização dos
  smartphones (DIR, COM, LOG, AUD; SUP só as suas rotas), no Plano de cargas (DIR, COM, AUD) e em Acessos (TI e AUD,
  para a revisão periódica de acessos); o Excel da sincronização inclui o histórico de 14 dias; numa página sem dados o
  botão fica inactivo com a explicação.
- **Comunicação dos smartphones no menu lateral**: um ponto por smartphone (verde em dia ≤ 2 h, âmbar ≤ 24 h, vermelho
  > 24 h, anel vermelho sem registo), resumo «n/N em dia» e detalhe na dica; actualiza a cada minuto e abre a página
  de sincronização. Só para quem tem acesso a essa página.
- **Indicadores da página principal** com variação face ao período comparável (▲/▼ %, azul favorável, vermelho desfavorável;
  Hoje → ontem até à mesma hora, N dias → N dias anteriores, Mês → mesmo intervalo do mês anterior) e mini-linha da tendência.
- **Vendas por artigo**: preço unitário médio sem IVA (valor s/ IVA ÷ quantidade, já com descontos) e indicador «Valor s/ IVA».
- **Página principal num só ecrã** (computador, a partir de 1366 × 768): indicadores compactos e grelha de 4 × 2 gráficos
  à altura disponível, sem deslocação; cada gráfico mostra as maiores parcelas e junta o resto em «Outros (n)».
- Ao actualizar (F5) o portal fica na mesma página e relatório; depois de entrar de novo (sessão terminada) volta à página onde estava.
- **SAP STORAGELOCATION automática** pela origem do stock: armazéns principais 1150 (FOOD) → RTM1 e 1151 (NON FOOD) → RTM2;
  cada linha leva a localização do armazém que abasteceu a viatura (transferências do Pinguim BO ou saldos de stock).
  Com os dados de 20 a 24-10-2026, todas as rotas → RTM2.
- **SAP location por omissão** (RTM2, configurável) para viaturas sem abastecimento registado: as vendas seguem para o SAP,
  assinaladas na pré-visualização, nos avisos e no registo do lote; «N/A» é recusado por não ser válido no SAP.
- **Logística › Registo de cargas**: cargas expedidas a partir dos planos publicados — por rota e viatura
  (matrícula, tipo de viatura, motorista, transportadora, caixas por artigo, zonas), por dia e por viatura.
  Recurso `registo-cargas` na matriz de acessos.
- **Plano de cargas**: *Exportar Excel* a qualquer momento (rascunho marcado «RASCUNHO — NÃO PUBLICADO»,
  ou versão publicada oficial) e *Enviar por correio electrónico* a versão publicada aos destinatários escolhidos da lista.
- **Exportar dados** (barra de topo): todos os dados de gestão do negócio desde o arranque do Pinguim BO,
  em tabelas do Excel com dicionário de dados (`RTM - Dados de Gestão-DD-MM-AAAA.xlsx`, confidencial).
  Recurso `exportar-dados` (DIR, FIN, AUD; SADM).
- **Dicionário técnico da base de dados** (Administração › Acessos): tabelas, colunas, chaves, relações e
  índices do Pinguim BO, só com SELECT ao catálogo. Recurso `estrutura-dados` (TI, AUD; SADM).

### Alterado
- Fontes de dados: com ligação parcial a bolinha alterna entre verde e âmbar (sem ligação: vermelho a pulsar); o detalhe
  das fontes fecha sozinho ao fim de 8 s (e com Esc ou clique fora). As fontes de dados mantêm-se.
- Início: carregar em «Início» fecha todas as áreas do menu e volta ao topo (estado inicial).
- «Ficheiro para o SAP» passa a **Integrações › Vendas para o SAP** (interfaces separadas da análise de recebimentos).
- Menu lateral com estados claros: página aberta em pílula azul com ícone branco e barra indicadora; área da página com ícone azul; restantes neutros.
- «Exportar tudo» passa a **Relatório consolidado** (nome do relatório que gera).
- Plano de cargas: publicar fixa a versão oficial; o envio por correio electrónico é opcional; o servidor recusa
  destinatários fora da lista e regista cada envio. Dia do plano sempre em dd-mm-aaaa.
- «Stock por promotor / carrinha» mostra só stock (as cargas passaram ao Registo de cargas).
- Barra de topo numa só linha em portáteis; datas nunca partem a linha nas tabelas.
- Carteira de clientes: primeiro **Clientes** (registo, agora com coluna GPS) e depois **Carteira por rota** (análise).

## 26-10-2026

### Adicionado
- **Acessos**: activação da gestão de contas no portal (passagem da lista antiga com correio electrónico, perfil e rotas;
  quem activa fica super administrador, com duas aprovações); criação de contas e de perfis; matriz dinâmica.
- **Terreno › Sincronização dos smartphones**: estado por limites (em dia ≤ 2 h, atenção ≤ 24 h, crítico),
  última sincronização, contagens de hoje e 7 dias, histórico de 14 dias; actualização a cada minuto.
- Ficheiro para o SAP com controlo de ficheiros emitidos: numeração sequencial, emissão única, SHA-256,
  anulação com motivo por outra pessoa, segunda via só para TI/AUD/SADM.
- Excel completo com o nome «RTM - Controlo de Gestão Consolidado-DD-MM-AAAA».
- Projecto novo: UI/UX do portal, módulos em falta e ligação directa à base de dados **só de leitura** (SELECT).

### Alterado
- Back-end organizado em camadas (`nucleo/`), configuração lida num único sítio (`.env`).
- «Método de pagamento» passa a **Cobranças / Meio de cobrança / Taxa de cobrança**.
- Menus laterais em acordeão (um aberto de cada vez); filtros de todas as páginas com o mesmo estilo visual.
- O portal abre em «Hoje» e actualiza sozinho a cada 5 minutos; stock só com quantidades diferentes de zero.
- Plano de cargas produzido na plataforma (sem importação do Excel); indicador de fontes só com o Pinguim BO.
- Gráficos de barras desenhados à largura real, rótulos alinhados à esquerda, sem verde em títulos e etiquetas;
  campos De/Até alinhados.
