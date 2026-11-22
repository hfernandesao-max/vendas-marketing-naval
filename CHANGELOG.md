## 23-11-2026

### Perdas e tempo parado, calendário editável e navegação por ligações

<!-- tipo: funcionalidade -->

- **Perdas e tempo parado** (Inteligência de negócio), por rota e no período (até 31 dias), tudo das bases de dados:
  - perdas efectivas: faltas na carrinha (conciliação), falta de dinheiro na entrega (declarado na app × contado) e custo do tempo parado sem vender (OLAP × `PERDAS_CUSTO_HORA`);
  - à parte, as decisões comerciais do Pinguim BO: notas de crédito, descontos e documentos anulados;
  - os números do topo levam ao detalhe, e as rotas abrem a ficha da rota.
- **Ficha da rota com o desempenho do vendedor:** perdas, faltas, entrega do dinheiro, tempo parado e custo, quilómetros, notas de crédito, descontos e anulados.
- **Calendário de trabalho** (Administração): horário, feriados de Angola e próximos dias fora do normal. A Direcção define folgas da empresa e dias de trabalho excepcionais, com motivo e registo, e todo o portal passa a usá-los. Na véspera de um feriado ou folga, às 17:30, vendedores e Logística são avisados no telemóvel.
- **Pesquisa global (Ctrl+K):** além das funções, procura documentos, clientes, artigos, rotas e números de PO do período, e abre a ficha certa.
- **Fichas com rasto, «Voltar» e «Copiar ligação»:** a ligação abre o portal com a mesma ficha, com as permissões de quem a abre.
- **Números e gráficos clicáveis:**
  - na página inicial, a barra do cliente abre a ficha do cliente e a do artigo a ficha do artigo (a do promotor continua a abrir os documentos dessa rota);
  - os quadros das perdas levam às tabelas respectivas;
  - as «Faltas» da conciliação levam às perdas por rota.

### Domingo e feriados como dias de descanso

<!-- tipo: correcao -->

- Num domingo, feriado ou folga da empresa, a conciliação já não pede para contar as carrinhas que não tiveram movimento nesse dia. Ficam como no último dia de trabalho e não aparece o aviso «telemóvel por sincronizar».
- Sem lembrete das 19:30 nos dias de descanso, e sem proposta de plano de cargas para um dia de descanso.
- O resumo diário não sai nos dias de descanso. À segunda-feira (ou depois de um feriado) traz o último dia de trabalho, por exemplo o sábado, em vez de um domingo sem vendas.

### Ligações entre todas as tabelas (drill-down)

<!-- tipo: funcionalidade -->

- Em qualquer tabela do portal, o documento, o cliente, o artigo e a rota (ou o promotor) abrem a ficha respectiva.
- Ficha do artigo, no período: quantidade, valor, documentos, clientes e preço médio; vendas por rota; principais clientes; últimos documentos.
- Ficha da rota, no período: vendas, documentos, clientes, dias com vendas e média por dia; principais clientes e artigos; últimos documentos; atalhos à conciliação da carrinha e às requisições.
- No painel do documento, o cliente, a rota e cada artigo também abrem a ficha. Dentro de cada ficha tudo volta a ser clicável: da venda ao documento, do documento ao artigo, do artigo aos clientes e à rota.

## 22-11-2026

### App «Naval Operações»: entrega do dinheiro, contagem da carrinha e carga pelo QR

<!-- tipo: funcionalidade -->

Nova app no telemóvel, instalada pelo código QR pessoal do portal como a «Naval Gestão» (mesma entrada e mesmo registo do equipamento). Cada pessoa vê só as suas tarefas:

- **Entregar dinheiro (vendedor):** o vendedor regista as notas e moedas que entrega, com o vendido em dinheiro no Pinguim BO ao lado. Vê logo se bate («Igual ao vendido em dinheiro») ou quanto falta, e envia à tesouraria.
  - A tesouraria recebe uma notificação e um pendente «contar o dinheiro entregue».
  - Na folha de caixa, a conferência passa a ter a coluna «Declarado pelo vendedor», ao lado do que foi contado e do que está no Pinguim BO. A contagem da tesouraria continua às cegas.
  - O vendedor pode corrigir até a tesouraria contar.
- **Contar a carrinha (Logística):** inventário às cegas linha a linha, com o lançamento do portal (linha activa, Enter para a seguinte, visto, valores fora do normal). A seguir, a pessoa que contou valida a sua própria contagem.
- **Carregar PO (armazém de carga):** lê o QR impresso na PO com a câmara, ou escreve-se o número, e mostra os artigos a carregar. A conferência é linha a linha, com a diferença em tempo real e o motivo obrigatório em cada diferença.
- Recurso novo na matriz: «caixa-entrega» (G: o vendedor, nas suas rotas).
- Quem tem a app de gestão continua a receber a «Naval Gestão». Os outros perfis com alguma destas tarefas recebem a «Naval Operações».

### Robô do Pinguim BO na criação das tarefas do servidor

<!-- tipo: melhoria -->

- `php ferramentas/actualizar.php tarefa` passa a criar também a tarefa «Portal RTM - Robo Pinguim» (contínua, iniciada no arranque do servidor). Só a cria quando o robô está configurado: chave, modo `robo`, receita dos ecrãs e Node.js. Enquanto falta alguma coisa, diz o que falta.

### PO aprovada sozinha dentro das regras e eventos entre módulos

<!-- tipo: funcionalidade -->

- A PO aprova-se sozinha ao ser emitida quando cumpre três regras:
  - repõe só o que foi vendido, sem artigos acrescentados nem quantidades acima do vendido;
  - vem de uma conciliação validada sem diferenças, ou já aprovada;
  - o stock do armazém está confirmado.

  Fica registada como «aprovada automaticamente». O coordenador só recebe as outras, e a PO mostra o motivo («Para aprovar porque: …»). `REQ_APROVACAO_AUTOMATICA=0` no .env desliga.
- Os módulos passam a publicar de facto os eventos que declaram, e cada evento fica registado:
  - «carrinha.diferenca_detectada»
  - «carrinha.descarga_conciliada»
  - «caixa.aprovada»
  - «relatorio.vendas_gerado»
- Ao fechar a última carrinha ou ao aprovar a caixa, o Relatório de Vendas vê-se logo, sem esperar pelo quarto de hora seguinte.

### Pendentes com dono, prazo e escalamento automático

<!-- tipo: funcionalidade -->

- «À sua espera» passa a mostrar também:
  - as PO aprovadas por conferir no cais (Fiel de Armazém);
  - as cargas conferidas por validar (Back-Office);
  - as guias de remessa por tratar no Pinguim BO (a gerar à mão, recusadas ou diferentes do pedido).
- **Resumo das 07:30:** nos dias de trabalho, cada pessoa recebe no telemóvel e por correio o que está à sua espera, os atrasados primeiro.
- **Escalamento:** às 10:00 e às 16:30, o que está atrasado sobe à Direcção (linha «pendentes-escalamento» da matriz), com o nome de quem o tem para fazer.
- As cargas conferidas e as guias ficam atrasadas ao fim de 2 horas. As horas e o envio por correio configuram-se no .env (`PENDENTES_*`).

### Relatório de Vendas gerado sozinho

<!-- tipo: funcionalidade -->

- O consolidado diário gera-se e envia-se sozinho, como «sistema», logo que todas as fontes do dia ficam completas: telemóveis sincronizados, carrinhas fechadas e vendas confirmadas no SAP. Ninguém precisa de carregar em «Gerar».
- Vê hoje (depois do fecho das vendas) e os 3 dias anteriores ainda sem relatório. Os dias sem vendas não geram relatório.
- Uma nova versão (correcção) continua a ser pedida por uma pessoa, com motivo. `RELATORIO_AUTOMATICO=0` no .env desliga a geração automática.

### Notificações e cópias de segurança: auto-reparação e aviso

<!-- tipo: correcao -->

- Quando o telemóvel renova sozinho a subscrição das notificações, a app volta a registá-la no servidor ao abrir. Antes, os avisos podiam deixar de chegar sem ninguém saber.
- Enquanto a cópia de segurança diária ficar só neste servidor (sem pasta externa configurada), a verificação de conformidade avisa todas as segundas-feiras.

### Lançamento linha a linha igual em todo o portal

<!-- tipo: funcionalidade -->

Aplica-se à contagem da carrinha, à conferência da carga da PO, à confirmação da carga na Logística e às correcções dos TPA Multicaixa.

- **Linha activa:** a linha em que se escreve fica destacada. **Enter** passa ao campo seguinte e, no fim, ao botão de gravar; **Ctrl+Enter** grava de qualquer linha. O número já escrito fica seleccionado.
- **Visto e progresso:** as linhas preenchidas levam um visto verde. O contador «N de M preenchidos» tem um botão «Ir ao primeiro em falta».
- **Diferença em tempo real:** na conferência da PO e na confirmação da carga aparece ✓ verde quando bate com o pedido; se não bate, aparece a diferença a vermelho (+3, −5).
- **Valores fora do normal:** casas decimais num artigo à unidade, mais do dobro do pedido ou números de 10 000 ou mais ficam a âmbar, com a explicação. Ao gravar, o portal pede para corrigir ou gravar assim mesmo.
- **Rascunho:** a conferência da PO e a confirmação da carga guardam neste navegador o que mudou e repõem-no ao reabrir.
- **Aviso ao sair:** mudar de página no portal com dados por gravar pergunta antes de sair («Sair sem gravar?»).
- **Procurar:** nas listas com 8 ou mais linhas há uma caixa «Procurar artigo (código ou nome)».
- **Telemóvel:** o cabeçalho da tabela fica fixo ao descer e o botão de gravar fica sempre visível no fundo do ecrã.
- **Janelas:** em todas, Ctrl+Enter carrega no botão principal (Esc já fechava).

## 21-11-2026

### Contagem da carrinha: linha activa destacada e visto nas já contadas

<!-- tipo: melhoria -->

- A linha em que se está a escrever fica destacada a azul. **Enter** passa à linha seguinte, como o Tab, e na última vai para «Gravar contagem». O número já escrito fica seleccionado, para se poder corrigir logo.
- Cada artigo já contado leva um visto verde à direita (o 0 também conta). Fica fácil ver o que falta.

### Conciliação: aviso automático de telemóvel sem sincronizar e de rota sem facturas

<!-- tipo: funcionalidade -->

- Cada rota em aberto mostra, no cartão e no detalhe, porque é que o Pinguim BO pode não ter as vendas do dia:
  - **«O telemóvel desta rota não sincroniza com o Pinguim BO desde …»**, quando o telemóvel não sincronizou na última hora antes da referência da contagem. As vendas podem estar só no telemóvel; quando sincronizar, a conciliação actualiza-se sozinha.
  - **«Esta rota não tem facturas no Pinguim BO desde …»**, quando a rota tem carga mas nenhuma factura há mais de 2 dias e o telemóvel sincroniza. Pede para confirmar com o promotor se está a emitir as facturas no Winrest.
- A última carga, venda e sincronização de cada rota é lida uma só vez de 5 em 5 minutos e partilhada com o alerta de vendas por sincronizar, para não pesar no Pinguim BO.

### Conciliação: uma só cor por estado

<!-- tipo: melhoria -->

- Cada carrinha tem uma só cor, a do passo em que está, igual na etiqueta e no risco do cartão: cinzento por contar, âmbar por validar, laranja por aprovar, azul fechada. As diferenças e a falta em Kz aparecem a vermelho no texto, e «Fora do prazo do fecho» continua a ser a única etiqueta vermelha.
- Uma conciliação contada e validada sem nada por aprovar (sem diferenças nem alertas, mesmo sem artigos) fecha sozinha na revisão automática.

### Conciliação: devoluções do fecho, fecho automático e diagnóstico do que fica por explicar

<!-- tipo: melhoria -->

- Devoluções ao armazém lançadas na janela do fecho (a partir das 15:30, calendário) contam como depois da contagem: conta-se a carrinha e só depois se lança a devolução (ex.: rota 1007, 18-11, SA 90 às 15:36).
- Uma conciliação já validada que fica sem diferenças por explicar fecha sozinha na revisão automática.
- Diagnóstico automático, com o documento a corrigir, das diferenças que ficam: sobra igual a uma guia anulada nesse dia (a mercadoria foi carregada) e falta igual a uma guia da tarde (provável devolução lançada como carga, com o sentido trocado).

### Conciliação: regularização documentada pelos acertos da Logística e alerta de vendas por sincronizar

<!-- tipo: funcionalidade -->

- Uma diferença da contagem que um acerto lançado depois no Pinguim BO corrige (saída de armazém, devolução ou inventário na mesma carrinha e artigo) fica «Regularizada no Pinguim BO», com o número do documento, e não conta como falta; o mesmo acerto explica a mesma diferença em dias seguidos. Sem diferenças por explicar, a conciliação validada fecha sozinha. Ex.: rota 1008 (SA A1000/118) e rota 1011 (SA A1000/119).
- Venda facturada depois da contagem (ex.: impressora avariada, factura no dia seguinte): numa falta, se a carrinha vendeu depois da contagem, antes de nova carga do artigo, mais do que tinha contado, esse excesso saiu antes da contagem e fica regularizado pela factura (ex.: rota 1008, 17-11, FR 4 de 18-11). O que podia ter saído do que foi contado não conta, para não esconder faltas reais.
- Vendas por sincronizar: uma rota carregada, sem vendas no Pinguim BO desde a carga e com o telemóvel sem sincronizar há mais de 6 horas aparece na Vigia e avisa por notificação quem acompanha a rota (SYNC_ALERTA_HORAS).
- Contagens escritas no portal noutro dia referem-se ao corte do dia da conciliação (a contagem física é a do fim desse dia).

### Conciliação: o esperado é o do momento da contagem; fontes de dados logo ao entrar

<!-- tipo: correcao -->

- A contagem da carrinha compara com o stock do momento em que se contou (no máximo a hora do corte), e não com o da hora em que a folha foi impressa: entre a folha e a contagem a carrinha continua a vender (ex.: rota 1004, folha às 13:29, venda às 13:57, contagem às 16:12). A revisão automática usa a mesma regra e repõe sozinha as conciliações afectadas.
- Carga do dia seguinte, sem decisão humana: uma guia de transporte para a carrinha registada à tarde e depois da última venda da rota nesse dia é a carga de amanhã (a carrinha já acabou de vender); não entra no que tinha de estar na carrinha à contagem e passa ao inicial do dia seguinte, mesmo lançada antes da hora do corte. Confirmado com os dados do Pinguim BO de 17 a 20-11.
- O indicador das fontes verifica o Pinguim BO, o stock e o OLAP logo ao entrar no portal (antes, só ao fim de 5 minutos: o OLAP não aparecia e o stock ficava «A verificar…»).

### Conciliação: «Actualizar bases históricas em aberto» com a regra certa

<!-- tipo: correcao -->

- A actualização em bloco das bases históricas passa a usar a mesma regra da revisão automática: volta a congelar no instante da folha de contagem (nunca à hora do corte) e só mexe nas conciliações que mudaram mesmo no Pinguim BO; as iguais ficam como estão, sem perder validações.
- O diálogo fecha sozinho no fim e mostra o resultado; só fica aberto, com o detalhe, se houver falhas.

## 20-11-2026

### Sala de resultados: Winrest sincroniza sozinho ao entrar na sala

<!-- tipo: funcionalidade -->

- Nova app «Naval Sala» para os Blackview (ferramentas/android-sala), gerida pelo ManageEngine MDM: detecta a entrada na sala do LCD pela rede Wi-Fi própria da sala (ponto de acesso e sinal, com histerese e espera entre disparos) e põe o Winrest em primeiro plano para sincronizar, sem toques.
- O portal recebe a presença dos telemóveis (v1/sala/presenca, com chave própria) e, opcionalmente, pede ao MDM o quiosque do Winrest durante 3 minutos e retira-o sozinho.
- Guia completo em docs/SALA-SINCRONIZACAO.md: arquitectura sem USB no LCD, configuração no ManageEngine, o que o Android permite e plano de teste.

### PDF da PO sem a coluna «Vendido»

<!-- tipo: melhoria -->

- A PO é um pedido ao armazém e não uma venda: o PDF passa a ter só «Requisitado» (e «Entregue» / «Carregado»), sem a coluna «Vendido».

### Tempo real na app, fontes de dados certas, consolidado sem esperar pela caixa e PO aprovadas pelo coordenador

<!-- tipo: melhoria -->

- App de gestão em tempo real: de 20 em 20 segundos pergunta ao servidor se algo mudou (documentos novos ou anulados no Pinguim BO, PO, conciliação, caixa) e só então relê os números; leve para o servidor e para os dados móveis.
- As PO por aprovar aparecem em «À sua espera» na app de quem aprova, e o coordenador recebe uma notificação no telemóvel quando um promotor emite uma PO.
- A aprovação das PO para a carga passa para o coordenador (Nathan Alemayehu, nova função «Aprovar as PO para a carga»), com a Direcção como alternativa.
- Relatório de Vendas: o fecho de caixa validado e aprovado deixa de ser obrigatório para gerar o consolidado do dia (aparece como «Não obrigatório», com o estado da caixa).
- Indicador das fontes: o «Stock de artigos» mostra o estado real (o servidor verifica a função do stock no Pinguim BO e guarda a última leitura), em vez de «sem dados» sempre que a página aberta não leu o stock; com as vendas lidas directamente do Pinguim BO, o OLAP aparece como «localização das visitas», com a hora da última leitura.

### PO: aprovação antes da carga, fecho das PO em aberto e requisitado × carregado

<!-- tipo: funcionalidade -->

- As PO só ficam disponíveis para a carga no cais depois de aprovadas pelo supervisor da rota (ou Comercial / Direcção), que não as pode ter criado nem emitido. Em Logística › Requisições ficam «Por aprovar» até lá, com o botão «Aprovar para a carga»; no cais, uma PO por aprovar não se confere. Vale para as PO emitidas a partir de 21-11-2026.
- As PO emitidas que não vão ser carregadas fecham-se com motivo («Fechar a PO»); as esquecidas fecham-se sozinhas ao fim de 3 dias e os rascunhos antigos anulam-se, para nada ficar aberto para sempre.
- No documento da PO, as colunas passam a «Requisitado» e «Carregado»: quando o carregado é igual ao pedido aparece um visto verde; quando não, a diferença a vermelho (com o motivo ao passar o rato). Na edição, o vendido no dia aparece por baixo do requisitado.
- Conciliação da carrinha: ao validar a contagem, o painel da rota fecha e o portal pergunta se quer imprimir a contagem e mostra a PO de reposição criada sozinha, com o botão para a abrir.

### Conciliação das carrinhas: a revisão respeita o instante da folha de contagem

<!-- tipo: correcao -->

- A revisão automática contra o Pinguim BO voltava a congelar a base à hora do corte, em vez do instante em que a folha de contagem foi impressa: uma guia para o dia seguinte lançada entre a folha e o corte (ex.: rota 1004, GTC A1000/122 às 15:42) passava a contar como se estivesse na carrinha. Passa a usar sempre o instante original; as conciliações afectadas são repostas sozinhas na revisão seguinte.

### Conciliação das carrinhas igual ao stock do Pinguim BO, também quando um documento é anulado depois

<!-- tipo: correcao -->

- As guias e saídas de armazém anuladas no Pinguim BO deixam de contar como carga ou devolução (o stock do Pinguim BO já não as contava): nos dias 16 e 17-11 havia 30 linhas com o carregado a mais.
- Os movimentos de cada carrinha passam a contar pelo armazém da linha, o mesmo do stock, e não pela loja do documento.
- Quando um documento é anulado ou corrigido no Pinguim BO depois de a base da conciliação ter sido congelada, a conciliação é revista sozinha (de hora a hora os últimos 3 dias, de madrugada os últimos 45): se estiver aberta, a base é actualizada, a contagem fica e volta a pedir a validação; se já estiver aprovada, não se altera e fica assinalada para revisão.
- Nova ferramenta para rever os dias passados (php ferramentas/carrinha-rever-pinguim.php, em ensaio; --aplicar para aplicar).
- Os inventários lançados no Pinguim BO nas carrinhas entram na conciliação, numa coluna própria «Inventário» (contado − teórico, com o número do inventário), no dia em que passam a contar no stock do Pinguim BO (o dia seguinte ao do inventário).
- Prova diária: todas as noites, por carrinha e artigo, inicial + carregado − devolvido − vendido ± inventário tem de dar o stock final do Pinguim BO. O que não bate abre um problema na Vigia, avisa por e-mail e bloqueia a aprovação dessa conciliação até ser explicado (CARRINHA_PROVA_BLOQUEIA=0 desliga o bloqueio).
- A ferramenta php ferramentas/carrinha-rever-pinguim.php faz a prova de todos os dias desde 01-01-2026 antes de rever as conciliações.

### Cargas (PO): o documento mostra o pedido e o carregado

<!-- tipo: melhoria -->

- Depois da conferência no cais, o PDF da PO mostra lado a lado o que foi requisitado e o que foi carregado, com a diferença e o motivo de cada linha, os totais, quem conferiu, quem validou e o número da guia no Pinguim BO.
- Logística › Cargas (PO): botão «PDF» em cada carga por validar e «PDF: pedido e carregado» ao rever a carga.

### Equipamentos: base de dados do módulo em dois passos

<!-- tipo: melhoria -->

- Novo script para a Informática criar a base de dados própria do módulo Equipamentos no SQL Server do OLAP e a conta do portal, só com acesso a essa base de dados (ferramentas/sql/equipamentos-criar-bd.sql).
- Nova ferramenta no servidor (php ferramentas/configurar-equipamentos.php): liga o portal a essa base de dados, cria as tabelas se ainda não existirem, grava a configuração e verifica o módulo e o cruzamento com o MDM. Recusa as bases de dados do Pinguim BO e do OLAP.

### Mapas mais rápidos, zoom só no mapa e local exacto das vendas

<!-- tipo: melhoria -->

- Nos mapas, a roda do rato e os dedos aproximam e afastam só o mapa, nunca a página inteira.
- Os clientes e os potenciais clientes desenham-se todos numa só camada, em vez de um marcador por ponto: milhares de pontos sem o mapa abrandar, também ao arrastar e aproximar. O Google Maps carrega mais cedo.
- Percurso do dia: tocar numa venda (no mapa ou na lista) aproxima até ao local exacto onde foi registada, com a vista de satélite disponível, a precisão do GPS, as coordenadas, a linha até ao ponto registado do cliente e a ligação ao Google Maps.
- Conformidade: o aviso do certificado das bases de dados diz porque falhou a validação e o que fazer. Nova ferramenta para a Informática (php ferramentas/configurar-conformidade.php) que gera a chave das cópias, confirma as pastas externas e guarda o nome do certificado do SQL Server quando a ligação é pelo IP.
- MDM Plus: o conector também lê a localização dos telemóveis (só leitura), confirmada na verificação da ligação.

