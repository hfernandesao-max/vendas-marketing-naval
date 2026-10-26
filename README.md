# GeoMobilidade Naval · Grupo Naval

Sistema Integrado de Operações. A página principal (`index.html`) dá acesso a dois módulos:

| Módulo | Endereço | Estado |
|---|---|---|
| **REPORTS** | `reports/` | Portal RTM (abaixo) |
| **GEO** | por configurar (`GEO_URL` em `index.html`) | Rotas, zonas de entrega e distribuidores |

## REPORTS · Portal RTM

Portal da equipa RTM (Route to Market) do Grupo Naval Angola, no estilo do Portal do Grupo. Mostra **só dados do Pinguim BO e do plano de cargas**.

Site: https://hfernandesao-max.github.io/vendas-marketing-naval/

## Menu (por área de negócio)

| Área | Páginas |
|---|---|
| Início | Indicadores do período e atalhos |
| **Vendas** | Vendas por promotor · por dia · por cliente · por artigo · por promotor e marca · Ranking · Documentos |
| **Recebimentos** | Cobranças (dinheiro recebido das vendas, por meio de cobrança, e taxa de cobrança) · Facturas pendentes (antiguidade 0–7, 8–30 e mais de 30 dias) · Ficheiro para o SAP |
| **Clientes** | Carteira de clientes · Cadastros (visão geral) · Análise de cadastros · Clientes registados |
| **Terreno** | Plano de visitas · Histórico de auditoria |
| **Logística** | Plano de cargas · Stock por promotor / carrinha · Stock por armazém |
| **Administração** | Acessos |

O menu só mostra as páginas que o perfil pode abrir (matriz de acessos).

Cada página é um **grupo de relatórios**: o menu é uma árvore (área → página → relatório, com áreas que recolhem) e, no topo do conteúdo, separadores com os relatórios da página. Mostra-se um relatório de cada vez, com os indicadores e os filtros da página sempre visíveis. Cada relatório tem endereço próprio (por exemplo `reports/#vendas-dia/vd-mat`) e a página lembra o último relatório aberto. O «Excel desta página» continua a levar todos os relatórios da página.

### Filtros

Uma barra de filtros única, por baixo do período, igual em todas as páginas. Cada página mostra só os filtros que se aplicam aos seus dados:

- **Rota / promotor** (escolha múltipla; os supervisores só vêem as suas rotas)
- **Cliente** (nome, NIF ou código) e **Artigo** (nome ou código)
- **Tipo de documento** (Factura-recibo, Factura), **Meio de cobrança**, **Estado** (pagos / pendentes)
- **Município**, **Tipo de cliente** e **Estado do cliente** (páginas de clientes)

Os filtros aplicam-se a quadros, tabelas e Excel (a capa do Excel indica os filtros usados), mantêm-se ao mudar de página, ficam guardados no navegador e no endereço — por exemplo `reports/#documentos?rota=1001,1003&estado=pendente` abre já filtrado. *Limpar filtros* repõe tudo.

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

## Acessos e utilizadores

As permissões seguem a matriz de acessos (11 perfis: SADM, TI, DIR, COM, SUP, PRO, FIN, BO, LOG, GEO, AUD) e são aplicadas no servidor: cada serviço em `reports/data/` verifica o perfil antes de responder, filtra as rotas dos supervisores e promotores, retira o NIF aos perfis que não o podem ver e regista as exportações. O menu só mostra o que o perfil pode abrir.

Regras:

- Cada conta tem de ter um email associado (único). Sem email a entrada é recusada. Entra-se com o utilizador ou com o email.
- À 3.ª palavra-passe errada a conta fica bloqueada. Só o super administrador (SADM) a desbloqueia.
- Contas novas e palavras-passe repostas recebem uma palavra-passe temporária; na primeira entrada a pessoa escolhe a sua (mínimo 10 caracteres, com letras e números).
- A sessão termina após 30 minutos sem actividade e, em qualquer caso, ao fim de 10 horas.
- Verificação em dois passos (código de 6 dígitos numa aplicação autenticadora) obrigatória para SADM, TI e DIR.
- Contas SADM: criar, dar ou retirar o perfil e activar exigem duas aprovações (nomes de duas pessoas da Administração). Fica sempre pelo menos um SADM activo.

