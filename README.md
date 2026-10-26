# GeoMobilidade Naval · Grupo Naval

Sistema Integrado de Operações. A página principal (`index.html`) dá acesso a dois módulos:

| Módulo | Endereço | Estado |
|---|---|---|
| **REPORTS** | `reports/` | Portal RTM (abaixo) |
| **GEO** | por configurar (`GEO_URL` em `index.html`) | Rotas, zonas de entrega e distribuidores |

## REPORTS · Portal RTM

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

- Cada conta tem de ter um email associado. Sem email a entrada é recusada.
- À 3.ª palavra-passe errada a conta fica bloqueada. Só o super administrador (SADM) a desbloqueia, na página **Acessos** do portal ou pelo comando `desbloquear`.
- A sessão termina após 30 minutos sem actividade e, em qualquer caso, ao fim de 10 horas.
- Verificação em dois passos (código de 6 dígitos numa aplicação autenticadora) obrigatória para SADM, TI e DIR.
- Contas SADM: criação, activação e mudança de perfil exigem duas aprovações (`--aprovado-por "Nome1,Nome2"`) e ficam inactivas até serem activadas.

### Instalação no servidor

1. Criar a pasta privada **fora** de `htdocs`, por exemplo `C:\Apache24\dados-privados` (é o valor por omissão: `../dados-privados` a partir do site). Para outro sítio, definir `DADOS_PRIVADOS=` no `.env`.
2. Passar as contas antigas para a lista nova:
   `php ferramentas\utilizadores.php importar-antigo`
3. Associar o email a cada conta e definir o perfil e as rotas:
   `php ferramentas\utilizadores.php email joao joao@naval.co.ao`
   `php ferramentas\utilizadores.php perfil joao SUP`
   `php ferramentas\utilizadores.php rotas joao 1001,1002`
4. Criar contas novas (a palavra-passe temporária aparece no ecrã):
   `php ferramentas\utilizadores.php criar joao --nome "João Silva" --email joao@naval.co.ao --perfil PRO --rotas 1003`
5. Criar os super administradores e activá-los com duas aprovações:
   `php ferramentas\utilizadores.php criar admin.emergencia --nome "..." --email ... --perfil SADM --aprovado-por "CEO,CFO"`
   `php ferramentas\utilizadores.php activar admin.emergencia --aprovado-por "CEO,CFO"`
6. Activar os dois passos para SADM, TI e DIR e ler o código QR / segredo na aplicação autenticadora:
   `php ferramentas\utilizadores.php dois-passos joao`
7. `php ferramentas\utilizadores.php listar` mostra todas as contas e o estado.

Outros comandos: `senha`, `desactivar`, `activar`, `validade`, `desbloquear`. Os registos de actividade (entradas, saídas, bloqueios, exportações) ficam em `dados-privados/registos/acessos-AAAA-MM.jsonl` e aparecem na página Acessos.

Enquanto a lista nova não existir, o portal continua a usar `geo/auth/config_users.php` em modo de compatibilidade (administradores como TI, restantes como DIR) e a página Acessos avisa disso.