### Cargas (PO): conferência no cais, validação do Back-Office e Guia de Remessa ordenada ao Pinguim BO

<!-- tipo: funcionalidade -->

- As requisições ao armazém passam a chamar-se «Requisição Interna / PO», com o número PO-AAAA-NNNNNN e um QR no PDF. As RQ antigas continuam a abrir.
- Nova página Logística › Cargas (PO). O Fiel de Armazém lê o QR (ou escreve o número), põe o visto em cada linha, indica o que carregou e o motivo de cada diferença. Nesta fase não há guia nem transferência: a carga fica reservada no armazém principal.
- O Back-Office valida com um clique (ou todas as sem diferenças de uma vez). Quem emitiu ou conferiu a carga não a valida.
- Ao validar, o portal dá a ordem da Guia de Remessa ao Pinguim BO. A guia é sempre criada no Pinguim BO, que a numera e assina; o portal encontra-a sozinho no Pinguim BO, confere-a com a carga e fecha o ciclo. Enquanto o robô do portal não estiver ligado aos ecrãs do Pinguim BO, o Back-Office cria a guia com os dados que o portal mostra e escreve o número.
- A confirmação da carga na página do Stock também pede o motivo de cada diferença.
- O armazém da carrinha confirma-se sozinho quando é o único e tem o código 100010NN da própria rota; os casos duvidosos continuam a pedir a confirmação de SADM/TI.

### Zonas de entrega e Distribuidores automáticos; mapas mais claros

<!-- tipo: melhoria -->

- Zonas de entrega: deixa de haver zonas desenhadas à mão. Cada rota tem a sua geofence, calculada sozinha todas as noites a partir dos seus clientes, com o código da rota no mapa. Os clientes fora da geofence da sua rota aparecem com contorno vermelho e contam à parte (coordenadas ou rota a confirmar).
- Distribuidores: a zona de cada distribuidor (cliente com a tipologia «Distribuidor») monta-se sozinha com os seus clientes ou, sem essa indicação, com os mais próximos, e mostra a distância média.
- Uma cor fixa por rota, a mesma em todas as páginas, e bem diferente das outras. A legenda e os números passam para a barra lateral: o mapa fica livre. Tocar numa rota mostra-a sozinha.
- Exportações automáticas: todos os clientes, dentro e fora das geofences, resumo por rota ou distribuidor (CSV para o Excel) e as zonas em GeoJSON (Google Earth, QGIS, My Maps).
- Prospecção: a geofence de cada rota cobre a rota toda (só ficam de fora os erros de GPS); o «Recalcular agora» responde logo e diz o que fez; o OpenStreetMap é lido sozinho em segundo plano, por partes, e o rodapé mostra quanto já leu e se falhou. As janelas do mapa voltam a ter o texto legível e os clientes no mesmo ponto mostram quantos são.

## 19-11-2026

### Prospecção: descoberta automática em Luanda e Ícolo e Bengo, ICP Score e Radar do dia

<!-- tipo: funcionalidade -->

- A descoberta de potenciais clientes cobre toda a área de Luanda e Ícolo e Bengo (já não só à volta dos clientes) e procura o que compra higiene e limpeza e alimentar: supermercados, mercearias, cantinas, armazéns e cash-and-carry, padarias, restaurantes, hotéis, cantinas e refeitórios, drogarias, escolas e clínicas. O nome também conta («Armazém…», «Comércio geral», «Panificadora…»).
- Cada potencial cliente tem um ICP Score de 0 a 100 (adequação ao portfólio, volume do tipo, contacto, fontes e distância aos clientes da rota) e a afinidade: higiene e limpeza, alimentar ou as duas.
- Fontes externas (n8n, Make, Apify, Scrapfly, Clay) podem enviar listas de estabelecimentos por uma entrada própria protegida por chave; passam as mesmas regras de qualidade e de duplicados e entram no cálculo 10 minutos depois. Contrato e plano em docs/DESCOBERTA.md.
- O mesmo estabelecimento com o mesmo telefone ou sítio em fontes diferentes conta uma só vez.
- Novo separador «Radar do dia» na Prospecção: para cada rota, os lugares livres do dia (30 visitas por vendedor) e o que lhes juntar perto do caminho — recuperar clientes regulares parados e conquistar potenciais clientes do território. Quando o plano passa a capacidade, mostra as visitas em dia de menor valor a adiar.

### Correcções: Prospecção, Percurso do dia e Distribuidores sem dados

<!-- tipo: correcao -->

- A Prospecção mostrava 0 clientes e 0 potenciais quando a leitura dos clientes falhava no momento do cálculo: o cálculo vazio ficava gravado por cima do anterior. Agora mantém-se o último cálculo bom, com o aviso. O botão «Recalcular agora» também lê o OpenStreetMap quando ainda não há leitura.
- O Percurso do dia mostrava as vendas com o código do cliente e «Cliente sem coordenadas» por causa desse cálculo vazio; passa a usar os clientes lidos no dia quando falta o cálculo. A etiqueta já não sai do cartão com nomes longos.
- Os clientes sem coordenadas no Pinguim BO usam o GPS do OLAP, quando o tem (Optimização de rotas, Zonas, Prospecção).
- Distribuidores: os clientes não carregavam (Total: 0) por um erro no arranque da página.

### Terreno: menu por tarefa, contadores do que pede acção e nova página Ocorrências

<!-- tipo: funcionalidade -->

- O menu do Terreno passa a estar organizado pela tarefa, com rótulos que não abrem nem fecham: Operação do dia (Visitas do dia, Percurso do dia, Ocorrências), Planeamento (Plano de visitas, Optimização de rotas, Rotas propostas), Território e clientes (Prospecção de clientes, Zonas de entrega, Distribuidores) e Análise (Conversão das visitas).
- O menu mostra quantas rotas propostas esperam decisão e quantas ocorrências estão por tratar, também no título da área Terreno quando está fechada.
- Nova página Ocorrências: numa só lista, o que pede acção no terreno — vendas longe do cliente, paragens longas sem vender, percurso fora do território, visitas do plano não feitas (depois das 18:00), clientes regulares sem comprar, clientes sem GPS ou no mesmo sítio e coordenadas a corrigir. Cada ocorrência leva ao mapa ou à página do pormenor e trata-se como os alertas: assumir, resolver com nota, silenciar até uma data ou reabrir.
- Tratam as ocorrências a Direcção, o Comercial, a GeoMobilidade e os supervisores nas suas rotas (nova função «ocorrencias» na matriz de acessos); a TI e a Auditoria só vêem.
- As Rotas propostas e as Ocorrências deixam de mostrar o filtro de período, que não se aplica a elas.

### Percurso do dia: vendas no mapa com o cliente, o documento e o valor

<!-- tipo: correcao -->

- No Percurso do dia (Terreno), as vendas lidas do OLAP passam a identificar o cliente pelo mesmo identificador dos clientes do mapa: aparecem com o nome do cliente e com a prova de visita (no cliente ou longe dele). Antes apareciam só com um código e sem prova de visita.
- Cada venda mostra o documento e o valor (com IVA) na lista e no balão do mapa; a lista passa a chamar-se «Vendas do dia», com o total.
- Visitas sem documento no OLAP deixam de contar como vendas.

### Rotas propostas: aprovação de rotas novas no portal

<!-- tipo: funcionalidade -->

- Nova página Operações › Terreno › Rotas propostas. Todas as noites o portal propõe uma rota nova quando uma rota passa 14 dias seguidos acima de 90 % das visitas que consegue fazer (dividir o território, passando a parte menor para a rota nova) ou quando uma zona sem rota tem muitos estabelecimentos. A proposta fica pendente de aprovação.
- Cada proposta mostra o território no mapa, os clientes que passam, as visitas por semana face à capacidade, a ocupação da rota de origem antes e depois, as vendas por semana e a carga por dia de visita face à capacidade das viaturas (quando o Pinguim BO tem o peso dos artigos).
- Decidem a Direcção, o Comercial e a GeoMobilidade (nova função «rotas-propostas» na matriz de acessos; a Logística, a TI e a Auditoria vêem; o supervisor vê as propostas da sua rota). Aprovar exige o código da rota nova; rejeitar exige o motivo.
- Ao aprovar, os clientes passam para a rota nova no portal: visitas do dia de imediato, percursos optimizados a partir da noite seguinte, prospecção e territórios. Um cliente que já esteja noutra rota nova não é atribuído duas vezes. Sai a lista dos clientes (CSV) para registar a rota no Pinguim BO; a proposta passa a activa quando as vendas já mostram a rota nova.
- Sem coordenadas dos clientes no Pinguim BO nem consulta própria, a GeoMobilidade passa a usar o GPS dos clientes guardado no OLAP.

### Ficha do cliente: vendas e pagamentos encontrados pelo identificador das vendas

<!-- tipo: correcao -->

- Os separadores Vendas e Pagamentos da ficha do cliente passam a encontrar os documentos pelo identificador interno do cliente no Pinguim BO (o mesmo das vendas, lido do OLAP) e, como recurso, pelo NIF. Antes procuravam pelo código da carteira, que as vendas não trazem, e ficavam vazios.

### Ficha do cliente na Carteira de clientes

<!-- tipo: funcionalidade -->

- Em Clientes › Carteira de clientes, o nome do cliente abre a ficha do cliente com cinco separadores: Dados e localização, Vendas, Pagamentos, Visitas e tempos, e Mapa.
- Dados e localização: identificação, tipologia, rota e equipa (vendedor, coordenador, gestor, distribuidor, canal), dias de visita, município e GPS com ligação ao mapa, lidos do OLAP.
- Vendas e Pagamentos: vendas do Pinguim BO no período da barra de filtros, com as regras de todos os ecrãs. Os pagamentos aparecem por modo (Numerário, Multicaixa…) e por tipo (pronto pagamento na factura-recibo ou a crédito na factura), com o pendente.
- Visitas e tempos: histórico dos últimos 180 dias no OLAP, com o promotor, o resultado ou o motivo de não venda, visitas presenciais ou remotas, a distância entre o GPS da visita e o do cliente, o tempo no cliente (da abertura ao fecho da visita, até 4 h) e a deslocação até ao cliente seguinte do mesmo promotor no mesmo dia.
- Mapa: o cliente e o sítio onde cada visita e cada venda foram feitas, com escala, e ligação ao percurso completo do promotor em cada dia.
- O Percurso do dia (Terreno) passa a ler as visitas do OLAP (`dbo.FACT_R1_MAP`), quando não há outra consulta configurada: as paragens mostram o tempo em cada cliente. Também abre num dia e numa rota pedidos no endereço.
- Só se vêem clientes das rotas de cada pessoa. Sem OLAP, a ficha mostra o aviso e continua com os dados da carteira, as vendas e os pagamentos.
- O portal passa a aceitar no `.env` as chaves por servidor (`DB_HOST_PINWINBO`…, `DB_HOST_OLAP`…).

### Stock de corte das carrinhas calculado pelos movimentos horários

<!-- tipo: impacto -->

- O saldo no corte passa a ser reconstruído a partir do stock de abertura e de vendas, cargas e devoluções registadas até à hora operacional; `dbo.GET_STOCK` recebe uma data sem hora e já não é usado como fotografia intradiária.
- O super administrador pode actualizar as bases históricas de todas as conciliações ainda abertas. Quantidades contadas são preservadas; validações e aprovações pendentes são invalidadas para nova conferência. Conciliações fechadas não são alteradas.

### Verificações do GitHub novamente a verde

<!-- tipo: correcao -->

- O teste da folha de contagem das carrinhas passa a usar uma rota própria e a verificar apenas que a carga autorizada entra na folha, sem depender da ordem dos artigos.
- As secções de 18-11-2026 do registo de alterações ficam classificadas, como exige a publicação das versões.
- A verificação das traduções em tigrínia passa a aceitar «JSON» e «Ctrl», como já aceitava «PDF», «Alt» ou «Esc».
- A análise do PHP deixa de assinalar uma protecção redundante na actualização das conciliações históricas (mesmo resultado).
- Formatação do código alinhada com as regras do projecto. Sem alterações ao funcionamento do portal nem ao ficheiro SAP.

## 18-11-2026

### Conciliação das carrinhas: base congelada na primeira folha de contagem

<!-- tipo: impacto -->

- A primeira geração da folha congela no servidor o stock e os movimentos do Pinguim BO; reimpressões e submissão reutilizam a mesma base.
- O PDF regista a hora de referência e a conciliação deixa de ser alterada por fotografias posteriores; movimentos e vendas são apurados até ao instante congelado.
- A variação líquida após o corte deixa de ser apresentada como carga.
- O super administrador pode actualizar em bloco as conciliações históricas ainda abertas. As quantidades contadas são preservadas; validações e aprovações pendentes são invalidadas para nova conferência. Conciliações fechadas não são alteradas.

### Consultar o dicionário técnico sem exportar Excel

<!-- tipo: funcionalidade -->

- Administração › Acessos permite ver e copiar o catálogo das tabelas e relações em JSON, directamente no portal.
- Mantém as permissões existentes; apresenta apenas metadados da estrutura, sem linhas de negócio.

### Total efectivo de vendas: desconto de substituição na factura original

<!-- paginas: inicio, vendas-promotor, vendas-dia, vendas-cliente -->
<!-- tipo: impacto -->

- Quando uma nota de crédito substitui uma factura anterior por outra de menor valor, o total efectivo passa a reflectir o desconto na venda original.
- Os agregados de recebido e Multicaixa reflectem o acerto na venda original, sem alterar os pagamentos brutos dos documentos nem o ficheiro SAP.
- Os valores brutos dos documentos e pagamentos, a lista de documentos e o ficheiro SAP permanecem inalterados.

### Total de vendas com IVA: apenas facturas, facturas-recibo e notas de crédito

<!-- paginas: inicio, documentos -->
<!-- tipo: impacto -->

- O total com IVA deixa de incluir documentos do Pinguim BO que não sejam vendas (FR, FT ou NC); as notas de crédito continuam a abater ao total.
- A cache das vendas é renovada para aplicar imediatamente o filtro corrigido.

### Sincronização do Pinguim BO de 30 em 30 segundos

<!-- tipo: impacto -->

- A tarefa do servidor passa a actualizar vendas, clientes e rotas a cada 30 segundos, sem sincronizações simultâneas.
- O estado da última sincronização e os erros ficam registados na pasta privada do portal.

### Vendas e ficheiro para o SAP: exactamente como no Pinguim BO; a conciliação das formas de pagamento fica à parte

<!-- paginas: inicio, pagamentos, documentos, sap-exportar -->
<!-- tipo: impacto -->

> titulo: Valores do Pinguim BO sem alterações
> titulo [en]: Pinguim BO figures, unchanged
> titulo [ti]: ቁጽርታት Pinguim BO ብዘይ ለውጢ
> - O Início, as Cobranças e os documentos voltam a mostrar exactamente o que está no Pinguim BO, sem nenhuma alteração.
> [en] The Home page, Collections and documents again show exactly what is in Pinguim BO, with no changes.
> [ti] መእተዊ ገጽ፡ ኣከፋፍላን ሰነዳትን ከም ብሓድሽ ኣብ Pinguim BO ዘሎ ብዘይ ዋላ ሓደ ለውጢ የርእዩ።
> - O ficheiro para o SAP leva as formas de pagamento exactamente como foram registadas na venda.
> [en] The SAP file carries the payment methods exactly as they were recorded at the sale.
> [ti] ፋይል SAP ነቶም ኣገባባት ክፍሊት ልክዕ ከምቲ ኣብ መሸጣ ዝተመዝገቡ ይሕዞም።
> - O que se recebeu de facto, segundo as correcções aprovadas, aparece só no quadro «Formas de pagamento conciliadas» do Início e na reconciliação.
> [en] What was actually received, according to the approved corrections, appears only in the «Reconciled payment methods» table on the Home page and in the reconciliation.
> [ti] ብሓቂ ዝተቐበልናዮ፡ ከም ዝጸደቑ ምእራማት፡ ኣብ ሰንጠረዥ «ዝተሰማምዑ ኣገባባት ክፍሊት» ናይ መእተዊ ገጽን ኣብ ምስምማዕን ጥራይ ይርአ።

- **Vendas (v1/vendas):** os documentos voltam a chegar ao portal exactamente como estão no Pinguim BO (formas de
  pagamento, valores e nomes dos meios). A correcção aprovada da forma de pagamento segue à parte, no campo
  «conciliacao», e não muda nenhum valor mostrado.
- **Início:** «Recebido», «Em dinheiro» e «Em Multicaixa» mostram o Pinguim BO. O quadro «Formas de pagamento
  conciliadas» mostra, dos documentos corrigidos, o Pinguim BO, o que se recebeu de facto (aprovado) e a diferença.
- **Ficheiro para o SAP:** o CASH AMOUNT e o CARD AMOUNT voltam a ser exactamente os do Pinguim BO, como foram
  registados na venda; as correcções aprovadas na reconciliação não mudam o ficheiro. A estrutura não muda.

### Formas de pagamento: documentos corrigidos só em leitura, ficheiro SAP com as formas da reconciliação e horas certas

<!-- paginas: multicaixa-tpa, sap-exportar, documentos -->
<!-- tipo: impacto -->

> titulo: Correcções da forma de pagamento e ficheiro para o SAP
> titulo [en]: Payment-method corrections and the SAP file
> titulo [ti]: ምእራም ኣገባብ ክፍሊትን ፋይል SAPን
> - Na correcção, os documentos já corrigidos (aprovados ou por aprovar) aparecem só para consulta. Só se pedem os que mudam de facto.
> [en] In the correction screen, documents already corrected (approved or pending) are shown for reference only. Only those that actually change are requested.
> [ti] ኣብ ምእራም፡ ቀደም ዝተኣረሙ ሰነዳት (ዝጸደቑ ወይ ዝጽበዩ) ንምርኣይ ጥራይ ይቐርቡ። ብሓቂ ዝቕየሩ ጥራይ ይሕተቱ።
> - Quando todos os documentos de um dia e promotor estão corrigidos, a reconciliação mostra «Corrigido» em vez do botão «Corrigir».
> [en] When every document of a day and sales rep is corrected, the reconciliation shows «Corrected» instead of the «Correct» button.
> [ti] ኩሎም ሰነዳት ናይ ሓንቲ መዓልትን ሸያጥን ምስ ዝኣረሙ፡ እቲ ምስምማዕ ኣብ ክንዲ «ኣርም» «ተኣሪሙ» የርኢ።
> - O ficheiro para o SAP leva o numerário e o Multicaixa aprovados na reconciliação. A estrutura do ficheiro não muda.
> [en] The SAP file carries the cash and Multicaixa amounts approved in the reconciliation. The file structure does not change.
> [ti] ፋይል SAP ነቲ ኣብ ምስምማዕ ዝጸደቐ ጥረ ገንዘብን Multicaixa ን ይሕዝ። ቅርጺ እቲ ፋይል ኣይቕየርን።
> - A hora dos documentos passa a ser a do registo no Pinguim BO (ex.: 18:25, e não 00:18).
> [en] Document times are now the registration time in Pinguim BO (e.g. 18:25, not 00:18).
> [ti] ሰዓት ሰነዳት ሕጂ ናይቲ ኣብ Pinguim BO ዝተመዝገበሉ እዩ (ንኣብነት 18:25፡ ኣይኮነን 00:18)።
> - No Início, «Recebido», «Em dinheiro» e «Em Multicaixa» passam a mostrar o que se recebeu de facto, com as correcções aprovadas. O quadro «Formas de pagamento conciliadas» mostra o que estava no Pinguim BO, o conciliado e a diferença.
> [en] On the Home page, «Received», «Cash» and «Multicaixa» now show what was actually received, with the approved corrections. The «Reconciled payment methods» table shows what was in Pinguim BO, the reconciled amount and the difference.
> [ti] ኣብ መእተዊ ገጽ፡ «ዝተቐበልናዮ»፡ «ብጥረ ገንዘብ»ን «ብ Multicaixa»ን ሕጂ ምስቶም ዝጸደቑ ምእራማት ብሓቂ ዝተቐበልናዮ የርእዩ። ሰንጠረዥ «ዝተሰማምዑ ኣገባባት ክፍሊት» ኣብ Pinguim BO ዝነበረ፡ ዝተሰማምዐን ፍልልይን የርኢ።
> 1. Para mudar uma correcção já aprovada, peça a quem aprova a caixa que a anule e volte a pedir a correcção.
> [en] To change an already approved correction, ask the cash-sheet approver to cancel it, then request the correction again.
> [ti] ዝጸደቐ ምእራም ንምቕያር፡ ነቲ ሳጹን ዘጽድቕ ክስርዞ ሕተትዎ፡ ድሕሪኡ ምእራም ደጊምኩም ሕተቱ።
> 2. Se um lote para o SAP já tinha estes documentos com a forma antiga, anule esse lote e gere-o de novo.
> [en] If an SAP batch already had these documents with the old payment method, cancel that batch and generate it again.
> [ti] ሓደ ጉጅለ ናይ SAP ነዞም ሰነዳት ብናይ ቀደም ኣገባብ ክፍሊት ሒዝዎም እንተ ነይሩ፡ ነቲ ጉጅለ ሰሪዝኩም ደጊምኩም ኣዳልውዎ።

- **Correcção:** os documentos com correcção aprovada ou por aprovar aparecem na janela sem campos (valores, banco e
  estado), não entram no pedido e os atalhos «Tudo em…» não lhes tocam. Sem nenhum documento por corrigir, a janela diz
  porquê e não deixa pedir. Antes de enviar, só seguem os documentos que mudam (numerário, Multicaixa ou banco); sem
  nenhuma mudança, a mensagem diz o que alterar.
- **Justificação:** tem de ter pelo menos três palavras (letras ao acaso são recusadas): é a prova para a auditoria.
- **Reconciliação:** cada linha conta os documentos que se podem corrigir e as correcções por aprovar; com tudo
  corrigido mostra «Corrigido» ou «Por aprovar» em vez de «Corrigir».
- **Ficheiro para o SAP:** o CASH AMOUNT e o CARD AMOUNT de cada documento passam a vir das correcções aprovadas da
  forma de pagamento (as mesmas da reconciliação, da folha de caixa e do consolidado). Folha, colunas e formatos do
  ficheiro mantêm-se. A página diz quantos documentos seguem com a forma corrigida. Exemplo: FP-2026-00045 (FR
  FR0126S78515N 13, Nelson, 22-10-2026), Multicaixa 1 568 000,00 no Pinguim BO → CASH AMOUNT 907 600,00 e CARD AMOUNT
  660 400,00 em todas as linhas do documento.
