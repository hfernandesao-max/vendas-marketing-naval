# Registo de alterações

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em dd-mm-aaaa.

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