### Gestão no portal (página Acessos)

O super administrador faz tudo na página **Acessos** do portal:

- **Utilizadores**: botão *Nova conta* e, em cada linha, *Gerir* — nome, email, perfil, rotas, data de fim, activar ou desactivar, repor a palavra-passe, activar os dois passos (mostra o código QR para ler no telemóvel da pessoa) e desbloquear.
- **Matriz de acessos**: cada célula é uma lista com os níveis permitidos para essa página ou função. *Guardar alterações* aplica-as de imediato nos dados; cada pessoa vê o menu novo quando actualizar a página. *Repor matriz original* volta à matriz aprovada. A coluna SADM e as funções de administração não se alteram. A linha «Ver o NIF dos clientes» decide quem vê o NIF.
- Antes de cada alteração o portal pede a palavra-passe (ou o código de dois passos) do super administrador; a confirmação dura 10 minutos. Três confirmações falhadas terminam a sessão.
- Tudo fica nos registos de actividade, na mesma página.

As contas ficam em `dados-privados/utilizadores.json` e as alterações à matriz em `dados-privados/matriz.json`, fora da pasta pública e fora do GitHub.

### Instalação no servidor (uma vez)

1. Criar a pasta privada **fora** de `htdocs`, por exemplo `C:\Apache24\dados-privados` (é o valor por omissão: `../dados-privados` a partir do site). Para outro sítio, definir `DADOS_PRIVADOS=` no `.env`.
2. **Pelo portal (recomendado):** entrar com a conta `admin`, abrir **Administração › Acessos** e carregar em **Activar a gestão**. Indica-se o email, o perfil e as rotas de cada conta antiga e as duas pessoas da Administração que aprovaram; a conta `admin` fica super administrador e as palavras-passe mantêm-se. Faz-se uma só vez; depois, passa-se directamente ao passo 4.

   Em alternativa, pela linha de comandos — passar as contas antigas para a lista nova:
   `php ferramentas\utilizadores.php importar-antigo`
   A partir daqui, cada conta antiga só volta a entrar depois de ter email associado (passo 4). Convém fazer os passos 2 a 4 seguidos.
3. Criar o primeiro super administrador e activá-lo:
   `php ferramentas\utilizadores.php criar <utilizador> --nome "Nome" --email nome@naval.co.ao --perfil SADM --aprovado-por "Nome1,Nome2"`
   `php ferramentas\utilizadores.php activar <utilizador> --aprovado-por "Nome1,Nome2"`
4. Entrar no portal com essa conta (é pedida a nova palavra-passe) e continuar na página **Acessos**: associar o email às contas importadas, acertar perfis e rotas, criar as contas novas e activar os dois passos.

A linha de comandos (`php ferramentas\utilizadores.php`) continua disponível para emergências, por exemplo se não houver nenhum super administrador activo. Comandos: `listar`, `criar`, `perfil`, `rotas`, `email`, `senha`, `dois-passos`, `activar`, `desactivar`, `validade`, `desbloquear`.

Enquanto a lista nova não existir, o portal continua a usar `geo/auth/config_users.php` em modo de compatibilidade (administradores como TI, restantes como DIR) e a página Acessos avisa disso.

## Estrutura de dados (botão «Estrutura de dados»)

Um só Excel, `RTM - Estrutura de Dados-DD-MM-AAAA.xlsx`, com o dicionário de dados da plataforma e **sem dados de negócio**:
capa (proveniência, classificação, convenções e índice com ligações), *Tabelas e vistas* (linhas aproximadas, chave primária),
*Colunas* (tipo de dados, nulos, PK, FK, identidade, valor por omissão, descrição), *Relações* (chaves estrangeiras e regras),
*Índices*, *Modelo do portal* (conjuntos e campos do «Exportar tudo»), *Matriz de acessos*, *Perfis* e *Fontes de dados*.
Cada folha é uma tabela do Excel com nome, filtros e cabeçalho fixo.

- Serviço: `reports/data/estrutura-dados.php` → `nucleo/servicos/dicionario.php`; lê só o catálogo do SQL Server
  (`sys.*`, ou `INFORMATION_SCHEMA` noutras bases de dados) com SELECT, pela mesma guarda de só leitura.