- **Recebido conciliado em todos os ecrãs:** as vendas do portal (v1/vendas) passam a trazer as correcções aprovadas da
  forma de pagamento, com o original do Pinguim BO ao lado. O Início, as Cobranças, os documentos e o ficheiro para o
  SAP mostram os mesmos valores da reconciliação e da folha de caixa. No Início, o quadro **«Formas de pagamento
  conciliadas»** mostra, só dos documentos corrigidos, o que estava no Pinguim BO na venda, o conciliado (aprovado) e a
  diferença por forma; o total não muda, só a forma.
- **Hora dos documentos:** lia-se o campo CLOCK_HOUR do Pinguim BO como minutos desde a meia-noite, mas só tem a hora;
  as 18:25 apareciam como 00:18. Passa a ser a hora do registo (CLOCK_DATE).

### Correcções da forma de pagamento: também as decide quem aprova a caixa no fim, e várias de uma vez

<!-- paginas: multicaixa-tpa -->
<!-- tipo: correcao -->

> titulo: Correcções da forma de pagamento
> titulo [en]: Payment-method corrections
> titulo [ti]: ምእራም ኣገባብ ክፍሊት
> - Quem aprova a caixa no fim (aprovação final) passa também a aprovar ou recusar as correcções da forma de pagamento, como o 1.º nível.
> [en] Whoever gives the final cash-sheet approval can now also approve or refuse payment-method corrections, as the 1st level does.
> [ti] ሳጹን ኣብ መወዳእታ ዘጽድቕ (ናይ መወዳእታ ምጽዳቕ) ከም 1ይ ደረጃ ምእራም ኣገባብ ክፍሊት ከጽድቕ ወይ ክነጽግ ይኽእል።
> - Quando há várias por decidir, pode revê-las numa lista e aprová-las de uma vez. Quem pediu uma correcção nunca a aprova.
> [en] When several are waiting, you can review them in a list and approve them at once. Whoever requested a correction never approves it.
> [ti] ብዙሓት ውሳነ ክጽበያ ከለዋ፡ ኣብ ዝርዝር ርእዩኹም ብሓንሳብ ከተጽድቕዎን ትኽእሉ። ምእራም ዝሓተተ ሰብ ፈጺሙ ኣየጽድቖን።
> 1. Abra Multicaixa por TPA › Formas de pagamento e desça até «Correcções da forma de pagamento».
> [en] Open Multicaixa by POS terminal › Payment methods and scroll down to «Payment-method corrections».
> [ti] Multicaixa ብ TPA › ኣገባባት ክፍሊት ክፈቱ፡ ናብ «ምእራም ኣገባብ ክፍሊት» ውረዱ።
> 2. Carregue em «Rever e aprovar todas», confirme a lista e carregue em «Aprovar», ou decida uma a uma na tabela.
> [en] Press «Review and approve all», check the list and press «Approve», or decide one by one in the table.
> [ti] «ኩሉ ርኣዩን ኣጽድቑን» ጠውቑ፡ ነቲ ዝርዝር ኣረጋጊጽኩም «ኣጽድቑ» ጠውቑ፡ ወይ ኣብቲ ሰንጠረዥ ሓደ ብሓደ ውሰኑ።

- **Quem decide:** as correcções pedidas pela Tesouraria passam a poder ser aprovadas, recusadas ou anuladas por quem
  aprova a caixa no 1.º nível **ou** na aprovação final (ex.: o responsável da área RTM). Até aqui só o 1.º nível as
  via, e o responsável da aprovação final não tinha o botão. Quem pediu continua a não poder aprovar.
- **Várias de uma vez:** «Rever e aprovar todas» mostra a lista das correcções que a pessoa pode decidir (nunca as que
  pediu), com o total corrigido; cada aprovação fica registada uma a uma, com quem e quando.

### Aviso a todos depois de cada actualização: o que muda para si e como proceder

<!-- paginas: actualizacoes, suporte, conciliacao-carrinha -->
<!-- publico: todos -->
<!-- tipo: funcionalidade -->

> titulo: Pedidos de acesso, acessos temporários e contagem às cegas
> titulo [en]: Access requests, temporary access and blind counting
> titulo [ti]: ሕቶታት ፍቓድ፡ ግዝያዊ ፍቓድን ዕዉር ቆጸራን
> - A partir de agora, depois de cada actualização, o portal mostra este aviso com o que muda para si e como proceder.
> [en] From now on, after each update, the portal shows this notice with what changes for you and what to do.
> [ti] ካብ ሕጂ ንደሓር፡ ድሕሪ ነፍሲ ወከፍ ምሕዳስ፡ ፖርታል እንታይ ከም ዝቕየረልኩምን ከመይ ከም እትቕጽሉን ዝገልጽ መልእኽቲ የርኢ።
> - Para ter acesso a uma página ou função nova, faça o pedido em Suporte › Pedidos de acesso. Quem autoriza é o responsável dessa função.
> [en] To get access to a new page or role, make the request in Support › Access requests. The owner of that role authorises it.
> [ti] ናብ ሓድሽ ገጽ ወይ ሓላፍነት ፍቓድ ንምርካብ፡ ኣብ ደገፍ › ሕቶታት ፍቓድ ሕተቱ። ዋና እቲ ሓላፍነት የጽድቖ።
> - Os acessos temporários (férias, substituições) terminam sozinhos no dia seguinte ao último dia pedido.
> [en] Temporary access (holidays, cover) ends automatically the day after the last day requested.
> [ti] ግዝያዊ ፍቓድ (ዕረፍቲ፡ ምትካእ) ድሕሪ እቲ ዝተሓተ ናይ መወዳእታ መዓልቲ ንጽባሒቱ ባዕሉ ይውዳእ።
> - Na conciliação da carrinha, até a rota ser contada, ninguém vê as quantidades do sistema (contagem às cegas). Quem acompanha a contagem pode imprimir a folha de contagem no cartão da rota.
> [en] In the van reconciliation, until the route has been counted, nobody sees the system quantities (blind count). Whoever follows the count can print the count sheet from the route card.
> [ti] ኣብ ምስምማዕ መኪና፡ እቲ መስመር ክሳብ ዝቑጸር፡ ዋላ ሓደ ብዝሒ ስርዓት ኣይርእን (ዕዉር ቆጸራ)። ነቲ ቆጸራ ዝከታተል ካብ ካርድ እቲ መስመር ወረቐት ቆጸራ ክሕትም ይኽእል።
> 1. Leia este aviso e carregue em «Li e compreendi». Se carregar em «Mais tarde», volta a aparecer na próxima entrada.
> [en] Read this notice and press «I have read and understood». If you press «Later», it appears again at your next sign-in.
> [ti] ነዚ መልእኽቲ ኣንቢብኩም «ኣንቢበ ተረዲኡኒ» ጠውቑ። «ጸኒሑ» እንተ ጠዊቕኩም፡ ኣብ ዝቕጽል ምእታው ተመሊሱ ይርአ።
> 2. Se não encontrar uma página de que precisa, peça o acesso em Suporte › Pedidos de acesso, com o motivo.
> [en] If you cannot find a page you need, request access in Support › Access requests, giving the reason.
> [ti] ዘድልየኩም ገጽ እንተ ዘይረኺብኩም፡ ኣብ ደገፍ › ሕቶታት ፍቓድ ምስ ምኽንያቱ ፍቓድ ሕተቱ።
> 3. Se algo não funcionar como descrito, abra um pedido em Suporte.
> [en] If something does not work as described, open a request in Support.
> [ti] ገለ ነገር ከም ዝተገለጸ እንተ ዘይሰሪሑ፡ ኣብ ደገፍ ሕቶ ክፈቱ።

- Depois de cada actualização, todos os utilizadores vêem um **aviso** com o que muda nas páginas a que têm acesso e
  como proceder, na sua língua (português, inglês ou tigrínia), seguindo a comunicação das alterações da ISO/IEC
  20000-1 (8.5.3) e a sensibilização da ISO/IEC 27001 (A.6.3).
- «**Li e compreendi**» fica registado (prova de quem tomou conhecimento); «Mais tarde» volta a mostrar o aviso na
  próxima entrada. O super administrador continua a ver primeiro o resumo técnico completo.
- Em **Actualizações**, o super administrador vê, antes de aprovar, a pré-visualização do aviso de cada versão (com
  alerta se faltar tradução) e, em «Avisos aos utilizadores», quantas pessoas já tomaram conhecimento e quem falta.
- O aviso escreve-se no registo de alterações de cada versão (linhas «> »): o que muda, os passos a seguir e as
  traduções; o público são as pessoas que vêem as páginas da alteração, ou todos.

### Acessos: funções do responsável da área RTM e do coordenador dos promotores, reconfiguradas automaticamente

<!-- paginas: acessos, multicaixa-tpa, sap-exportar -->
<!-- tipo: impacto -->

> titulo: Novas funções de acesso: responsável da área RTM e coordenação dos promotores
> titulo [en]: New access roles: RTM area manager and sales-rep coordination
> titulo [ti]: ሓደስቲ ሓላፍነታት ፍቓድ፡ ሓላፊ ክፍሊ RTM ን ምውህሃድ ሸየጥትን
> - Se é responsável da área RTM ou coordena os promotores, as suas funções no portal foram ajustadas ao seu trabalho.
> [en] If you manage the RTM area or coordinate the sales reps, your portal roles have been adjusted to your job.
> [ti] ሓላፊ ክፍሊ RTM እንተ ኾንኩም ወይ ንሸየጥቲ እንተ ኣወሃሂድኩም፡ ሓላፍነታትኩም ኣብ ፖርታል ምስ ስራሕኩም ተሰማሚዖም።
> - Quem aprova a caixa passa a ver o Multicaixa por TPA, onde se decidem as correcções da forma de pagamento.
> [en] Whoever approves the cash sheet can now see Multicaixa by POS terminal, where payment-method corrections are decided.
> [ti] ሳጹን ዘጽድቕ ሕጂ Multicaixa ብ TPA ይርኢ፡ ኣብኡ ድማ ምእራም ኣገባብ ክፍሊት ይውሰን።
> 1. Na próxima entrada, se o portal pedir, configure a verificação em dois passos com a aplicação de autenticação.
> [en] At your next sign-in, if the portal asks, set up two-step verification with the authenticator app.
> [ti] ኣብ ዝቕጽል ምእታው፡ ፖርታል እንተ ሓቲቱ፡ ብመተግበሪ Authenticator ናይ ክልተ ስጉምቲ መረጋገጺ ኣዳልዉ።
> 2. Confirme que vê as páginas de que precisa. Se faltar alguma, peça-a em Suporte › Pedidos de acesso.
> [en] Check that you can see the pages you need. If one is missing, request it in Support › Access requests.
> [ti] ዘድልዩኹም ገጻት ከም እትርእዩ ኣረጋግጹ። እንተ ጎዲሉ፡ ኣብ ደገፍ › ሕቶታት ፍቓድ ሕተትዎ።

- Nova função-base **«Coordenação comercial e operacional»** (P-COORD): coordena todos os promotores e as rotas, sem
  limite por rota — vendas, clientes, cargas, stock da carrinha e do armazém, requisições e terreno. Exige os dois
  passos na entrada.
- Nova função **«Gerar e enviar o ficheiro SAP»** (C-SAP-EXPORTAR), com os dois passos. A estrutura do ficheiro não
  muda; cada geração continua registada.
- Quem aprova a caixa (1.º nível e final) passa a ver o **Multicaixa por TPA**: o 1.º nível decide aí as correcções da
  forma de pagamento.
- Reconfiguração automática, uma só vez, das contas pedidas pelo super administrador, com o estado anterior registado:
  - **Michael Samson** (responsável da área de negócio RTM): Direcção comercial e marketing, aprovação final da caixa,
    fecho das conciliações e ficheiro SAP. Fica dono das funções da caixa, da conciliação, do supervisor, do promotor e
    da coordenação: autoriza os pedidos de acesso da equipa e revê-os por trimestre (o seu próprio acesso revê-o o
    super administrador).
  - **Nathan Alemayehu** (coordenador de todos os promotores): Coordenação comercial e operacional e aprovação das
    conciliações no 1.º nível.
  - Se uma conta não for encontrada pelo nome, ou houver mais de uma, não muda nada e o aviso aparece em Acessos ›
    Governação dos acessos.
- Na revisão trimestral, cada dono revê os outros, nunca o seu próprio acesso.

## 17-11-2026

### Acessos: pedidos de acesso autorizados pelo dono da função, revisão por área e novas regras de separação de funções

<!-- paginas: acessos, suporte -->
<!-- tipo: impacto -->

- **Dono de cada função** (Acessos › Funções › Gerir): a pessoa responsável pelo processo (ex.: a chefia da logística
  para as funções da conciliação). O dono autoriza os pedidos de acesso à sua função e revê, por trimestre, quem a tem
  (ISO/IEC 27001, controlos 5.9, 5.15 e 5.18). Sem dono, autoriza o super administrador.
- **Pedidos de acesso** (Suporte › Pedidos de acesso): qualquer pessoa pede uma função para si ou para outra conta,
  com o motivo e, se for temporário, o último dia. O portal recusa logo funções que a conta já tem e combinações
  proibidas. O dono autoriza (recebe um aviso por correio) e o super administrador aplica em Acessos › Governação dos
  acessos, com a confirmação da identidade. Ninguém autoriza o pedido que fez nem um acesso para a sua própria conta,
  e o super administrador não aplica um pedido para si. Cada passo fica no histórico do pedido e nos registos.
- **Funções temporárias**: na conta, cada função pode ter um último dia (substituições, férias). No dia seguinte sai
  sozinha na verificação da noite; sem nenhuma função, a conta fica desligada. Fica registado.
- **Revisão trimestral por área**: quando a revisão abre, cada dono recebe um aviso e confirma a sua parte em Suporte ›
  Revisão de acessos da sua área (mantém, ou marca a quem retirar, com a razão). O super administrador fecha a revisão
  com essas confirmações; sem todas, só fecha com a razão escrita.
- **Novas regras de separação de funções do sistema** (não se retiram no portal): a Logística (quem conta a carrinha)
  não acumula com aprovar nem fechar a conciliação, e as Finanças (quem lança a caixa) não acumulam com aprovar a caixa
  (1.º nível ou final). As contas que hoje tenham essas combinações aparecem em Acessos › Governação dos acessos para
  retirar uma das funções; até lá, cada operação continua a recusar a mesma pessoa em dois passos.
- **Contas sem funções**: em Governação dos acessos, a lista das contas que ainda têm perfil manual e o que mudaria ao
  passarem para a função equivalente. As que ficam com os mesmos acessos passam com um clique.
- O **Apoio administrativo RTM** passa a ver a conciliação da carrinha e a imprimir a folha de contagem.

### Conciliação da carrinha: contagem às cegas também para quem só consulta

<!-- paginas: conciliacao-carrinha -->
<!-- tipo: melhoria -->

- Enquanto a rota está por contar, quem só consulta a conciliação (ex.: o promotor ou quem acompanha a contagem) vê os
  artigos sem as quantidades do sistema, como quem conta. A coordenação e o fecho continuam a ver o esperado.

### Conciliação da carrinha: as vendas depois do corte saem do que tem de voltar

<!-- paginas: conciliacao-carrinha -->
<!-- tipo: correcao -->

- Quando o corte vem da fotografia do stock (16:00), as facturas emitidas depois do corte (ex.: rota 1009 a 23-10-2026,
  três facturas entre as 18:25 e as 18:44) não eram descontadas: a contagem mostrava faltas que não existiam. Passam a
  sair do que tem de voltar, e a nota do corte diz quanto foi «Vendido depois do corte (já não está na carrinha)».
- As vendas lêem-se dos mesmos documentos válidos do Pinguim BO que as vendas do portal; as entradas e saídas de stock
  continuam a ser só as guias e as saídas de armazém.

### Folha de contagem: disponível para toda a logística

<!-- paginas: conciliacao-carrinha -->
<!-- tipo: melhoria -->

- O botão **«Imprimir folha de contagem»** passa a estar no cartão de cada rota por contar, para todos os utilizadores
  que vêem a conciliação (logística, coordenação, fecho, supervisores, direcção), e não só para quem conta no portal.
  A folha continua às cegas: só os artigos, o código e a unidade, sem nenhuma quantidade do sistema.
- Depois de contada a rota, o botão sai do cartão (fica o «PDF da contagem» com o resultado).

### Formas de pagamento: corrigir a partir das excepções e escolher o banco do Multicaixa

<!-- paginas: multicaixa-tpa -->
<!-- tipo: melhoria -->

- Na tabela «Excepções das formas de pagamento», cada excepção tem o botão **«Corrigir»**, que abre os documentos desse
  dia e promotor (como na reconciliação). Para fechar Outubro e Novembro: escolha o período, corrija a partir das
  excepções e aprove.
- Na correcção, além de Numerário e Multicaixa, escolhe-se o **banco do Multicaixa** (os códigos de Multicaixa da tabela:
  MC, MCB · BPC…). Assim corrige-se também o «Multicaixa de outro banco». Continua a valer: justificação obrigatória,
  aprovação por outra pessoa e o documento do Pinguim BO sem alteração.
- Antes de corrigir um «Multicaixa de outro banco», confirme o terminal do promotor em Terminais (TPA): se o registo do
  terminal estiver errado, corrige-se o registo e a excepção desaparece sem corrigir documentos.
- A janela da correcção fica **compacta e sem barras de deslocamento** num ecrã de computador: o documento, a hora e a
  forma do Pinguim BO numa só coluna, linhas mais baixas, justificação e evidência lado a lado, e os atalhos «Tudo em
  Numerário» e «Tudo em Multicaixa». No telemóvel, cada documento é um cartão (sem deslocamento para o lado).

### Terminais TPA: a cadeia de custódia começa com o promotor que o terminal já tinha

<!-- paginas: multicaixa-tpa -->
<!-- tipo: correcao -->

- Nos terminais registados antes da cadeia de custódia, o promotor que já tinham não ficava no histórico: ao mudar de
  promotor, só aparecia o novo. Passa a entrar na cadeia, desde a data do registo do terminal, e a fechar na mudança.

### Terminais TPA: associar o promotor pela lista, situação, número de série e cadeia de custódia

<!-- paginas: multicaixa-tpa -->
<!-- tipo: melhoria -->

- Em Multicaixa por TPA › Terminais (TPA), o **promotor associa-se ao terminal na própria tabela**, escolhendo-o na
  lista dos promotores (rota · nome). Se o promotor já tiver outro TPA em funcionamento, o portal avisa (pode ser a
  substituição de um avariado).
- **Cadeia de custódia** (ISO/IEC 27001:2022 A.5.9 inventário, A.5.10 e A.5.11 uso e devolução de activos): cada mudança
  de promotor fecha a associação anterior e abre a nova, com quem associou e quando; nada se apaga. Na ficha do TPA,
  «Promotores que tiveram este TPA».
- Cada TPA passa a ter **número de série**, **situação** (em funcionamento, reserva, em revisão, avariado, abatido) e
  **avaria ou defeito**, como no «Control de TPA» do Marketing. Só os TPA em funcionamento contam como activos.

### Formas de pagamento do RTM: lista das formas válidas, banco do TPA por rota e reconciliação por dia e promotor

<!-- paginas: multicaixa-tpa, relatorio-vendas -->
<!-- tipo: funcionalidade -->

- **Formas de pagamento válidas no RTM** (Finanças › Multicaixa por TPA › Formas de pagamento): só **Numerário** e
  **Multicaixa**, por lista branca dos códigos do Pinguim BO — NU (Numerário), MC (Multicaixa) e MCB (Multicaixa BPC).
  Um código que não esteja na tabela, ou um documento sem forma de pagamento, é uma **excepção**; nunca se adivinha a
  forma pelo nome. Um código novo (ex.: Multicaixa de outro banco) acrescenta-se na tabela, no portal.
- **Banco do TPA de cada rota** (BPC: 1001 a 1007, 1009 e 1012; Atlântico: 1008, 1010 e 1011): o Multicaixa de outro banco
  é assinalado (TPA de outro promotor ou forma mal escolhida).
- **Reconciliação das vendas RTM por forma de pagamento**, por dia e promotor (o mapa «Rtm sales reconciliation», agora
  lido da base de dados): Numerário do Pinguim BO × dinheiro entregue na caixa e Multicaixa do Pinguim BO × talões do
  TPA, com as diferenças, as formas não válidas, o por receber e o total; Excel pela tabela.
- **«Provável numerário registado como Multicaixa»**: quando a caixa recebeu mais dinheiro e o TPA menos do que o
  Pinguim BO indica. O documento fiscal não se altera: a forma certa escolhe-se no telemóvel; a diferença explica-se na
  folha de caixa.
- **Consolidado diário**: nova conferência «Formas de pagamento válidas» e excepções «Forma de pagamento» e «Banco do
  TPA».
- **Correcção**: a conferência do Multicaixa por TPA só lia o código MC; passa a ler todos os códigos de Multicaixa da
  tabela (MC, MCB…).
- **Correcção da forma de pagamento, com justificação e aprovação** (quatro olhos): quando a forma registada na venda
  não é a que se recebeu de facto, a Tesouraria corrige no portal, documento a documento («Corrigir» na
  reconciliação), com a **justificação obrigatória** e a evidência (talão do TPA, folha de caixa); Numerário + Multicaixa
  tem de dar o valor cobrado. Outra pessoa, do 1.º nível de aprovação da caixa, **aprova ou recusa** (com motivo); quem
  pede não aprova. **O documento fiscal do Pinguim BO nunca se altera**: só a correcção aprovada conta na folha de caixa,
  no Multicaixa por TPA, no consolidado e na reconciliação, sempre com a forma original ao lado. Uma correcção aprovada
  anula-se com motivo; nada se apaga (ISO/IEC 27001:2022 A.5.3 e A.8.15; ISO 9001:2015 7.5.3; COSO).
- **Banco do TPA por rota a partir do registo dos terminais**: quando a rota tem um TPA registado, manda o banco desse
  TPA; a tabela das formas só completa as rotas sem terminal.
- **TPA de outra empresa**: um talão de um TPA da Higitec (ou de outra empresa) numa venda RTM da Naval é assinalado — o
  valor entra na conta da outra empresa e tem de ser acertado entre empresas.

### Fecho de caixa: revisão pela Tesouraria dos fechos validados por outra pessoa

<!-- paginas: caixa-marketing -->
<!-- tipo: funcionalidade -->

- Os fechos de caixa validados por outra pessoa (por exemplo, os lançados antes de a tesoureira ter conta no portal)
  passam a poder ser **revistos pela Tesouraria**: botão «Confirmar a revisão (Tesouraria)», com uma nota opcional.
