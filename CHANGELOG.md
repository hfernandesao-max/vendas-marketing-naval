# Registo de alterações

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em dd-mm-aaaa.

## 02-11-2026

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
- **Migrações:** tabela `ATV_MIGRACAO` no script do módulo Equipamentos e pasta `ferramentas/sql/migracoes`.

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
  do tamanho da origem (ficheiros, entradas da cache) e do âmbito de quem pede, antes de ler e converter os dados;
  quando nada mudou o servidor responde 304 logo (ex.: carteira de clientes 8,3 ms → 3,2 ms).
- Medido numa rede móvel simulada (150 ms, 1,5 Mbps), no Apache: portal 3,4 s → 2,4 s na primeira vez (303 KB →
  152 KB, 61 → 46 pedidos) e 1,4 s → 0,9 s nas seguintes. Sem alterações à configuração do Apache.

### Velocidade
- **Pedidos à API em paralelo:** a sessão PHP fecha-se para escrita logo a seguir à autenticação; os outros pedidos
  da mesma pessoa deixam de esperar pelas consultas lentas (medido: 1,8 s → 6 ms enquanto as vendas são lidas).
- **Compressão** do HTML, CSS, JS e SVG no Apache (mod_deflate): cerca de 3 vezes menos bytes.
- **Cache longa com versões:** os módulos e estilos do portal levam a versão no endereço (import map) e ficam no
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
- Base de dados própria do módulo (`ATV_DB_*`; script em `ferramentas/sql/equipamentos-sqlserver.sql`), separada do
  Pinguim BO, que continua só de leitura. Testes do módulo numa base SQLite.
- Preparação para o SQL Server: número gerado lido com `@@IDENTITY` (as tabelas com triggers não aceitam `OUTPUT`
  directo) e verificação da instalação com `php ferramentas/verificar-equipamentos.php` (escrita de ensaio desfeita).
- **Segunda validação:** testes num SQL Server real (Windows com o ODBC Driver 17, como em produção, e Linux com
  pdo_sqlsrv) e verificação da ligação ao MDM Plus, só de leitura (`php ferramentas/verificar-mdm.php`): token OAuth,
  lista dos equipamentos e cruzamento com o portal pelo ID do MDM, IMEI ou n.º de série.
- **Configuração do MDM num só passo:** `php ferramentas/configurar-mdm.php` pede o Client ID, o Client Secret e o código
  do Self Client, troca o código pelo refresh token, grava as chaves no `.env` (com cópia do anterior, sem mostrar os
  segredos) e verifica a ligação.
- Fase 2: selagem PAdES com selo temporal, ordens ao MDM Plus, etiquetas, inventário e reconciliação com o MDM.

### Junção (servidor + GitHub)
- **Uma só versão do código:** junta o que estava no servidor (pacotes de 28 a 30-10: stock e vendas pela base de dados,
  sincronização, tigrínia e idioma por conta, modo claro, notas de crédito, Comunicação AGT) com o que estava no GitHub
  (API central v1 com JWT, segurança ASVS, pré-preenchimento do plano de cargas, localização das entradas, plano sem
  Excel). Ponto comum: commit ca0b85a (27-10, 11:37).
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
  passaram de `reports/data/*.php` para o back-end privado (`nucleo/api/v1/`). Cada pedido leva um token de acesso
  JWT (HS256, 15 min, só em memória, ligado à sessão e à versão das credenciais), obtido com a sessão da página de
  entrada e o código CSRF; origem própria sem CORS, rotas e métodos fechados, tipo de conteúdo e tamanho verificados,
  ritmo limitado. Nova chave obrigatória no `.env`: `JWT_SEGREDO`.
- **Fase 4 — optimização:** cache das consultas no servidor (vendas 2 min / 30 min, sincronização 1 min), um só
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
    domínios de email autorizados; CSV do GEO sem fórmulas; dependência `uuid` corrigida; `geo/csv.html` retirado;
  - verificação automática: workflow «Segurança» (gitleaks, npm audit, OSV-Scanner, Semgrep, CodeQL, OWASP ZAP) em cada
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
  ou versão publicada oficial) e *Enviar por email* a versão publicada aos destinatários escolhidos da lista.
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
- Plano de cargas: publicar fixa a versão oficial; o envio por email é opcional; o servidor recusa
  destinatários fora da lista e regista cada envio. Dia do plano sempre em dd-mm-aaaa.
- «Stock por promotor / carrinha» mostra só stock (as cargas passaram ao Registo de cargas).
- Barra de topo numa só linha em portáteis; datas nunca partem a linha nas tabelas.
- Carteira de clientes: primeiro **Clientes** (registo, agora com coluna GPS) e depois **Carteira por rota** (análise).

## 26-10-2026

### Adicionado
- **Acessos**: activação da gestão de contas no portal (passagem da lista antiga com email, perfil e rotas;
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
- Menus laterais em acordeão (um aberto de cada vez); filtros de todas as páginas com o mesmo design system.
- O portal abre em «Hoje» e actualiza sozinho a cada 5 minutos; stock só com quantidades diferentes de zero.
- Plano de cargas produzido na plataforma (sem importação do Excel); indicador de fontes só com o Pinguim BO.
- Gráficos de barras desenhados à largura real, rótulos alinhados à esquerda, sem verde em títulos e etiquetas;
  campos De/Até alinhados.