- Acesso: recurso `estrutura-dados` da matriz (nível E): TI e AUD; o SADM tem sempre. Cada exportação fica nos registos.
- O utilizador da base de dados só vê a estrutura dos objectos a que tem acesso; com `db_datareader` vê todas as tabelas.
  Se faltarem objectos, conceder também `VIEW DEFINITION` (só leitura da estrutura).
- As descrições vêm das propriedades `MS_Description` do SQL Server, quando existem.

## Plano de cargas (`reports/#plano-cargas`)

Substitui o Excel «REPORT DRIVER MARKETING TRANSPORTES» preparado à mão e enviado por email todos os dias.

- **Quem preenche e publica:** perfil Logística (LOG), nível G na matriz. Os restantes perfis com acesso vêem a versão publicada; os supervisores só as suas rotas.
- **Preenchimento:** um cartão por rota. O motorista escolhe-se da lista e preenche o telefone, a matrícula, a viatura e a transportadora. As zonas escolhem-se da lista (sem nomes repetidos com grafias diferentes) e as quantidades indicam-se por artigo. O rascunho guarda-se sozinho.
- **Produzido na plataforma:** o plano é preenchido no portal (atalho *Copiar de …* para partir do plano do dia anterior); já não se importa o Excel antigo.
- **Publicar e enviar:** valida (motorista, matrícula, zonas e carga em cada rota que sai), gera o Excel no formato habitual e envia-o por email à lista de destinatários, com o resumo no corpo. Uma alteração depois de publicado sai como *Rectificação* (versão 2, 3, …), com nota.
- **Listas:** destinatários, motoristas, zonas, armazéns, viaturas, transportadoras e artigos. Motoristas, zonas e viaturas novos entram sozinhos ao publicar.
- O plano publicado alimenta logo o *Stock por promotor / carrinha* e o escoamento.
- Ficheiros em `dados-privados/plano-cargas/` (um JSON por dia, o Excel de cada versão e `listas.json`).

### Envio de email pelo Microsoft 365

O Microsoft 365 deixou de aceitar SMTP com palavra-passe em 2026, por isso a plataforma envia pelo Microsoft Graph com uma aplicação registada:

1. Criar a caixa que envia, por exemplo `noreply@naval.co.ao` (caixa partilhada, sem licença).
2. Em **Microsoft Entra** → *Registos de aplicações* → *Novo registo*: «Portal RTM – envio de email».
3. *Permissões de API* → *Microsoft Graph* → *Permissões de aplicação* → **Mail.Send** → *Conceder consentimento de administrador*.
4. *Certificados e segredos* → *Novo segredo do cliente* (anotar o valor e a data de expiração, para o renovar a tempo).
5. Recomendado: limitar a aplicação à caixa `noreply` no Exchange Online (política de acesso da aplicação / RBAC para aplicações), para não poder enviar em nome de outras caixas.
6. No `.env` do servidor:
   ```
   EMAIL_TENANT_ID=<identificador do inquilino>
   EMAIL_CLIENT_ID=<identificador da aplicação>
   EMAIL_CLIENT_SECRET=<segredo>
   EMAIL_REMETENTE=noreply@naval.co.ao
   PORTAL_URL=https://geo.naval.co.ao:2051
   ```
7. No `php.ini`, a extensão `openssl` tem de estar activa (o cURL é opcional).
8. Testar no portal: *Plano de cargas* → *Listas* → *Destinatários* → **Enviar email de teste para mim**.

Enquanto o email não estiver configurado, o plano publica-se na mesma e o botão *Reenviar* envia-o depois.

## Ficheiro de vendas para o SAP (`reports/#sap-exportar`)

Gera, sempre que for preciso, o Excel no modelo **RTM_Sales_SAP_Upload_Format** (folha «Upload Format», cabeçalho em português na linha 1 e nomes técnicos na linha 2), a partir das vendas do Pinguim BO.

O ficheiro é gerado **a partir do próprio modelo** (`reports/modelos/RTM_Sales_SAP_Upload_Format.xlsx`): estilos, tema, larguras, folha e as linhas 1 e 2 ficam exactamente como no original, e cada linha de dados usa a formatação da linha de exemplo do modelo. Só mudam as linhas de dados (as linhas de exemplo e as fórmulas de teste saem). Se o SAP passar a usar outro modelo, basta substituir esse ficheiro.