- Boa prática de controlo interno e de auditoria (ISO/IEC 27001:2022 A.8.15; COSO): **as assinaturas registadas nunca se
  reescrevem**. Quem validou e quem aprovou ficam como estão; a revisão acrescenta-se, com o nome, a data e a hora, no
  cabeçalho, na rastreabilidade das assinaturas e no PDF. Não muda valores, estado nem aprovações (vale também numa caixa
  já aprovada).
- Uma revisão por pessoa e por dia; quem validou o fecho não o revê; uma caixa aberta valida-se (não se revê).
- No topo da folha de caixa, «Fechos por rever pela Tesouraria (N)», com um atalho para cada dia (últimos 120 dias).
- Só quem lança a caixa («Lançar e validar o fecho de caixa») revê.

### Conciliação das carrinhas: entradas e saídas lidas das linhas dos documentos do Pinguim BO (DOCMOVDETAIL), com a hora

<!-- paginas: conciliacao-carrinha -->
<!-- tipo: correcao -->

- As cargas (guias de transporte GTC) e as devoluções (saídas de armazém SA) não eram lidas: a consulta procurava as
  linhas na tabela DOCMOV, que não tem a ligação ao documento. Passa a ler as linhas de **DOCMOVDETAIL**, com o sinal de
  stock do tipo de documento e a **hora de registo** de cada documento.
- O **stock no corte** (16:00), quando não há fotografia, passa a ser inicial − vendido + entradas − saídas dos
  documentos registados **antes do corte** (antes só contava as cargas confirmadas no portal). As colunas «Carregado» e
  «Devolvido» mostram esses documentos; o que foi registado depois do corte fica em «Carregado depois do corte».
- Exemplo (rota 1005, 16-11-2026): a carga das 08:54 (+15 sabão verde, +15 sabão castanho) e a saída de armazém das
  11:39 (−12 sabão branco) passam a contar; as diferenças de +15, +15 e −12 desaparecem.
- As contagens já feitas mantêm os números com que foram contadas: para recalcular uma contagem por validar, use
  «Recontar».

### Consolidado diário: o depósito fica na conta do banco

<!-- paginas: relatorio-vendas -->
<!-- tipo: correcao -->

- Em «Bancos: Multicaixa e depósitos», o depósito (lacre) aparecia numa linha à parte, sem conta, mesmo quando o banco
  só tem uma conta nos talões do TPA. Passa a ficar na linha dessa conta; só fica à parte quando o banco tem várias
  contas ou nenhuma.
- No PDF, o subtítulo passa a «Portal RTM · Comercial · Vendas» (dizia «Logística»).

## 16-11-2026

### Plano de cargas: a requisição manda na carga; matrícula, marca e modelo; motorista só pelo nome; capacidades de referência

<!-- paginas: plano-cargas, requisicoes-carrinha -->
<!-- tipo: melhoria -->

- **A carga vem da requisição** (documento de origem): com requisição emitida para a rota, o plano mostra sozinho o
  **número da requisição**, o **armazém de carga** (o armazém dos artigos da requisição) e a **quantidade de cada artigo
  = existências na carrinha (contadas no fecho do dia anterior) + requisição**, todos só para leitura. O servidor volta
  a fixá-los ao gravar e ao publicar, seja o que for que venha do ecrã (sem ligação ao Pinguim BO, nunca abaixo do
  requisitado). O limite de peso usa esse total, sem somar outra vez o que está na carrinha.
- A requisição ainda **em rascunho** aparece no campo «Requisição ao armazém» («RQ-… em rascunho: emita a requisição»).
- Ao emitir uma requisição, fica gravado o armazém de cada artigo (o do catálogo do Pinguim BO nesse momento); nas
  requisições antigas, o armazém vem do catálogo.
- No cartão de cada rota, a lista **Matrícula** mostra só a matrícula. Ao escolhê-la, a **Marca** e o **Modelo** aparecem
  em dois campos ao lado, que não se editam (vêm da tabela de viaturas); a transportadora fica por baixo.
- A lista **Motorista** mostra só o nome (sem a transportadora).
- **Capacidade de carga**: a que conta é a carga útil do livrete (peso bruto − tara), gravada na tabela de viaturas.
  Para as viaturas sem ela, o plano mostra a referência do fabricante (FUSO Canter FE71, 4 rodas: 2 500 kg; Canter
  FE85, 6 rodas: 6 000 kg; Sinotruk HOWO ligeiro: 5 000 kg) e, em Listas → Viaturas, um botão preenche as capacidades
  em falta com essa referência (só depois de gravar passa a contar para o limite de peso).

### Consolidado diário de vendas RTM da Naval: resumo, conferências e excepções, e envio automático

<!-- paginas: relatorio-vendas -->
<!-- tipo: funcionalidade -->

- O **Relatório Diário de Vendas** passa a **Consolidado Diário de Vendas**, só com as vendas **RTM** (Pinguim BO) da
  **Naval**, a única empresa que factura no RTM. Sai a verificação e a secção da facturação SAP das outras empresas: o
  consolidado já não depende do conector SAP.
- **Resumo do dia** no formato do SALES REPORT: vendas brutas, notas de crédito e vendas líquidas; dinheiro, Multicaixa
  e por receber (crédito); caixa (saldo inicial + dinheiro entregue − depósitos = saldo final pela contagem física).
- **Conferências automáticas** (cada uma compara duas fontes independentes, tolerância zero): vendas = cobranças + por
  receber; total por promotor = total do dia; dinheiro recebido = dinheiro entregue na caixa; Multicaixa = talões do
  TPA; saldo teórico da caixa = contagem física; stock contado = stock teórico nas carrinhas. Estado no topo: «Tudo
  confere» ou «N conferência(s) com diferença».
- **Excepções a explicar**, primeiro (gestão por excepção): caixa, faltas e sobras de dinheiro, Multicaixa, stock e
  vendas a crédito, por promotor, o maior valor primeiro. Os nomes vêm sempre da tabela de rotas.
- **Bancos**: Multicaixa (talões do TPA, com a conta do registo dos terminais) e depósitos (lacres) por banco e conta.
- **PDF** com o resumo, as conferências, as excepções (com espaço para a explicação), os bancos e as assinaturas
  (preparado, revisto e aprovado). Excel por tabela, como antes.
- Ao gerar, segue **por correio** o resumo, as conferências e as excepções para quem tem acesso ao consolidado na matriz
  (sem os perfis limitados a rotas), com a ligação para o portal; `RELATORIO_VENDAS_EMAIL=0` no `.env` desliga o envio.
  Os relatórios já gerados mantêm-se como estavam (fotografia congelada).

### Modo apresentação: moldura vermelha a toda a volta e menu da conta com cores com significado

<!-- paginas: inicio -->
<!-- tipo: melhoria -->

- Com o modo apresentação activo, o ecrã fica com uma **moldura vermelha a toda a volta** e o rótulo «● Em apresentação
  · até às HH:MM» ao centro, em cima (estado sempre visível, como nas aplicações de partilha de ecrã; ISO 9241-110 e
  WCAG 2.2 1.4.1: o estado também em texto, nunca só pela cor). A moldura não intercepta cliques, não pisca e não sai na
  impressão.
- **Menu da conta**: as opções passam a cor normal e só **Terminar sessão** fica a vermelho (separada por uma linha). O
  estado de cada opção aparece em texto: «activa» / «por instalar» na app no telemóvel e «activo até às HH:MM» no modo
  apresentação, com o ponto da cor da moldura.

### Plano de cargas: tabelas de motoristas e viaturas, requisição como documento e carga pelo stock contado

<!-- paginas: plano-cargas -->
<!-- tipo: funcionalidade -->

- **Tabela de motoristas** (Listas → Motoristas): nome, telefone, transportadora, matrícula habitual, **validade da carta
  de condução** e activo. No plano, o motorista escolhe-se numa lista (só os activos e com a carta válida no dia); o
  telefone, a viatura e a transportadora preenchem-se sozinhos. O plano já não cria nem altera motoristas: a tabela é a
  única fonte (dados mestre).
- **Tabela de viaturas** (Listas → Viaturas): matrícula (validada e única), **marca, modelo**, transportadora,
  capacidade (kg) e activa. Ao escolher a matrícula, aparecem a marca e o modelo; a capacidade passa a vir daqui para o
  limite de peso. Na primeira abertura, a tabela é criada com as capacidades e as matrículas que já existiam.
- Publicar recusa motoristas fora da tabela, inactivos ou com a carta caducada no dia, e viaturas fora da frota ou
  inactivas.
- **Documento = número da requisição** ao armazém (RQ-…), preenchido sozinho pelo servidor (também no Excel e no
  correio). Rota sem requisição emitida fica com aviso.
- **Quantidade de cada artigo = já na carrinha + a carregar**: o stock contado na conciliação do dia anterior (no corte;
  sem contagem, o esperado, «estimado») mais a requisição (depois de carregada, o entregue). O cartão mostra a conta por
  artigo; uma rota com requisição fica marcada a sair. Os artigos do Pinguim BO juntam-se aos do plano pelo nome ou pela
  **correspondência** (Listas → Correspondência).

### Conciliação da carrinha: a carga para o dia seguinte não entra na contagem

<!-- paginas: conciliacao-carrinha -->
<!-- tipo: impacto -->

- A conciliação do dia vai até ao **corte** (início do carregamento, 16:00 de segunda a sexta, pelo calendário): conta-se
  o que estava na carrinha nessa hora. A **carga para o dia seguinte** (e uma devolução feita depois do corte) **não
  entra na contagem**: passa a stock efectivo da carrinha no dia seguinte (o inicial, às 00:00).
- O portal tira sozinho uma **fotografia do stock das carrinhas** nos 20 minutos antes do corte. Sem fotografia, o stock
  no corte é **estimado**: inicial − vendido + cargas confirmadas no portal antes do corte. A conciliação diz qual foi
  usado e quanto foi carregado depois do corte.
- Vale a partir de 16-11-2026, incluindo as conciliações deste dia ainda por fechar (ex.: rota 1005); as já aprovadas
  ficam como foram aprovadas. `CARRINHA_CORTE=0` desliga.

### Carrinhas: só se carrega com o fecho do dia anterior feito

<!-- paginas: stock-carrinha, conciliacao-carrinha -->
<!-- tipo: impacto -->

- **Fecho antes da carga** (liquidação da rota antes da saída seguinte, como na distribuição directa): o armazém só
  confirma a carga de uma requisição quando as conciliações dos dias anteriores dessa carrinha estão **fechadas**. Com
  um fecho por fazer, o ecrã do stock mostra «Fecho por fazer: dd-mm-aaaa (estado)» e o botão «Confirmar carga» fica
  bloqueado; o servidor recusa também. Sem dados do Pinguim BO para verificar, conta como por fechar.
- **Excepção autorizada**: quem fecha as conciliações («Fechar conciliações») pode autorizar a carga antes do fecho,
  com motivo; a carga é confirmada por outra pessoa (separação de funções) e **não entra na contagem** dos dias por
  contar: soma-se ao que se conta, para a conciliação desses dias ficar certa. Fica no histórico da requisição, na
  conciliação e no registo de actividade.
- A regra vale para os dias a partir da instalação desta versão (os dias antigos ficam como estavam), até 7 dias para
  trás (`CARRINHA_FECHO_DIAS`); `CARRINHA_FECHO_ANTES_CARGA=0` desliga.

### Suporte: pedidos classificados pela ITIL 4 e enviados à equipa de TI (ServiceDesk Plus)

<!-- paginas: suporte -->
<!-- tipo: funcionalidade -->

- Cada pedido novo segue por correio para **ITSupport@grupo-naval.com**, que alimenta a ferramenta de pedidos da equipa
  de TI: assunto normalizado «[Portal RTM] [SUP-000123] P2 · Incidente · Smartphone RTM · título», resposta directa a
  quem abriu o pedido e confirmação para essa pessoa com o número e os prazos. Se o correio não sair, o portal tenta de
  novo sozinho (até 10 vezes) e quem gere pode reenviar.
- **Classificação ITIL 4 / ISO/IEC 20000-1**: tipo, **serviço afectado** (catálogo: Portal RTM, App de gestão,
  Smartphone RTM, Impressora móvel, Pinguim BO, SAP, Rede e Internet, Contas e acessos) e **prioridade calculada pela
  matriz impacto × urgência** (já não é escolhida por quem abre o pedido), com prazo da **primeira resposta** (15 min,
  1 h, 4 h, 8 h) e da resolução (4 h, 8 h, 24 h, 72 h).
- **Contexto automático**: página onde a pessoa estava, versão e revisão do portal, navegador, ecrã e idioma; captura de
  ecrã opcional (PNG, JPEG ou WebP até 5 MB, conferida pelo conteúdo).
- **E-mail Command do ServiceDesk Plus** (Administração › Suporte › Encaminhamento): com os nomes da ferramenta, o
  correio preenche sozinho o tipo, a categoria, a prioridade, o impacto, a urgência, o grupo e o requerente.
- Pedidos com dados pessoais: o correio não leva a descrição nem a captura (ficam no portal) e lembra a avaliação de
  comunicação à Agência de Protecção de Dados (Lei n.º 22/11).

### Acessos: retirar os dois passos e dispensar numa só operação; nova função «Tesouraria»

<!-- paginas: acessos -->
<!-- tipo: impacto -->

- **Retirar dois passos** num perfil que os exige abre logo a dispensa: motivo e prazo na mesma janela, gravados numa
  só operação (ou fica tudo feito, ou nada). Em alternativa, «configurar de novo na próxima entrada» (por exemplo,
  telemóvel novo). «Dispensar dois passos» fica só para as contas que nunca os configuraram, ou para renovar ou retirar
  uma dispensa em vigor.
- Nova função principal **Tesouraria** (menor privilégio, ISO/IEC 27001:2022 A.5.15, A.5.18 e A.8.2): início,
  documentos de venda, relatório de vendas, clientes, cobranças e contas a receber (com exportação), e Multicaixa por
  TPA só para consulta. Junta-se a «Lançar e validar o fecho de caixa». Sem logística, equipamentos, integrações,
  inteligência de negócio nem exportações completas; exige dois passos (salvo dispensa).
- Separação de funções (A.5.3): «Tesouraria» não se pode juntar às aprovações do fecho de caixa. As funções do sistema
  acrescentadas em versões novas passam a aparecer nos catálogos já gravados, sem mexer nas funções alteradas.

### Versões: numeração sem falhas, classificação das alterações e revisão do código

<!-- paginas: actualizacoes -->
<!-- tipo: impacto -->

- O número de cada versão (v<AAAA.MM.DD>.<N>) passa a ser **próprio do portal e sem falhas**: a versão anterior + 1, e
  não o contador de execuções do GitHub, que contava também as execuções sem versão (por isso não houve a v.104).
- **Classificação obrigatória de cada alteração**: correcção, melhoria, nova funcionalidade ou alteração com impacto. A
  versão fica com a mais exigente e a classificação aparece em **Actualizações** (para aprovar) e nas novidades.
- Alterações em ficheiros sensíveis (base de dados, ficheiro e integração SAP, segurança e acessos, actualizador,
  Apache) só são aceites classificadas como **alteração com impacto**: a verificação dos pedidos de integração recusa-as
  de outra forma (ISO/IEC 27001:2022 A.8.32, gestão das alterações).
- A **revisão do código** (commit) aparece na nota de cada versão e na versão instalada, em Actualizações.

### Acessos: dispensa de dois passos por conta

<!-- paginas: acessos -->
<!-- tipo: impacto -->

- Em **Acessos › Gerir**, nas contas com perfil que obriga à verificação em dois passos, o super administrador pode
  **dispensar** essa obrigação a uma pessoa: motivo obrigatório e prazo de 7, 30, 60 ou 90 dias, renovável ou
  retirável a qualquer momento. Terminado o prazo, a obrigação volta sozinha na entrada seguinte.
- Excepção documentada e temporária (aceitação do risco, ISO/IEC 27001:2022 6.1.3, A.5.17 e A.8.5): nunca para contas
  SADM nem para a própria conta; fica no registo de actividade e na revisão de acessos da conformidade.
- Na lista de contas: «Dispensada até dd-mm-aaaa»; o quadro «Sem verificação em dois passos» não conta as contas
  dispensadas e indica quantas há.

### Equipamentos: página pública do código QR integrada no portal

<!-- paginas: equipamentos -->
<!-- tipo: melhoria -->

- A página provisória do código QR (id.naval.co.ao) passa a ser a do próprio portal: o mesmo QR das etiquetas abre a
  ficha para quem tem sessão e permissão e, para quem o encontra, uma página em **português e inglês** com a propriedade
  do Grupo Naval, o **tipo** (smartphone ou impressora móvel, deduzido do ID), o ID e os botões **Ligar** e **Enviar
  mensagem**; modo claro e escuro. Não mostra nem revela se o ID existe nem nenhum dado interno.
- **Contacto de devolução gravado no portal** (Equipamentos › Página pública do código QR, «Equipamentos –
  Administrar»), validado e registado no registo de actividade, com **pré-visualização** da página pública; as chaves
  do `.env` ficam como valores por omissão.
- **Equipamento perdido ou roubado lido**: a leitura sem sessão fica na ficha (evento «Leitura do código QR», com o IP e
  a localização aproximada) e quem administra os equipamentos recebe uma notificação crítica (uma por hora e por
  equipamento). Todas as leituras sem sessão ficam no registo de actividade.
- Cabeçalhos mais estritos na página pública: CSP sem scripts, sem enquadramento nem formulários, sem enviar o endereço de origem;
  continua o limite de 60 leituras por minuto e por ligação.

### Multicaixa por TPA: conferência máquina a máquina e resumo de fim de dia para o Financeiro

<!-- paginas: multicaixa-tpa, caixa-marketing -->
<!-- tipo: funcionalidade -->

- Nova página **Finanças › Multicaixa por TPA**, pedida pelo Financeiro para conferir com os extractos e limpar a conta
  de cartões (POS Card GL) no SAP:
  - **por TPA (máquina) e por dia**: empresa, banco e conta, Ident. TPA, rota, período, operações, total do fecho,
    Multicaixa no Pinguim BO e diferença;
  - **por empresa, banco e conta**: o que o banco credita e o que já está conferido;
  - **Multicaixa sem talão de fecho**: rotas com Multicaixa no Pinguim BO e sem talão de fecho na folha de caixa;
  - **Conferido no extracto**: o Financeiro marca cada fecho com a referência e a data do movimento;
  - **Pagamentos a rever**: com `PINGUIM_MC_REFERENCIA` (a coluna do Pinguim BO com o «Número» do pagamento), cada
    pagamento Multicaixa é conferido pela referência «período/transacção» do talão (sem referência, formato errado ou
    de outro período).
- **Registo dos terminais (TPA)**: Ident. TPA, Id. Estab., empresa, banco, conta e rota atribuída.
- **Folha de caixa**: com o registo, cada talão de fecho tem o TPA (o da rota já escolhido), o período, as operações e
  o «total a movimentar» escrito do talão (às cegas, como as notas); banco e conta vêm do registo; TPA + período não se
  repetem (nem nos 60 dias anteriores). Sem terminais registados, a folha fica como antes.
- **Resumo de fim de dia por correio** quando a caixa recebe a aprovação final, para os destinatários escolhidos na
  página: Multicaixa por banco, conta, TPA e período, numerário, depósitos e o que está a rever, com a ligação ao dia.
- Recurso «multicaixa-tpa» na matriz (Financeiro gere; TI, Direcção, Back Office e Auditoria vêem). Nunca se guarda o
  número do cartão nem o certificado da transacção (PCI DSS). Tudo fica nos registos de auditoria.

# Registo de alterações

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em dd-mm-aaaa.

## 14-11-2026

### Rapidez, Face ID sem toques e telemóvel avisado no modo apresentação

<!-- paginas: inicio -->

- **Portal mais rápido a mudar de página**: aberta a primeira página, os módulos das outras páginas do perfil
  descarregam-se em segundo plano (um de cada vez, nos tempos livres do navegador), por isso abrir uma página do menu
  já não espera pela rede. Não corre com a poupança de dados ligada.
- **App mais rápida a mudar de secção**: com o resumo no ecrã, os dados de Documentos, Vendas, Clientes, Cobranças e
  Artigos vêm em segundo plano; cada secção abre logo. Os 12 meses lêem-se em duas partes — dias fechados (guardados 1
  hora) e o dia de hoje (2 minutos) — e não se voltam a pedir inteiros a cada actualização.
- **Face ID sem tocar em nada**: a entrada na app pede a biometria sozinha ao abrir e ao voltar à app; se o telemóvel
  recusar no primeiro instante (a app ainda não está à frente), espera e tenta de novo, até três vezes, antes de pedir
  um toque (iOS 17.4 ou mais recente e Android).
- **Modo apresentação**: ao mostrar a app no ecrã (só a app ou lado a lado), o telemóvel recebe logo uma notificação e
  **um toque abre a app no mesmo ecrã** (o iPhone e o Android não deixam uma app abrir-se sozinha). Se o telemóvel já
  estiver a seguir, nada se envia. Fica registado.

### App de gestão: menu lateral e consulta completa (vendas de 12 meses, documentos, clientes, artigos, cobranças)

<!-- paginas: inicio -->

- **Menu numa gaveta lateral** (botão no topo; Material 3 e Apple HIG): todas as secções num só sítio, agrupadas —
  Resumo, Comercial, Finanças, Operações e Acompanhamento. Fecha ao escolher, com Esc ou no fundo; foco preso enquanto
  aberto e devolvido ao botão (WCAG 2.2). Sai a barra de separadores em baixo e deixam de se ver barras de deslocamento.
- **Vendas**: Hoje, Semana, Mês e **12 meses** — vendas sem IVA e com IVA, gráfico por hora, dia ou mês, documentos,
  valor médio, recebido e por receber, por rota e melhores clientes.
- **Documentos**: os documentos de qualquer dia, com pesquisa (documento, cliente, NIF, rota) e estado (pago, por pagar,
  nota de crédito); ao tocar, o **documento com as linhas** (artigo, quantidade, preço, desconto, total), totais com e
  sem IVA, recebido, por receber e pagamentos.
- **Clientes**: os últimos 12 meses por cliente, com pesquisa; a ficha tem vendas por mês, compras, valor médio, última
  compra, por receber e os documentos (abrem com as linhas, com «Voltar»).
- **Artigos** (hoje, semana, mês) e **Cobranças** (por receber nos últimos 12 meses, por antiguidade e por cliente).
- Cada secção só aparece com o recurso correspondente na matriz de acessos e com o âmbito da conta; os dados ficam só na
  memória enquanto a app está aberta. O espelho do modo apresentação acompanha também a secção, o dia, o documento ou o
  cliente abertos e o menu.
- Modo apresentação: sai a vista «Portal no telemóvel».

