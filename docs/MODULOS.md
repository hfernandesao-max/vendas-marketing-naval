# Módulos do portal e ambiente de ensaio

## 1. Arquitectura: monólito modular

O portal é **uma só aplicação feita de peças independentes** (monólito modular, o modelo da Shopify; um módulo por
área de negócio, como no Domain-Driven Design). É a recomendação da Microsoft e da AWS antes de passar a
microserviços: a flexibilidade das peças sem o custo e a complexidade de dezenas de serviços separados. Mais tarde, uma
peça com muita carga pode sair para um serviço próprio sem mexer nas outras.

| Camada     | O que é                                                                                                                                                                      |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Núcleo     | Entrada, sessão, matriz de acessos, auditoria, componentes visuais, traduções, API, cache, agendador, notificações e actualizações. Serve todos os módulos; nunca se desliga |
| Módulos    | Vendas, Clientes, Pedidos dos clientes, Cobranças, Terreno, Logística e carrinhas, Inteligência de negócio, Equipamentos, Integração SAP, Comunicação AGT, Administração     |
| Conectores | Um por sistema externo (adaptadores): Pinguim BO (SQL e REST), OLAP, Google Maps, ManageEngine MDM, SAP, IA, GitHub, Quatenus, CCTV. Trocar de fornecedor muda só o conector |

## 2. Ficha de cada módulo (`nucleo/modulos/<id>.json`)

| Campo                     | Conteúdo                                                                                                 |
| ------------------------- | -------------------------------------------------------------------------------------------------------- |
| `id`, `nome`, `descricao` | Identificação                                                                                            |
| `versao`                  | Versão semântica (MAIOR.MENOR.CORRECÇÃO)                                                                 |
| `nucleo`                  | `true` nos módulos que nunca se desligam (núcleo e administração)                                        |
| `paginas`                 | Páginas do catálogo do portal                                                                            |
| `recursos`                | Funções da matriz de acessos                                                                             |
| `api`                     | Rotas da API v1                                                                                          |
| `conectores`              | Sistemas externos que usa                                                                                |
| `eventos`                 | Eventos que publica e que escuta (contratos entre módulos)                                               |
| `depende`                 | Módulos de que precisa                                                                                   |
| `ensaio`                  | `normal` ou `so_leitura` (escreve em sistemas partilhados com a produção: no ensaio a API recusa o POST) |

Regras (verificadas em `tests/unidade/modulos.teste.php`): cada página, função da matriz e rota da API pertence a um e
um só módulo; as dependências existem e não têm ciclos; os conectores estão declarados. Um módulo só fala com outro
pela API ou por eventos, nunca a ler os ficheiros do outro.

## 3. Ligar, piloto e desligar (Administração › Módulos)

| Estado    | Quem vê                                                                                              |
| --------- | ---------------------------------------------------------------------------------------------------- |
| Ligado    | Todos os perfis, conforme a matriz de acessos                                                        |
| Piloto    | Só o super administrador (SADM) e o administrador da plataforma (TI): lançamento por anéis, «anel 0» |
| Desligado | Ninguém: as páginas saem do menu, a matriz devolve «sem acesso» e a API responde 403                 |

Só o super administrador muda o estado, com confirmação da identidade; cada mudança fica no registo de auditoria
(`modulo_estado`). Não se desliga um módulo de que outro ligado dependa. O estado fica em `dados-privados/modulos.json`.

## 4. Ambiente de ensaio dentro do portal

Instalação azul e verde: cada versão nova instala-se primeiro em `<portal>/ensaio/`, ao lado da produção, sem lhe tocar.

1. A tarefa de 5 em 5 minutos descarrega a versão publicada mais recente, confere o SHA-256 e a sintaxe de todo o PHP.
2. Copia o código para `<portal>/ensaio/` (pasta nova trocada de uma só vez) e os dados privados para
   `<dados-privados>/ensaio/` (sem sessões, cache, limites, registos nem o estado do actualizador).
3. Corre os testes de fumo: arranque e bases de dados (linha de comandos) e entrada, portal, API e ficheiros (HTTP).
4. Avisa por correio: «pronta a testar no ensaio» ou «falhou no ensaio».
5. Na página Actualizações, «Abrir a versão nova (ensaio)»; os botões de aprovação só ficam activos para a versão que
   passou no ensaio, com o mesmo SHA-256. A aprovação automática das 02:45 segue a mesma regra.
6. Aprovada, instala-se na produção às 03:00 (ou já, com «Aprovar e instalar já»), com cópia de segurança e reposição
   automática, como antes.

Regras do ensaio (nunca afecta a produção):

| Regra                   | Como                                                                                                   |
| ----------------------- | ------------------------------------------------------------------------------------------------------ |
| Quem entra              | Só SADM e TI (na entrada, na abertura da sessão e em cada pedido); as tentativas ficam registadas      |
| Configuração            | O `.env` da produção (os segredos não se copiam), com dados privados próprios e endereço com `/ensaio` |
| Sessões e cookies       | Cookie de sessão e cookie da app próprios (`NAVALENSAIO`, `naval_eq_ensaio`)                           |
| Faixa                   | «AMBIENTE DE ENSAIO · versão x · não é a produção» em todas as páginas e na app                        |
| Pinguim BO              | Só leitura, como sempre                                                                                |
| SAP                     | Ficheiro com `ENSAIO_NAO_CARREGAR_` no nome; os lotes ficam só nos dados do ensaio                     |
| Correio                 | Só para os administradores de sistema, com «[ENSAIO]» no assunto                                       |
| Equipamentos            | Só leitura (a base de dados do módulo e o MDM são os da produção)                                      |
| Actualizações           | Desligadas dentro do ensaio                                                                            |
| Configuração Apache/PHP | Não se testa no ensaio (é partilhada): continua com validação antes de aplicar e reposição automática  |

`ENSAIO=0` no `.env` desliga o ensaio e a exigência de passar por ele.

## 5. Painel da carrinha (Logística)

Um ecrã por carrinha e dia (fecho de rota da distribuição directa à loja, «route settlement»): carregado, vendido,
devolvido, contagem e aprovação, peso na carrinha e a origem dos dados. **Gestão por excepção**: a contagem que bate
certo em todos os artigos, sem alertas, fecha sozinha (`CARRINHA_FECHO_AUTOMATICO`); só as diferenças vão a aprovação,
com motivo. Contagem cega: quem conta não vê totais antes de contar. Os blocos das contagens da solução do Pinguim BO,
da Quatenus e do CCTV ficam preparados e passam a «Ligado» quando a ligação existir (`PINGUIM_CONTAGENS_TABELA`,
`QUATENUS_API_URL`, `CCTV_NVR_URL`).