- **Uma linha por artigo:** INVOICENO (tipo + número, por exemplo `FR FR0126S78515N/1`), data, loja, código do artigo, quantidade, unidade, tipo de documento, SAP location, valor sem IVA, IVA e total da linha. O total do documento, o Cash (numerário) e o MULTICAIXA são do documento e repetem-se em cada linha, como no modelo.
- **Filtros:** o período do topo da página, as rotas e os tipos de documento (Factura-recibo e Factura). Por omissão ficam de fora os documentos já incluídos num ficheiro anterior, para não serem carregados duas vezes no SAP.
- **Verificação antes de gerar.** Bloqueiam: rota sem SAP location, documento sem linhas e linha sem código de artigo. São só avisos: linhas que não somam o total do documento e pagamentos por outros meios (transferência, adiantamento), que o modelo não tem.
- **Correspondência com o SAP**, na mesma página: Loja, SAP location e empregado de vendas de cada rota. Por omissão, `<rota> - FHR PIV` e `FHR PIV - ROTA <rota> - <promotor>`, como no exemplo do modelo. A SAP location tem de ser indicada para cada rota (o exemplo só traz a 1011 = RTM2).
- **Controlo de ficheiros emitidos:** cada lote tem número sequencial e impressão digital SHA-256 e é emitido uma única vez; o servidor recusa documentos que já estejam num lote válido. Estados: emitido → carregado no SAP (com a referência do SAP) ou anulado (motivo obrigatório, por outra pessoa que não quem emitiu; um lote já carregado corrige-se no SAP, por estorno). Quem emite não volta a descarregar; a segunda via é só para a auditoria (TI e AUD), com «SEGUNDA_VIA_» no nome. Tudo fica nos registos.
- **Acessos:** Financeiro (FIN) e Back-office (BO) geram; TI e Auditoria vêem e descarregam os lotes. Ficheiros em `dados-privados/sap/`.

## Desenvolvimento: testes e normas de código

O front-end está em módulos ES nativos (`reports/js/`, sem compilação) e estilos em `reports/css/`; a arquitectura, o mapa dos módulos e as regras estão em [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) e o guia de contribuição em [CONTRIBUTING.md](CONTRIBUTING.md).

Ferramentas só de desenvolvimento (não são precisas no servidor). Requer Node 22, PHP 8.1+ e Python 3.

```bash
npm ci                          # dependências de desenvolvimento
npx playwright install chromium # navegador dos testes (uma vez)
npm test                        # todos os testes (portal e servidor)
npm run lint                    # normas de código (ESLint)
npm run format:check            # formatação (Prettier)
npm run test:unidade            # testes unitários do back-end PHP (sem dependências)
composer install                # ferramentas PHP (uma vez)
composer analisar               # análise estática (PHPStan)
```

O back-end está em camadas no `nucleo/` (controladores em `reports/data/`, serviços de negócio em `nucleo/servicos/`, segurança em `nucleo/seguranca/`); todas as chaves do `.env` estão em [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md#configuração-env-na-raiz-do-site).

- `tests/portal/` — o portal no navegador, com a API do Pinguim BO simulada: todas as páginas em quatro tamanhos de ecrã (sem erros, nada fora do ecrã, quadros alinhados), barra de filtros, relatórios, painel de gráficos, ficheiro SAP igual ao modelo e importação do plano de cargas.
- `tests/servidor/` — os serviços PHP com contas de cada perfil e um Pinguim BO de teste: entrada, bloqueio e desbloqueio, âmbito por rota, NIF, gestão de contas, plano de cargas, ficheiro SAP e modo de estrutura. O ambiente de teste é criado por `tests/servidor/preparar.php` e usa um `.env` próprio (variável `NAVAL_ENV`); nada toca nos dados reais.
- `tests/unidade/` — o núcleo e os serviços PHP isolados (`php tests/unidade/correr.php`).
- No GitHub, `.github/workflows/testes.yml` corre, nas versões 7.1, 7.4, 8.1 e 8.3 do PHP, a sintaxe, os testes unitários e os testes dos serviços; e ainda a análise estática, as normas e todos os testes do portal a cada alteração.