### Modo apresentação: a app no computador e no telemóvel ao mesmo tempo, no idioma do portal

<!-- paginas: inicio -->

- **Espelho**: com o modo apresentação activo, a app de gestão mostrada no ecrã do computador e a app no telemóvel da
  mesma conta mostram **o mesmo ecrã ao mesmo tempo**, nos dois sentidos — separador, período, rota aberta, posição na
  página e conversa do assistente. Quem mexe conduz; o outro segue em menos de um segundo. No telemóvel, uma faixa diz
  que está a seguir a apresentação, com «Deixar de seguir».
- **Idioma**: a app no computador fala o idioma escolhido no portal (já não o do navegador) e o telemóvel que segue a
  apresentação passa a esse idioma; ao acabar, volta ao idioma do telefone. A marca do modo apresentação tem os botões
  PT · EN · TI, para mudar de idioma mesmo com a app à frente do portal.
- Só passa pelo servidor o estado do ecrã (validado; nunca dados de negócio além da conversa do assistente), só para a
  própria conta, só durante o modo apresentação; cada lado lê os dados com a própria sessão.

### Modo apresentação e ambiente de pré-produção

<!-- paginas: inicio -->

- **Modo apresentação** (menu da conta), para apresentar com o computador ligado a um projector ou LCD: escolhe-se a
  duração (30 minutos a 4 horas) e o que mostrar — **o portal, a app de gestão, o portal no telemóvel ou o portal e a
  app lado a lado**. A app corre ao vivo numa moldura de telemóvel no ecrã do computador, com a mesma conta e os mesmos
  dados; muda-se de vista a qualquer momento na marca discreta do canto do ecrã, que também tem «Ecrã inteiro» e
  «Terminar».
- Durante a apresentação, a sessão só termina após 60 minutos sem actividade (NIST SP 800-63B-4, AAL2) e o portal não
  volta sozinho ao painel; não abrem janelas automáticas. Acaba sozinho à hora marcada.
- Controlos: recurso «modo-apresentacao» na matriz de acessos (só o super administrador, por omissão); a app no
  computador só abre dentro do próprio portal e com o modo activo; início, fim e app mostrada ficam registados
  (ISO/IEC 27001 A.8.15).
- O ambiente «ensaio» passa a chamar-se **pré-produção** em todo o portal, correios e documentação (ISO/IEC 27001
  A.8.31). O endereço `/ensaio` e as chaves `ENSAIO` do `.env` mantêm-se, para não mexer na instalação do servidor.

### Sessão: inactividade real, regresso ao painel e ensaio do OLAP

<!-- paginas: inicio -->

- A sessão termina após **15 minutos sem actividade** (antes 30), com 10 horas no máximo (PCI DSS 4.0 8.2.8; OWASP
  ASVS V7.3; NIST SP 800-63B-4). Só conta o que a pessoa faz (rato, teclado, toque): as actualizações automáticas dos
  dados e a renovação do acesso deixam de manter a sessão aberta sozinhas. A actividade vale para todos os separadores.
- **1 minuto antes**, aviso com contagem decrescente; qualquer gesto mantém a sessão. No fim, a sessão termina no
  servidor, fica registada com o motivo e a página de entrada diz porquê.
- **5 minutos sem actividade**: o portal volta ao painel principal (ecrã limpo, ISO/IEC 27001 A.7.7), salvo com
  trabalho por gravar, uma janela aberta ou a escrever num campo.
- **Ensaio do OLAP**: `ENSAIO_FONTE_<DOMÍNIO>` muda a fonte só no ambiente de ensaio (ex.: `ENSAIO_FONTE_VENDAS=olap`),
  para testar o OLAP antes da produção (ISO/IEC 27001 A.8.31); só as chaves `FONTE_*`.

### Fecho de caixa: contagem final às cegas

<!-- paginas: caixa-marketing -->

- A janela «Validar a caixa» explica que é a **contagem final de todo o dinheiro da caixa** (saldo do dia anterior +
  entregas − depósitos), diferente das contagens de cada entrega, que conferem o promotor.
- **Contagem às cegas**: o saldo esperado e a diferença deixam de aparecer enquanto se conta; o total vem só das notas e
  moedas (saiu o campo de valor solto, sem nome).
- Se a contagem não bater, o portal mostra a diferença e pede **nova contagem**; se se mantiver, pede a explicação, que
  fica registada para a aprovação (controlo de numerário, COSO; ISO/IEC 27001 A.8.15).

### Relatórios por correio (subscrições)

<!-- paginas: relatorios -->

- Num relatório guardado, **«Receber por correio»**: todos os dias, às segundas-feiras ou no dia 1 de cada mês, às 07:30
  (`RELATORIOS_HORA`). Cada pessoa gere só a sua subscrição e não vê as dos outros; o autor vê quantas pessoas o
  recebem.
- O correio leva **o aviso e a ligação** para o relatório, nunca os números: os dados vêem-se no portal, com as
  permissões de quem abre (ISO/IEC 27001 A.5.14). Só recebe quem continua com conta activa, correio, acesso aos
  relatórios e à página da área; cada envio fica registado.

### Criar relatório: explorador de relatórios para todas as áreas do portal

<!-- paginas: relatorios -->

- Nova página **Inteligência de negócio › Criar relatório**. A pessoa escolhe a área de dados, o que quer ver nas
  linhas (até três níveis) e nas colunas, as medidas e os filtros; o portal monta a tabela cruzada com totais e o
  gráfico, e ao clicar numa linha mostra o detalhe.
- **Áreas:** vendas (documentos), vendas por artigo, cobranças, carteira de clientes, cadastros, pedidos dos clientes,
  plano de cargas, stock, requisições, conciliação das carrinhas (rotas e artigos), fecho de caixa (dias e
  movimentos), alertas, equipamentos e suporte. Datas por dia, semana ISO, mês, trimestre, ano ou dia da semana.
- **Os mesmos números dos ecrãs:** as medidas usam as regras da Naval (vendas sem IVA, notas de crédito, cobranças,
  substituições de facturas, clientes pelo NIF). Cada área só aparece a quem vê a página de origem e mostra só as
  rotas dessa pessoa.
- **Relatórios guardados:** pessoais, partilhados com todos (nível G) e **certificados** (selo; certifica outra pessoa
  que não o autor, controlo dos quatro olhos). Guarda-se só a definição, nunca os dados: quem abre um relatório vê os
  números com as suas próprias permissões. Cada relatório tem endereço próprio (para favoritos e partilha).
- **Excel e PDF** com o cabeçalho da Naval, os filtros e a fonte; só para quem pode exportar a área, e cada
  exportação fica registada.

### Camada de fontes: Pinguim BO para hoje, OLAP para o histórico conferido, e «Dados até hh:mm»

<!-- paginas: inicio, conformidade, sap-exportar -->

- **Camada de fontes das vendas** (documentos, linhas e pagamentos): hoje vem sempre do Pinguim BO; os dias anteriores
  podem vir do OLAP, mas só os que têm a carga concluída e foram **conferidos sem diferenças** depois dessa carga. As
  regras do portal (IVA, notas de crédito, anulados, devoluções e substituições) aplicam-se do mesmo modo às duas
  fontes. Escolha no servidor com `FONTE_VENDAS`: `pinguim` (por omissão, nada muda), `paralelo` ou `olap`.
- **Contrato de dados** do OLAP (esquema `rtm`, versão 1.0, em `docs/OLAP-CONTRATO.md`): o portal confirma as colunas
  de hora a hora; sem o contrato completo, lê do Pinguim BO.
- **Conferência diária** dos últimos 7 dias (06:00), por dia e rota: documentos, totais com e sem IVA, linhas,
  quantidades, total bruto e pagamentos por meio. Diferenças vão por correio e aparecem em Conformidade › Vigilância e
  conferência.
- **Disjuntor**: 3 falhas do OLAP em 5 minutos param as consultas durante 5 minutos e o portal lê do Pinguim BO.
- **«Dados até hh:mm»** no topo de cada ecrã, com a hora a que os dados foram lidos da fonte e a fonte (Pinguim BO,
  histórico do OLAP); com mais de 15 minutos nos dados de hoje, aviso a âmbar. O indicador das fontes, no menu, mostra
  também o OLAP e até que dia o histórico está conferido.
- **Ficheiro para o SAP sempre do Pinguim BO** (fonte de registo fiscal): se o histórico do OLAP estiver carregado, a
  página volta a ler as vendas do Pinguim BO antes de gerar. A estrutura do ficheiro não muda.

### Fecho de caixa em PDF

<!-- paginas: caixa-marketing -->

- Depois de validado, o fecho de caixa tem o botão **«PDF do fecho de caixa»**: reconciliação do dia (saldo inicial +
  dinheiro entregue − depósitos = saldo final teórico, contagem física e diferença; o Multicaixa à parte), o dinheiro
  entregue por promotor com as notas, os talões de fecho do TPA, os depósitos no banco (lacre, requisitante e
  aprovador), a conferência por promotor com o Pinguim BO, as assinaturas registadas e o registo de todos os passos.
- Autenticidade (ISO 15489): referência FC-aaaammdd e versão do fecho, código de verificação SHA-256 dos dados, data e
  hora em que foi gerado e «Página x de y». Até à aprovação final sai marcado **«POR APROVAR»**.

## 13-11-2026

### Ligações às bases de dados conforme a ISO/IEC 27001 (Pinguim BO, OLAP e Equipamentos)

<!-- paginas: conformidade -->

- **Certificado do SQL Server validado primeiro**, sempre. Enquanto o servidor não tiver um certificado válido, a
  ligação continua cifrada mas fica assinalada como fora da norma; logo que o certificado seja instalado, o portal
  passa a validá-lo sozinho, sem mexer no .env. `DB_CONFIAR_CERTIFICADO=0` recusa ligar sem certificado válido.
- **Controlador mais recente**: pdo_sqlsrv ou ODBC Driver 18 → 17, o primeiro instalado; o controlador antigo do
  Windows fica só como último recurso, assinalado.
- **Intenção só de leitura** (`ApplicationIntent=ReadOnly`) e **nome da aplicação** (`APP=PortalRTM`), para o DBA
  ver quem faz cada consulta.
- **Tempos limite**: 5 s para ligar e 30 s por consulta (`DB_TEMPO_CONSULTA`; no ODBC limita a espera por bloqueios,
  para nunca atrasar a facturação). Consultas com mais de 5 s ficam registadas para revisão.
- **Administração › Conformidade**: nova tabela «Ligações às bases de dados» com cada controlo, a norma, o estado e
  o que falta fazer no servidor. A verificação diária testa cada ligação e envia por correio o que estiver fora da
  norma.

### «Fecho de caixa», depósitos só quando há, requisições com stock e quantidades sem setas

<!-- paginas: caixa-marketing, requisicoes-carrinha -->

- «Folha de caixa» passa a **«Fecho de caixa»** em todo o portal, na app, nas notificações e nos PDF.
- **Depósitos no banco**: como só acontecem 2 ou 3 vezes por semana, sem depósitos não aparece a tabela vazia, só
  «Sem depósitos neste dia» e o botão «Registar depósito no banco».
- **Requisições — stock do armazém principal**: corrigida a leitura do stock (uma consulta rápida por armazém
  principal, 1150 e 1151, em vez de todos os armazéns e carrinhas, que excedia o tempo); a lista de artigos e cada
  linha voltam a mostrar a quantidade em stock. Se o Pinguim BO não responder, o ecrã diz porquê.
- Campos de quantidade da requisição do tamanho do número, com o stock ao lado.
- **Sem setas** de aumentar e diminuir em nenhum campo numérico do portal e da app.
- A requisição aberta fica na própria página, junto à lista, e já não aparece no menu como se fosse uma página.

### Folha de caixa: mudar só o saldo inicial volta a gravar

<!-- paginas: caixa-marketing -->

- Corrigido: ao mudar **só** o saldo inicial (por exemplo para 0), a folha dizia «Sem alterações» e não gravava. A
  protecção contra assinaturas repetidas comparava já com o saldo novo. Agora grava e assina uma vez; gravar outra vez
  igual continua a não assinar de novo.

### Alertas: assumir, resolver, silenciar e reabrir

<!-- paginas: alertas -->

- Cada alerta passa a ter **tratamento** (gestão de alarmes ISA-18.2 e de eventos ITIL 4, como no ServiceNow e no
  PagerDuty): **assumir** («em tratamento por…», para ninguém fazer o trabalho a dobrar), **resolver** com a nota do
  que foi feito (obrigatória), **silenciar** até uma data (no máximo 30 dias, com o motivo) e **reabrir**.
- Um alerta resolvido cujo problema continua **reabre sozinho** ao fim de 24 horas; o silêncio acaba sozinho na data.
- Filtro **Activos / Tratados / Todos os estados**, e o **histórico** de cada alerta (quem, quando, nota); nada se apaga.
- Na app: assumir e resolver directamente no telemóvel; os números e a lista só contam os alertas por tratar. O resumo
  diário por correio também.

### Termos e listas como nas aplicações de referência

<!-- paginas: requisicoes-carrinha, conciliacao-carrinha, stock-carrinha -->

- «Juntar artigo» passa a **«Adicionar artigo»** (e o mesmo nas mensagens das requisições).
- Nas listas de escolha (rotas, estados…), **«Todos» deixa todas as caixas marcadas**, como no filtro do Excel: desmarcar
  uma deixa as outras. Há uma linha **«Seleccionar todos»** com três estados (todos, alguns, nenhum).
- **Código primeiro e descrição a seguir** em todas as tabelas de artigos (conciliação das carrinhas e o seu PDF,
  stock, confirmação da carga e relatório de vendas), com os mesmos nomes de coluna: «Código» e «Artigo».

### Campos de data: escrever à mão ou escolher no calendário (ISO 8601)

<!-- paginas: inicio, caixa-marketing, conciliacao-carrinha, relatorio-vendas, plano-cargas, carteira -->

- Em todos os campos de data a data **escreve-se** em dia-mês-ano (os hífenes entram sozinhos: 13112026 →
  13-11-2026; também aceita / e . e datas coladas em ISO, 2026-11-13) **ou escolhe-se no calendário** (botão ao lado,
  ou Alt+↓ / F4). ↑ e ↓ mudam um dia; PgUp e PgDn um mês.
- Por baixo, o valor é sempre **ISO 8601** (aaaa-mm-dd), seja qual for o idioma do navegador.
- Datas que não existem ou fora dos limites (ex.: dias futuros na folha de caixa) ficam marcadas, com a explicação, e
  não mudam nada.
- Acessível (WAI-ARIA): o nome do campo, o erro anunciado aos leitores de ecrã e tudo pelo teclado.

### Folha de caixa: campo do saldo inicial do tamanho do valor

- O campo do saldo inicial passa a ter o tamanho do valor (até 15 algarismos), com «Kz» ao lado, em vez de ocupar a
  largura toda.

### App no telemóvel: Face ID sozinho, datas certas e valores com e sem IVA

- **Entrada**: o Face ID (ou a impressão digital) é pedido sozinho ao abrir a app e sempre que ela volta a ficar à
  frente, sem botão. Se o telemóvel não deixar pedir sem um toque (iPhone ao voltar de segundo plano), basta tocar em
  qualquer sítio do ecrã.
- **Aviso «sem vendas desde…»**: compara com o último dia de trabalho do calendário (não com «ontem»), por isso já não
  aparece depois de um domingo, de um sábado à tarde ou de um feriado, nem quando já há vendas hoje.
- **«Última venda»** nunca mostra tempos negativos.
- **IVA como no portal**: o valor principal é sem IVA (desempenho) e por baixo aparece o valor com IVA (facturado ao
  cliente), no dia, na semana, no mês e no «Ao vivo»; o recebido continua com IVA.

### Folha de caixa: saldo inicial pode ser 0

<!-- paginas: caixa-marketing -->

- No primeiro dia, o saldo inicial pode ser 0: campo vazio ou «0» contam como 0,00 Kz (antes, apagar o valor
  mantinha o anterior).
- O campo escreve-se (sem setas), aceita vírgula e não aceita valores negativos.

### Requisições: stock do armazém principal e quantidade sem setas

<!-- paginas: requisicoes-carrinha -->

- A lista de artigos (Enter ou F4) mostra a **quantidade em stock** de cada artigo, a vermelho quando não há.
- Cada linha da requisição tem a coluna **«Stock (armazém principal)»**; a quantidade pedida **nunca pode ser maior**
  do que o stock: fica marcada, não se grava nem se emite (o servidor também recusa, ao gravar e ao emitir).
- O stock é sempre o do **armazém principal** (1150 alimentar e 1151 não alimentar, os do ficheiro para o SAP), nunca
  o dos armazéns das carrinhas.
- A requisição automática da conciliação pede o vendido, mas limitado ao stock do armazém principal (o vendido fica
  como sugerido).
- A quantidade escreve-se (sem as setas para aumentar ou diminuir).

### Substituições de facturas: o acerto do recebido segue para o SAP

<!-- paginas: sap-exportar -->

- Quando o cliente só pagou o valor com desconto, os pagamentos do Pinguim BO (nota de crédito −1 500 000 e factura
  nova +1 480 000 em Multicaixa) corrigem o recebido da factura original: o ficheiro para o SAP leva-os tal como
  estão, sem deixar saldo a crédito ao cliente.
- Nas Cobranças e no ecrã do SAP o acerto aparece à parte («acerto do recebido na factura original»); o dinheiro e o
  terminal do dia não mudam.

### Substituições de facturas: tudo alinhado no portal, na caixa e no ficheiro para o SAP

<!-- paginas: sap-exportar, documentos -->

- **Vendas para o SAP**: os dois documentos (nota de crédito e factura nova) seguem no ficheiro, mas o numerário e o
  Multicaixa são os do portal — a substituição não é movimento de dinheiro (deixa de aparecer Multicaixa −20 000 Kz).
  A estrutura do ficheiro não muda.
- O «Total facturado» do SAP mostra o desconto incluído e uma **conciliação com as vendas**: vendas do portal −
  descontos em substituições = total do ficheiro.
- A mesma regra no fecho do dia por correio, nas notificações, no relatório diário de vendas, na carteira de
  clientes e no dinheiro esperado na folha de caixa.
- No detalhe do documento, os pagamentos tal como estão no Pinguim BO e a explicação da substituição.

### Folha de caixa: uma assinatura por passo

<!-- paginas: caixa-marketing -->

- Carregar várias vezes em «Gravar» já não assina várias vezes: gravar sem alterações não regista nada (nem muda a
  versão) e o botão só envia um pedido de cada vez.
- Na rastreabilidade, as gravações seguidas contam como um só passo «Lançada», com a última hora e o número de
  gravações nas notas; o histórico completo continua guardado (nada se apaga).
- «Juntar linha» passa a «Adicionar entrega», «Adicionar talão do TPA» e «Adicionar depósito».

### Selo «Novo» durante 24 horas no que mudou

- Depois de cada actualização, as páginas que mudaram mostram o selo **«Novo»** no menu (e um ponto azul na área),
  com uma faixa no topo da página a dizer o que mudou.
- O selo **vai esmorecendo**: cheio nas primeiras 6 horas, em contorno até às 12 horas, discreto até às 24 horas, e
  desaparece. Depois de a pessoa abrir a página, passa logo a discreto.
- Sempre com o texto «Novo» (não só a cor) e sem animação para quem pede movimento reduzido.

### Requisições automáticas: o promotor já vem preenchido

<!-- paginas: requisicoes-carrinha -->

- No PDF da requisição, «Requisitado por (promotor)» já traz o promotor da rota (também nas requisições criadas
  pela conciliação da carrinha); fica só a assinatura e a data. Quem emitiu continua no cabeçalho.

### Substituição de facturas de dias anteriores (nota de crédito + factura nova com desconto)

<!-- paginas: pagamentos, vendas-promotor -->

- Uma nota de crédito que anula uma factura de outro dia, com uma factura nova ao mesmo cliente no mesmo dia (por
  exemplo, com um desconto que não tinha sido feito), é reconhecida como **substituição**: não é venda nova nem
  movimento de dinheiro.
- Fica **fora das vendas e das cobranças do dia** (deixa de aparecer −20 000 Kz em vendas, recebido e Multicaixa) e
  o desconto aparece **em positivo** em «Rectificações de documentos anteriores», nas Cobranças e nas Vendas por
  promotor, para conciliar com o Pinguim BO.
- Indicadores, app de gestão, previsão e objectivos seguem a mesma regra (agregados recalculados).
- Os dois documentos continuam nos Documentos de venda, no controlo interno e na AGT tal como no Pinguim BO; o
  **ficheiro para o SAP não muda** (estrutura e valores).

### App de gestão: alinhada com as melhorias de hoje

- **«À sua espera»** (como as caixas de tarefas SAP Fiori e Microsoft Approvals): no Hoje e nos Alertas, só o que a
  pessoa pode fazer agora — folhas de caixa ao nível que falta, depósitos no banco, contar, validar, aprovar e fechar
  as conciliações das carrinhas. Respeita a separação de funções (quem lança, conta ou requisita não aprova); os
  atrasados primeiro. Aprova-se no portal, com a verificação em dois passos e a assinatura registada.
- **Notificações no telemóvel** para a folha de caixa e os depósitos por aprovar, a quem aprova o nível que falta e
  nunca a quem lançou ou requisitou.
- **Vendas sem IVA** também no «Ao vivo», nas rotas e nas últimas vendas (como os indicadores e o portal); o recebido
  continua com IVA.
- **Calendário de trabalho**: horário do dia (ou folga e feriado) no topo; rotas paradas só dentro do horário das
  vendas; fecho «Fora do prazo» depois das 16:00.
- **Rastreabilidade**: na ficha da rota, quem contou, validou e aprovou o fecho (nome, conta, data e hora).

### Requisições: sem rascunhos repetidos nem vazios

- **«Criar» já não regista nada:** só abre o formulário da nova requisição («Por gravar»). A requisição fica registada e
  ganha número apenas ao «Gravar» com pelo menos um artigo com quantidade; «Descartar» fecha sem deixar rasto.
- **Um só rascunho por promotor e dia:** carregar outra vez em «Criar» abre o rascunho que já existe, em vez de
  gastar um número novo.
- A «Nova requisição» deixa de ter o primeiro promotor escolhido por omissão: é preciso escolher o promotor.
- **Rascunhos sem artigos:** os de dias anteriores ficam anulados sozinhos, com o motivo; os de hoje anulam-se de uma
  vez com «Anular rascunhos sem artigos». Ficam registados como anulados (a numeração continua completa e
  auditável).

### Acessos atribuídos sozinhos pelas funções de cada pessoa

- Em **Acessos › Utilizadores › Gerir**, escolhem-se as **funções da pessoa** (ex.: «Finanças e tesouraria» +
  «Lançar e validar a folha de caixa») e o portal **atribui as permissões sozinho**: a maior de cada página entre as
  funções, só o necessário (menor privilégio), com o âmbito (só as suas rotas) e os dois passos que as funções pedem.
  É o controlo de acessos baseado em funções (RBAC, NIST/INCITS 359) das normas ISO/IEC 27001 (A.5.15, A.5.18).
- **Separação de funções:** combinações proibidas são recusadas logo no ecrã e no servidor — lançar e aprovar a mesma
  caixa; contar, coordenar e fechar a mesma conciliação (ISO/IEC 27001 A.5.3).
- Nova secção **Funções** com o catálogo: as funções principais (uma por perfil do sistema) e as funções de aprovação
  e controlo, com o que fazem, as páginas, as incompatibilidades e as contas. O super administrador cria e altera
  funções; ao alterar uma função, **todas as contas com ela ficam actualizadas sozinhas**.
- O perfil de cada conjunto de funções é gerado pelo portal e não se mexe na matriz (só pelas funções); o perfil
  manual continua disponível para casos especiais. O nível A (administrar) nunca vem das funções.

### Vendas líquidas sem IVA e arrumação dos menus (fase 1)

- **Base de valor:** o desempenho de vendas passa a medir-se em **vendas líquidas sem IVA** (facturas − notas de
  crédito, já com os descontos), como mandam as normas de reconhecimento de receita (IFRS 15) e a prática das grandes
  empresas; o valor com IVA aparece ao lado. Cobranças, contas a receber e caixa continuam com IVA (é o que o cliente
  paga).
  - Aplica-se ao Início, às páginas de Vendas, à previsão, aos alertas e ao painel de gestão (os agregados diários
    recalculam-se sozinhos com o valor sem IVA de cada documento, lido das linhas do Pinguim BO).
  - **Objectivos:** os de Novembro, já congelados com IVA, continuam a medir-se com IVA até ao fim do mês; a partir de
    Dezembro calculam-se e medem-se sem IVA (o mínimo crescente converte o mês anterior pela proporção real).
- **Vendas:** a «Classificação» entrou em «Vendas por promotor» (posição, quota, % recebido) e «Vendas por cliente»
  (posição, quota, **classe ABC**, melhor cliente, peso dos 10 maiores, Consumidor Final); «Documentos» passa a
  **«Documentos de venda»**, sem o resumo por rota repetido e com a coluna sem IVA; «Relatório de Vendas» passa a
  **«Relatório Diário de Vendas»**; «Vendas por promotor e marca» sai do menu até haver a marca no Pinguim BO.
- **Finanças:** «Facturas pendentes» passa a **«Contas a receber (antiguidade)»**.
- **Logística**, pela ordem do processo: Cargas planeadas → Requisições → Cargas realizadas → Stock → Conciliação →
  Painel da carrinha («Plano de cargas» e «Registo de cargas» passam a **«Cargas planeadas»** e **«Cargas
  realizadas»**).
- **Controlo interno das vendas** (antes «Histórico de auditoria» em Administração) passa para Inteligência de
  negócio, junto da qualidade dos dados; o histórico de quem fez o quê continua em Acessos › Registos de actividade.
- «Sincronização dos telemóveis» passa para **Integrações**; «Valor do portal» e «Apresentação do portal» passam para
  **Administração**.

### Folha de caixa: circuito de um só nível (Josefa lança e valida, Michael aprova)

- O 1.º nível de aprovação passa a ser opcional e decide-se na matriz de acessos: sem ninguém com «Folha de caixa ·
  aprovação do superior de quem a lança (1.º nível)», quem tem a aprovação final aprova directamente a folha validada.
- Fica assim: **quem lança valida** (Josefa) e **o responsável aprova** (Michael Samson) a folha e os depósitos,
  sempre com segregação de funções (quem lançou ou validou nunca aprova).
- Para voltar aos dois níveis basta marcar de novo o 1.º nível a um perfil na matriz; nada muda no programa.

### Finanças e depósitos no banco com controlo duplo

- A área **Cobranças** do menu passa a **Finanças** (Cobranças, Facturas pendentes e Folha de caixa), como nas
  estruturas de processos de referência (finanças e tesouraria como processo próprio).
- Folha de caixa:
  - o painel «Saídas da caixa» dá lugar a **Depósitos no banco**: banco, **código do lacre** do saco de segurança
    (obrigatório, sem repetições no dia) e valor;
  - **controlo duplo** (prática de tesouraria para valores em trânsito; ISO/IEC 27001 A.5.3, segregação de funções):
    o **requisitante** é quem lança o depósito; o **aprovador** é outra pessoa com nível de aprovação da caixa;
    um depósito aprovado fica bloqueado (não se altera nem se retira) e só quem aprovou pode retirar a aprovação;
  - a folha só é aprovada com todos os depósitos aprovados; cada aprovação fica na rastreabilidade das assinaturas,
    com o banco, o lacre e o valor;
  - a coluna «Nota» passa a **«Nota informativa (facultativa)»**.
- O Relatório de Vendas mostra cada depósito com o banco e o lacre; as saídas antigas de outros tipos ficam como
  estavam.

### Requisição: escolher o artigo só com o teclado

- No campo do artigo, **Enter ou F4 mostra a lista dos artigos activos** (código, nome e disponível no armazém);
  escrever filtra por código ou nome; ↑ ↓ percorrem; **a mesma tecla (Enter ou F4) selecciona**; Esc fecha. Segue o
  padrão «combobox» da WAI-ARIA e a ajuda de valores F4 dos ERP.
- Depois de seleccionar, o cursor passa à quantidade; Enter junta o artigo e volta ao campo do artigo, pronto para o
  seguinte: lança-se a requisição inteira sem tirar as mãos do teclado. O rato continua a funcionar.

### Calendário de trabalho das vendas

- Novo calendário de trabalho, fonte única para os objectivos, as previsões, o relatório, as notificações e os prazos
  do fecho:
  - **segunda a sexta:** vendas das 07:30 às 15:00; fecho de contas com a tesouraria, contagem das carrinhas e
    requisição ao armazém das 15:30 às 16:00; carregamento da mercadoria das 16:00 às 17:00;
  - **sábado:** vendas das 07:30 às 13:00 (meio dia), com o fecho e a carga logo a seguir (13:30–14:00 e
    14:00–15:00); **sábado à tarde e domingo são folga**;
  - **feriados nacionais de Angola** (incluindo Carnaval e Sexta-feira Santa, calculados pela Páscoa) são folga; as
    pontes e outras folgas da empresa configuram-se no servidor.
- **Objectivos:** só contam os dias de trabalho; o sábado vale meio dia (≈ 0,73 de um dia completo) e o ritmo
  compara-se por dia completo equivalente, para um mês com mais sábados ou feriados não parecer mais fraco. O
  «necessário por dia» e a projecção do fecho do mês seguem os mesmos pesos. O objectivo de Novembro, já congelado,
  mantém-se; o novo cálculo aplica-se a partir de Dezembro.
- **Previsões:** dia de folga prevê zero e os feriados passados já não puxam a média do mesmo dia da semana para baixo.
- **Relatório de Vendas:** os telemóveis têm de sincronizar depois do fim das vendas do dia (15:00; 13:00 ao sábado).
- **Notificações:** alertas das rotas só durante as vendas e nunca em dias de folga; resumo do dia depois do fim da
  carga; carrinhas por aprovar a partir do fecho.
- **Prazos no ecrã:** a conciliação mostra o horário do dia e assinala «Fora do prazo do fecho» as carrinhas por contar
  ou validar depois das 16:00; o Stock por carrinha assinala a vermelho a carga por confirmar depois das 17:00.

### Rastreabilidade de quem assinou

- Cada passo assinado no portal mostra o **nome completo e o utilizador** de quem assinou (ex.: «Michael Samson
  (michael.samson)»), com a data e a hora:
  - **Folha de caixa:** nova secção «Rastreabilidade das assinaturas» com todo o histórico (lançada, validada,
    1.º nível, aprovação final e reaberturas com o motivo); nada se apaga.
  - **Resultado da contagem (PDF):** «Registo das assinaturas» com cada passo e as correcções do registo (o que estava
    antes, o que ficou e o motivo); os blocos de assinatura já assinados no portal mostram «Assinado no portal», o
    nome e a data, em vez da linha em branco.
  - **Requisição (PDF):** «Requisitado por» e «Recebido por» com quem emitiu e quem confirmou a carga.
  - **Ecrãs** da conciliação e das requisições com o nome completo.
- O servidor junta às respostas só os nomes de quem aparece nos dados (nunca a lista de contas).

## 12-11-2026

### Cartões empilhados com o espaçamento certo

- A barra do dia, os cabeçalhos e as secções da folha de caixa e do Relatório de Vendas deixam de ficar encostados
  uns aos outros: 16 px entre cartões, com os cantos arredondados inteiros.
- Corrigida uma camada invisível no fundo da folha de caixa (herdada do estilo das apresentações) que podia tapar
  botões ao percorrer a página.

### Folha de caixa: empresa Naval, talão obrigatório e valor do TPA do Pinguim BO

- O dinheiro e os talões dos promotores são sempre da **Naval**: deixa de haver a escolha da empresa nessas linhas
  (continua só nas saídas, para os fundos de maneio de cada empresa).
- O **Nº do talão de fecho** está marcado como obrigatório (*), fica realçado enquanto estiver vazio e a folha não
  grava nem valida sem ele.
- O **valor de cada talão do TPA vem directamente do Pinguim BO** (Multicaixa recebido pelo promotor no dia) e não
  se escreve; a Josefa indica só o banco e o número do talão. Um talão por promotor. Sem ligação ao Pinguim BO, o
  valor escreve-se à mão, para não parar a tesouraria.

### Gráficos em vidro

- Os cartões dos gráficos ganham o acabamento em vidro, ao estilo «Liquid Glass» da Apple: aresta superior
  iluminada, reflexo diagonal suave, sombra em duas camadas e entrada curta. A dica que aparece ao passar o rato
  passa também a vidro.
- Segue as boas práticas de visualização de dados: o vidro fica só na moldura e as barras e linhas continuam sólidas
  e nítidas, com a grelha mais discreta. Funciona igual no tema escuro e no claro.
- Acessibilidade: sem animação para quem escolheu reduzir o movimento, sem transparência para quem a desligou no
  sistema e sem efeitos em cores forçadas; vidro mais leve nos telemóveis.

### Conciliação da carrinha: correcção com a lista de utilizadores

- Em «Corrigir quem contou / validou» já não se escreve nada: escolhe-se o utilizador da lista das contas activas do
  portal (primeiro.último). O servidor recusa nomes que não sejam de uma conta.

### Conciliação da carrinha: correcção do registo de quem contou e validou

- O primeiro passo da conciliação passa a chamar-se «Contado» (no ecrã, no painel e no PDF).
- O super administrador pode corrigir **quem contou** e **quem validou** as contagens de um dia («Corrigir quem
  contou / validou»), por exemplo quando a contagem foi feita por uma pessoa e lançada na conta de outra, ou nas
  contagens anteriores ao passo «Validar».
- Só mudam os nomes: os números, o estado e as aprovações ficam como estão. O motivo é obrigatório e o que estava
  antes fica no histórico de cada carrinha e no registo de auditoria.

### Folha de caixa: quem lança valida, aprovação em dois níveis

- Circuito com segregação de funções, três pessoas diferentes:
  - **quem lança valida** («Validar a caixa», com a contagem por notas);
  - **1.º nível**: o superior de quem lança («Aprovar (1.º nível)»);
  - **aprovação final**: o responsável máximo («Aprovação final»). Só então a caixa entra no Relatório de Vendas.
- Duas permissões novas na matriz de acessos: «Folha de caixa · aprovação do superior de quem a lança (1.º nível)» e
  «Folha de caixa · aprovação final do responsável máximo (2.º nível)». Por omissão o 1.º nível não está atribuído e
  a aprovação final é da Direcção; o super administrador atribui-as no portal aos perfis das pessoas certas.
- Quem lançou ou validou nunca aprova, e quem aprovou o 1.º nível não faz a aprovação final. Os colegas da tesouraria
  e do apoio RTM já não aprovam a caixa uns dos outros. Quem aprova também pode reabrir uma caixa ainda sem a
  aprovação final, com motivo.

### Folha de caixa: contagem às cegas e talão de fecho do TPA

- Segue a prática internacional de **contagem às cegas** (blind count): quem recebe o dinheiro não vê o valor esperado
  antes de contar.
  - Numa folha nova aparece uma linha por promotor com recebimentos no Pinguim BO, **sem valor**.
  - A Josefa só conta as **notas e moedas** de cada entrega. O valor da entrega é sempre o total contado e já não se
    escreve à mão; o servidor recusa entregas sem contagem por notas.
  - Cada talão do TPA exige o **número do talão de fecho**, o banco e o valor, para se conciliar depois com o extracto
    do banco.
  - O valor esperado (Pinguim BO) só aparece na **conferência por promotor**, com as diferenças a vermelho.

### Relatório de Vendas e folha de caixa: carrinhas com vendas, valores sugeridos e contagem por notas

- No Relatório de Vendas, só as carrinhas que **venderam** no dia têm de estar conciliadas e fechadas. As carrinhas
  paradas, sem vendas, já não impedem a emissão.
- A folha de caixa preenche-se toda no portal:
  - numa folha nova, **sugere** uma entrega por promotor com o dinheiro que recebeu no Pinguim BO, e um talão do
    TPA com o Multicaixa. Quem recebe confirma ou corrige cada valor e grava;
  - **contagem por notas** em cada entrega (5000, 2000, 1000, 500 e 200 Kz, mais o valor das moedas; a lista muda
    em `CAIXA_NOTAS`). O valor da entrega passa a ser o total das notas e fica registado quantas notas de cada valor
    se receberam;
  - **conferência por promotor**, ao vivo: dinheiro entregue × dinheiro no Pinguim BO e talões do TPA × Multicaixa,
    com as diferenças a vermelho;
  - o **fecho da caixa** conta também nota a nota: o total passa para o dinheiro contado e a diferença calcula-se
    sozinha.

### Relatório de Vendas: dia sem vendas ou falha de dados

- O controlo do Pinguim BO passa a distinguir as duas situações:
  - **dia sem vendas** (feriado, como o 11 de Novembro): aparece «Completo» com essa explicação;
  - **falha de dados**: se o Pinguim BO não tiver documentos mas as carrinhas tiverem vendido artigos, o controlo
    fica «Sem ligação» e bloqueia o relatório.

### Conversão das visitas

- Nova página **Terreno › Conversão das visitas** (indicadores de execução da distribuição directa):
  - **conversão do plano**: clientes planeados que compraram ÷ clientes planeados, por rota e no total;
  - **vendas fora do plano**: clientes que compraram sem estarem no plano;
  - **peso do plano nas vendas**: valor vendido a clientes planeados;
  - **planeados sem compra**: a lista para o supervisor seguir, os de maior compra média primeiro.
- O plano de cada dia (a lista das Visitas do dia) fica **congelado logo de manhã**. Assim, a medição compara com
  o que estava planeado e não com uma lista que muda durante o dia. Os dias anteriores a esta versão não têm plano
  guardado e ficam assinalados.
- Com o servidor OLAP ligado (localização das visitas), a página mostra também a **cobertura do plano** (planeados
  visitados) e a **conversão das visitas** (visitados que compraram).
- Acesso pela matriz («Conversão das visitas», como as Visitas do dia; supervisores e promotores só nas suas rotas;
  os valores em Kz só para quem vê as vendas).

### Objectivos de vendas automáticos, sempre a crescer

- Os objectivos de cada rota calculam-se sozinhos no início de cada mês, a partir das vendas diárias do Pinguim BO
  (planeamento comercial com base estatística):
  - **base**: previsão de cada dia útil do mês (mesmo dia da semana × tendência), só com os dados até ao fim do mês
    anterior;
  - **crescimento**: base + 5% (`OBJECTIVOS_CRESCIMENTO_ALVO`);
  - **nunca descem**: o ritmo por dia útil nunca fica abaixo do maior entre o realizado e o objectivo do mês
    anterior, mais 3% (`OBJECTIVOS_CRESCIMENTO_MIN`). O ritmo por dia útil torna justa a comparação entre meses com
    números de dias úteis diferentes;
  - arredondados ao milhar e repartidos pelos dias úteis segundo o padrão de cada dia da semana;
  - **congelados** durante o mês (são um compromisso, não um alvo móvel) e guardados para auditoria.
- Página **Previsão de vendas**: objectivo do mês, realizado, ritmo face ao objectivo até hoje, necessário por dia
  útil, projecção do fecho e estado de cada rota (acima, no caminho, abaixo), do mais atrasado para o mais
  adiantado.
- App «Naval Gestão» › Controlo: cartão do objectivo do mês, com o que falta por dia útil e o fecho ao ritmo actual.

### Relatório de Vendas diário e folha de caixa no portal

- Nova página **Vendas › Relatório de Vendas**. Junta num só documento o que vinha em quatro ficheiros (resumo de
  vendas, registo de vendas, folha de caixa e stock), sempre lido das bases de dados:
  - resumo de vendas e cobranças (dinheiro, Multicaixa, por receber, notas de crédito);
  - **vendas e cobranças por promotor**, com a conciliação Pinguim BO × caixa × TPA (diferença do dinheiro e do
    Multicaixa a vermelho);
  - caixa do dia, Multicaixa por banco, registo de vendas linha a linha e stock das carrinhas (inicial, carregado,
    vendido, devolvido, final, contado e diferença);
  - facturação SAP de todas as empresas (Naval, Foodtec, Higitec, Refitech), quando o conector SAP estiver ligado.
- **Fecho do dia antes do relatório**: o relatório só se gera quando todas as fontes do dia estão completas:
  - todos os telemóveis das rotas com vendas sincronizaram depois da hora de fecho (`RELATORIO_HORA_FECHO`, 18:00);
  - todas as carrinhas estão conciliadas e fechadas;
  - todas as vendas do dia estão num ficheiro marcado «carregado no SAP»;
  - a folha de caixa está fechada e aprovada.

  O ecrã mostra o que falta em cada fonte. O conector SAP por ligar não impede a geração.

- Depois de gerado, o relatório fica **congelado**, com número (RV-AAAAMMDD-v1), impressão digital SHA-256 e quem o
  gerou. O PDF sai sempre dessa versão e leva os controlos e as assinaturas. Uma correcção gera uma nova versão, com
  motivo, e a anterior fica guardada.
- Nova página **Cobranças › Folha de caixa** (substitui a folha Excel da tesouraria):
  - dinheiro entregue por promotor, talões do TPA por banco e saídas (depósito, fundo de maneio por empresa,
    transferência para a gestão, outras);
  - saldo inicial igual ao saldo aprovado do dia anterior, e saldo teórico calculado ao vivo;
  - fecho com a contagem física, em que uma diferença tem de ser explicada;
  - aprovação por outra pessoa que não quem registou ou fechou; reabertura com motivo enquanto não estiver aprovada.
- **Conector SAP S/4HANA (OData, só leitura)** preparado, com a facturação do dia de todas as empresas pelo serviço
  normalizado `API_BILLING_DOCUMENT_SRV`. Fica «por ligar» até a equipa SAP dar um utilizador técnico só de leitura
  (`SAP_ODATA_URL`, `SAP_ODATA_UTILIZADOR`, `SAP_ODATA_SENHA`, opcional `SAP_ODATA_CLIENTE`, no `.env`).
- Matriz de acessos: «Relatório de Vendas» (Direcção, Comercial, Financeiro e Auditoria geram e exportam) e «Folha de
  caixa» (Financeiro e Apoio RTM registam; Direcção, Comercial e Auditoria vêem).

### Requisições de reposição da carrinha ao armazém

- Nova página **Logística › Requisições ao armazém**. Quando quem contou valida a contagem da carrinha, o sistema
  cria sozinho uma requisição em rascunho com as quantidades vendidas nesse dia (reposição pelo consumo). Só há uma
  por rota e dia, e a conciliação mostra a ligação para ela.
- **Rascunho**: o promotor muda as quantidades, retira artigos, junta outros (lista dos armazéns, com o disponível)
  e escreve uma nota para o armazém. Pode gravar e voltar a editar quantas vezes precisar. Se duas pessoas gravarem
  ao mesmo tempo, a segunda é avisada e não apaga o trabalho da primeira.
- **Gravar, emitir e imprimir**: pede confirmação, fecha o documento e abre o PDF para imprimir. Também se pode
  gravar e emitir mais tarde. Depois de emitida, a requisição já não se altera; para mais mercadoria, faz-se uma
  nova. Cada reimpressão sai marcada «2.ª via» e fica registada. Um rascunho que não vai ser usado anula-se, com motivo.
- O PDF tem o número sequencial (RQ-AAAA-000001), a origem, o vendido, o requisitado e uma coluna «Entregue» para o
  armazém, e as assinaturas de quem requisita, de quem avia e de quem recebe.
- **Stock por promotor / carrinha**: nova coluna **Requisição ao armazém**. Mostra a âmbar, a tracejado, o que vai
  ser carregado (requisição emitida) e, depois de o armazém carregar em **Confirmar carga** (com a quantidade
  entregue de cada artigo), passa a azul com ✓. Os artigos requisitados que ainda não estão na carrinha também
  aparecem. Quem emitiu a requisição não confirma a sua própria carga.
- Nova linha na matriz de acessos «Requisições ao armazém»: o promotor e o supervisor gerem as das suas rotas e a
  logística gere todas; os outros perfis só vêem.

### Conciliação da carrinha: folha de contagem às cegas, PDF do resultado e aprovação em dois níveis

- **Imprimir folha de contagem** (às cegas): PDF A4 com a referência da contagem (CC-rota-data), os artigos, o código
  e a unidade, e espaço para escrever a quantidade contada e as observações. Não leva nenhuma quantidade do sistema.
  Tem a hora de início e de fim, a assinatura de quem conta e de quem acompanha (o promotor), e «Página x de y».
- **PDF da contagem**: depois de gravada, a mesma tabela do ecrã (inicial, carregado, vendido, devolvido, tem de
  voltar, contado, diferença, valor e motivo), o resumo (faltas e sobras), o circuito de aprovação (quem e quando) e
  quatro assinaturas.
- **Circuito de aprovação com segregação de funções**: quem contou confere e **valida** (ou reconta); o
  **coordenador** aprova, com o motivo de cada diferença (1.º nível); por fim, o **responsável** aprova e a
  conciliação fica **fechada** (2.º nível). A mesma pessoa não pode fazer dois passos (o servidor recusa). Quando não
  há diferenças nem alertas, a contagem validada continua a fechar sozinha.
- Novas linhas na matriz de acessos: «Conciliação da carrinha · aprovação do coordenador (1.º nível)» (Logística) e
  «… aprovação final do responsável (2.º nível, fecha)» (Direcção e Financeiro). O SADM pode mudar quem tem cada uma.
- As colunas têm cores por tipo, no ecrã e no PDF: sistema (Pinguim BO) em cinza-azulado, contagem física em azul,
  diferença em âmbar. As faltas aparecem a vermelho e as sobras a azul, com legenda.
- Painel da carrinha, app «Naval Gestão», notificações e aviso do fim do dia passam a conhecer os passos novos.
- **A actualização automática já não apaga o que se está a escrever**: cada número da contagem fica guardado como
  rascunho neste navegador até se gravar (e volta se a página redesenhar ou abrir de novo). Enquanto houver dados por
  gravar, o portal não actualiza sozinho e a app não recarrega com uma versão nova. Ao fechar ou recarregar o
  separador com dados por gravar, o navegador pede confirmação. Os rascunhos com mais de 7 dias apagam-se sozinhos.

### Novo relatório «Vendas por promotor e artigo»

- Portal › Vendas › **Vendas por promotor e artigo** (análise do mix de vendas de cada promotor, com as linhas de
  venda do Pinguim BO no período, já descontadas as notas de crédito):
  - **Matriz promotor × artigo**: valor s/ IVA de cada promotor nos 8 artigos que mais vendem na empresa (mapa de
    intensidade numa só cor azul), restantes em «Outros artigos», e o peso no promotor ao passar o rato.
  - **Sortido por promotor**: artigos vendidos, artigos A (80% do valor, análise ABC), artigo principal e o seu peso,
    clientes (distribuição numérica), documentos, artigos por documento e peso na empresa.
  - **Lacunas de sortido**: dos 10 artigos mais fortes da empresa, os promotores que não os venderam no período
    (oportunidades de venda por rota).
  - **Detalhe por promotor e artigo**: quantidade, preço unit. s/ IVA (média ponderada) e diferença face ao preço
    médio da empresa, desconto e % de desconto, valor s/ e c/ IVA, peso no promotor, quota no artigo, classe ABC,
    clientes e presença nos documentos.
- Filtros de rota, cliente, artigo e tipo de documento; Excel desta página; português, inglês e tigrínia; perfis com
  o mesmo acesso que «Vendas por promotor» (SADM e TI podem ajustar em Acessos).

### App no idioma do telefone, e notificações também

- A app «Naval Gestão» abre no idioma do telefone (Definições › Geral › Idioma e região), entre português, inglês e
  tigrínia, pela ordem de preferência do telefone; muda sozinha quando o idioma do telefone muda. Num idioma que a app
  não tem, fica o da conta no portal (e, sem esse, o português).
- As notificações chegam a cada telefone no idioma dele (o telefone diz ao servidor o idioma ao ligar as
  notificações e sempre que muda); a caixa de recebidas guarda os textos nos três idiomas e mostra-os no idioma actual.

### Recebido por promotor em dinheiro e em Multicaixa, e separador «Controlo» na app

- Portal › Pagamentos: nova tabela **Recebido por promotor** (vendido, recebido, em dinheiro, em Multicaixa, outros
  meios, % em dinheiro, por receber e documentos). O cartão «Vendas por promotor» do Início mostra também o dinheiro
  e a Multicaixa de cada um. A tabela por meio passa a chamar-se «Por promotor e meio de cobrança».
- As regras dos meios de cobrança ficam num só módulo (`reports/js/meios.js`), partilhado pelo portal, pela app e
  pelo ficheiro para o SAP (sem alterar o ficheiro: o teste de referência confirma).
- App: o cartão «Ao vivo» mostra o recebido de hoje, em dinheiro e em Multicaixa; a ficha da rota mostra o recebido
  hoje dessa rota por meio e o que falta receber.
- App: novo separador **Controlo** com o fecho previsto do mês (vendido + habitual dos dias que faltam, face ao mês
  passado), o recebido hoje por rota em dinheiro e Multicaixa, os clientes do mês (com compras, novos, por recuperar e
  valor em risco, com os maiores), os artigos que mais vendem, a produtividade de cada rota (dias, documentos por
  dia, valor médio, clientes, notas de crédito) e os riscos (peso dos 5 maiores clientes, da maior rota e das notas
  de crédito). Calculado no servidor (`nucleo/servicos/controlo.php`) a partir dos mesmos dados do Pinguim BO.

### App «Naval Gestão»: rotas ao vivo com o stock de cada carrinha, organização e ícone novos

- Corrigido: o separador «Agora» mostrava «Tentar de novo» porque esperava as vendas num formato diferente do que o
  servidor devolve. O teste passa a usar a resposta real do servidor.
- Organização como nas apps de referência (quatro separadores, conteúdo onde se procura):
  - **Hoje** abre com o cartão «Ao vivo» (vendas de hoje, documentos, valor médio, última venda);
  - **Rotas** abre em «Agora» (também «Hoje» e «Mês até hoje»): estado de cada rota, paradas primeiro, stock na
    carrinha e quantas carrinhas faltam fechar;
  - tocar numa rota abre a **ficha da rota**: estado, vendas de hoje, ontem e do mês, **stock na carrinha neste
    momento** (artigo a artigo, do Pinguim BO), fecho do dia, últimas vendas e os últimos 14 dias;
  - **Alertas** com uma linha «Notificações» que abre as opções numa folha própria, e as recebidas por baixo.
- Ícone novo da app no estilo da Apple: o mesmo logótipo da Naval (globo de faixas e crescente) em 3D, com volume,
  reflexos e sombra suave sobre fundo claro (versões para iPhone, Android e ícone adaptável; fonte em
  `app/icones/icone-fonte.svg`). No iPhone, o ícone de uma app já no ecrã principal só muda ao voltar a adicioná-la.

### Notificações no telemóvel, como no WhatsApp, mesmo com a app fechada

- Web Push com as normas abertas (RFC 8030, 8291 e 8292): o servidor do portal cifra e assina cada mensagem e entrega-a
  ao serviço de push do próprio telefone (Apple, Google, Microsoft, Mozilla), sem serviços de terceiros. A cifra foi
  conferida com a biblioteca de referência. No iPhone, com a app no ecrã principal (iOS 16.4 ou mais recente).
- Tipos: rota parada (mais de uma hora sem vender, ou sem vendas às 10:30), venda anulada, carrinha por aprovar,
  diferença na conferência com o Pinguim BO, problema em produção, actualizações do portal e resumo do dia às 18:30.
  Cada pessoa só recebe o que a matriz de acessos lhe permite ver, e quem trabalha por rota só as das suas rotas.
- Na app, em Alertas: ligar com um toque (a autorização só é pedida nesse momento), escolher o que recebe, horas de
  silêncio (20:00–07:00, excepto as críticas), enviar um teste e desligar neste telefone. As recebidas ficam numa
  caixa, com o número de por ler no ícone da app; ao tocar numa notificação a app abre no separador certo.
- Nunca repete a mesma notificação; subscrições retiradas pelo telefone saem sozinhas; só aceita serviços de push
  conhecidos em HTTPS.

### App «Naval Gestão» no telemóvel: separador «Agora» com a operação em tempo real

- Novo separador **Agora**, entre «Hoje» e «Rotas», como nos painéis ao vivo das apps de referência (Shopify Live View,
  Uber Fleet, Salesforce Field Service). Mostra as vendas do dia com documentos, valor médio e tempo desde a última
  venda; as rotas por estado, com as paradas há mais de 60 minutos e as sem vendas em primeiro; o fecho das carrinhas
  (por contar, por aprovar, fechadas); e as últimas 15 vendas, com as notas de crédito assinaladas.
- Actualiza-se a cada minuto enquanto o separador está aberto e a app está no ecrã. Só aparece a quem tem acesso às
  vendas; o fecho das carrinhas só a quem tem a conciliação.

### Conciliação da carrinha: só pede contagem quando há alguma coisa para contar

- Um artigo que não devia estar na carrinha no fim do dia e não teve vendas (por exemplo stock do dia anterior
  retirado ou corrigido no Pinguim BO) deixa de entrar na conciliação. Uma carrinha só com esses artigos fica «sem
  movimento» e deixa de aparecer «Por contar» com o botão «Contar na conciliação». Artigos com alertas ou já contados
  continuam sempre.

### Organização das páginas de administração (Conformidade e Módulos)

- Páginas com muitos relatórios passam a ter dois níveis de separadores (arquitectura da informação: no máximo 5 a 7
  por nível): grupos em cima e, dentro do grupo, separadores secundários sublinhados. Em ecrãs largos os separadores
  dobram para a linha seguinte em vez de ficarem escondidos à direita.
- Conformidade organizada em quatro grupos: Resumo, Sistema de gestão (SGSI), Vigilância e conferência, Contas e
  dados. Os indicadores e botões de cada grupo só aparecem com ele, sem sobreposições.
- Módulos em lista, como nas consolas de administração (Microsoft 365, Atlassian, Google Workspace): uma linha por
  módulo com nome, resumo, versão, estado, páginas, conectores e interruptor; a ficha completa abre ao passar o rato ou
  com «Detalhes» (teclado e ecrãs tácteis). Pesquisa, filtro por estado e grupos «Base da plataforma» e «Áreas de
  negócio».
- Conectores num catálogo de integrações próprio: estado, categoria, fornecedor, protocolo, sentido dos dados, módulos
  que o usam e, nos que faltam, a definição a configurar no servidor.

### Fiabilidade dos dados: armazém de cada carrinha, conferência diária e ficheiro para o SAP congelado

- Stock por carrinha: cada carrinha mostra de que armazém(ns) do Pinguim BO vem o stock (código, nome e se foi
  reconhecido pelo código 100010NN ou pelo nome) e avisa quando junta mais do que um. O super administrador e a
  informática confirmam o armazém certo com um clique; a partir daí só esse código conta para a carrinha, sem procurar
  no nome, e qualquer outro armazém com «10NN» no nome passa para o stock por armazém.
- Conferência diária com o Pinguim BO: todas as madrugadas, para o dia anterior, a vigilância compara por rota o
  número de documentos e o valor de venda do Pinguim BO com o que o portal mostra. Uma diferença de um documento ou de
  mais de 1 cêntimo abre um problema (GitHub e correio); o resultado fica em Administração › Conformidade.
- Ficheiro para o SAP congelado: um teste gera o livro com dados fixos e compara cada parte, carácter a carácter, com o
  ficheiro de referência (`tests/referencia/sap/`). Qualquer alteração à estrutura trava a versão nas verificações.

## 11-11-2026

### Vigilância em produção e reposição automática

- Cada excepção ou erro fatal do PHP fica registado sem dados pessoais (e-mails, NIF e valores saem da mensagem) e
  agrupado por assinatura. De 15 em 15 minutos a vigilância junta as falhas e as respostas 5xx da API; de hora a hora
  confirma que o Pinguim BO responde e que, havendo documentos de venda no Pinguim BO, o portal os mostra (o defeito de
  07-11-2026 não dava erro nenhum).
- Cada problema novo vai para o GitHub como «issue» com a etiqueta `vigilancia` (lida pela correcção automática da
  madrugada) e por correio a `ACTUALIZACAO_EMAIL`. Falta de permissão da GitHub App aparece no portal.
- Na primeira hora depois de uma actualização, se as falhas dispararem ou as vendas deixarem de aparecer, o portal
  volta sozinho à versão anterior (uma vez por versão) e avisa por correio.
- Nova secção em Administração › Conformidade com os problemas, as verificações e a ligação ao GitHub.
- O Apache recusa chaves e certificados (`.pem`, `.key`, `.pfx`…), arquivos (`.zip`, `.rar`, `.7z`…) e notas em texto
  deixados na pasta do site; as licenças das fontes continuam disponíveis.

### Publicação sem intervenção humana, mesmo com falhas do GitHub

- Quando as verificações do GitHub falham ou são canceladas por razões passageiras (fila sem servidores de teste,
  espelho do Ubuntu em baixo), repetem-se sozinhas até 3 vezes, ao fim de 5 e de 15 minutos. Execuções substituídas
  por uma alteração mais recente não se repetem. Um defeito verdadeiro continua a bloquear a versão.
- As verificações correm em `ubuntu-24.04` fixo em vez de `ubuntu-latest`: durante a migração do GitHub para o
  Ubuntu 26, os servidores `ubuntu-latest` deixaram de aceitar trabalhos («not acquired by Runner») e as versões
  ficavam por publicar.

### Ensaio fiável no servidor Windows

- Ao activar a versão no ensaio, o Windows pode recusar a mudança de nome da pasta enquanto o antivírus ou o indexador
  lêem os ficheiros acabados de escrever («Não foi possível activar a pasta nova do ensaio»). O actualizador tenta de
  novo com espera crescente (cerca de 30 s) e, se mesmo assim não der, copia a pasta. A mensagem passa a dizer o erro do
  sistema.
- Uma falha da instalação no ensaio repete-se sozinha até 3 vezes, de 5 em 5 minutos; falhas dos testes de fumo não se
  repetem. Novo botão **«Repetir no ensaio»** em Actualizações.

### Vendas de novo visíveis: a regra dos documentos anulados corrigida

- Desde 07-11-2026 o portal tirava das vendas os documentos com `DOCSTATUS_ID = 8002`. A verificação no Pinguim BO
  mostrou que **todos** os documentos (facturas, facturas-recibo e notas de crédito) têm esse estado: é o estado normal
  de um documento fechado. As vendas, o Início, os pagamentos e o ficheiro para o SAP ficavam a zero, e o ecrã da AGT
  marcava tudo como anulado.
- Um documento conta agora como anulado quando está eliminado (`DELETED = 1`) ou tem o **motivo de cancelamento**
  preenchido no Pinguim BO (o mesmo campo que o ecrã da AGT já lia, `PINGUIM_ANULACAO_EXTRA_ID`, 26003 por omissão). Se
  o Pinguim BO tiver um estado próprio de anulado, define-se em `PINGUIM_ESTADO_ANULADO`, sem mexer no código.

### App no telemóvel: Face ID sempre que a app volta a abrir

- Ao terminar a app e abri-la de novo, a sessão anterior termina e a entrada pede o Face ID (ou a impressão digital),
  como nas apps dos bancos. Voltar de segundo plano não pede. Fica registado («sessão terminada: app reaberta»).

### Módulos com interruptor

- Cada módulo tem um interruptor ligado/desligado no topo do cartão, como nas definições do telemóvel (com motivo e
  confirmação da identidade). O piloto fica ao lado («Pôr em piloto» / «Ligar para todos»). O núcleo mostra o
  interruptor bloqueado: nunca se desliga.

## 09-11-2026

### Tabelas com o aspecto das referências internacionais

- O cabeçalho das tabelas deixa de ter a faixa azul: título, colunas e linhas ficam na mesma superfície do cartão,
  separados só por uma linha (prática das tabelas de dados da Apple, do Material Design e do IBM Carbon). Nomes das
  colunas em letra normal, mais legível do que em maiúsculas. Procurar e Excel ficam na mesma linha do título.
- Ao descer numa tabela longa, o cabeçalho continua preso no topo e só então ganha a cor do cartão e uma sombra suave.

### Valor do portal para a Naval

- Nova página **Inteligência de negócio › Valor do portal**: quanto o portal rende à Naval, mês a mês, com contagens
  reais (rotas conciliadas e fechadas sozinhas, faltas de stock aprovadas, ficheiros SAP, relatórios exportados,
  utilizadores e documentos anulados no Pinguim BO) e parâmetros definidos pela Direcção no portal (custo da hora,
  minutos poupados, perda de referência, licença equivalente, reconstrução e câmbio). O que falta definir fica à vista
  e não entra no total; os anulados são valor corrigido, mostrado à parte. Direcção altera (G), Informática,
  Financeiro e auditoria vêem.

### Sistema de gestão da segurança da informação (ISO/IEC 27001) completo

- **Declaração de aplicabilidade** com os 93 controlos da ISO/IEC 27002:2022 (37 organizacionais, 8 de pessoas, 14
  físicos, 34 tecnológicos): se se aplicam, estado, evidência, responsável e o que falta.
- **Registo de riscos e plano de tratamento** (probabilidade × impacto, aceitável até 6), incluindo a falta de
  segregação de funções com uma só pessoa a desenvolver, aceite com controlos compensatórios.
- **Registo das actividades de tratamento (RoPA)** para a Lei n.º 22/11 e a ISO/IEC 27701.
- **Objectivos de segurança medidos pelo próprio portal**, programa de auditoria interna e revisão pela direcção.
- **Manual do SGSI** (SGSI-MAN-01, PT e EN): política de segurança e os procedimentos de acessos, incidentes, cópias e
  continuidade, desenvolvimento seguro, fornecedores, utilização aceitável, dados pessoais e não conformidades.
- **Aprovação de cada documento pelo super administrador**, com o resumo SHA-256 da versão aprovada.
- **Não conformidades** no Suporte: só se resolvem com a causa e a acção correctiva. Pedidos fechados há mais de 3 anos
  são apagados.

## 08-11-2026

### Conformidade com as normas ISO, suporte e incidentes

- **Registos invioláveis** (ISO/IEC 27001 8.15): cada linha dos registos leva o SHA-256 da anterior; a verificação
  diária encontra alterações, linhas apagadas e cortes, guarda um selo do dia e copia os registos para fora do servidor.
- **Contas sem uso** desligam-se sozinhas ao fim de 90 dias (nunca o super administrador) e **revisão de acessos
  trimestral** confirmada pelo super administrador (ISO/IEC 27001 5.18).
- **Cópia diária cifrada** dos dados privados (AES-256-GCM) com **teste de recuperação** automático (ISO 22301).
- **Prazos de retenção** por tipo de dado, com eliminação automática (ISO/IEC 27701, Lei n.º 22/11).
- **Ensaio** com os dados pessoais mascarados e apagado quando a versão chega à produção (ISO/IEC 27001 8.33).
- **Componentes e licenças**: inventário das bibliotecas, SBOM CycloneDX e proveniência em cada versão (OpenChain).
- **Suporte e incidentes** (Administração › Suporte): pedidos com prioridade e prazo, histórico, causa e lições nos
  incidentes graves e satisfação; incidente de segurança avisa logo o super administrador (ISO/IEC 20000-1, 27035).
- Nova página **Administração › Conformidade** com a prova de todos os controlos. Documentação: `docs/CONFORMIDADE.md`.

## 07-11-2026

### Portal por módulos (peças de lego), ambiente de ensaio e painel da carrinha

- **Módulos:** o portal passa a ser um monólito modular (o modelo da Shopify): cada área de negócio é uma peça com ficha
  própria (`nucleo/modulos/*.json`: páginas, rotas da API, funções da matriz, conectores, eventos, dependências e
  versão). Em Administração › Módulos, o super administrador põe cada módulo **ligado**, **piloto** (só SADM e TI,
  para validar antes de ligar para todos) ou **desligado**, com confirmação da identidade e registo. O núcleo nunca se
  desliga. Conectores declarados para o Pinguim BO, OLAP, Google Maps, MDM, SAP, IA, GitHub, Quatenus e CCTV.
- **Ensaio dentro do portal** (só SADM e TI, sem outro endereço): cada versão nova instala-se primeiro em
  `/ensaio/`, ao lado da produção, com dados privados próprios e testes de fumo automáticos; só se aprova a versão que
  passou no ensaio (também a aprovação automática das 02:45). Faixa «AMBIENTE DE ENSAIO», ficheiro do SAP marcado
  `ENSAIO_NAO_CARREGAR_`, correio só para os administradores, Equipamentos só a ler. `ENSAIO=0` desliga.
- **Painel da carrinha** (Logística): por carrinha e dia, o fecho de rota, a carga e a origem dos dados (contagens da
  solução do Pinguim BO, Quatenus e CCTV preparados). **Fecho por excepção:** a contagem igual ao esperado em todos
  os artigos, sem alertas, fecha sozinha; só as diferenças vão a aprovação, com motivo.
- Documentação: `docs/MODULOS.md`.

### Documentos anulados fora das vendas

- Os documentos anulados no Pinguim BO (`DOCHEADER.DOCSTATUS_ID = 8002`) deixam de contar nas vendas, nas linhas de
  artigos e nos pagamentos, em todos os ecrãs e exportações (antes só ficavam de fora os eliminados, `DELETED = 1`).
  O estado é configurável no `.env` (`PINGUIM_ESTADO_ANULADO`). No ecrã da AGT, o estado 8002 passa também a marcar o
  documento como anulado.
- Stock: sem alterações — o stock, as entradas e as saídas mostram-se exactamente como no Pinguim BO (as funções do
  WinRest/Pinguim BO já devolvem ao armazém o stock dos documentos anulados).

### Vendas para o SAP com os valores do Início

- O total facturado, o numerário e o Multicaixa da página são os do Início, e são exactamente esses que seguem no
  ficheiro: as notas de crédito seguem como documentos próprios, a negativo, e o numerário e o Multicaixa seguem a
  mesma regra do Início. A estrutura do ficheiro não muda (uma folha, as mesmas colunas, os mesmos formatos).

### Falhas momentâneas da rede: o portal repete sozinho

- Com `ERR_NETWORK_CHANGED` (troca de Wi-Fi ou de rede móvel, VPN a ligar, computador a acordar), o pedido do token
  e as leituras repetem-se até 3 vezes, com espera crescente (0,4 s, 1,2 s e 3 s), antes de aparecer «Sem ligação
  ao servidor do portal». As gravações não se repetem sozinhas, para não ficarem em dobro.

### Capacidade: 50 pessoas ao mesmo tempo já, caminho para 1000

- **Medido com teste de carga** (`ferramentas/carga.mjs`): 50 pessoas com p95 abaixo de 30 ms e a carga de ~1000
  pessoas (150 pedidos/s) sem erros. Plano completo, objectivos de serviço e pedido ao departamento de TI em
  `docs/CAPACIDADE.md`.
- **Sessões** na pasta privada do portal e limpas só depois de 60 minutos (o valor do PHP, 24 minutos, terminava
  sessões activas antes dos 30 minutos de inactividade).
- **Entrada:** limite por IP de 20 para 100 falhas em 15 minutos (muitas pessoas partilham o IP da empresa ou da rede
  móvel); cada conta mantém o seu limite.
- **Apache** (aplicado pelo actualizador, com validação e reposição): 600 fios de execução, ligações persistentes,
  HTTP/2 quando o módulo existe e recomendações para Windows.
- **PHP** (aplicado pelo actualizador, com cópia do php.ini): OPcache ligado, cache de caminhos e memória — melhor
  esforço, nunca pára a actualização.
- **Observabilidade:** pedidos lentos e erros do servidor registados em `registos/desempenho-AAAA-MM.jsonl`;
  `GET api/index.php/v1/saude` para a monitorização e o futuro balanceador de carga.

## 06-11-2026

### App no telemóvel: máxima rapidez na instalação, na entrada e na abertura

Medido com rede 4G lenta simulada (latência de 150 ms, 1,6 Mbit/s) e processador 4 vezes mais lento:

| Passo                                               | Antes                     | Agora                      |
| --------------------------------------------------- | ------------------------- | -------------------------- |
| Página de instalação (passos visíveis)              | 1,5 s · 10 pedidos        | 0,6 s · 2 pedidos          |
| Primeira abertura até ao botão do Face ID           | 1,3 s · 10 pedidos        | 0,6 s · 4 pedidos          |
| Abrir a app com a sessão terminada até estar dentro | 4,5 s · 2 ecrãs e 1 toque | 1,6 s · Face ID automático |

- **Páginas da app num só pedido:** estilos, tema e funções dentro da página, letra do sistema do telemóvel (sem
  descarregar letras), passos do sistema (iPhone ou Android) mostrados logo pelo servidor e tradução descarregada só
  quando o idioma não é o português.
- **Entrada rápida com Face ID:** ao abrir a app com a sessão terminada, a biometria aparece logo (como nas apps dos
  bancos), com o desafio já dentro da página; o Face ID da activação também já vem na resposta da activação.
- **App de gestão:** módulos com versão no endereço, guardados no telemóvel até mudarem (abrir a app já não volta a
  pedir cada ficheiro), pré-carregamento de toda a cadeia de módulos, estilos dentro da página, token de acesso já
  na página e os pedidos da sessão e dos indicadores ao mesmo tempo. Os números aparecem sem esperar pelo estado do
  Pinguim BO. O portal também passa a levar o token de acesso na página.
- O service worker regista-se depois de a página aparecer, sem disputar a rede.

### App no telemóvel: nunca pede a palavra-passe

- Na app só se entra com a chave do telemóvel: Face ID; sem Face ID, a impressão digital; se a biometria falhar, o
  próprio telemóvel pede o seu código de bloqueio. A página da palavra-passe nunca aparece dentro da app.
- O Face ID passa a ser obrigatório na activação (sem «Agora não»); se a activação ficar a meio, a app volta a
  pedi-lo antes de abrir. Um telemóvel sem Face ID, impressão digital nem código de bloqueio não activa a app e
  explica como activar a protecção nas Definições (o código QR não se gasta).
- O logótipo da Naval roda nas páginas da app enquanto se espera (como no arranque do portal) e pára quando a
  página fica pronta.
- No menu da conta, «App no telemóvel» aparece a verde enquanto a pessoa não tem a app e a vermelho quando já a tem
  activa num telemóvel.

### App no telemóvel: actualizações transparentes

- A app vem sempre do servidor: cada versão instalada no portal chega aos telemóveis sem App Store, sem descarregar
  nada e sem pedir nada à pessoa.
- Se a versão mudar com a app aberta, a app recarrega-se sozinha quando volta ao ecrã (nunca a meio de uma pergunta
  ao assistente nem com uma janela aberta). A sessão do portal passa a indicar a versão instalada.

### App no telemóvel: instalação só pelo código QR, equipamento registado e entrada com Face ID

- **Instalar pelo código QR:** no portal, o seu nome › «App no telemóvel» mostra um código QR pessoal (uso único,
  válido 10 minutos). A câmara do telemóvel abre a página de instalação, que reconhece o sistema: no Android, um
  botão «Instalar a app»; no iPhone, os toques Partilhar › Adicionar ao ecrã principal (a Apple não deixa um site
  instalar apps sozinho). A app instalada é a do perfil: Naval Gestão ou Portal RTM.
- **Só por este método:** o portal, a página de entrada e a app de gestão deixaram de oferecer a instalação; uma app
  adicionada de outra forma, ou aberta no navegador, não funciona e explica como instalar pelo código QR.
- **Registo:** ao abrir pela primeira vez, a app fica activada nesse equipamento (quem, que app, sistema e modelo,
  data, última utilização) e a pessoa recebe um aviso por correio. Um equipamento activo por pessoa: activar noutro
  telemóvel desactiva o anterior, que termina a sessão. O equipamento renova-se com o uso e expira após 90 dias sem
  uso. Em Acessos, a lista «Apps no telemóvel» mostra todos os equipamentos, com o botão Desactivar.
- **Entrada com Face ID:** chaves de acesso FIDO2/WebAuthn («passkeys»). O telemóvel usa o método mais forte que tiver:
  Face ID; sem Face ID, a impressão digital; sem biometria, o código de bloqueio do telemóvel. A biometria nunca sai
  do telemóvel: o servidor guarda só a chave pública e confirma a assinatura. Sem nenhum destes, entra-se com a
  palavra-passe e o código de verificação.
- Testes novos: código QR, activação, biometria (autenticador virtual), substituição do equipamento, desactivação
  pelo administrador e bloqueio da app instalada sem o código.

### App «Naval Gestão» no telemóvel: novo desenho, segundo as apps de gestão de referência

- Desenho inspirado nas melhores apps de gestão (Shopify, Stripe, Power BI, Salesforce, SAP Fiori) e nas regras da
  Apple e da Google para telemóveis: título grande, barra de separadores em baixo com o número de alertas, alvos de
  toque de 44 px, tema claro e escuro, puxar para actualizar e esqueleto durante a leitura.
- **Hoje · Semana · Mês:** um número principal com a comparação ao lado (seta e sinal, não só cor) e um gráfico — barras
  dos últimos 14 dias, semana actual sobre a semana passada, ou acumulado do mês contra o mês passado.
- **Precisa de atenção:** os três alertas mais importantes logo no início, com atalho para a lista completa.
- **Indicadores:** ontem face ao habitual, compras de clientes, valor médio e artigos por compra, com a variação.
- **Rotas:** classificação do mês ou de hoje, com resumo (a crescer e a cair); ao tocar numa rota abre o detalhe numa
  folha que sobe do fundo (hoje face à previsão, ontem, mês, mês passado e gráfico de 14 dias).
- **Dados fiáveis à vista:** hora da actualização com o estado do Pinguim BO no topo e aviso claro quando os valores
  podem estar incompletos (Pinguim BO sem ligação, sem internet ou dias sem vendas registadas).
- A API v1/indicadores passa a enviar a série diária desde o início do mês anterior, os últimos 14 dias de cada rota e
  o último dia com vendas.
- Testes novos da app no telemóvel (claro e escuro, ecrã pequeno sem nada cortado) e da série da API.

### Apresentação ao Conselho: projecto estratégico «Frota ligada» (GPS Quatenus e CCTV do parque)

- **Três diapositivos novos** (agora 13): o caso de negócio da ligação do GPS das carrinhas (Quatenus) e do CCTV do
  parque da Foodtec ao portal, o plano com metas e a conformidade legal.
- **Caso de negócio:** benefícios em três frentes — mais vendas, menos custos e mais controlo — e o valor de cada visita a
  mais por rota e por dia, calculado com os dados reais do mês (compra média × rotas activas × dias úteis), por mês e
  por ano. Investimento: plataforma Quatenus e CCTV já em uso, integração interna; só uma câmara de leitura de
  matrículas no portão, se o CCTV actual não as ler.
- **Metas a 6 meses com linha de base:** visitas com venda por rota e por dia (valor actual do Pinguim BO), saídas do
  parque a horas (CCTV e GPS), visitas confirmadas por GPS, quilómetros por venda, combustível por caixa entregue e uso
  das viaturas fora de horas; cronograma em quatro fases com datas.
- **Conformidade legal:** o que diz a lei (Constituição, art. 32.º; Lei 22/11, arts. 5.º, 11.º, 17.º, 25.º, 33.º–35.º e
  51.º) e os sete passos para ficar tudo oficial, cada um com responsável e data: avaliação de impacto, pedido de
  autorização à Agência de Protecção de Dados, política escrita aos colaboradores, avisos no parque e nas viaturas,
  contrato com a Quatenus, regras no portal e arranque com dados reais só depois da autorização.
- A Quatenus e o CCTV do parque aparecem na arquitectura como sistemas «Por ligar» até estarem configurados; novas
  recomendações ao Conselho: pedido à APD (13-11-2026), acesso à API da Quatenus (16-11-2026) e leitura de matrículas no
  portão (27-11-2026).
- Teste novo: nenhum diapositivo tem conteúdo por cima do rodapé.

## 05-11-2026

### Painel de indicadores dinâmico num só ecrã; cabeçalhos das tabelas fixos

- **Painel num só ecrã, sem barras de deslocação, em qualquer ecrã de computador:** a disposição ajusta-se à altura
  disponível (cabeçalho compacto e, em ecrãs baixos, indicadores numa só linha e quadros sem subtítulo); cada gráfico
  desenha-se à altura do seu quadro e os números dos eixos espaçam-se para nunca se sobreporem.
- **Tabelas longas:** ao descer, ficam fixos no topo o cabeçalho da própria tabela (título, nota, Procurar e Excel) e o
  nome das colunas. A barra superior e os filtros da página já não ficam presos.

### Cartazes QR, painel de indicadores, cabeçalhos fixos e registos de acesso

- **Cartazes QR:** a impressão deixa de ter o título, o endereço, a data e o número de página do navegador (página sem
  margens só durante a impressão dos cartazes; a margem de segurança fica dentro do cartaz).
- **Painel de indicadores sem nada cortado:** os gráficos desenham-se à altura real de cada quadro (eixos, datas e
  legendas sempre visíveis). Em ecrãs baixos, onde os quadros ficariam ilegíveis, o painel passa a deslocar-se na
  vertical com os quadros à altura normal; nos ecrãs grandes continua num só ecrã. Rótulos que não cabem na largura
  saem alternados (o valor completo continua na dica). O idioma (PT/EN/TI) passou para a barra superior.
- **Registos de acesso com país e cidade:** a base de dados de localização dos IP (DB-IP) passa a ser instalada e
  renovada todos os meses pela tarefa automática do servidor, sem intervenção; os registos antigos ganham a
  localização logo que a base exista.
- **Contas:** a lista de utilizadores mostra a última entrada e a última alteração da palavra-passe (como foi feita:
  pela própria pessoa, por recuperação por correio ou reposta pelo super administrador), com a data, o local
  aproximado e o IP. Os registos da recuperação do acesso aparecem com nomes e detalhes legíveis.

### Recuperar o acesso pela própria pessoa (palavra-passe esquecida ou conta bloqueada)

- **«Esqueceu-se da palavra-passe ou tem a conta bloqueada?»** na página de entrada: a pessoa indica o utilizador ou o
  correio electrónico e recebe, no correio da sua ficha, uma ligação para escolher uma nova palavra-passe. Ao concluir,
  a conta fica desbloqueada sem precisar do super administrador.
- **Segurança (OWASP Forgot Password Cheat Sheet, OWASP ASVS 2.5, NIST SP 800-63B):** a resposta é igual exista ou não
  a conta e demora sempre o mesmo tempo mínimo; a ligação é aleatória, de uso único e válida 30 minutos, e no servidor
  fica só o seu resumo; um pedido novo anula o anterior; limites por endereço (5 em 15 minutos) e por conta (3 por
  hora); com a verificação em dois passos activa pede também o código da aplicação (3 erros anulam a ligação).
- **No fim:** palavra-passe com as regras do portal, todas as sessões abertas terminadas, aviso por correio
  electrónico («a sua palavra-passe foi alterada») e registo na auditoria. Não entra automaticamente.
- Quem perdeu o telemóvel da verificação em dois passos continua a precisar do super administrador.

## 04-11-2026

### Menu e barra superior segundo as normas internacionais (ISO 9241-110/112, ISO/IEC 25010, WCAG 2.2 AA)

- **Menu por secções da cadeia de valor:** Comercial (Vendas, Clientes, Cobranças), Operações (Terreno, Logística),
  Análise (Inteligência de negócio) e, separada em baixo, Sistema (Equipamentos, Integrações, Administração). O
  primeiro nível passa de 11 entradas soltas para 4 secções claras.
- **Nomes que descrevem a função:** «Gestão» deu lugar a «Inteligência de negócio»; «Recebimentos» passou a
  «Cobranças», como a página; o GeoMobilidade juntou-se ao Terreno (visitas, percurso, rotas, prospecção, zonas e
  distribuidores no mesmo sítio).
- **Cada função na sua área:** Histórico de auditoria em Administração; Sincronização dos telemóveis em Equipamentos.
- **Barra superior:** Procurar (Ctrl+K ou /) vai directo a qualquer função pelo nome; estado das fontes de dados e dos
  telemóveis em texto (já não ocupa o menu nem depende só da cor); sino dos alertas com o número por tratar; menu da
  conta no canto superior direito, com nome, perfil e Terminar sessão.
- **Favoritos:** a estrela ao lado do título (ou na procura) fixa até 6 funções no topo do menu; a lista segue a conta
  em qualquer computador ou telemóvel.
- **Indicadores com significado:** o número ao lado de uma área diz o que conta (por exemplo, «75 clientes não
  activos»), também para leitores de ecrã.
- **Ícones:** cada função tem um ícone só seu (um ícone, um significado).
- **Orientação e acessibilidade:** cada página mostra onde está (por exemplo, Comercial › Vendas); texto secundário do
  tema escuro com contraste de pelo menos 4,5:1.

### Entrada directa no portal e entrada mais leve

- **Sem página intermédia:** depois de entrar, o utilizador vai directo ao portal (Início). A antiga página de escolha
  «Onde pretende entrar?» deixou de existir, porque o GeoMobilidade já está dentro do portal; a raiz do site também
  leva ao portal. Menos um clique e menos uma página a carregar em cada entrada.
- **Página de entrada mais leve:** a biblioteca do código QR só é descarregada quando é preciso configurar a dupla
  autenticação (menos 56 KB em cada entrada).
- **Medição de tempos (04-11-2026):** da entrada ao Início utilizável, entre 1,2 e 1,9 segundos (rede do escritório,
  4G e 4G lento); abrir qualquer função do menu, mediana de 0,2 segundos e máximo de 0,7 segundos em 4G lento. Todas
  as funções ficam a no máximo dois cliques do Início no computador (três no telemóvel, com o botão Menu).

### Comunicação AGT: estados e datas exactamente como na consulta

- **Estado AGT:** «Enviado», «Erro», «Pendente» e «Não enviado», como na consulta do Pinguim BO. Os indicadores do topo
  usam os mesmos nomes.
- **Datas:** Data documento e Data fiscal no formato da consulta (dd/mm/aaaa).

### Pré-visualização do documento: nada sobreposto

- A descrição longa do artigo passa à linha seguinte e já não fica por cima da quantidade; as colunas Qtd., Un. e IVA
  ficaram mais largas.
- O código do cliente aparece numa só linha.

### Comunicação AGT: colunas e estados iguais à consulta validada no Pinguim BO

- **Só os documentos que comunicaram com a AGT:** entram os documentos com resposta da AGT (campo 26098), não
  eliminados, do período escolhido.
- **Colunas:** Loja, Tipo de documento, Documento (ex.: FR FR0126S78519N/6), Total do documento, Mensagem (estado
  interno do documento), Data documento, Data fiscal, Nome entidade, Estado AGT e Mensagem de erro.
- **Estado AGT:** «Enviado» (código 3 ou confirmação da AGT), «Erro - Comunicado» (código 5), «Pendente» (código 2) e
  «Erro - Não comunicado» (outros casos). A mensagem de erro aparece tal como vem do Pinguim BO.
- **Indicadores:** documentos, enviados, erro comunicado, erro não comunicado e pendentes. Os dois tipos de erro
  continuam a gerar o alerta crítico por rota.

### Apresentação ao Conselho de Administração, em formato corporativo

- **10 diapositivos em formato executivo (16:9):** capa, sumário executivo, desempenho comercial, carteira de
  clientes, automação e eficiência, governação e segurança, arquitectura e integrações, roteiro com datas, visão
  2027–2028 (Plano Tecnológico do Grupo Naval) e próximos passos recomendados, para conhecimento do Conselho.
- **Todos os números vêm do portal:** vendas de 6 meses, média mensal, último mês fechado e variação, mês corrente,
  saúde da carteira, tarefas automáticas, contas com dupla autenticação, perfis, registos de acesso e taxa de
  comunicação à AGT. Cada diapositivo indica a fonte e a hora dos dados.
- **Leitura de Conselho:** uma mensagem-chave por diapositivo, comparação «antes / agora», normas seguidas (ISO/IEC
  27001, ITIL, OWASP, Lei 22/11) e estado de cada integração (ligada ou por ligar).
- **Apresentar:** ecrã inteiro, navegação por teclado e ajuste automático a qualquer ecrã, sem barras de deslocamento.

### Comunicação AGT: a resposta oficial da AGT de cada documento

- **Fonte certa:** o estado passa a vir do campo do Pinguim BO onde fica a resposta da AGT a cada envio: código 3
  enviado, 5 erro, 2 pendente, e o texto da resposta. Quando há várias respostas, conta a final mais recente.
- **Confirmações da AGT:** «Process completed successfully» e «já consta no repositório» contam como enviado.
- **Mensagem de erro legível:** quando a resposta da AGT vem em formato técnico (JSON), o portal mostra só a descrição
  do erro.
- **Sem resposta da AGT:** documento com pedido enviado mas sem resposta fica «pendente»; sem pedido nem resposta fica
  «por comunicar». Deixa de se presumir «enviado» sem prova.

### Comunicação AGT: estado devolvido pela AGT e mensagens de erro

- **Estado como no Pinguim BO:** a página passa a ler o estado que a AGT devolveu a cada documento («Enviado»,
  «Erro» ou «Pendente») e a mensagem de erro, quando a AGT recusa (ex.: NIF do cliente desconhecido no sistema da
  AGT). Antes só se procurava o número do pedido e muitos documentos comunicados apareciam «por comunicar».
- **Novos indicadores e coluna:** Comunicados, **Com erro**, Por comunicar (pendentes ou sem pedido), Anulados, e a
  coluna **Mensagem da AGT**. O Estado AGT mostra o comprovativo: estado registado, número do pedido ou «sem registo
  de erro».
- **Alertas:** os documentos recusados pela AGT geram logo um alerta crítico por rota e entram nas tarefas de
  qualidade dos dados.
- A consulta ao Pinguim BO continua só de leitura e limitada ao período escolhido.

### Optimização de rotas automática e GeoMobilidade com os dados do portal

- **Rotas optimizadas sozinhas, todas as noites, sem intervenção humana:** logo a seguir ao cálculo da carteira, o
  portal calcula o percurso de cada rota para hoje e os 6 dias seguintes. Usa as mesmas visitas das «Visitas do dia»
  (dia habitual de compra e compras em atraso) e as coordenadas dos clientes no Pinguim BO.
  - Ordem de visita pelos métodos de referência do problema do caixeiro-viajante (vizinho mais próximo, 2-opt e
    Or-opt), com a hora prevista de chegada a cada cliente, os quilómetros, a hora de regresso e o aviso quando o
    percurso passa a jornada de trabalho.
  - Ponto de partida: o armazém configurado ou, sem configuração, o sítio onde a carrinha costuma começar o dia
    (localização do servidor OLAP).
  - As **Visitas do dia** saem já pela ordem do percurso, com o número de cada paragem.
- **Optimização de rotas (GeoMobilidade):** deixa de haver ficheiros CSV e botões «Importar» e «Optimizar». A página
  mostra o percurso do dia escolhido, no mapa e em lista, e o Excel do percurso.
- **Zonas de entrega e Distribuidores:** os clientes vêm do Pinguim BO pela API do portal, com o mesmo âmbito por rota.
  As zonas desenhadas ficam guardadas no servidor (iguais para todos, com registo de quem alterou), e não só no
  navegador de quem as desenhou.
- As páginas GEO passam a carregar sempre a versão mais recente, sem ficarem presas à cópia guardada no navegador.

### Uma só plataforma: GeoMobilidade dentro do portal

- **Sem nova entrada:** as páginas de mapas abrem dentro do portal, com a mesma sessão, o mesmo menu e o mesmo tema.
  Passar entre os relatórios e os mapas já não pede outra vez a palavra-passe. Os endereços antigos do GEO passam
  sozinhos para o portal.
- **Nova área GeoMobilidade no menu:** Optimização de rotas, Prospecção de clientes, Percurso do dia, Zonas de entrega
  e Distribuidores, só para quem tem acesso a cada uma na matriz de acessos.
- **Menu lateral à medida de cada pessoa** (botão «Menu lateral», no fundo do menu):
  - modo **Fixo** (sempre visível), **Compacto** (só os ícones; abre ao passar o rato) ou **Oculto** (abre com o botão
    Menu, deixando o ecrã inteiro para a página);
  - posição à **esquerda** ou à **direita**;
  - a escolha fica guardada na conta e vale em qualquer computador ou telemóvel.
- **Nomes:** a página inicial passa a «Painel de indicadores» e «Cadastros · visão geral» a «Cadastros · indicadores».

### Documento: exportar PDF e ajuste automático à janela

- **Exportar PDF:** o documento descarrega-se em PDF A4, com texto pesquisável e a mesma estrutura da folha.
- **Ajuste à janela:** a folha ajusta-se sozinha à largura do painel, como um leitor de PDF, sem barras de deslocação
  horizontais, no computador e no telemóvel.

### Apresentação do portal para o Conselho de Administração

- Uma mensagem por ecrã, números grandes e pouco texto, sem explicações técnicas: início, negócio, clientes,
  automação, futuro e calendário.
- Datas concretas em cada iniciativa, calendário das fases e recomendações com data, para conhecimento do Conselho.

### App Naval Gestão: entrada corrigida

- **Naval Gestão (telemóvel):** quem abria o endereço da app sem sessão iniciada era enviado para uma página de
  entrada inexistente. Agora vai para a página de entrada do portal e, depois de entrar, volta à app, pronta a
  adicionar ao ecrã principal do iPhone ou do Android.

## 03-11-2026

### Recomendações para o Conselho de Administração

- **Apresentação do portal:** a última cena passa a «Fases e recomendações». Os próximos passos aparecem como
  recomendações para conhecimento, cada uma com o benefício e o calendário indicativo, e seguem os circuitos habituais
  de aprovação da empresa (boas práticas de documentos para o conselho: finalidade clara, recomendação, benefício e
  calendário).

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
  - **Evolução, futuro, fases e recomendações.**
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
  > de sincronização. Só para quem tem acesso a essa página.
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
- **Plano de cargas**: _Exportar Excel_ a qualquer momento (rascunho marcado «RASCUNHO — NÃO PUBLICADO»,
  ou versão publicada oficial) e _Enviar por correio electrónico_ a versão publicada aos destinatários escolhidos da lista.
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
